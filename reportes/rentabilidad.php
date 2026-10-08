<?php
$pageTitle = 'Rentabilidad';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();

try {
// P1: utilidad viva = capital vigente × tasa (antes SUM(utilidad_mensual) congelada al origen, 10x tras abonos).
$stmt = $pdo->query("
    SELECT c.id, c.nombre, c.apellido,
           COALESCE(SUM(p.monto_prestado - p.abonos_capital), 0) AS capital_vigente,
           COALESCE(SUM((p.monto_prestado - p.abonos_capital) * p.tasa_interes / 100), 0) AS interes_cobrado,
           COALESCE(SUM(CASE WHEN p.fuente_fondos = 'apalancado' THEN (p.monto_prestado - p.abonos_capital) * (p.tasa_acreedor / 100) ELSE 0 END), 0) AS interes_pagado,
           COALESCE(pg.total_cobrado, 0) AS total_cobrado,
           CASE WHEN SUM(p.monto_prestado - p.abonos_capital) > 0
               THEN ROUND(SUM((p.monto_prestado - p.abonos_capital) * p.tasa_interes / 100) / SUM(p.monto_prestado - p.abonos_capital) * 100, 1)
               ELSE 0 END AS margen_pct,
           CASE WHEN SUM(p.monto_prestado - p.abonos_capital) > 0
               THEN ROUND(SUM((p.monto_prestado - p.abonos_capital) * p.tasa_interes / 100) * 12 / SUM(p.monto_prestado - p.abonos_capital) * 100, 1)
               ELSE 0 END AS rendimiento_anual
    FROM clientes c
    INNER JOIN prestamos p ON p.cliente_id = c.id
    LEFT JOIN (
        SELECT p2.cliente_id, SUM(pg.monto_pagado) AS total_cobrado
        FROM pagos pg INNER JOIN prestamos p2 ON pg.prestamo_id = p2.id
        GROUP BY p2.cliente_id
    ) pg ON pg.cliente_id = c.id
    WHERE p.estado IN ('activo','moroso')
    GROUP BY c.id, c.nombre, c.apellido
    ORDER BY interes_cobrado DESC
");
$deudores = $stmt->fetchAll();

$totalCobrado = 0; $totalPagado = 0; $totalCobradoTotal = 0;
foreach ($deudores as $d) {
    $totalCobrado += $d['interes_cobrado'];
    $totalPagado += $d['interes_pagado'];
    $totalCobradoTotal += $d['total_cobrado'];
}
} catch (Exception $e) {
    error_log("Error en rentabilidad: " . $e->getMessage());
    $deudores = [];
    $totalCobrado = 0; $totalPagado = 0; $totalCobradoTotal = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-graph-up"></i> Rentabilidad</h4>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Imprimir</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Interés a Cobrar / Mes</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--accent);"><?= formatMoney($totalCobrado) ?></div>
            </div>
            <div>
                <div class="stat-label">Interés a Pagar / Mes</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($totalPagado) ?></div>
            </div>
            <div>
                <div class="stat-label">Total Cobrado (histórico)</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($totalCobradoTotal) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($deudores)): ?>
            <div class="empty-state">
                <i class="bi bi-graph-up"></i>
                <h5>Sin datos de rentabilidad</h5>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>Deudor</th><th class="num">Capital Vigente</th><th class="num">Int. a Cobrar/Mes</th><th class="num">Int. a Pagar/Mes</th><th class="num">Total Cobrado</th><th class="num">Int./Capital %</th><th class="num">Int. Anual %</th><th>Rentabilidad</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deudores as $i => $d): ?>
                            <?php
                            if ($d['interes_cobrado'] <= 0) { $calif = 'Sin interés'; $califClass = 'badge-danger'; }
                            elseif ($d['margen_pct'] >= 10) { $calif = 'Alta'; $califClass = 'badge-success'; }
                            elseif ($d['margen_pct'] >= 5) { $calif = 'Media'; $califClass = 'badge-warning'; }
                            else { $calif = 'Baja'; $califClass = 'badge-danger'; }
                            ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><a href="estado_cuenta.php?cliente_id=<?= $d['id'] ?>"><?= sanitize($d['nombre'] . ' ' . $d['apellido']) ?></a></strong></td>
                                <td class="num"><?= formatMoney($d['capital_vigente']) ?></td>
                                <td class="num" style="color:var(--success);"><?= formatMoney($d['interes_cobrado']) ?></td>
                                <td class="num" style="color:var(--danger);"><?= formatMoney($d['interes_pagado']) ?></td>
                                <td class="num"><?= formatMoney($d['total_cobrado']) ?></td>
                                <td class="num"><?= $d['margen_pct'] ?>%</td>
                                <td class="num"><?= $d['rendimiento_anual'] ?>%</td>
                                <td><span class="badge <?= $califClass ?>"><?= $calif ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="3">TOTALES</td>
                            <td class="num" style="color:var(--success);"><?= formatMoney($totalCobrado) ?></td>
                            <td class="num" style="color:var(--danger);"><?= formatMoney($totalPagado) ?></td>
                            <td class="num"><?= formatMoney($totalCobradoTotal) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
