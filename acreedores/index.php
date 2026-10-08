<?php
$pageTitle = 'Acreedores';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();

try {
    $stmt = $pdo->query("SELECT * FROM acreedores ORDER BY fecha_registro DESC");
    $acreedores = $stmt->fetchAll();
    $deudasPorAcreedor = getDeudaPendientePorAcreedor($pdo, array_column($acreedores, 'id'));
} catch (Exception $e) {
    error_log("Error en listado de acreedores: " . $e->getMessage());
    $acreedores = [];
    $deudasPorAcreedor = [];
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-bank"></i> Acreedores</h4>
        </div>
        <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Acreedor</a>
    </div>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($acreedores)): ?>
            <div class="empty-state">
                <i class="bi bi-bank"></i>
                <h5>Sin acreedores</h5>
                <p>Registre sus acreedores (quienes le prestan dinero).</p>
                <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Acreedor</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="acreedoresTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nombre</th>
                            <th>Teléfono</th>
                            <th>RFC</th>
                            <th>Ciudad</th>
                            <th>Estado</th>
                            <th>Le Debo</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($acreedores as $i => $ac): ?>
                            <tr data-href="ver.php?id=<?= intval($ac['id']) ?>">
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><?= sanitize($ac['nombre']) ?></strong></td>
                                <td><?= sanitize($ac['telefono'] ?? '-') ?></td>
                                <td><?= sanitize($ac['rfc'] ?? '-') ?></td>
                                <td><?= sanitize($ac['ciudad'] ?? '-') ?></td>
                                <td><span class="badge badge-<?= $ac['estado'] ?>"><?= ucfirst($ac['estado']) ?></span></td>
                                <td>
                                    <?php $deuda = $deudasPorAcreedor[(int) $ac['id']] ?? null; ?>
                                    <?php if ($deuda && $deuda['total'] > 0): ?>
                                        <span class="badge badge-moroso" title="<?= $deuda['num_deudas'] ?> deuda(s) activa(s)">
                                            <?= formatMoney($deuda['total']) ?>
                                        </span>
                                        <div class="text-muted" style="font-size:0.7rem;"><?= $deuda['num_deudas'] ?> deuda(s)</div>
                                    <?php else: ?>
                                        <span class="badge badge-activo" title="Sin deudas activas"><i class="bi bi-check2"></i> Al corriente</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="ver.php?id=<?= $ac['id'] ?>" class="btn btn-sm btn-outline-info" title="Ver"><i class="bi bi-eye"></i></a>
                                        <a href="editar.php?id=<?= $ac['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                                        <a href="eliminar.php?id=<?= $ac['id'] ?>" class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="return confirmarAccion('¿Eliminar este acreedor?')"><i class="bi bi-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderDataTable('acreedoresTable', [7]); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
