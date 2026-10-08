<?php
$pageTitle = 'Detalle de Deuda';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$id = intval($_GET['id'] ?? 0);

try {
$stmt = $pdo->prepare("
    SELECT d.*, a.nombre AS acreedor_nombre, a.telefono AS acreedor_telefono, a.email AS acreedor_email
    FROM mis_deudas d
    INNER JOIN acreedores a ON d.acreedor_id = a.id
    WHERE d.id = ?
");
$stmt->execute([$id]);
$deuda = $stmt->fetch();

if (!$deuda) { flash('danger', 'Deuda no encontrada'); redirect('index.php'); }

$stmt = $pdo->prepare("SELECT * FROM pagos_deudas WHERE deuda_id = ? ORDER BY fecha_pago ASC, id ASC");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

$totalPagado = 0; $totalCapital = 0; $totalInteres = 0;
foreach ($pagos as $pg) {
    $totalPagado += $pg['monto_pagado'];
    $totalCapital += $pg['capital_pagado'];
    $totalInteres += $pg['interes_pagado'];
}

} catch (Exception $e) {
    error_log("Error en deuda ver: " . $e->getMessage());
    flash('danger', 'Error al cargar los datos');
    redirect('index.php');
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-credit-card"></i> Deuda #<?= $deuda['id'] ?></h4>
        </div>
        <div>
            <?php if ($deuda['estado'] !== 'pagado'): ?>
                <a href="pagar.php?id=<?= $deuda['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-credit-card"></i> Registrar Pago</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Monto Recibido</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($deuda['monto_recibido']) ?></div>
            </div>
            <div>
                <div class="stat-label">Capital Vigente</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($deuda['capital_vigente']) ?></div>
            </div>
            <div>
                <div class="stat-label">Total Pagado</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($totalPagado) ?></div>
            </div>
            <div>
                <div class="stat-label">Interés Mensual</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($deuda['interes_mensual']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-lg-5 mb-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-info-circle"></i> Datos de la Deuda</div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="info-label">Acreedor</span><span class="info-value"><a href="../acreedores/ver.php?id=<?= $deuda['acreedor_id'] ?>"><?= sanitize($deuda['acreedor_nombre']) ?></a></span></li>
                    <li><span class="info-label">Origen</span><span class="info-value"><?php if (!empty($deuda['prestamo_id'])): ?><a href="../prestamos/ver.php?id=<?= (int)$deuda['prestamo_id'] ?>">Financiamiento de préstamo #<?= (int)$deuda['prestamo_id'] ?></a><?php else: ?><span class="text-muted">Registro manual</span><?php endif; ?></span></li>
                    <li><span class="info-label">Concepto</span><span class="info-value"><?= sanitize($deuda['concepto'] ?? '-') ?></span></li>
                    <li><span class="info-label">Monto recibido</span><span class="info-value fw-bold"><?= formatMoney($deuda['monto_recibido']) ?></span></li>
                    <li><span class="info-label">Tasa mensual</span><span class="info-value"><?= $deuda['tasa_mensual'] ?>%</span></li>
                    <li><span class="info-label">Capital vigente</span><span class="info-value fw-bold" style="color:var(--danger);"><?= formatMoney($deuda['capital_vigente']) ?></span></li>
                    <li><span class="info-label">Interés mensual</span><span class="info-value fw-bold"><?= formatMoney($deuda['interes_mensual']) ?></span></li>
                    <li><span class="info-label">Int. pagado hasta</span><span class="info-value"><?= $deuda['interes_pagado_hasta'] ? date('d/m/Y', strtotime($deuda['interes_pagado_hasta'])) : '-' ?></span></li>
                    <li><span class="info-label">Abonos a capital</span><span class="info-value" style="color:var(--success);"><?= formatMoney($deuda['abonos_capital_pagados']) ?></span></li>
                    <li><span class="info-label">Fecha inicio</span><span class="info-value"><?= date('d/m/Y', strtotime($deuda['fecha_inicio'])) ?></span></li>
                    <?php if ($deuda['fecha_vencimiento']): ?>
                        <li><span class="info-label">Vencimiento</span><span class="info-value"><?= date('d/m/Y', strtotime($deuda['fecha_vencimiento'])) ?></span></li>
                    <?php endif; ?>
                    <li><span class="info-label">Estado</span><span class="info-value"><span class="badge badge-<?= $deuda['estado'] ?>"><?= ucfirst($deuda['estado']) ?></span></span></li>
                    <?php if ($deuda['notas']): ?>
                        <li><span class="info-label">Notas</span><span class="info-value"><?= sanitize($deuda['notas']) ?></span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7 mb-4">
        <div class="card animate-in">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="bi bi-credit-card"></i> Pagos (<?= count($pagos) ?>)</span>
                <span style="font-size:0.72rem; color:var(--text-muted); font-weight:400;">
                    Cap: <?= formatMoney($totalCapital) ?> · Int: <?= formatMoney($totalInteres) ?>
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pagos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-credit-card"></i>
                        <h5>Sin pagos registrados</h5>
                        <p>Registre pagos realizados a este acreedor.</p>
                        <?php if ($deuda['estado'] !== 'pagado'): ?>
                            <a href="pagar.php?id=<?= $id ?>" class="btn btn-success btn-sm"><i class="bi bi-credit-card"></i> Registrar Pago</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr><th>#</th><th>Fecha</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Total</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagos as $pg): ?>
                                    <tr>
                                        <td><?= $pg['id'] ?></td>
                                        <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></td>
                                        <td class="num"><?= formatMoney($pg['capital_pagado']) ?></td>
                                        <td class="num"><?= formatMoney($pg['interes_pagado']) ?></td>
                                        <td class="num fw-bold"><?= formatMoney($pg['monto_pagado']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
