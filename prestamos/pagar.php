<?php
$pageTitle = 'Registrar Pago';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);
$id = intval($_GET['id'] ?? 0);
$errors = [];

$stmt = $pdo->prepare("
    SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula,
           a.nombre AS acreedor_nombre
    FROM prestamos p
    INNER JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN acreedores a ON p.acreedor_id = a.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$prestamo = $stmt->fetch();

if (!$prestamo) { flash('danger', 'Préstamo no encontrado'); redirect('../prestamos/index.php'); }
if ($prestamo['estado'] === 'pagado') { flash('warning', 'Este préstamo ya está liquidado'); redirect('ver.php?id=' . $id); }

$esIndefinido = esPrestamoIndefinido($prestamo);
$dCalcPagar = diasAtrasoPrestamo($pdo, $prestamo, $config);
$diasAtraso = $dCalcPagar['dias'];
$moraPendiente = $dCalcPagar['mora'];
$graciaAplicable = $dCalcPagar['gracia'];

$stmtCuota = $pdo->prepare("SELECT COUNT(*) + 1 FROM pagos WHERE prestamo_id = ?");
$stmtCuota->execute([$id]);
$numeroCuota = $stmtCuota->fetchColumn();

$tasaPeriodoCalc = tasaPorPeriodo($prestamo['tasa_interes'], $prestamo['tipo_plazo'] ?? 'meses') / 100;
$interesPeriodoCalc = round($prestamo['saldo_pendiente'] * $tasaPeriodoCalc, 2);
// Interés vigente: causado acumulado + meses calendario cumplidos hasta hoy
// (interés vencido: el mes en curso aún no genera).
$baseDevengoHoy = substr($prestamo['fecha_ultimo_devengo'] ?? $prestamo['fecha_prestamo'], 0, 10);
$calcDevengoHoy = interesCausadoPorMeses($prestamo['saldo_pendiente'], $prestamo['tasa_interes'], $baseDevengoHoy, hoyFecha(), intval($config['dias_gracia_interes'] ?? 0));
$diasDevengoHoy = diasEntreFechas($baseDevengoHoy, hoyFecha());
$interesVigenteTotal = round(floatval($prestamo['interes_vigente'] ?? 0) + $calcDevengoHoy['total'], 2);
$maxCuota = round($prestamo['saldo_pendiente'] + $interesVigenteTotal + $moraPendiente, 2);
$maxInteres = round($interesVigenteTotal + $moraPendiente, 2);
$maxAbono = round($prestamo['saldo_pendiente'], 2);
$maxPermitido = $maxCuota;
$cuotasRestantes = (($prestamo['num_cuotas'] ?? null) ?: ($prestamo['plazo_meses'] ?? null) ?: 1) - $numeroCuota + 1;

$montoSugerido = $prestamo['cuota_mensual'];
if ($esIndefinido) {
    // Sin interés no hay monto sugerido (evita el 0.00)
    $montoSugerido = $interesPeriodoCalc > 0 ? $interesPeriodoCalc : '';
} elseif ($cuotasRestantes <= 1 && $montoSugerido > $maxPermitido) {
    $montoSugerido = $maxPermitido;
}

$stmtTotalPagado = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado), 0) FROM pagos WHERE prestamo_id = ?");
$stmtTotalPagado->execute([$id]);
$totalPagado = $stmtTotalPagado->fetchColumn();

$cuotasPagadas = $numeroCuota - 1;
$capitalVigente = $prestamo['monto_prestado'] - $prestamo['abonos_capital'];

$pagoCuotasStmt = $pdo->prepare("SELECT numero_cuota, SUM(monto_pagado) AS total_pagado, SUM(interes_pagado) AS total_interes, SUM(capital_pagado) AS total_capital, MIN(fecha_pago) AS fecha_pago FROM pagos WHERE prestamo_id = ? AND tipo_pago = 'cuota' GROUP BY numero_cuota");
$pagoCuotasStmt->execute([$id]);
$pagosPorCuota = [];
foreach ($pagoCuotasStmt->fetchAll() as $pc) {
    $pagosPorCuota[$pc['numero_cuota']] = $pc;
}

$tablaMensualidades = [];
if ($prestamo['num_cuotas'] && $prestamo['cuota_mensual'] && $prestamo['plazo_meses']) {
    $numCuotas = $prestamo['num_cuotas'];
    $cuotaMensual = floatval($prestamo['cuota_mensual']);
    $tasaMensual = floatval($prestamo['tasa_interes']) / 100;
    $saldo = floatval($prestamo['monto_prestado']);
    $fechaBase = new DateTime($prestamo['fecha_prestamo']);

    for ($i = 1; $i <= $numCuotas; $i++) {
        $interesCuota = round($saldo * $tasaMensual, 2);
        $capitalCuota = round($cuotaMensual - $interesCuota, 2);
        if ($i === $numCuotas) {
            $capitalCuota = round($saldo, 2);
        }
        $saldo = round($saldo - $capitalCuota, 2);
        if ($saldo < 0) $saldo = 0;

        $fechaCuota = clone $fechaBase;
        $fechaCuota->modify("+$i months");

        $pagada = isset($pagosPorCuota[$i]);
        $montoPagado = $pagada ? $pagosPorCuota[$i]['total_pagado'] : 0;
        $fechaPago = $pagada ? $pagosPorCuota[$i]['fecha_pago'] : null;

        $totalCuota = ($i === $numCuotas) ? round($capitalCuota + $interesCuota, 2) : $cuotaMensual;
        $tablaMensualidades[] = [
            'cuota' => $i,
            'fecha' => $fechaCuota->format('d/m/Y'),
            'capital' => $capitalCuota,
            'interes' => $interesCuota,
            'total' => $totalCuota,
            'pagada' => $pagada,
            'monto_pagado' => $montoPagado,
            'fecha_pago' => $fechaPago ? date('d/m/Y', strtotime($fechaPago)) : null,
        ];
    }
}

// P0: idempotencia por formulario (doble-click no duplica).
if (empty($_SESSION['pago_tokens'])) $_SESSION['pago_tokens'] = [];
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $tok = bin2hex(random_bytes(16));
    $_SESSION['pago_tokens'][$id] = $tok;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } elseif (($_POST['pago_token'] ?? '') === '' || ($_POST['pago_token'] ?? '') !== ($_SESSION['pago_tokens'][$id] ?? '')) {
        $errors[] = 'Formulario ya procesado o expirado. Recargue la página (anti doble-click P0).';
    } else {
        $montoPagado = floatval($_POST['monto_pagado'] ?? 0);
        $fechaPago = $_POST['fecha_pago'] ?? date('Y-m-d');
        $observaciones = trim($_POST['observaciones'] ?? '');
        $tipoPago = $_POST['tipo_pago'] ?? 'cuota';
        if (!in_array($tipoPago, ['cuota', 'interes', 'abono'], true)) $tipoPago = 'cuota';
        $esAbonoCapital = ($tipoPago === 'abono');

        if (!numeroValido($_POST['monto_pagado'] ?? null)) $errors[] = 'El monto debe ser un número mayor a 0';
        elseif ($montoPagado <= 0) $errors[] = 'El monto debe ser mayor a 0';
        $fechaPago = trim((string)$fechaPago);
        if (!fechaValida($fechaPago)) $errors[] = 'Fecha de pago inválida (use fecha real YYYY-MM-DD)';
        else {
            if ($fechaPago > date('Y-m-d')) $errors[] = 'La fecha de pago no puede ser futura';
            if ($prestamo['fecha_prestamo'] && $fechaPago < substr($prestamo['fecha_prestamo'], 0, 10)) $errors[] = 'La fecha de pago no puede ser anterior al préstamo';
        }
        if (mb_strlen($observaciones) > 1000) $errors[] = 'Las observaciones no pueden exceder 1000 caracteres';

        if (empty($errors)) {
            // P0: todo cálculo real ocurre DENTRO de la transacción con FOR UPDATE.
            // Aquí solo validación superficial de formato.
            try {
                $pdo->beginTransaction();
                $lockStmt = $pdo->prepare("SELECT saldo_pendiente, abonos_capital, monto_mora_acumulada, interes_vigente, fecha_ultimo_devengo, estado, tasa_interes, fecha_vencimiento, monto_prestado, fuente_fondos, acreedor_id FROM prestamos WHERE id = ? FOR UPDATE");
                $lockStmt->execute([$id]);
                $locked = $lockStmt->fetch();
                if (!$locked || $locked['estado'] === 'pagado') {
                    $pdo->rollBack();
                    $errors[] = 'El préstamo ya fue liquidado o no existe';
                } else {
                $saldoPendiente = round(floatval($locked['saldo_pendiente']), 2);
                $baseDevengo = substr($locked['fecha_ultimo_devengo'] ?? $prestamo['fecha_prestamo'], 0, 10);
                if ($fechaPago < $baseDevengo) { $pdo->rollBack(); $errors[] = 'La fecha de pago no puede ser anterior al último movimiento (' . date('d/m/Y', strtotime($baseDevengo)) . ')'; }
                else {
                $calcDevengoPago = interesCausadoPorMeses($saldoPendiente, $locked['tasa_interes'], $baseDevengo, $fechaPago, intval($config['dias_gracia_interes'] ?? 0));
                $interesTotal = round(floatval($locked['interes_vigente'] ?? 0) + $calcDevengoPago['total'], 2);
                // P0: mora a fecha_pago, no a hoy (evita sobrecobro retroactivo).
                $moraFecha = 0.0;
                $vencTmp = substr($locked['fecha_vencimiento'] ?? '', 0, 10);
                if ($vencTmp !== '' && $fechaPago > $vencTmp) {
                    $dA = diasEntreFechas($vencTmp, $fechaPago);
                    $gT = intval($config['dias_gracia'] ?? 0);
                    if ($dA > $gT) $moraFecha = calcularMora($saldoPendiente, $dA, floatval($config['tasa_mora_diaria'] ?? 0), $gT);
                }
                // numero_cuota dentro del lock (anti race): MAX+1.
                $nc = $pdo->prepare("SELECT COALESCE(MAX(numero_cuota),0)+1 FROM pagos WHERE prestamo_id = ?");
                $nc->execute([$id]);
                $numeroCuota = intval($nc->fetchColumn());
                $interesPagar = 0; $moraPagar = 0; $capitalPagado = 0;
                if ($tipoPago === 'abono') {
                    if ($montoPagado > $saldoPendiente + 0.005) { $pdo->rollBack(); $errors[] = 'El abono excede el saldo pendiente. Máximo: ' . formatMoney($saldoPendiente); }
                    else {
                        // P0: prohíbe condonación silenciosa. Si el abono liquida capital
                        // dejando interés >0, se bloquea: use Cuota o registre condonación explícita.
                        if (round($saldoPendiente - round($montoPagado,2),2) <= 0.005 && $interesTotal > 0.005) {
                            $pdo->rollBack();
                            $errors[] = 'Abono bloqueado P0: liquidaría el capital dejando ' . formatMoney($interesTotal) . ' de interés vigente (se evaporaría). Use Cuota para incluir el interés.';
                        } else {
                            $capitalPagado = round($montoPagado, 2);
                            $montoPagado = $capitalPagado;
                        }
                    }
                } elseif ($tipoPago === 'interes') {
                    $maxInteresCalc = round($interesTotal + $moraFecha, 2);
                    if ($montoPagado > $maxInteresCalc + 0.005) { $pdo->rollBack(); $errors[] = 'El monto excede interés+mora. Máximo: ' . formatMoney($maxInteresCalc) . '. Use Cuota o Abono para el resto.'; }
                    else { $interesPagar = round(min($interesTotal, $montoPagado), 2); $moraPagar = round($montoPagado - $interesPagar, 2); }
                } else {
                    $maxPermitidoCalc = round($saldoPendiente + $interesTotal + $moraFecha, 2);
                    if ($montoPagado > $maxPermitidoCalc + 0.005) { $pdo->rollBack(); $errors[] = 'El monto excede el saldo pendiente. Máximo a pagar: ' . formatMoney($maxPermitidoCalc); }
                    else {
                        $interesPagar = round(min($interesTotal, $montoPagado), 2);
                        $resto = round($montoPagado - $interesPagar, 2);
                        $moraPagar = round(min($moraFecha, $resto), 2);
                        $capitalPagado = round(min($resto - $moraPagar, $saldoPendiente), 2);
                        $montoPagado = round($interesPagar + $capitalPagado + $moraPagar, 2);
                    }
                }
                if (empty($errors)) {
                $nuevoInteresVigente = round($interesTotal - $interesPagar, 2);
                if ($nuevoInteresVigente < 0) $nuevoInteresVigente = 0;
                $nuevoSaldo = round($saldoPendiente - $capitalPagado, 2);
                // P0: sin micro-ajuste silencioso. El residual <0.05 queda para el próximo pago.
                if ($nuevoSaldo < 0) $nuevoSaldo = 0;
                $nuevaMoraAcumulada = round(floatval($locked['monto_mora_acumulada']) + $moraPagar, 2);
                $nuevosAbonos = round(floatval($locked['abonos_capital']) + $capitalPagado, 2);
                $esMorosoInteresPago = esMorosoPorInteres($calcDevengoPago['meses'] ?? 0, $nuevoInteresVigente);
                $nuevoEstado = 'activo';
                // QA D9: al forzar cierre por centavos, ajustar abonos para mantener monto-abonos==saldo.
                if ($nuevoSaldo <= 0.005 && $nuevoInteresVigente <= 0.005) { $capitalPagado = round($capitalPagado + $nuevoSaldo, 2); $montoPagado = round($interesPagar + $moraPagar + $capitalPagado, 2); $nuevoSaldo = 0; $nuevoInteresVigente = 0; $nuevoEstado = 'pagado'; $nuevosAbonos = round(floatval($locked['abonos_capital']) + $capitalPagado, 2); }
                elseif ($diasAtraso > $graciaAplicable || $esMorosoInteresPago) { $nuevoEstado = 'moroso'; }
                // P0: cierre exige interés 0. Si queda interés, no puede quedar pagado.
                if ($nuevoEstado === 'pagado' && ($nuevoInteresVigente > 0.005 || $moraFecha - $moraPagar > 0.005)) {
                    $pdo->rollBack();
                    $errors[] = 'Cierre bloqueado P0: queda interés/mora pendiente. Cobre la cuota completa.';
                } else {
                unset($_SESSION['pago_tokens'][$id]); // consume idempotencia solo al éxito
                $stmt = $pdo->prepare("INSERT INTO pagos (prestamo_id, monto_pagado, capital_pagado, interes_pagado, mora_pagada, descuento, numero_cuota, fecha_pago, saldo_despues_pago, observaciones, es_abono_capital, tipo_pago) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id, $montoPagado, $capitalPagado, $interesPagar, $moraPagar, $numeroCuota, $fechaPago, $nuevoSaldo, $observaciones, $esAbonoCapital ? 1 : 0, $tipoPago]);
                $pagoId = intval($pdo->lastInsertId());

                // QA D11: el abono NO mueve la base de devengo (antes regalaba medio mes).
                $nuevaBase = ($tipoPago === 'abono') ? $baseDevengo : $fechaPago;
                $stmt = $pdo->prepare("UPDATE prestamos SET saldo_pendiente = ?, monto_mora_acumulada = ?, estado = ?, abonos_capital = ?, interes_vigente = ?, fecha_ultimo_devengo = ? WHERE id = ?");
                $stmt->execute([$nuevoSaldo, $nuevaMoraAcumulada, $nuevoEstado, $nuevosAbonos, $nuevoInteresVigente, $nuevaBase, $id]);

                // Prestamo apalancado: el capital que el cliente devuelve es el
                // mismo que debia al acreedor, asi que la deuda creada en el alta
                // se reduce en la misma medida. Al bajar ambos lados a la vez,
                // el capital disponible del acreedor vuelve a liberarse.
                // interes_pagado va en 0 a proposito: el interes que le debes
                // al acreedor se devenga aparte y se paga desde Mis Deudas.
                // Ademas getCapitalPropioDisponible() resta este campo, asi que
                // meterle valor descuadraria el capital propio.
                $deuda = null; $nuevoCapitalDeuda = null;
                if (($locked['fuente_fondos'] ?? $prestamo['fuente_fondos']) === 'apalancado' && ($locked['acreedor_id'] ?? $prestamo['acreedor_id']) && $capitalPagado > 0) {
                    $deudaStmt = $pdo->prepare("SELECT id, capital_vigente, abonos_capital_pagados FROM mis_deudas WHERE prestamo_id = ? AND estado = 'activo' FOR UPDATE");
                    $deudaStmt->execute([$id]);
                    $deuda = $deudaStmt->fetch();
                    if ($deuda) {
                        $nuevoCapitalDeuda = round($deuda['capital_vigente'] - $capitalPagado, 2);
                        if ($nuevoCapitalDeuda < 0) $nuevoCapitalDeuda = 0;
                        $nuevosAbonosDeuda = round(floatval($deuda['abonos_capital_pagados'] ?? 0) + $capitalPagado, 2);

                        $upd = $pdo->prepare("UPDATE mis_deudas SET capital_vigente = ?, abonos_capital_pagados = ?, estado = ? WHERE id = ?");
                        $upd->execute([$nuevoCapitalDeuda, $nuevosAbonosDeuda, $nuevoCapitalDeuda <= 0 ? 'pagado' : 'activo', $deuda['id']]);

                        $ins = $pdo->prepare("INSERT INTO pagos_deudas (deuda_id, monto_pagado, capital_pagado, interes_pagado, fecha_pago, notas) VALUES (?, ?, ?, 0, ?, ?)");
                        $ins->execute([$deuda['id'], $capitalPagado, $capitalPagado, $fechaPago, 'Abono de capital recibido del cliente del préstamo #' . $id]);
                    } else {
                        error_log("Prestamo $id apalancado sin deuda activa en mis_deudas; no se sincronizo el capital.");
                    }
                }

                $pdo->commit();
                $deudaSincronizada = !empty($deuda);
                audit($pdo, 'pago_prestamo', 'pagos', $pagoId, "prestamo=$id tipo=$tipoPago monto=$montoPagado capital=$capitalPagado interes=$interesPagar mora=$moraPagar" . ($deudaSincronizada ? " deuda={$deuda['id']} capital_deuda=$nuevoCapitalDeuda" : ''));
                flash('success', 'Pago registrado exitosamente');
                redirect('ver.php?id=' . $id);
                } // cierre ok
                } // if empty errors calc
                } // baseDevengo ok
                } // locked ok
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Error al registrar pago: " . $e->getMessage());
                $errors[] = 'Error al registrar el pago. Intente de nuevo.';
            }
        } // validación formato ok
    } // CSRF+idempotencia ok
} // POST
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-credit-card"></i> Registrar Pago — #<?= $prestamo['id'] ?></h4>
        </div>
        <a href="ver.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger animate-in">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card animate-in">
            <div class="card-body">
                <form method="POST" data-loading>
                    <?= csrfField() ?>
                    <input type="hidden" name="pago_token" value="<?= sanitize($_SESSION['pago_tokens'][$id] ?? '') ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="number" name="monto_pagado" id="montoPagado" placeholder=" " step="0.01" min="0.01" max="<?= $maxPermitido ?>" value="<?= isset($_POST['monto_pagado']) && $_POST['monto_pagado'] !== '' ? sanitize($_POST['monto_pagado']) : '0.00' ?>" required>
                                <label>Monto a Pagar *</label>
                            </div>
                            <small class="text-muted"><?= $esIndefinido ? 'Pago' : ($cuotasRestantes <= 1 ? 'Última cuota' : 'Cuota') ?>: <?= $montoSugerido !== '' ? formatMoney($montoSugerido) : '—' ?> · Máximo: <?= formatMoney($maxPermitido) ?></small>
                            <div class="form-text" style="font-size:0.75rem; color:var(--text-muted);">Cada cuota incluye <strong>capital + interés</strong> del período. El capital vigente es solo el saldo del principal, sin intereses.</div>
                            <div class="form-text" style="font-size:0.75rem; color:var(--text-muted);"><i class="bi bi-calculator"></i> Máximo a cobrar hoy = <?= formatMoney($prestamo['saldo_pendiente']) ?> capital + <?= formatMoney($interesVigenteTotal) ?> interés (<?= $calcDevengoHoy['meses'] ?> mes(es) × <?= formatMoney($calcDevengoHoy['mensual']) ?>).</div>
                            <div class="form-text" style="font-size:0.75rem; color:var(--text-muted);"><i class="bi bi-clock-history"></i> Cada corte mensual incluye <?= intval($config['dias_gracia_interes'] ?? 0) ?> día(s) de gracia para pagar sin generar ese interés.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="date" name="fecha_pago" value="<?= date('Y-m-d') ?>" required>
                                <label>Fecha de Pago *</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3 mt-2">
                        <label style="font-size:0.8rem; font-weight:600;">Tipo de pago *</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tipo_pago" value="cuota" id="tipoCuota" checked>
                            <label class="form-check-label" for="tipoCuota" style="font-size:0.85rem;"><strong>Cuota normal</strong> <span style="color:var(--text-muted);">— cubre interés y capital, en ese orden.</span></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tipo_pago" value="interes" id="tipoInteres">
                            <label class="form-check-label" for="tipoInteres" style="font-size:0.85rem;"><strong>Solo interés</strong> <span style="color:var(--text-muted);">— no reduce capital.</span></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tipo_pago" value="abono" id="tipoAbono">
                            <label class="form-check-label" for="tipoAbono" style="font-size:0.85rem;"><strong>Abono a capital</strong> <span style="color:var(--text-muted);">— 100% a capital; el interés vigente se mantiene.</span></label>
                        </div>
                        <div class="form-text" id="tipoPagoHint" style="font-size:0.78rem;"></div>
                    </div>
                    <div class="floating-group">
                        <textarea name="observaciones" placeholder=" " rows="2"></textarea>
                        <label>Observaciones</label>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Registrar Pago</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-info-circle"></i> Resumen del Préstamo</div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="info-label">Cliente</span><span class="info-value"><?= sanitize($prestamo['cliente_nombre']) ?></span></li>
                    <li><span class="info-label">Cédula</span><span class="info-value"><?= sanitize($prestamo['cedula']) ?></span></li>
                    <li><span class="info-label">Monto prestado</span><span class="info-value fw-bold"><?= formatMoney($prestamo['monto_prestado']) ?></span></li>
                    <li><span class="info-label">Abonos capital</span><span class="info-value" style="color:var(--success);"><?= formatMoney($prestamo['abonos_capital'] ?? 0) ?></span></li>
                    <li><span class="info-label">Capital vigente</span><span class="info-value fw-bold" style="color:var(--danger);"><?= formatMoney($capitalVigente) ?></span> <span style="font-size:0.7rem; color:var(--text-muted); display:block;">(solo capital, sin intereses)</span></li>
                    <li><span class="info-label">Total pagado</span><span class="info-value fw-bold" style="color:var(--success);"><?= formatMoney($totalPagado) ?></span></li>
                    <li><span class="info-label">Tasa <?= textoPeriodo($prestamo['tipo_plazo'] ?? 'meses') ?></span><span class="info-value"><?= $prestamo['tasa_interes'] ?>%</span></li>
                    <li><span class="info-label">Plazo</span><span class="info-value"><?= $prestamo['plazo_meses'] ? $prestamo['plazo_meses'] . ' ' . textoPlazo($prestamo['tipo_plazo'] ?? 'meses') . ' (' . ($prestamo['num_cuotas'] ?? '?') . ' ' . textoCuota($prestamo['tipo_plazo'] ?? 'meses') . ')' : 'Indefinido' ?></span></li>
                    <li><span class="info-label">Pagadas / Restantes</span><span class="info-value"><?= $esIndefinido ? $cuotasPagadas . ' / ∞' : $cuotasPagadas . ' / ' . $cuotasRestantes ?></span></li>
                    <li><span class="info-label">Fuente fondos</span><span class="info-value"><span class="badge badge-<?= ($prestamo['fuente_fondos'] ?? 'propio') === 'apalancado' ? 'warning' : 'primary' ?>"><?= ucfirst($prestamo['fuente_fondos'] ?? 'Propio') ?></span></span></li>
                    <?php if ($prestamo['fuente_fondos'] === 'apalancado' && $prestamo['acreedor_nombre']): ?>
                        <li><span class="info-label">Acreedor</span><span class="info-value"><?= sanitize($prestamo['acreedor_nombre']) ?></span></li>
                        <li><span class="info-label">Tasa que cobra el acreedor</span><span class="info-value" style="color:var(--danger);"><?= $prestamo['tasa_acreedor'] ?>%</span></li>
                    <?php endif; ?>
                    <li><span class="info-label">Interés a cobrar / mes</span><span class="info-value fw-bold" style="color:var(--success);"><?= formatMoney($prestamo['utilidad_mensual'] ?? 0) ?></span></li>
                    <li><span class="info-label">Estado</span><span class="info-value"><span class="badge badge-<?= $prestamo['estado'] ?>"><?= ucfirst($prestamo['estado']) ?></span></span></li>
                    <?php if ($diasAtraso > 0): ?>
                        <li><span class="info-label">Días atraso</span><span class="info-value fw-bold" style="color:var(--danger);"><?= $diasAtraso ?> días</span></li>
                    <?php endif; ?>
                    <?php if (false && $moraPendiente > 0): // Fila de mora oculta: no se usa ?>
                        <li><span class="info-label">Mora pendiente</span><span class="info-value fw-bold" style="color:var(--danger);"><?= formatMoney($moraPendiente) ?></span></li>
                    <?php endif; ?>
                    <li><span class="info-label">Interés vigente</span><span class="info-value fw-bold" style="color:var(--warning, #d97706);"><?= formatMoney($interesVigenteTotal) ?></span> <span style="font-size:0.7rem; color:var(--text-muted); display:block;">(acumulado al día de hoy; último devengo <?= date('d/m/Y', strtotime($baseDevengoHoy)) ?>)</span></li>
                    <li><span class="info-label">Interés <?= textoPeriodo($prestamo['tipo_plazo'] ?? 'meses') ?></span><span class="info-value" id="interesMes"><?= formatMoney($interesPeriodoCalc) ?></span> <span style="font-size:0.7rem; color:var(--text-muted); display:block;">(calculado sobre el capital vigente)</span></li>
                    <li><span class="info-label">Vencimiento</span><span class="info-value"><?= $prestamo['fecha_vencimiento'] ? date('d/m/Y', strtotime($prestamo['fecha_vencimiento'])) : 'Indefinido' ?></span></li>
                    <?php if ($esIndefinido): ?>
                        <?php $detIndPagar = detalleInteresIndefinido($prestamo, null, intval($config['dias_gracia_interes'] ?? 0)); ?>
                        <li><span class="info-label">Corte mensual</span><span class="info-value"><?= $detIndPagar['meses_completos'] ?> mes(es) × <?= formatMoney($detIndPagar['interes_mensual']) ?> <span style="font-size:0.7rem; color:var(--text-muted); display:block;">Desde inicio <?= date('d/m/Y', strtotime($prestamo['fecha_prestamo'])) ?> · próximo corte <?= $detIndPagar['proximo_corte'] ? date('d/m/Y', strtotime($detIndPagar['proximo_corte'])) : '-' ?></span></span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($tablaMensualidades)): ?>
<div class="card animate-in mt-4">
    <div class="card-header"><i class="bi bi-calendar-check"></i> Tabla de Mensualidades</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha de Pago</th>
                        <th class="num">Capital</th>
                        <th class="num">Interés</th>
                        <th class="num">Cuota</th>
                        <th>Estatus</th>
                        <th>Fecha Pagado</th>
                        <th class="num">Monto Pagado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tablaMensualidades as $m): ?>
                        <tr style="<?= $m['pagada'] ? 'opacity:0.6;' : '' ?>">
                            <td><strong><?= $m['cuota'] ?></strong></td>
                            <td style="font-size:0.82rem;"><?= $m['fecha'] ?></td>
                            <td class="num"><?= formatMoney($m['capital']) ?></td>
                            <td class="num"><?= formatMoney($m['interes']) ?></td>
                            <td class="num fw-bold"><?= formatMoney($m['total']) ?></td>
                            <td>
                                <?php if ($m['pagada']): ?>
                                    <span class="badge badge-success"><i class="bi bi-check-circle-fill"></i> Pagado</span>
                                <?php elseif ($m['cuota'] < $numeroCuota): ?>
                                    <span class="badge badge-danger"><i class="bi bi-x-circle-fill"></i> Pendiente</span>
                                <?php else: ?>
                                    <span class="badge badge-warning"><i class="bi bi-clock"></i> Por pagar</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.78rem; color:var(--text-muted);"><?= $m['fecha_pago'] ?: '-' ?></td>
                            <td class="num"><?= $m['pagada'] ? formatMoney($m['monto_pagado']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php elseif (floatval($prestamo['num_cuotas'] ?? 0) == 0 && floatval($prestamo['cuota_mensual'] ?? 0) == 0): ?>
<div class="card animate-in mt-4">
    <div class="card-header"><i class="bi bi-calendar-check"></i> Tabla de Mensualidades</div>
    <div class="card-body">
        <div class="empty-state" style="padding:20px 0;">
            <i class="bi bi-infinity" style="font-size:2rem; color:var(--text-muted);"></i>
            <h5>Préstamo Indefinido</h5>
            <p style="color:var(--text-muted);">Este préstamo no tiene plazo definido. No hay tabla de mensualidades. Los pagos se registran a medida que se reciben.</p>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('montoPagado');
    const hint = document.getElementById('tipoPagoHint');
    const cfg = {
        cuota: { max: <?= jsonSeguro($maxCuota) ?>, txt: 'Cuota: primero interés vigente y el resto a capital.' },
        interes: { max: <?= jsonSeguro($maxInteres) ?>, txt: 'Solo interés: no reduce capital.' },
        abono: { max: <?= jsonSeguro($maxAbono) ?>, txt: 'Abono: 100% a capital. El interés vigente se mantiene.' }
    };
    function fmt(n) { return 'MXN ' + Number(n).toFixed(2); }
    function aplicar(t, resetValor) {
        const c = cfg[t] || cfg.cuota;
        input.max = c.max;
        if (resetValor) input.value = '0.00';
        if (hint) hint.textContent = c.txt + ' Máximo: ' + fmt(c.max);
    }
    document.querySelectorAll('input[name="tipo_pago"]').forEach(r => r.addEventListener('change', function() { aplicar(this.value, true); }));
    const inicial = (document.querySelector('input[name="tipo_pago"]:checked') || {}).value || 'cuota';
    aplicar(inicial, false);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
