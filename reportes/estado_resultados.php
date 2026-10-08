<?php
$pageTitle = 'Estado de Resultados';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$configER = [];
try { $configER = getConfiguracion($pdo); } catch (Exception $e) { $configER = []; }
$anioActual = intval($_GET['anio'] ?? date('Y'));
if ($anioActual < 2000 || $anioActual > intval(date('Y'))) $anioActual = intval(date('Y'));

$meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$resultados = [];

try {
for ($m = 1; $m <= 12; $m++) {
    // P1: rango sargable (usa idx fecha) en vez de DATE_FORMAT(col) que mata índices.
    $ini = sprintf('%04d-%02d-01', $anioActual, $m);
    $fin = date('Y-m-d', strtotime($ini . ' +1 month'));

    $ingresosStmt = $pdo->prepare("SELECT COALESCE(SUM(interes_pagado), 0) FROM pagos pg INNER JOIN prestamos p ON pg.prestamo_id = p.id WHERE pg.fecha_pago >= ? AND pg.fecha_pago < ?");
    $ingresosStmt->execute([$ini, $fin]);
    $interesesCobrados = $ingresosStmt->fetchColumn();

    $abonosCapitalStmt = $pdo->prepare("SELECT COALESCE(SUM(capital_pagado), 0) FROM pagos pg INNER JOIN prestamos p ON pg.prestamo_id = p.id WHERE pg.fecha_pago >= ? AND pg.fecha_pago < ? AND pg.es_abono_capital = 1");
    $abonosCapitalStmt->execute([$ini, $fin]);
    $abonosCapitalRecibidos = $abonosCapitalStmt->fetchColumn();

    // Capital devuelto dentro de cuotas normales (es_abono_capital = 0)
    $capCuotasStmt = $pdo->prepare("SELECT COALESCE(SUM(capital_pagado), 0) FROM pagos pg INNER JOIN prestamos p ON pg.prestamo_id = p.id WHERE pg.fecha_pago >= ? AND pg.fecha_pago < ? AND COALESCE(pg.es_abono_capital, 0) = 0");
    $capCuotasStmt->execute([$ini, $fin]);
    $capitalEnCuotas = $capCuotasStmt->fetchColumn();

    // QA: descuento resta (condonación), no suma. Antes +descuento inflaba ingresos.
    $moraCobradaStmt = $pdo->prepare("SELECT COALESCE(SUM(mora_pagada), 0), COALESCE(SUM(COALESCE(descuento,0)),0) FROM pagos pg INNER JOIN prestamos p ON pg.prestamo_id = p.id WHERE pg.fecha_pago >= ? AND pg.fecha_pago < ?");
    $moraCobradaStmt->execute([$ini, $fin]);
    [$moraCobrada, $descuentos] = array_map('floatval', (array)$moraCobradaStmt->fetch(PDO::FETCH_NUM));

    $egresosStmt = $pdo->prepare("SELECT COALESCE(SUM(interes_pagado), 0) FROM pagos_deudas pd INNER JOIN mis_deudas md ON pd.deuda_id = md.id WHERE pd.fecha_pago >= ? AND pd.fecha_pago < ?");
    $egresosStmt->execute([$ini, $fin]);
    $interesesPagados = $egresosStmt->fetchColumn();

    $abonosCapitalPagadosStmt = $pdo->prepare("SELECT COALESCE(SUM(capital_pagado), 0) FROM pagos_deudas pd INNER JOIN mis_deudas md ON pd.deuda_id = md.id WHERE pd.fecha_pago >= ? AND pd.fecha_pago < ?");
    $abonosCapitalPagadosStmt->execute([$ini, $fin]);
    $abonosCapitalPagados = $abonosCapitalPagadosStmt->fetchColumn();

    // P1: desembolsos del mes (antes invisibles; el capital devuelto figuraba como ingreso puro).
    $desStmt = $pdo->prepare("SELECT COALESCE(SUM(monto_prestado),0) FROM prestamos WHERE fecha_prestamo >= ? AND fecha_prestamo < ?");
    $desStmt->execute([$ini, $fin]);
    $desembolsos = $desStmt->fetchColumn();

    $ingresos = $interesesCobrados + $capitalEnCuotas + $abonosCapitalRecibidos + $moraCobrada - $descuentos;
    $egresos = $interesesPagados + $abonosCapitalPagados;
    $remanente = $ingresos - $egresos;
    $flujoNeto = $ingresos - $egresos - $desembolsos; // P1: caja real tras prestar
    $utilidad = $interesesCobrados + $moraCobrada - $descuentos - $interesesPagados; // P1: mora sí es utilidad, descuento resta

    $resultados[$m] = [
        'ingresos' => $ingresos,
        'intereses_cobrados' => $interesesCobrados,
        'capital_cuotas' => $capitalEnCuotas,
        'abonos_recibidos' => $abonosCapitalRecibidos,
        'mora_cobrada' => $moraCobrada,
        'descuentos' => $descuentos,
        'egresos' => $egresos,
        'intereses_pagados' => $interesesPagados,
        'abonos_pagados' => $abonosCapitalPagados,
        'remanente' => $remanente,
        'utilidad' => $utilidad,
        'desembolsos' => $desembolsos,
        'flujo_neto' => $flujoNeto,
    ];
}

$totalIngresos = array_sum(array_column($resultados, 'ingresos'));
$totalIntCobrados = array_sum(array_column($resultados, 'intereses_cobrados'));
$totalCapCuotas = array_sum(array_column($resultados, 'capital_cuotas'));
$totalAbonosRecibidos = array_sum(array_column($resultados, 'abonos_recibidos'));
$totalMoraCobrada = array_sum(array_column($resultados, 'mora_cobrada'));
$totalEgresos = array_sum(array_column($resultados, 'egresos'));
$totalIntPagados = array_sum(array_column($resultados, 'intereses_pagados'));
$totalAbonosPagados = array_sum(array_column($resultados, 'abonos_pagados'));
$totalRemanente = $totalIngresos - $totalEgresos;
$totalUtilidad = $totalIntCobrados + $totalMoraCobrada - array_sum(array_column($resultados, 'descuentos')) - $totalIntPagados; // QA: igual que mensual (mora suma, descuento resta)
$totalDescuentos = array_sum(array_column($resultados, 'descuentos'));
$totalDesembolsos = array_sum(array_column($resultados, 'desembolsos'));
$totalFlujoNeto = array_sum(array_column($resultados, 'flujo_neto'));

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="estado_resultados_' . $anioActual . '_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    $cabecera = array_merge(['Concepto'], $meses, ['TOTAL']);
    fputcsv($f, $cabecera);
    $filasCsv = [
        'INGRESOS' => 'ingresos',
        'Intereses cobrados' => 'intereses_cobrados',
        'Capital en cuotas' => 'capital_cuotas',
        'Abonos capital recibidos' => 'abonos_recibidos',
        'Mora cobrada' => 'mora_cobrada',
        'Descuentos otorgados' => 'descuentos',
        'EGRESOS' => 'egresos',
        'Intereses pagados' => 'intereses_pagados',
        'Abonos capital pagados' => 'abonos_pagados',
        'Desembolsos' => 'desembolsos',
        'REMANENTE' => 'remanente',
        'FLUJO NETO (tras desembolsos)' => 'flujo_neto',
        'UTILIDAD' => 'utilidad',
    ];
    foreach ($filasCsv as $etiqueta => $clave) {
        $fila = [$etiqueta];
        for ($m = 1; $m <= 12; $m++) $fila[] = $resultados[$m][$clave] ?? 0;
        $fila[] = array_sum(array_column($resultados, $clave));
        fputcsv($f, $fila);
    }
    fclose($f);
    exit();
}

} catch (Exception $e) {
    error_log("Error en estado de resultados: " . $e->getMessage());
    $resultados = [];
    $totalIngresos = 0; $totalIntCobrados = 0; $totalCapCuotas = 0; $totalAbonosRecibidos = 0; $totalMoraCobrada = 0; $totalDescuentos = 0;
    $totalEgresos = 0; $totalIntPagados = 0; $totalAbonosPagados = 0; $totalRemanente = 0; $totalUtilidad = 0;
    $totalDesembolsos = 0; $totalFlujoNeto = 0;
}
?>

<div class="d-none d-print-block">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; border-bottom:3px double #1e40af; padding-bottom:10px; margin-bottom:12px;">
        <div>
            <div style="font-size:1.3rem; font-weight:700; color:#1e40af;"><?= sanitize($configER['nombre_empresa'] ?? 'Sistema de Préstamos') ?></div>
            <div style="font-size:1rem; font-weight:600;">Estado de Resultados <?= $anioActual ?></div>
        </div>
        <div style="text-align:right; font-size:0.78rem; color:#444;">
            Generado el <?= date('d/m/Y H:i') ?>
        </div>
    </div>
    <table style="width:100%; border-collapse:collapse; margin-bottom:12px; font-size:0.82rem;">
        <tr>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0fdf4;"><strong>Ingresos Totales</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalIngresos, $configER['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fef2f2;"><strong>Egresos Totales</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalEgresos, $configER['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0f4ff;"><strong>Remanente</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalRemanente, $configER['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fffbeb;"><strong>Utilidad</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalUtilidad, $configER['moneda'] ?? 'MXN') ?></span></td>
        </tr>
    </table>
</div>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-bar-chart-line"></i> Estado de Resultados</h4>
        </div>
        <div class="no-print">
            <a href="?anio=<?= $anioActual ?>&export=csv" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Imprimir / PDF</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card mb-4 animate-in">
    <div class="card-body">
        <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="floating-group" style="margin-bottom:0; flex:0 0 auto;">
                <select name="anio">
                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $anioActual == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <label>Año</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
        </form>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Ingresos Totales</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($totalIngresos) ?></div>
            </div>
            <div>
                <div class="stat-label">Egresos Totales</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($totalEgresos) ?></div>
            </div>
            <div>
                <div class="stat-label">Remanente (flujo neto)</div>
                <div class="stat-value" style="font-size:1.15rem; color:<?= $totalRemanente >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= formatMoney($totalRemanente) ?></div>
                <small class="text-muted">Entradas menos salidas (incluye capital). No es utilidad.</small>
            </div>
            <div>
                <div class="stat-label">Utilidad (intereses)</div>
                <div class="stat-value" style="font-size:1.15rem; color:<?= $totalUtilidad >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= formatMoney($totalUtilidad) ?></div>
                <small class="text-muted">Intereses cobrados menos pagados.</small>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header"><i class="bi bi-table"></i> AÑO <?= $anioActual ?></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Concepto</th><?php foreach ($meses as $m): ?><th class="num"><?= $m ?></th><?php endforeach; ?><th class="num" style="background:var(--bg);">TOTAL</th></tr>
                </thead>
                <tbody>
                    <tr style="font-weight:600;"><td>INGRESOS</td><?php foreach ($meses as $i => $m): ?><td class="num" style="color:var(--success);"><?= $resultados[$i+1]['ingresos'] > 0 ? formatMoney($resultados[$i+1]['ingresos']) : '-' ?></td><?php endforeach; ?><td class="num fw-bold" style="background:var(--bg); color:var(--success);"><?= formatMoney($totalIngresos) ?></td></tr>
                    <tr><td style="padding-left:24px;">Intereses cobrados</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= $resultados[$i+1]['intereses_cobrados'] > 0 ? formatMoney($resultados[$i+1]['intereses_cobrados']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalIntCobrados) ?></td></tr>
                    <tr><td style="padding-left:24px;">Capital en cuotas</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= $resultados[$i+1]['capital_cuotas'] > 0 ? formatMoney($resultados[$i+1]['capital_cuotas']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalCapCuotas) ?></td></tr>
                    <tr><td style="padding-left:24px;">Abonos capital recibidos</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= $resultados[$i+1]['abonos_recibidos'] > 0 ? formatMoney($resultados[$i+1]['abonos_recibidos']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalAbonosRecibidos) ?></td></tr>
                    <tr><td style="padding-left:24px;">Mora cobrada</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= $resultados[$i+1]['mora_cobrada'] > 0 ? formatMoney($resultados[$i+1]['mora_cobrada']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalMoraCobrada) ?></td></tr>
                    <tr><td style="padding-left:24px;">Descuentos otorgados (−)</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= ($resultados[$i+1]['descuentos'] ?? 0) > 0 ? formatMoney($resultados[$i+1]['descuentos']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalDescuentos) ?></td></tr>
                    <tr style="font-weight:600;"><td>EGRESOS</td><?php foreach ($meses as $i => $m): ?><td class="num" style="color:var(--danger);"><?= $resultados[$i+1]['egresos'] > 0 ? formatMoney($resultados[$i+1]['egresos']) : '-' ?></td><?php endforeach; ?><td class="num fw-bold" style="background:var(--bg); color:var(--danger);"><?= formatMoney($totalEgresos) ?></td></tr>
                    <tr><td style="padding-left:24px;">Intereses pagados</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= $resultados[$i+1]['intereses_pagados'] > 0 ? formatMoney($resultados[$i+1]['intereses_pagados']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalIntPagados) ?></td></tr>
                    <tr><td style="padding-left:24px;">Abonos capital pagados</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= $resultados[$i+1]['abonos_pagados'] > 0 ? formatMoney($resultados[$i+1]['abonos_pagados']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalAbonosPagados) ?></td></tr>
                    <tr style="font-weight:600; border-top:2px solid var(--border-strong);"><td>REMANENTE</td><?php foreach ($meses as $i => $m): ?><td class="num" style="color:<?= $resultados[$i+1]['remanente'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= $resultados[$i+1]['ingresos'] > 0 || $resultados[$i+1]['egresos'] > 0 ? formatMoney($resultados[$i+1]['remanente']) : '-' ?></td><?php endforeach; ?><td class="num fw-bold" style="background:var(--bg); color:<?= $totalRemanente >= 0 ? 'var(--success)' : 'var(--danger)' ?>; font-size:1.05rem;"><?= formatMoney($totalRemanente) ?></td></tr>
                    <tr><td style="padding-left:24px;">Desembolsos (−)</td><?php foreach ($meses as $i => $m): ?><td class="num"><?= ($resultados[$i+1]['desembolsos'] ?? 0) > 0 ? formatMoney($resultados[$i+1]['desembolsos']) : '-' ?></td><?php endforeach; ?><td class="num" style="background:var(--bg);"><?= formatMoney($totalDesembolsos) ?></td></tr>
                    <tr style="font-weight:600;"><td>FLUJO NETO</td><?php foreach ($meses as $i => $m): ?><td class="num" style="color:<?= ($resultados[$i+1]['flujo_neto'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= $resultados[$i+1]['ingresos'] > 0 || $resultados[$i+1]['egresos'] > 0 || ($resultados[$i+1]['desembolsos'] ?? 0) > 0 ? formatMoney($resultados[$i+1]['flujo_neto']) : '-' ?></td><?php endforeach; ?><td class="num fw-bold" style="background:var(--bg); color:<?= $totalFlujoNeto >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= formatMoney($totalFlujoNeto) ?></td></tr>
                    <tr style="font-weight:600; border-top:1px solid var(--border-strong);"><td>UTILIDAD</td><?php foreach ($meses as $i => $m): ?><td class="num" style="color:<?= $resultados[$i+1]['utilidad'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= $resultados[$i+1]['intereses_cobrados'] > 0 || $resultados[$i+1]['intereses_pagados'] > 0 ? formatMoney($resultados[$i+1]['utilidad']) : '-' ?></td><?php endforeach; ?><td class="num fw-bold" style="background:var(--bg); color:<?= $totalUtilidad >= 0 ? 'var(--success)' : 'var(--danger)' ?>; font-size:1.05rem;"><?= formatMoney($totalUtilidad) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="d-none d-print-block" style="margin-top:12px; border-top:1px solid #999; padding-top:6px; font-size:0.72rem; color:#555;">
    <span>Documento generado por <?= sanitize($configER['nombre_empresa'] ?? 'Sistema de Préstamos') ?> el <?= date('d/m/Y H:i') ?> — año <?= $anioActual ?></span>
    <span style="float:right;">Remanente: <?= formatMoney($totalRemanente, $configER['moneda'] ?? 'MXN') ?> · Utilidad: <?= formatMoney($totalUtilidad, $configER['moneda'] ?? 'MXN') ?></span>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
