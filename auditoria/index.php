<?php
$pageTitle = 'Auditoría';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';
requireRole('admin');

$pdo = getConnection();
try {
    $total = $pdo->query("SELECT COUNT(*) FROM auditoria")->fetchColumn();
    $stmt = $pdo->query("SELECT * FROM auditoria ORDER BY fecha DESC, id DESC");
    $rows = $stmt->fetchAll();
} catch (Exception $e) {
    $rows = []; $total = 0;
}
?>
<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-shield-check"></i> Auditoría (<?= $total ?>)</h4>
        </div>
    </div>
</div>
<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="empty-state"><i class="bi bi-shield-check"></i><p>Sin registros. Ejecute la migración fase3 y las acciones se registrarán automáticamente.</p></div>
        <?php else: ?>
        <div class="table-responsive">
            <table id="auditoriaTable" class="table table-hover mb-0">
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Tabla</th><th>ID</th><th>Detalle</th><th>IP</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td style="font-size:0.78rem;"><?= sanitize($r['fecha']) ?></td>
                        <td><?= sanitize($r['username'] ?? '-') ?></td>
                        <td><strong><?= sanitize($r['accion']) ?></strong></td>
                        <td><?= sanitize($r['tabla'] ?? '-') ?></td>
                        <td><?= $r['registro_id'] ? intval($r['registro_id']) : '-' ?></td>
                        <td style="font-size:0.78rem;"><?= sanitize($r['detalle'] ?? '-') ?></td>
                        <td style="font-size:0.78rem;"><?= sanitize($r['ip'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php renderDataTable('auditoriaTable', [], 0, 'desc'); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
