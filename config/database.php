<?php
// Fuente única de "hoy" para todo el sistema (mora, devengo, cortes).
// America/Santo_Domingo (UTC-4 fijo, sin horario de verano).
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('America/Santo_Domingo');
}
function hoyFecha() {
    return date('Y-m-d');
}
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Strict'
    ]);
    session_start();
}
// Nota P0: el enforcement de timeout vive en requireLogin() (más abajo),
// cuando flash()/redirect()/BASE_PATH ya existen. Aquí solo iniciamos reloj.

// P1 producción: las variables de entorno REALES del servidor tienen prioridad
// sobre el archivo .env (permite no subir .env al hosting). Solo claves conocidas.
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'FORCE_HTTPS'] as $envKey) {
    $envVal = getenv($envKey);
    if ($envVal !== false && $envVal !== '' && !defined($envKey)) {
        define($envKey, $envVal);
    }
}
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Quita comillas envolventes: PASS="abc" => abc
            if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                $value = substr($value, 1, -1);
            }
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

if (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', 'prestamos_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('FORCE_HTTPS')) define('FORCE_HTTPS', '0');
if (!defined('BASE_PATH')) {
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $configDir = str_replace('\\', '/', __DIR__);
    $projectDir = dirname($configDir);
    if ($docRoot !== '' && stripos($configDir, $docRoot) === 0) {
        $base = substr($projectDir, strlen($docRoot));
        if ($base === '') {
            define('BASE_PATH', '/');
        } else {
            define('BASE_PATH', '/' . trim($base, '/') . '/');
        }
    } else {
        define('BASE_PATH', '/');
    }
}

function getConnection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Error de conexión: " . $e->getMessage());
        die("Error de conexión con la base de datos. Intente nuevamente más tarde.");
    }
}

function getConfiguracion($pdo) {
    $stmt = $pdo->query("SELECT * FROM configuracion LIMIT 1");
    return $stmt->fetch();
}

// Capital del acreedor que aún se puede prestar.
//
// Lado disponible = SUM(mis_deudas.monto_recibido): todo lo que el acreedor
// te ha financiado. No se usa capital_vigente porque ese valor baja en tandem
// con el prestamo cuando el cliente paga, y la resta se quedaria siempre en 0.
// Lado consumido = SUM(prestamos apalancados pendientes de capital).
//
// Asi el dinero que te devuelve un cliente vuelve a estar disponible para
// prestar, que es como funciona una linea de credito renovable.
//
// Sin $acreedorId: total de todos los acreedores (vista global del dashboard).
// Con $acreedorId: solo el de ese acreedor, para no prestar el dinero de B
// usando el de A.
function getCapitalApalancadoDisponible($pdo, $acreedorId = null) {
    $consumido = "COALESCE((SELECT SUM(monto_prestado - COALESCE(abonos_capital,0)) FROM prestamos WHERE fuente_fondos='apalancado' AND estado IN ('activo','moroso')";
    $deudas = "FROM mis_deudas WHERE estado='activo'";
    if ($acreedorId) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(monto_recibido),0) - " . $consumido . " AND acreedor_id = ?),0) AS disponible "
            . $deudas . " AND acreedor_id = ?"
        );
        $stmt->execute([$acreedorId, $acreedorId]);
    } else {
        $stmt = $pdo->query(
            "SELECT COALESCE(SUM(monto_recibido),0) - " . $consumido . "),0) AS disponible " . $deudas
        );
    }
    return floatval($stmt->fetchColumn());
}

function getDeudaPendientePorAcreedor($pdo, $acreedorIds) {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $acreedorIds))));
    if (empty($ids)) return [];
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT acreedor_id, COUNT(*) AS num_deudas, COALESCE(SUM(capital_vigente),0) AS total FROM mis_deudas WHERE estado = 'activo' AND acreedor_id IN ($marcas) GROUP BY acreedor_id");
    $stmt->execute($ids);
    $resultado = [];
    foreach ($stmt->fetchAll() as $fila) {
        $resultado[(int) $fila['acreedor_id']] = [
            'num_deudas' => (int) $fila['num_deudas'],
            'total' => round(floatval($fila['total']), 2),
        ];
    }
    return $resultado;
}

function getCapitalPropioDisponible($pdo) {
    // P0: la mora cobrada SÍ es capital disponible (antes se evaporaba).
    $cfg = getConfiguracion($pdo);
    $inicial = floatval($cfg['capital_propio_inicial'] ?? 0);
    $stmt = $pdo->query("SELECT COALESCE((SELECT SUM(pg.interes_pagado) FROM pagos pg INNER JOIN prestamos pr ON pg.prestamo_id = pr.id),0) + COALESCE((SELECT SUM(pg.mora_pagada) FROM pagos pg INNER JOIN prestamos pr ON pg.prestamo_id = pr.id),0) + COALESCE((SELECT SUM(pg.capital_pagado) FROM pagos pg INNER JOIN prestamos pr ON pg.prestamo_id = pr.id WHERE pr.fuente_fondos='propio'),0) - COALESCE((SELECT SUM(interes_pagado) FROM pagos_deudas),0) - COALESCE((SELECT SUM(monto_prestado) FROM prestamos WHERE fuente_fondos='propio' AND estado IN ('activo','moroso')),0) AS disponible");
    return $inicial + floatval($stmt->fetchColumn());
}

function escapeLike($s) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function redirect($url) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Location: $url");
    exit();
}

function sanitize($data) {
    if (is_array($data) || is_object($data)) return '';
    return htmlspecialchars(trim((string)($data ?? '')), ENT_QUOTES, 'UTF-8');
}

function formatMoney($amount, $moneda = 'MXN') {
    if ($amount === null || $amount === '') $amount = 0;
    if (!is_numeric($amount)) $amount = 0;
    $moneda = is_string($moneda) && $moneda !== '' ? $moneda : 'MXN';
    return $moneda . ' ' . number_format(floatval($amount), 2, ',', '.');
}

// QA: fecha calendario real (rechaza 2026-02-30, "next thursday").
function fechaValida($fecha) {
    if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}

// QA: número estricto (rechaza "abc", "10abc", "" silenciosos de floatval).
function numeroValido($raw, $permitirCero = false) {
    if (!isset($raw) || $raw === '') return false;
    if (!is_numeric(trim((string)$raw))) return false;
    $v = floatval($raw);
    return $permitirCero ? $v >= 0 : $v > 0;
}

function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function calcularMora($saldo, $diasAtraso, $tasaMoraDiaria, $diasGracia) {
    $diasEfectivos = max(0, $diasAtraso - $diasGracia);
    return round($saldo * ($tasaMoraDiaria / 100) * $diasEfectivos, 2);
}

function esPrestamoIndefinido($prestamo) {
    return empty($prestamo['plazo_meses']) || empty($prestamo['fecha_vencimiento']);
}

// DEPRECADO: la mora por inactividad ya no se usa (los indefinidos no generan mora).
// Se conservan por compatibilidad y siempre devuelven 0.
function ultimaActividadPrestamo($pdo, $prestamoId, $fechaPrestamo) {
    return substr($fechaPrestamo ?? hoyFecha(), 0, 10);
}

function moraInactividad($pdo, $prestamo, $config) {
    $ultima = substr($prestamo['fecha_prestamo'] ?? hoyFecha(), 0, 10);
    return ['dias' => 0, 'mora' => 0.0, 'ultima' => $ultima, 'gracia' => 0];
}

function devengarInteres($saldo, $tasaMensual, $dias) {
    $dias = max(0, intval($dias));
    return round(floatval($saldo) * (floatval($tasaMensual) / 100) / 30 * $dias, 2);
}

function diasEntreFechas($desde, $hasta = null) {
    $d1 = substr($desde ?? hoyFecha(), 0, 10);
    $d2 = substr($hasta ?? hoyFecha(), 0, 10);
    $t1 = strtotime($d1); $t2 = strtotime($d2);
    if ($t1 === false || $t2 === false) return 0;
    return max(0, (int)(($t2 - $t1) / 86400));
}

function diasAtrasoPrestamo($pdo, $prestamo, $config) {
    $estado = $prestamo['estado'] ?? 'activo';
    if (!in_array($estado, ['activo', 'moroso'], true)) {
        return ['dias' => 0, 'gracia' => 0, 'mora' => 0.0, 'base' => null, 'tipo' => 'ninguno'];
    }
    if (!esPrestamoIndefinido($prestamo)) {
        $gracia = intval($config['dias_gracia'] ?? 0);
        $venc = substr($prestamo['fecha_vencimiento'] ?? '', 0, 10);
        if ($venc === '') return ['dias' => 0, 'gracia' => $gracia, 'mora' => 0.0, 'base' => null, 'tipo' => 'vencimiento'];
        $dias = diasEntreFechas($venc, hoyFecha());
        $mora = ($dias > $gracia) ? calcularMora(floatval($prestamo['saldo_pendiente'] ?? 0), $dias, floatval($config['tasa_mora_diaria'] ?? 0), $gracia) : 0.0;
        return ['dias' => $dias, 'gracia' => $gracia, 'mora' => $mora, 'base' => $venc, 'tipo' => 'vencimiento'];
    }
    // Sin plazo: no hay vencimiento, por tanto no hay mora ni días de atraso.
    return ['dias' => 0, 'gracia' => 0, 'mora' => 0.0, 'base' => null, 'tipo' => 'ninguno'];
}

function esMorosoPrestamo($pdo, $prestamo, $config) {
    if (floatval($prestamo['saldo_pendiente'] ?? 0) <= 0) return false;
    $d = diasAtrasoPrestamo($pdo, $prestamo, $config);
    return $d['dias'] > $d['gracia'];
}

// Regla combinada de morosidad: atraso mayor a la gracia tras el
// vencimiento (definidos) o 1+ meses de interés sin pagar (cualquiera).
// Solo etiqueta: no genera recargo de mora por sí misma.
function esMorosoCombinado($pdo, $prestamo, $config) {
    if (esMorosoPrestamo($pdo, $prestamo, $config)) return true;
    if (floatval($prestamo['saldo_pendiente'] ?? 0) <= 0) return false;
    $base = substr($prestamo['fecha_ultimo_devengo'] ?? $prestamo['fecha_prestamo'] ?? hoyFecha(), 0, 10);
    $calc = interesCausadoPorMeses(floatval($prestamo['saldo_pendiente'] ?? 0), floatval($prestamo['tasa_interes'] ?? 0), $base, hoyFecha(), intval($config['dias_gracia_interes'] ?? 0));
    $intVig = round(floatval($prestamo['interes_vigente'] ?? 0) + $calc['total'], 2);
    return esMorosoPorInteres($calc['meses'], $intVig);
}

function mesesCalendarioCumplidos($base, $ref = null, $diasGracia = 0) {
    $b = substr($base ?? hoyFecha(), 0, 10);
    $r = substr($ref ?? hoyFecha(), 0, 10);
    $tb = strtotime($b); $tr = strtotime($r);
    if ($tb === false || $tr === false || $tr < $tb) return 0;
    // Gracia de interés: cada corte mensual da N días extra para pagar
    // sin que se genere el interés de ese mes (ref efectiva = ref - N días).
    $gracia = max(0, intval($diasGracia));
    if ($gracia > 0) {
        $tr -= $gracia * 86400;
        if ($tr < $tb) return 0;
    }
    $y1 = intval(date('Y', $tb)); $m1 = intval(date('n', $tb)); $d1 = intval(date('j', $tb));
    $y2 = intval(date('Y', $tr)); $m2 = intval(date('n', $tr)); $d2 = intval(date('j', $tr));
    $meses = ($y2 - $y1) * 12 + ($m2 - $m1);
    if ($d2 < $d1) $meses--;
    return max(0, $meses);
}

function interesCausadoPorMeses($saldo, $tasaMensual, $base, $ref = null, $diasGracia = 0) {
    $n = mesesCalendarioCumplidos($base, $ref, $diasGracia);
    $mensual = round(floatval($saldo) * floatval($tasaMensual) / 100, 2);
    return ['meses' => $n, 'mensual' => $mensual, 'total' => round($mensual * $n, 2)];
}

// Interés del primer mes por adelantado (solo préstamos indefinidos con
// interés): exigible desde el día 1. Definidos y sin interés: 0.
function interesInicialPrestamo($monto, $tasaMensual) {
    return round(floatval($monto) * floatval($tasaMensual) / 100, 2);
}

// Moroso por interés: 1 mes cumplido o más con interés pendiente de pago.
// Solo etiqueta visual (no genera recargo de mora).
function esMorosoPorInteres($mesesCumplidos, $interesVigente) {
    return intval($mesesCumplidos) >= 1 && round(floatval($interesVigente), 2) > 0;
}

function detalleInteresIndefinido($prestamo, $hoy = null, $diasGracia = 0) {
    $hoy = substr($hoy ?? hoyFecha(), 0, 10);
    $base = substr($prestamo['fecha_ultimo_devengo'] ?? $prestamo['fecha_prestamo'] ?? $hoy, 0, 10);
    $saldo = floatval($prestamo['saldo_pendiente'] ?? 0);
    $tasa = floatval($prestamo['tasa_interes'] ?? 0);
    $calc = interesCausadoPorMeses($saldo, $tasa, $base, $hoy, $diasGracia);
    $dias = diasEntreFechas($base, $hoy);
    $interesTotal = round(floatval($prestamo['interes_vigente'] ?? 0) + $calc['total'], 2);
    try {
        $b = new DateTime($base);
        $b->modify('+' . ($calc['meses'] + 1) . ' months');
        // El corte efectivo incluye la gracia: el interés aparece N días después del aniversario.
        $gracia = max(0, intval($diasGracia));
        if ($gracia > 0) $b->modify('+' . $gracia . ' days');
        $proximoCorte = $b->format('Y-m-d');
    } catch (Exception $e) { $proximoCorte = null; }
    return [
        'base' => $base, 'dias' => $dias, 'meses_completos' => $calc['meses'],
        'dias_restantes' => 0, 'interes_mensual' => $calc['mensual'],
        'interes_meses' => $calc['total'], 'interes_prorrateo' => 0.0,
        'interes_total' => $interesTotal, 'proximo_corte' => $proximoCorte,
    ];
}

// QA D17: solo tipo=cuota marca mensualidad (antes interes/abono consumían número y falseaban tabla).
function pagosPorCuota($pdo, $prestamoId) {
    $stmt = $pdo->prepare("SELECT numero_cuota, COALESCE(SUM(monto_pagado), 0) AS total_pagado, MIN(fecha_pago) AS fecha_pago FROM pagos WHERE prestamo_id = ? AND tipo_pago = 'cuota' GROUP BY numero_cuota");
    $stmt->execute([$prestamoId]);
    $out = [];
    foreach ($stmt->fetchAll() as $pc) $out[(int)$pc['numero_cuota']] = $pc;
    return $out;
}

function generarCalendario($prestamo, $pagosPorCuota, $diasGracia = 0) {
    $num = intval($prestamo['num_cuotas'] ?? 0);
    if ($num <= 0 || esPrestamoIndefinido($prestamo)) return ['tabla' => [], 'proxima' => null];
    $tipo = $prestamo['tipo_plazo'] ?? 'meses';
    $tasaP = tasaPorPeriodo(floatval($prestamo['tasa_interes']), $tipo) / 100;
    $cuota = floatval($prestamo['cuota_mensual']);
    $saldo = floatval($prestamo['monto_prestado']);
    try { $base = new DateTime($prestamo['fecha_prestamo']); } catch (Exception $e) { $base = new DateTime(); }
    $hoy = new DateTime('today');
    $intervalos = ['dias' => 'days', 'semanas' => 'weeks', 'meses' => 'months', 'anios' => 'years'];
    $tabla = []; $proxima = null;
    for ($i = 1; $i <= $num; $i++) {
        $interes = round($saldo * $tasaP, 2);
        $capital = round($cuota - $interes, 2);
        if ($i === $num) $capital = round($saldo, 2);
        $saldo = round($saldo - $capital, 2);
        if ($saldo < 0) $saldo = 0;
        $f = clone $base;
        $f->modify("+$i " . ($intervalos[$tipo] ?? 'months'));
        $pagado = round(floatval($pagosPorCuota[$i]['total_pagado'] ?? 0), 2);
        $total = ($i === $num) ? round($capital + $interes, 2) : $cuota;
        if ($pagado >= $total - 0.005) $estado = 'pagada';
        elseif ($pagado > 0) $estado = 'parcial';
        elseif ($f < $hoy && $hoy->diff($f)->days > intval($diasGracia)) $estado = 'vencida';
        else $estado = 'pendiente';
        $row = ['cuota' => $i, 'fecha' => $f->format('Y-m-d'), 'capital' => $capital, 'interes' => $interes, 'total' => $total, 'pagado' => $pagado, 'estado' => $estado, 'es_proxima' => false];
        if ($proxima === null && $estado !== 'pagada') { $row['es_proxima'] = true; $proxima = $row; }
        $tabla[] = $row;
    }
    if ($proxima !== null) {
        foreach ($tabla as &$t) { if ($t['cuota'] === $proxima['cuota']) $t['es_proxima'] = true; }
        unset($t);
        $proxima = $tabla[$proxima['cuota'] - 1];
    }
    return ['tabla' => $tabla, 'proxima' => $proxima];
}

function proximoVencimiento($pdo, $prestamo, $diasGracia = 0) {
    if (esPrestamoIndefinido($prestamo)) return null;
    $cal = generarCalendario($prestamo, pagosPorCuota($pdo, $prestamo['id']), $diasGracia);
    return $cal['proxima'];
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function rotateCSRFToken() {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && is_string($token) && $token !== '' && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        flash('danger', 'Debe iniciar sesión para acceder');
        redirect(BASE_PATH . 'auth/login.php');
    }
    // P0: timeout inactividad 30min + absoluta 8h (fail-closed).
    $now = time();
    $last = intval($_SESSION['last_activity'] ?? $now);
    $start = intval($_SESSION['session_start'] ?? $now);
    if (($now - $last) > (30 * 60) || ($now - $start) > (8 * 3600)) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        session_start();
        flash('danger', 'Sesión expirada por inactividad. Inicie sesión de nuevo.');
        redirect(BASE_PATH . 'auth/login.php');
    }
    $_SESSION['last_activity'] = $now;
    if (empty($_SESSION['session_start'])) $_SESSION['session_start'] = $now;
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function currentUserRole() {
    // P0 fail-closed: sin rol en sesión => lector (antes era admin).
    $r = $_SESSION['user_rol'] ?? 'lector';
    return in_array($r, ['admin', 'cajero', 'lector'], true) ? $r : 'lector';
}

function isAdmin() {
    return isLoggedIn() && currentUserRole() === 'admin';
}

function requireRole($roles) {
    requireLogin();
    $roles = (array)$roles;
    if (!in_array(currentUserRole(), $roles, true)) {
        flash('danger', 'No tiene permisos para esta acción');
        redirect(BASE_PATH . 'index.php');
    }
}

function audit($pdo, $accion, $tabla = null, $registroId = null, $detalle = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (user_id, username, accion, tabla, registro_id, detalle, ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['user_username'] ?? null,
            substr($accion, 0, 100),
            $tabla ? substr($tabla, 0, 50) : null,
            $registroId ? intval($registroId) : null,
            $detalle ? substr($detalle, 0, 2000) : null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Audit falló: " . $e->getMessage());
    }
}

function tasaPorPeriodo($tasaMensual, $tipoPlazo) {
    $factores = ['dias' => 1/30, 'semanas' => 7/30, 'meses' => 1, 'anios' => 12];
    return $tasaMensual * ($factores[$tipoPlazo] ?? 1);
}

function numCuotasPorPlazo($plazo, $tipoPlazo) {
    $multiplicadores = ['dias' => 1, 'semanas' => 1, 'meses' => 1, 'anios' => 12];
    return $plazo * ($multiplicadores[$tipoPlazo] ?? 1);
}

function calcularCuota($monto, $tasaMensual, $plazo, $tipoPlazo) {
    $numCuotas = numCuotasPorPlazo($plazo, $tipoPlazo);
    if ($numCuotas <= 0) return 0.0; // P0: evita DivisionByZero en plazo 0/indefinido
    $tasa = tasaPorPeriodo($tasaMensual, $tipoPlazo) / 100;
    if (abs($tasa) < 1e-9) return round($monto / $numCuotas, 2);
    return round($monto * ($tasa * pow(1 + $tasa, $numCuotas)) / (pow(1 + $tasa, $numCuotas) - 1), 2);
}

function jsonSeguro($data) {
    // P0: evita Stored XSS por ruptura de </script>
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

function textoPeriodo($tipoPlazo) {
    $textos = ['dias' => 'diario', 'semanas' => 'semanal', 'meses' => 'mensual', 'anios' => 'anual'];
    return $textos[$tipoPlazo] ?? 'mensual';
}

function textoPlazo($tipoPlazo) {
    $textos = ['dias' => 'días', 'semanas' => 'semanas', 'meses' => 'meses', 'anios' => 'años'];
    return $textos[$tipoPlazo] ?? 'meses';
}

function textoCuota($tipoPlazo) {
    $textos = ['dias' => 'Cuota diaria', 'semanas' => 'Cuota semanal', 'meses' => 'Cuota mensual', 'anios' => 'Cuota anual'];
    return $textos[$tipoPlazo] ?? 'Cuota mensual';
}
