<?php
$pageTitle = 'Usuarios';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';
requireRole('admin');

$pdo = getConnection();
$stmt = $pdo->query("SELECT id, username, nombre, rol, estado, fecha_registro FROM usuarios ORDER BY id");
$usuarios = $stmt->fetchAll();
?>
<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-person-gear"></i> Usuarios</h4>
        </div>
        <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Usuario</a>
    </div>
</div>
<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($usuarios)): ?>
            <div class="empty-state"><i class="bi bi-people"></i><p>Sin usuarios registrados</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="usuariosTable" class="table table-hover mb-0">
                <thead><tr><th>Usuario</th><th>Nombre</th><th>Rol</th><th>Estado</th><th>Registro</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><strong><?= sanitize($u['username']) ?></strong></td>
                        <td><?= sanitize($u['nombre']) ?></td>
                        <td><span class="badge badge-<?= $u['rol'] === 'admin' ? 'primary' : 'warning' ?>"><?= sanitize($u['rol'] ?? 'admin') ?></span></td>
                        <td><span class="badge badge-<?= $u['estado'] === 'activo' ? 'activo' : 'inactivo' ?>"><?= sanitize($u['estado']) ?></span></td>
                        <td style="font-size:0.78rem;"><?= sanitize($u['fecha_registro'] ?? '-') ?></td>
                        <td><div class="d-flex gap-1 justify-content-end"><a href="editar.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a></div></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php renderDataTable('usuariosTable', [5], 0, 'asc'); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
