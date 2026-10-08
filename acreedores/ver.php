<?php
$pageTitle = 'Detalle del Acreedor';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$id = intval($_GET['id'] ?? 0);

try {
$stmt = $pdo->prepare("SELECT * FROM acreedores WHERE id = ?");
$stmt->execute([$id]);
$acreedor = $stmt->fetch();

if (!$acreedor) {
    flash('danger', 'Acreedor no encontrado');
    redirect('index.php');
}

$stmt = $pdo->prepare("SELECT * FROM mis_deudas WHERE acreedor_id = ? ORDER BY fecha_inicio DESC");
$stmt->execute([$id]);
$deudas = $stmt->fetchAll();

$totalPrestado = 0;
$totalPendiente = 0;
$deudasActivas = 0;
foreach ($deudas as $d) {
    $totalPrestado += $d['monto_recibido'];
    if ($d['estado'] === 'activo') {
        $totalPendiente += $d['capital_vigente'];
        $deudasActivas++;
    }
}

} catch (Exception $e) {
    error_log("Error en acreedor ver: " . $e->getMessage());
    flash('danger', 'Error al cargar datos del acreedor');
    redirect('index.php');
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-bank"></i> <?= sanitize($acreedor['nombre']) ?></h4>
        </div>
        <div>
            <a href="editar.php?id=<?= $acreedor['id'] ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil"></i> Editar</a>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Total Recibido</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalPrestado) ?></div>
            </div>
            <div>
                <div class="stat-label">Capital Pendiente</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($totalPendiente) ?></div>
            </div>
            <div>
                <div class="stat-label">Deudas Activas</div>
                <div class="stat-value"><?= $deudasActivas ?></div>
            </div>
            <div>
                <div class="stat-label">Total Deudas</div>
                <div class="stat-value"><?= count($deudas) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-lg-5 mb-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-person-badge"></i> Datos del Acreedor</div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="info-label">Nombre</span><span class="info-value"><?= sanitize($acreedor['nombre']) ?></span></li>
                    <li><span class="info-label">Teléfono</span><span class="info-value"><?= sanitize($acreedor['telefono'] ?? '-') ?></span></li>
                    <li><span class="info-label">Email</span><span class="info-value"><?= sanitize($acreedor['email'] ?? '-') ?></span></li>
                    <li><span class="info-label">RFC</span><span class="info-value"><?= sanitize($acreedor['rfc'] ?? '-') ?></span></li>
                    <li><span class="info-label">Ciudad</span><span class="info-value"><?= sanitize($acreedor['ciudad'] ?? '-') ?></span></li>
                    <li><span class="info-label">Estado</span><span class="info-value"><span class="badge badge-<?= $acreedor['estado'] ?>"><?= ucfirst($acreedor['estado']) ?></span></span></li>
                    <li><span class="info-label">Registro</span><span class="info-value"><?= date('d/m/Y H:i', strtotime($acreedor['fecha_registro'])) ?></span></li>
                    <?php if ($acreedor['notas']): ?>
                        <li><span class="info-label">Notas</span><span class="info-value"><?= sanitize($acreedor['notas']) ?></span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7 mb-4">
        <div class="card animate-in">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="bi bi-credit-card"></i> Mis Deudas con Este Acreedor</span>
                <a href="../mis_deudas/crear.php?acreedor_id=<?= $acreedor['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> Nueva</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($deudas)): ?>
                    <div class="empty-state">
                        <i class="bi bi-credit-card"></i>
                        <h5>Sin deudas registradas</h5>
                        <p>Registre cuánto le ha pedido prestado a este acreedor.</p>
                        <a href="../mis_deudas/crear.php?acreedor_id=<?= $acreedor['id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Registrar Deuda</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr><th>Concepto</th><th class="num">Monto</th><th class="num">Capital Vte.</th><th class="num">Int./Mes</th><th>Estado</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deudas as $d): ?>
                                    <tr>
                                        <td><?= sanitize($d['concepto'] ?? '-') ?></td>
                                        <td class="num"><?= formatMoney($d['monto_recibido']) ?></td>
                                        <td class="num" style="color:var(--danger);"><?= formatMoney($d['capital_vigente']) ?></td>
                                        <td class="num"><?= formatMoney($d['interes_mensual']) ?></td>
                                        <td><span class="badge badge-<?= $d['estado'] ?>"><?= ucfirst($d['estado']) ?></span></td>
                                        <td><a href="../mis_deudas/ver.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a></td>
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
