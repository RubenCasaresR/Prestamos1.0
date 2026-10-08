<?php
$pageTitle = 'Resumen de Deudores';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = [];

try {
$config = getConfiguracion($pdo);
// Deuda real: solo préstamos activos/morosos con saldo pendiente
$stmt = $pdo->query("
    SELECT c.id, c.nombre, c.apellido, c.cedula,
           COUNT(p.id) AS total_prestamos,
           COALESCE(SUM(p.monto_prestado), 0) AS capital_original,
           COALESCE(SUM(p.abonos_capital), 0) AS total_abonos,
           COALESCE(SUM(p.monto_prestado - p.abonos_capital), 0) AS capital_vigente,
           COALESCE(SUM((p.monto_prestado - p.abonos_capital) * p.tasa_interes / 100), 0) AS interes_mes
    FROM clientes c
    INNER JOIN prestamos p ON p.cliente_id = c.id AND p.estado IN ('activo','moroso')
    WHERE c.estado = 'activo'
    GROUP BY c.id, c.nombre, c.apellido, c.cedula
    HAVING capital_vigente > 0
    ORDER BY capital_vigente DESC
");
$deudores = $stmt->fetchAll();

// Préstamos liquidados en filas separadas
$stmt = $pdo->query("
    SELECT c.id, c.nombre, c.apellido, c.cedula,
           COUNT(p.id) AS total_liquidados,
           COALESCE(SUM(p.monto_prestado), 0) AS capital_prestado,
           COALESCE(SUM(pg.total_cobrado), 0) AS total_cobrado
    FROM clientes c
    INNER JOIN prestamos p ON p.cliente_id = c.id AND p.estado = 'pagado'
    LEFT JOIN (SELECT prestamo_id, SUM(monto_pagado) AS total_cobrado FROM pagos GROUP BY prestamo_id) pg ON pg.prestamo_id = p.id
    WHERE c.estado = 'activo'
    GROUP BY c.id, c.nombre, c.apellido, c.cedula
    ORDER BY total_cobrado DESC
");
$liquidados = $stmt->fetchAll();

// Estatus por deudor con la misma regla de prestamos/index.php:
// mora por vencimiento o 1+ meses de interés sin pagar
$prestamosVig = $pdo->query("SELECT id, cliente_id, saldo_pendiente, tasa_interes, interes_vigente, fecha_ultimo_devengo, fecha_prestamo, fecha_vencimiento, plazo_meses, estado FROM prestamos WHERE estado IN ('activo','moroso')")->fetchAll();
$graciaIntDeud = intval($config['dias_gracia_interes'] ?? 0);
$morosoPorCliente = [];
foreach ($prestamosVig as $pv) {
    if (!empty($morosoPorCliente[$pv['cliente_id']])) continue;
    $dPv = diasAtrasoPrestamo($pdo, $pv, $config);
    $basePv = substr($pv['fecha_ultimo_devengo'] ?? $pv['fecha_prestamo'], 0, 10);
    $calcPv = interesCausadoPorMeses(floatval($pv['saldo_pendiente'] ?? 0), floatval($pv['tasa_interes'] ?? 0), $basePv, hoyFecha(), $graciaIntDeud);
    $intVigPv = round(floatval($pv['interes_vigente'] ?? 0) + $calcPv['total'], 2);
    if ((floatval($pv['saldo_pendiente'] ?? 0) > 0 && $dPv['dias'] > $dPv['gracia']) || esMorosoPorInteres($calcPv['meses'], $intVigPv)) {
        $morosoPorCliente[$pv['cliente_id']] = true;
    }
}
foreach ($deudores as &$dRef) { $dRef['es_moroso'] = !empty($morosoPorCliente[$dRef['id']]); }
unset($dRef);

$totalCapital = 0; $totalAbonos = 0; $totalVigente = 0; $totalInteresMes = 0;
foreach ($deudores as $d) {
    $totalCapital += $d['capital_original'];
    $totalAbonos += $d['total_abonos'];
    $totalVigente += $d['capital_vigente'];
    $totalInteresMes += $d['interes_mes'];
}

$totalLiqN = 0; $totalLiqCapital = 0; $totalLiqCobrado = 0;
foreach ($liquidados as $l) {
    $totalLiqN += $l['total_liquidados'];
    $totalLiqCapital += $l['capital_prestado'];
    $totalLiqCobrado += $l['total_cobrado'];
}

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resumen_deudores_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    fputcsv($f, ['#', 'Deudor', 'Cedula', 'Prestamos', 'CapitalOriginal', 'AbonosCapital', 'CapitalVigente', 'InteresMes', 'Estatus']);
    foreach ($deudores as $i => $d) {
        fputcsv($f, [$i + 1, $d['nombre'] . ' ' . $d['apellido'], $d['cedula'], $d['total_prestamos'], $d['capital_original'], $d['total_abonos'], $d['capital_vigente'], $d['interes_mes'], !empty($d['es_moroso']) ? 'Moroso' : 'Al dia']);
    }
    fclose($f);
    exit();
}

if (($_GET['export'] ?? '') === 'csv_liquidados') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="resumen_liquidados_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    fputcsv($f, ['#', 'Deudor', 'Cedula', 'Liquidados', 'CapitalPrestado', 'TotalCobrado']);
    foreach ($liquidados as $i => $l) {
        fputcsv($f, [$i + 1, $l['nombre'] . ' ' . $l['apellido'], $l['cedula'], $l['total_liquidados'], $l['capital_prestado'], $l['total_cobrado']]);
    }
    fclose($f);
    exit();
}
} catch (Exception $e) {
    error_log("Error en resumen deudores: " . $e->getMessage());
    $deudores = [];
    $liquidados = [];
    $totalCapital = 0; $totalAbonos = 0; $totalVigente = 0; $totalInteresMes = 0;
    $totalLiqN = 0; $totalLiqCapital = 0; $totalLiqCobrado = 0;
}
?>

<div class="d-none d-print-block">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; border-bottom:3px double #1e40af; padding-bottom:10px; margin-bottom:12px;">
        <div>
            <div style="font-size:1.3rem; font-weight:700; color:#1e40af;"><?= sanitize($config['nombre_empresa'] ?? 'Sistema de Préstamos') ?></div>
            <div style="font-size:1rem; font-weight:600;">Resumen de Deudores</div>
            <?php if (!empty($config['telefono'])): ?><div style="font-size:0.78rem; color:#444;">Tel: <?= sanitize($config['telefono']) ?></div><?php endif; ?>
        </div>
        <div style="text-align:right; font-size:0.78rem; color:#444;">
            Generado el <?= date('d/m/Y H:i') ?><br><?= count($deudores) ?> deudor(es)
        </div>
    </div>
    <table style="width:100%; border-collapse:collapse; margin-bottom:12px; font-size:0.82rem;">
        <tr>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0f4ff;"><strong>Total Prestado</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalCapital, $config['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fef2f2;"><strong>Capital Vigente</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalVigente, $config['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0fdf4;"><strong>Interés / Mes</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalInteresMes, $config['moneda'] ?? 'MXN') ?></span></td>
        </tr>
    </table>
</div>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-people"></i> Resumen de Deudores</h4>
        </div>
        <div class="no-print">
            <a href="?export=csv" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel</a>
            <a href="?export=csv_liquidados" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel Liquidados</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Imprimir / PDF</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Total Prestado</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalCapital) ?></div>
            </div>
            <div>
                <div class="stat-label">Capital Vigente</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalVigente) ?></div>
            </div>
            <div>
                <div class="stat-label">Interés / Mes</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--accent);"><?= formatMoney($totalInteresMes) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($deudores)): ?>
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <h5>Sin deudores</h5>
                <p>No hay préstamos registrados aún.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>Deudor</th><th class="num"># Préstamos</th><th class="num">Capital Original</th><th class="num">Abonos Capital</th><th class="num">Capital Vigente</th><th class="num">Interés/Mes</th><th>Estatus</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deudores as $i => $d): ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><a href="estado_cuenta.php?cliente_id=<?= $d['id'] ?>"><?= sanitize($d['nombre'] . ' ' . $d['apellido']) ?></a></strong></td>
                                <td class="num"><?= $d['total_prestamos'] ?></td>
                                <td class="num"><?= formatMoney($d['capital_original']) ?></td>
                                <td class="num" style="color:var(--success);"><?= formatMoney($d['total_abonos']) ?></td>
                                <td class="num fw-bold" style="color:var(--danger);"><?= formatMoney($d['capital_vigente']) ?></td>
                                <td class="num"><?= formatMoney($d['interes_mes']) ?></td>
                                <td><?php if (!empty($d['es_moroso'])): ?><span class="badge badge-moroso">Moroso</span><?php else: ?><span class="badge badge-activo">Al día</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="2">TOTALES (<?= count($deudores) ?>)</td>
                            <td class="num"></td>
                            <td class="num"><?= formatMoney($totalCapital) ?></td>
                            <td class="num" style="color:var(--success);"><?= formatMoney($totalAbonos) ?></td>
                            <td class="num" style="color:var(--danger);"><?= formatMoney($totalVigente) ?></td>
                            <td class="num"><?= formatMoney($totalInteresMes) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-in mt-4">
    <div class="card-header"><i class="bi bi-check-circle"></i> Préstamos Liquidados</div>
    <div class="card-body p-0">
        <?php if (empty($liquidados)): ?>
            <div class="empty-state">
                <i class="bi bi-check-circle" style="color:var(--success);"></i>
                <h5>Sin liquidados</h5>
                <p>Aún no hay préstamos pagados.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>Deudor</th><th class="num"># Liquidados</th><th class="num">Capital Prestado</th><th class="num">Total Cobrado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liquidados as $i => $l): ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><a href="estado_cuenta.php?cliente_id=<?= $l['id'] ?>"><?= sanitize($l['nombre'] . ' ' . $l['apellido']) ?></a></strong></td>
                                <td class="num"><?= $l['total_liquidados'] ?></td>
                                <td class="num"><?= formatMoney($l['capital_prestado']) ?></td>
                                <td class="num fw-bold" style="color:var(--success);"><?= formatMoney($l['total_cobrado']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="2">TOTALES (<?= count($liquidados) ?> deudores · <?= $totalLiqN ?> prést.)</td>
                            <td class="num"></td>
                            <td class="num"><?= formatMoney($totalLiqCapital) ?></td>
                            <td class="num" style="color:var(--success);"><?= formatMoney($totalLiqCobrado) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="d-none d-print-block" style="margin-top:12px; border-top:1px solid #999; padding-top:6px; font-size:0.72rem; color:#555;">
    <span>Documento generado por <?= sanitize($config['nombre_empresa'] ?? 'Sistema de Préstamos') ?> el <?= date('d/m/Y H:i') ?></span>
    <span style="float:right;">Total vigente: <?= formatMoney($totalVigente, $config['moneda'] ?? 'MXN') ?></span>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
