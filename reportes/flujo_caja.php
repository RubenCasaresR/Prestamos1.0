<?php
$pageTitle = 'Flujo de Caja';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();

$fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');

if (!fechaValida($fechaInicio)) { $fechaInicio = date('Y-m-01'); }
if (!fechaValida($fechaFin)) { $fechaFin = date('Y-m-d'); }
if ($fechaInicio > $fechaFin) { $tmpFc = $fechaInicio; $fechaInicio = $fechaFin; $fechaFin = $tmpFc; }

try {
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(pg.fecha_pago, '%Y-%m-%d') AS dia,
           SUM(pg.monto_pagado) AS total_pagado, SUM(pg.capital_pagado) AS total_capital,
           SUM(pg.interes_pagado) AS total_interes, SUM(pg.mora_pagada) AS total_mora,
           COUNT(*) AS cantidad_pagos
    FROM pagos pg WHERE pg.fecha_pago BETWEEN ? AND ?
    GROUP BY dia ORDER BY dia ASC
");
$stmt->execute([$fechaInicio, $fechaFin]);
$flujoDiario = $stmt->fetchAll();

$totalGeneral = 0; $totalCapital = 0; $totalInteres = 0; $totalMora = 0; $totalPagos = 0;
foreach ($flujoDiario as $f) {
    $totalGeneral += $f['total_pagado']; $totalCapital += $f['total_capital'];
    $totalInteres += $f['total_interes']; $totalMora += $f['total_mora']; $totalPagos += $f['cantidad_pagos'];
}

$stmtNuevo = $pdo->prepare("SELECT COALESCE(SUM(monto_prestado),0) FROM prestamos WHERE fecha_prestamo BETWEEN ? AND ?");
$stmtNuevo->execute([$fechaInicio, $fechaFin]);
$totalNuevos = $stmtNuevo->fetchColumn();
} catch (Exception $e) {
    error_log("Error en reporte flujo de caja: " . $e->getMessage());
    $flujoDiario = [];
    $totalGeneral = 0; $totalCapital = 0; $totalInteres = 0; $totalMora = 0; $totalPagos = 0;
    $totalNuevos = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-wallet2"></i> Flujo de Caja</h4>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Imprimir</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card mb-4 animate-in">
    <div class="card-body">
        <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="floating-group" style="margin-bottom:0; flex:0 0 auto;">
                <input type="date" name="fecha_inicio" value="<?= $fechaInicio ?>">
                <label>Fecha Inicio</label>
            </div>
            <div class="floating-group" style="margin-bottom:0; flex:0 0 auto;">
                <input type="date" name="fecha_fin" value="<?= $fechaFin ?>">
                <label>Fecha Fin</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Filtrar</button>
        </form>
    </div>
</div>

<div class="card mb-4 animate-in">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Total Cobrado</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($totalGeneral) ?></div>
                <div class="stat-trend neutral"><?= $totalPagos ?> pago(s)</div>
            </div>
            <div>
                <div class="stat-label">Capital</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalCapital) ?></div>
            </div>
            <div>
                <div class="stat-label">Intereses</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--accent);"><?= formatMoney($totalInteres) ?></div>
            </div>
            <div style="display:none;"><!-- Mora oculta: no se usa -->
                <div class="stat-label">Mora</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--warning);"><?= formatMoney($totalMora) ?></div>
            </div>
            <div>
                <div class="stat-label">Nuevos Préstamos (salida)</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($totalNuevos) ?></div>
            </div>
            <div>
                <div class="stat-label">Flujo Neto (cobrado − prestado)</div>
                <div class="stat-value" style="font-size:1.15rem; color:<?= ($totalGeneral - $totalNuevos) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= formatMoney($totalGeneral - $totalNuevos) ?></div>
                <div class="stat-trend neutral">P1: antes la salida no se restaba</div>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header"><i class="bi bi-calendar3"></i> Detalle Diario</div>
    <div class="card-body p-0">
        <?php if (empty($flujoDiario)): ?>
            <div class="empty-state">
                <i class="bi bi-wallet2"></i>
                <h5>Sin movimientos</h5>
                <p>No se registran pagos en el período seleccionado.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Fecha</th><th>Pagos</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Mora</th><th class="num">Total Cobrado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flujoDiario as $f): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($f['dia'])) ?></td>
                                <td><?= $f['cantidad_pagos'] ?></td>
                                <td class="num"><?= formatMoney($f['total_capital']) ?></td>
                                <td class="num"><?= formatMoney($f['total_interes']) ?></td>
                                <td class="num"><?= formatMoney($f['total_mora']) ?></td>
                                <td class="num fw-bold"><?= formatMoney($f['total_pagado']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td>TOTAL</td>
                            <td><?= $totalPagos ?></td>
                            <td class="num"><?= formatMoney($totalCapital) ?></td>
                            <td class="num"><?= formatMoney($totalInteres) ?></td>
                            <td class="num"><?= formatMoney($totalMora) ?></td>
                            <td class="num"><?= formatMoney($totalGeneral) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
