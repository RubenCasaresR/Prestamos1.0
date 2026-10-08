<?php
$pageTitle = 'Clientes';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();

try {
    $stmt = $pdo->query("SELECT * FROM clientes ORDER BY fecha_registro DESC");
    $clientes = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error en listado de clientes: " . $e->getMessage());
    $clientes = [];
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-people"></i> Clientes</h4>
        </div>
        <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Cliente</a>
    </div>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($clientes)): ?>
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <h5>Sin clientes</h5>
                <p>Comience registrando su primer cliente.</p>
                <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Cliente</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="clientesTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nombre</th>
                            <th>Cédula</th>
                            <th>Teléfono</th>
                            <th>Estado</th>
                            <th>Registro</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes as $i => $cliente): ?>
                            <tr data-href="ver.php?id=<?= intval($cliente['id']) ?>">
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><?= sanitize($cliente['nombre'] . ' ' . $cliente['apellido']) ?></strong></td>
                                <td><?= sanitize($cliente['cedula']) ?></td>
                                <td><?= sanitize($cliente['telefono']) ?></td>
                                <td><span class="badge badge-<?= $cliente['estado'] ?>"><?= ucfirst($cliente['estado']) ?></span></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($cliente['fecha_registro'])) ?></td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="ver.php?id=<?= $cliente['id'] ?>" class="btn btn-sm btn-outline-info" title="Ver"><i class="bi bi-eye"></i></a>
                                        <a href="editar.php?id=<?= $cliente['id'] ?>" class="btn btn-sm btn-outline-warning" title="Editar"><i class="bi bi-pencil"></i></a>
                                        <a href="eliminar.php?id=<?= $cliente['id'] ?>" class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="return confirmarAccion('¿Eliminar este cliente?')"><i class="bi bi-trash"></i></a>
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

<?php renderDataTable('clientesTable', [6]); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
