<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';

$pdo = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['onboarding_action'] ?? '') !== '') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Token de seguridad inválido');
    } else {
        $action = $_POST['onboarding_action'];
        if ($action === 'set') {
            $montoOnb = max(0, floatval($_POST['capital_inicial_onboarding'] ?? 0));
            $stmt = $pdo->prepare("UPDATE configuracion SET capital_propio_inicial = ?, preguntar_capital_inicial = 0 WHERE id = 1");
            $stmt->execute([$montoOnb]);
            flash('success', 'Capital propio inicial registrado: ' . formatMoney($montoOnb));
        } elseif ($action === 'skip') {
            $pdo->exec("UPDATE configuracion SET preguntar_capital_inicial = 0 WHERE id = 1");
        }
    }
    redirect('index.php');
}

$config = getConfiguracion($pdo);
$mostrarOnboarding = intval($config['preguntar_capital_inicial'] ?? 0) === 1 && floatval($config['capital_propio_inicial'] ?? 0) == 0;

try {
$totalClientes = $pdo->query("SELECT COUNT(*) FROM clientes WHERE estado = 'activo'")->fetchColumn();
$totalPrestamosActivos = $pdo->query("SELECT COUNT(*) FROM prestamos WHERE estado IN ('activo','moroso')")->fetchColumn();
$totalMontoPrestado = $pdo->query("SELECT COALESCE(SUM(monto_prestado),0) FROM prestamos WHERE estado IN ('activo','moroso')")->fetchColumn();
$totalMontoPrestadoHistorico = $pdo->query("SELECT COALESCE(SUM(monto_prestado),0) FROM prestamos")->fetchColumn();
$totalSaldoPendiente = $pdo->query("SELECT COALESCE(SUM(saldo_pendiente),0) FROM prestamos WHERE estado IN ('activo','moroso')")->fetchColumn();
$totalCobrado = $pdo->query("SELECT COALESCE(SUM(monto_pagado),0) FROM pagos")->fetchColumn();
$totalMora = $pdo->query("SELECT COALESCE(SUM(mora_pagada),0) FROM pagos")->fetchColumn();
$totalInteresCobrado = $pdo->query("SELECT COALESCE(SUM(interes_pagado),0) FROM pagos")->fetchColumn();
$totalInteresPagado = $pdo->query("SELECT COALESCE(SUM(interes_pagado),0) FROM pagos_deudas")->fetchColumn();
$propioActivoMonto = $pdo->query("SELECT COALESCE(SUM(monto_prestado),0) FROM prestamos WHERE fuente_fondos='propio' AND estado IN ('activo','moroso')")->fetchColumn();
$diasGraciaCfg = intval($config['dias_gracia'] ?? 0);
try {
    // P1: criterio combinado (vencimiento OR 1+ mes interés) igual que morosos/reportes.
    $candStmt = $pdo->query("SELECT id, cliente_id, saldo_pendiente, estado, fecha_vencimiento, fecha_prestamo, plazo_meses, tasa_interes, interes_vigente, fecha_ultimo_devengo FROM prestamos WHERE estado IN ('activo','moroso') AND COALESCE(saldo_pendiente, 0) > 0");
    $prestamosMorosos = 0;
    $morClientes = [];
    foreach ($candStmt->fetchAll() as $cm) {
        if (esMorosoCombinado($pdo, $cm, $config)) {
            $prestamosMorosos++;
            $morClientes[$cm['cliente_id']] = true;
        }
    }
    $totalClientesMorosos = count($morClientes);
} catch (Exception $e) { error_log("Error morosos dashboard: " . $e->getMessage()); $prestamosMorosos = 0; $totalClientesMorosos = 0; }
$prestamosPagados = $pdo->query("SELECT COUNT(*) FROM prestamos WHERE estado = 'pagado'")->fetchColumn();

$totalDebo = $pdo->query("SELECT COALESCE(SUM(capital_vigente),0) FROM mis_deudas WHERE estado = 'activo'")->fetchColumn();

// P0: fórmula única = helper (monto_recibido - capital pendiente). Antes restaba
// monto_prestado bruto y mostraba sobregiro fantasma tras abonos.
try { $totalCapitalDisponible = getCapitalApalancadoDisponible($pdo); }
catch (Exception $e) { $totalCapitalDisponible = 0; }

$totalInteresCobrarMes = $pdo->query("
    SELECT COALESCE(SUM((p.monto_prestado - p.abonos_capital) * p.tasa_interes / 100), 0)
    FROM prestamos p WHERE p.estado IN ('activo','moroso')
")->fetchColumn();

$totalInteresPagarMes = $pdo->query("
    SELECT COALESCE(SUM(d.capital_vigente * d.tasa_mensual / 100), 0)
    FROM mis_deudas d WHERE d.estado = 'activo'
")->fetchColumn();

$saldoVencidoTotal = 0;
try {
    $vencStmt = $pdo->query("SELECT monto_prestado, abonos_capital, tasa_interes, saldo_pendiente, estado, fecha_vencimiento, fecha_prestamo, plazo_meses, id FROM prestamos WHERE estado IN ('activo','moroso') AND COALESCE(saldo_pendiente, 0) > 0");
    foreach ($vencStmt->fetchAll() as $vp) {
        if (esMorosoPrestamo($pdo, $vp, $config)) {
            $saldoVencidoTotal += round((floatval($vp['monto_prestado']) - floatval($vp['abonos_capital'])) * floatval($vp['tasa_interes']) / 100, 2);
        }
    }
} catch (Exception $e) { error_log("Error saldo vencido dashboard: " . $e->getMessage()); }

// Congruencia con prestamos/ver.php: total a cobrar hoy = saldo + interés
// vigente + mora, con las mismas funciones (gracia de interés por corte
// y tasa 0 cuando es sin interés).
$totalPorCobrarHoy = 0; $totalInteresVigente = 0; $totalMoraPendiente = 0;
$graciaInteresCfg = intval($config['dias_gracia_interes'] ?? 0);
try {
    $cobStmt = $pdo->query("SELECT id, saldo_pendiente, tasa_interes, interes_vigente, fecha_ultimo_devengo, fecha_prestamo, estado, fecha_vencimiento, plazo_meses FROM prestamos WHERE estado IN ('activo','moroso') AND COALESCE(saldo_pendiente, 0) > 0");
    foreach ($cobStmt->fetchAll() as $cb) {
        $saldoCb = round(floatval($cb['saldo_pendiente'] ?? 0), 2);
        $baseDev = substr($cb['fecha_ultimo_devengo'] ?? $cb['fecha_prestamo'], 0, 10);
        $calcCb = interesCausadoPorMeses($saldoCb, $cb['tasa_interes'], $baseDev, hoyFecha(), $graciaInteresCfg);
        $intVig = round(floatval($cb['interes_vigente'] ?? 0) + $calcCb['total'], 2);
        $dCb = diasAtrasoPrestamo($pdo, $cb, $config);
        $moraCb = round(floatval($dCb['mora'] ?? 0), 2);
        $totalInteresVigente = round($totalInteresVigente + $intVig, 2);
        $totalMoraPendiente = round($totalMoraPendiente + $moraCb, 2);
        $totalPorCobrarHoy = round($totalPorCobrarHoy + $saldoCb + $intVig + $moraCb, 2);
    }
} catch (Exception $e) { error_log("Error por cobrar dashboard: " . $e->getMessage()); }

$mesActual = date('Y-m');
$porCobrarMes = $totalInteresCobrarMes;

$yaCobradoInteres = $pdo->prepare("SELECT COALESCE(SUM(interes_pagado),0) FROM pagos WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ?");
$yaCobradoInteres->execute([$mesActual]);
$yaCobradoInteres = $yaCobradoInteres->fetchColumn();

$yaCobradoCapital = $pdo->prepare("SELECT COALESCE(SUM(capital_pagado),0) FROM pagos WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ? AND es_abono_capital = 1");
$yaCobradoCapital->execute([$mesActual]);
$yaCobradoCapital = $yaCobradoCapital->fetchColumn();

$yaPagadoInteres = $pdo->prepare("SELECT COALESCE(SUM(interes_pagado),0) FROM pagos_deudas WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ?");
$yaPagadoInteres->execute([$mesActual]);
$yaPagadoInteres = $yaPagadoInteres->fetchColumn();

$yaPagadoCapital = $pdo->prepare("SELECT COALESCE(SUM(capital_pagado),0) FROM pagos_deudas WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ?");
$yaPagadoCapital->execute([$mesActual]);
$yaPagadoCapital = $yaPagadoCapital->fetchColumn();

$saldoNeto = $totalMontoPrestado - $totalDebo;
$utilidadNetaMes = $totalInteresCobrarMes - $totalInteresPagarMes;
// QA: mora cobrada sí es ganancia (igual que estado_resultados). Antes se omitía y no cuadraba.
$totalMoraCobrada = $pdo->query("SELECT COALESCE(SUM(mora_pagada),0) FROM pagos")->fetchColumn();
$gananciaTotal = $totalInteresCobrado + $totalMoraCobrada - $totalInteresPagado;
$interesCobradoApa = $pdo->query("SELECT COALESCE(SUM(pg.interes_pagado),0)+COALESCE(SUM(pg.mora_pagada),0) FROM pagos pg INNER JOIN prestamos pr ON pg.prestamo_id = pr.id WHERE pr.fuente_fondos = 'apalancado'")->fetchColumn();
$utilidadApa = $interesCobradoApa - $totalInteresPagado;
$interesCobradoPropio = $pdo->query("SELECT COALESCE(SUM(pg.interes_pagado),0)+COALESCE(SUM(pg.mora_pagada),0) FROM pagos pg INNER JOIN prestamos pr ON pg.prestamo_id = pr.id WHERE pr.fuente_fondos = 'propio'")->fetchColumn();
$capitalPropioInicial = floatval(getConfiguracion($pdo)['capital_propio_inicial'] ?? 0);
try { $capitalPropioDisponible = getCapitalPropioDisponible($pdo); } catch (Exception $e) { $capitalPropioDisponible = $capitalPropioInicial + $gananciaTotal - $propioActivoMonto; }
$capitalDisponibleGeneral = $capitalPropioDisponible + $totalCapitalDisponible;

$hoyIdx = hoyFecha();
$deudoresDashboard = $pdo->query("
    SELECT c.id AS cliente_id, c.nombre, c.apellido,
           COUNT(p.id) AS num_prestamos,
           COALESCE(SUM(p.monto_prestado - p.abonos_capital), 0) AS capital_vigente,
           p.tasa_interes,
           COALESCE(SUM((p.monto_prestado - p.abonos_capital) * p.tasa_interes / 100), 0) AS interes_mes,
           p.fuente_fondos,
           MIN(p.fecha_vencimiento) AS proximo_vencimiento
    FROM clientes c
    INNER JOIN prestamos p ON p.cliente_id = c.id
    WHERE p.estado IN ('activo','moroso')
    GROUP BY c.id, c.nombre, c.apellido, p.tasa_interes, p.fuente_fondos
    ORDER BY proximo_vencimiento ASC
")->fetchAll();

$mesAnterior = date('Y-m', strtotime('-1 month'));

$clientesMesAnt = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE estado = 'activo' AND DATE_FORMAT(fecha_registro, '%Y-%m') = ?");
$clientesMesAnt->execute([$mesAnterior]);
$clientesMesAnt = $clientesMesAnt->fetchColumn();
$tendenciaClientes = $clientesMesAnt > 0 ? round((($totalClientes - $clientesMesAnt) / $clientesMesAnt) * 100) : 0;

$cobradoMesAnt = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado),0) FROM pagos WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ?");
$cobradoMesAnt->execute([$mesAnterior]);
$cobradoMesAnt = $cobradoMesAnt->fetchColumn();
$cobradoMesAct = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado),0) FROM pagos WHERE DATE_FORMAT(fecha_pago, '%Y-%m') = ?");
$cobradoMesAct->execute([$mesActual]);
$cobradoMesAct = $cobradoMesAct->fetchColumn();
$tendenciaCobro = $cobradoMesAnt > 0 ? round((($cobradoMesAct - $cobradoMesAnt) / $cobradoMesAnt) * 100) : 0;

$nuevosMesAnt = $pdo->prepare("SELECT COUNT(*) FROM prestamos WHERE DATE_FORMAT(fecha_prestamo, '%Y-%m') = ?");
$nuevosMesAnt->execute([$mesAnterior]);
$nuevosMesAnt = $nuevosMesAnt->fetchColumn();
$nuevosMesAct = $pdo->prepare("SELECT COUNT(*) FROM prestamos WHERE DATE_FORMAT(fecha_prestamo, '%Y-%m') = ?");
$nuevosMesAct->execute([$mesActual]);
$nuevosMesAct = $nuevosMesAct->fetchColumn();
$tendenciaPrestamos = $nuevosMesAnt > 0 ? round((($nuevosMesAct - $nuevosMesAnt) / $nuevosMesAnt) * 100) : 0;

$actividad = [];
$ultimosPagos = $pdo->query("
    SELECT pg.id AS pago_id, pg.monto_pagado, pg.fecha_pago, pg.fecha_registro, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, p.id AS prestamo_id
    FROM pagos pg INNER JOIN prestamos p ON pg.prestamo_id = p.id INNER JOIN clientes c ON p.cliente_id = c.id
    ORDER BY pg.fecha_registro DESC LIMIT 5
")->fetchAll();
foreach ($ultimosPagos as $pg) {
    $actividad[] = ['tipo' => 'pago', 'texto_cliente' => $pg['cliente_nombre'], 'texto_detalle' => 'pagó ' . formatMoney($pg['monto_pagado']), 'fecha' => $pg['fecha_registro'], 'color' => 'success'];
}
$ultimosPrestamos = $pdo->query("
    SELECT p.monto_prestado, p.fecha_registro, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre
    FROM prestamos p INNER JOIN clientes c ON p.cliente_id = c.id ORDER BY p.fecha_registro DESC LIMIT 5
")->fetchAll();
foreach ($ultimosPrestamos as $p) {
    $actividad[] = ['tipo' => 'prestamo', 'texto_cliente' => $p['cliente_nombre'], 'texto_detalle' => '· Préstamo nuevo de ' . formatMoney($p['monto_prestado']), 'fecha' => $p['fecha_registro'], 'color' => 'primary'];
}
usort($actividad, function($a, $b) { return strtotime($b['fecha']) - strtotime($a['fecha']); });
$actividad = array_slice($actividad, 0, 8);

} catch (Exception $e) {
    error_log("Error en dashboard: " . $e->getMessage());
    $totalClientes = 0; $totalPrestamosActivos = 0; $totalMontoPrestado = 0; $totalSaldoPendiente = 0;
    $totalCobrado = 0; $totalMora = 0; $prestamosMorosos = 0; $totalClientesMorosos = 0; $prestamosPagados = 0;
    $totalDebo = 0; $totalCapitalDisponible = 0; $totalInteresCobrarMes = 0; $totalInteresPagarMes = 0; $saldoVencidoTotal = 0; $totalMontoPrestadoHistorico = 0; $totalInteresCobrado = 0; $totalInteresPagado = 0; $gananciaTotal = 0;
    $porCobrarMes = 0; $yaCobradoInteres = 0; $yaCobradoCapital = 0; $yaPagadoInteres = 0; $yaPagadoCapital = 0;
    $saldoNeto = 0; $utilidadNetaMes = 0; $deudoresDashboard = [];
    $interesCobradoApa = 0; $utilidadApa = 0; $interesCobradoPropio = 0;
    $propioActivoMonto = 0; $capitalPropioDisponible = 0; $capitalDisponibleGeneral = 0; $capitalPropioInicial = 0;
    $tendenciaClientes = 0; $tendenciaCobro = 0; $tendenciaPrestamos = 0;
    $actividad = []; $ultimosPagos = [];
    $totalPorCobrarHoy = 0; $totalInteresVigente = 0; $totalMoraPendiente = 0;
}

function timeAgo($datetime) {
    $diff = (new DateTime())->diff(new DateTime($datetime));
    if ($diff->d > 0) return 'hace ' . $diff->d . 'd';
    if ($diff->h > 0) return 'hace ' . $diff->h . 'h';
    if ($diff->i > 0) return 'hace ' . $diff->i . 'm';
    return 'ahora';
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <ol class="breadcrumb-inline">
                <li class="bc-current">Dashboard</li>
            </ol>
            <h4 class="page-title"><i class="bi bi-grid-1x2"></i> Resumen</h4>
        </div>
        <div>
            <a href="<?= getBasePath() ?>prestamos/crear.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg"></i> Nuevo Préstamo
            </a>
        </div>
    </div>
</div>

<div class="card dash-stats animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Clientes Activos</div>
                <div class="stat-value"><?= $totalClientes ?></div>
            </div>
            <div>
                <div class="stat-label">Préstamos Activos</div>
                <div class="stat-value"><?= $totalPrestamosActivos ?></div>
            </div>
            <div style="display:none;"><!-- En Mora oculto: no se usa -->
                <div class="stat-label">En Mora
                    <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Préstamos con atraso mayor a la gracia: <?= $diasGraciaCfg ?> días tras vencer. Los préstamos sin plazo no generan mora. Ver detalle en Reportes → Clientes Morosos."></i>
                </div>
                <div class="stat-value" style="color:var(--danger);"><?= $prestamosMorosos ?></div>
            </div>
            <div>
                <div class="stat-label"><i class="bi bi-wallet2"></i> Capital Disponible
                    <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Cuánto puedes prestar ahora: tu dinero real (Capital Propio Inicial <?= formatMoney($capitalPropioInicial) ?> + Ganancia Total <?= formatMoney($gananciaTotal) ?> − Préstamos propios activos <?= formatMoney($propioActivoMonto) ?>) + crédito libre del acreedor (<?= formatMoney($totalCapitalDisponible) ?>). Se permite prestar con capital negativo."></i>
                </div>
                <div class="stat-value" style="color:<?= $capitalDisponibleGeneral > 0 ? 'var(--success)' : '#dc2626' ?>;"><?= formatMoney($capitalDisponibleGeneral) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-2">
    <div class="col-lg-6">
        <div class="card dash-stats animate-in h-100 mb-0">
            <div class="card-header"><i class="bi bi-briefcase"></i> Mi Cartera</div>
            <div class="card-body p-0">
                <div class="stat-row">
                    <div style="border-left:3px solid var(--accent); padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-cash-stack"></i> Por Cobrar Hoy
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Total exigible hoy: saldo + interés vigente + mora."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem;"><?= formatMoney($totalPorCobrarHoy) ?></div>
                        <div style="font-size:0.68rem; color:var(--text-muted); line-height:1.45; margin-top:4px;">
                            Cap <?= formatMoney($totalSaldoPendiente) ?> · Int <?= formatMoney($totalInteresVigente) ?> · Mora <?= formatMoney($totalMoraPendiente) ?>
                        </div>
                    </div>
                    <div style="border-left:3px solid var(--accent); padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-cash-coin"></i> Total Cobrado</div>
                        <div class="stat-value" style="font-size:1.1rem; color:var(--success);"><?= formatMoney($totalCobrado) ?></div>
                    </div>
                    <div style="border-left:3px solid var(--accent); padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-graph-up-arrow"></i> Interés a Cobrar / Mes
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Suma del interés mensual de cada préstamo vigente: capital vigente × tasa. Es lo que se genera cada mes si nadie paga ni abona."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem; color:var(--accent);"><?= formatMoney($totalInteresCobrarMes) ?></div>
                        <div style="font-size:0.68rem; color:var(--text-muted); line-height:1.45; margin-top:4px;">
                            Vigente hoy: <?= formatMoney($totalInteresVigente) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card dash-stats animate-in h-100 mb-0">
            <div class="card-header"><i class="bi bi-bank"></i> Mis Deudas</div>
            <div class="card-body p-0">
                <div class="stat-row">
                    <div style="border-left:3px solid #dc2626; padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-credit-card"></i> Total que Debo</div>
                        <div class="stat-value" style="font-size:1.1rem; color:#dc2626;"><?= formatMoney($totalDebo) ?></div>
                    </div>
                    <div style="border-left:3px solid #dc2626; padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-graph-down-arrow"></i> Interés a Pagar / Mes</div>
                        <div class="stat-value" style="font-size:1.1rem; color:#dc2626;"><?= formatMoney($totalInteresPagarMes) ?></div>
                    </div>
                    <div style="border-left:3px solid #dc2626; padding-left:12px; display:none;"><!-- Mora por Cobrar oculta: no se usa -->
                        <div class="stat-label"><i class="bi bi-receipt"></i> Mora por Cobrar
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Mora pendiente de cobro hoy (aún no pagada), con la misma fórmula del detalle. No confundir con la mora ya cobrada en pagos."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem; color:#dc2626;"><?= formatMoney($totalMoraPendiente) ?></div>
                    </div>
                    <div style="border-left:3px solid #059669; padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-bank"></i> Crédito Libre del Acreedor
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Cuánto puedes tomar aún de tus acreedores: deudas activas − préstamos apalancados activos. Financia préstamos 'apalancado'."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem; color:<?= $totalCapitalDisponible > 0 ? '#059669' : '#dc2626' ?>;"><?= formatMoney($totalCapitalDisponible) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card dash-stats animate-in h-100 mb-0">
            <div class="card-header"><i class="bi bi-graph-up"></i> Rentabilidad</div>
            <div class="card-body p-0">
                <div class="stat-row">
                    <div style="border-left:3px solid var(--success); padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-coin"></i> Ganancia Total</div>
                        <div class="stat-value" style="font-size:1.1rem; color:<?= $gananciaTotal >= 0 ? 'var(--success)' : '#dc2626' ?>;"><?= formatMoney($gananciaTotal) ?></div>
                        <div style="font-size:0.68rem; color:var(--text-muted); line-height:1.45; margin-top:4px;">
                            Propio <?= formatMoney($interesCobradoPropio) ?> + Apalancamiento <?= formatMoney($utilidadApa) ?>
                        </div>
                    </div>
                    <div style="border-left:3px solid var(--success); padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-bar-chart"></i> Rendimiento
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Porcentaje de retorno sobre el total prestado históricamente: (Ganancia Total ÷ Total Prestado Histórico) × 100. Ganancia Total = intereses cobrados a clientes − intereses pagados a acreedores."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem; color:var(--success);"><?= $totalMontoPrestadoHistorico > 0 ? number_format(($gananciaTotal / $totalMontoPrestadoHistorico) * 100, 2) . '%' : '—' ?></div>
                        <div style="font-size:0.68rem; color:var(--text-muted); line-height:1.45; margin-top:4px;">
                            Fórmula: (Ganancia ÷ Prestado) × 100<br>
                            Ganancia: <?= formatMoney($gananciaTotal) ?> (cobrado <?= formatMoney($totalInteresCobrado) ?> - pagado <?= formatMoney($totalInteresPagado) ?>) sobre <?= formatMoney($totalMontoPrestadoHistorico) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6" style="display:none;"><!-- Riesgo oculto: mora no se usa -->
        <div class="card dash-stats animate-in h-100 mb-0">
            <div class="card-header"><i class="bi bi-exclamation-triangle"></i> Riesgo</div>
            <div class="card-body p-0">
                <div class="stat-row">
                    <div style="border-left:3px solid #dc2626; padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-exclamation-triangle"></i> Interés en Mora / Mes
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Interés mensual que generan los préstamos en mora (capital vigente × tasa). Es lo que producen al mes, no el saldo vencido."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem; color:#dc2626;"><?= formatMoney($saldoVencidoTotal) ?></div>
                    </div>
                    <div style="border-left:3px solid #dc2626; padding-left:12px;">
                        <div class="stat-label"><i class="bi bi-exclamation-triangle"></i> Préstamos en Mora
                            <i class="bi bi-info-circle" style="cursor:help; font-size:0.75rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Mismo conteo que Reportes → Clientes Morosos: atraso mayor a la gracia (<?= $diasGraciaCfg ?> días tras el vencimiento). Los préstamos sin plazo no generan mora."></i>
                        </div>
                        <div class="stat-value" style="font-size:1.1rem; color:#dc2626;"><?= $prestamosMorosos ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-5 mb-3">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-activity"></i> Actividad Reciente</div>
            <div class="card-body">
                <?php if (empty($actividad)): ?>
                    <div class="empty-state" style="padding:24px 12px;">
                        <i class="bi bi-clock-history"></i>
                        <p>Sin actividad reciente</p>
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($actividad as $act): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot <?= $act['color'] ?>"></div>
                                <div class="timeline-content"><strong><?= sanitize($act['texto_cliente']) ?></strong> <?= sanitize($act['texto_detalle']) ?></div>
                                <div class="timeline-date"><?= timeAgo($act['fecha']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7 mb-3">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-clock-history"></i> Últimos Pagos</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Cliente</th><th class="num">Monto</th><th>Fecha</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ultimosPagos)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Sin pagos registrados</td></tr>
                        <?php else: ?>
                            <?php foreach ($ultimosPagos as $pg): ?>
                                <tr>
                                    <td><?= sanitize($pg['cliente_nombre']) ?></td>
                                    <td class="num"><?= formatMoney($pg['monto_pagado']) ?></td>
                                    <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></td>
                                    <td>
                                        <a href="<?= getBasePath() ?>pagos/recibo.php?id=<?= $pg['pago_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Ver recibo">
                                            <i class="bi bi-receipt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($deudoresDashboard)): ?>
<div class="row mt-3">
    <div class="col-12 mb-3">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-people"></i> Estado Actual por Deudor</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr><th>#</th><th>Deudor</th><th class="num">Capital Vigente</th><th class="num">Tasa</th><th class="num">Interés/Mes</th><th>Fuente</th><th>Próx. Vencimiento</th><th>Estatus</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deudoresDashboard as $i => $d): ?>
                                <?php
                                // Mismo criterio de mora del dashboard y reportes (los indefinidos no generan mora)
                                $estDeu = 'Vigente';
                                $hoyDeu = $hoyIdx ?? hoyFecha();
                                $graciaDeu = intval($diasGraciaCfg ?? 0);
                                $pvDeu = !empty($d['proximo_vencimiento']) ? substr($d['proximo_vencimiento'], 0, 10) : null;
                                if (isset($morClientes[$d['cliente_id']])) $estDeu = 'VENCIDO';
                                elseif ($pvDeu && $pvDeu < date('Y-m-d', strtotime('-' . $graciaDeu . ' days'))) $estDeu = 'VENCIDO';
                                elseif ($pvDeu && (strtotime($pvDeu) - strtotime($hoyDeu)) / 86400 <= 7) $estDeu = 'Por vencer';
                                ?>
                                <tr>
                                    <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                    <td><strong><?= sanitize($d['nombre'] . ' ' . $d['apellido']) ?></strong></td>
                                    <td class="num"><?= formatMoney($d['capital_vigente']) ?></td>
                                    <td class="num"><?= $d['tasa_interes'] ?>%</td>
                                    <td class="num"><?= formatMoney($d['interes_mes']) ?></td>
                                    <td><span class="badge badge-<?= ($d['fuente_fondos'] ?? 'propio') === 'apalancado' ? 'warning' : 'primary' ?>" style="font-size:0.7rem;"><?= ucfirst($d['fuente_fondos'] ?? 'Propio') ?></span></td>
                                    <td style="font-size:0.78rem; color:var(--text-muted);"><?= $d['proximo_vencimiento'] ? date('d/m/Y', strtotime($d['proximo_vencimiento'])) : 'Indefinido' ?></td>
                                    <td><span class="badge badge-<?= $estDeu === 'VENCIDO' ? 'danger' : ($estDeu === 'Por vencer' ? 'warning' : 'success') ?>"><?= $estDeu ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($mostrarOnboarding): ?>
<div class="modal fade" id="onboardingModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="onboardingForm">
                <?= csrfField() ?>
                <input type="hidden" name="onboarding_action" id="onboardingAction" value="skip">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-wallet2" style="color:var(--accent);"></i> ¡Bienvenido!</h5>
                </div>
                <div class="modal-body">
                    <div id="onbPregunta">
                        <p class="mb-1" style="font-size:0.9rem;">¿Vas a ingresar <strong>capital propio</strong> para empezar a prestar con tu dinero?</p>
                        <p class="text-muted mb-0" style="font-size:0.78rem;">Puedes registrar el dinero que aportas de tu bolsillo ahora, o configurarlo más tarde en Configuración.</p>
                    </div>
                    <div id="onbMonto" style="display:none;">
                        <div class="floating-group">
                            <input type="number" name="capital_inicial_onboarding" id="onbMontoInput" placeholder=" " step="0.01" min="0" disabled>
                            <label>Capital propio inicial</label>
                        </div>
                        <p class="text-muted mb-0" style="font-size:0.78rem;">Se sumará a tu capital disponible para préstamos con fuente "propio".</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <div id="onbPreguntaBtns">
                        <button type="button" id="onbSi" class="btn btn-success"><i class="bi bi-piggy-bank"></i> Sí, registrar mi capital</button>
                        <button type="submit" id="onbSkip" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i> No, por ahora</button>
                    </div>
                    <div id="onbMontoBtns" style="display:none;">
                        <button type="button" id="onbVolver" class="btn btn-link" style="font-size:0.8rem;">Volver</button>
                        <button type="submit" id="onbSet" class="btn btn-success"><i class="bi bi-check-lg"></i> Confirmar monto</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
