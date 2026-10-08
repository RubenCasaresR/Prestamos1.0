<?php
$pageTitle = 'Mis Deudas';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();

try {
$stmt = $pdo->prepare("
    SELECT d.*, a.nombre AS acreedor_nombre
    FROM mis_deudas d
    INNER JOIN acreedores a ON d.acreedor_id = a.id
    ORDER BY d.fecha_inicio DESC
");
$stmt->execute();
$deudas = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error en listado de mis deudas: " . $e->getMessage());
    $deudas = [];
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-credit-card"></i> Mis Deudas</h4>
        </div>
        <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva Deuda</a>
    </div>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($deudas)): ?>
            <div class="empty-state">
                <i class="bi bi-credit-card"></i>
                <h5>Sin deudas registradas</h5>
                <p>Registre cuando usted se apalanca con fondos de terceros.</p>
                <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva Deuda</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="deudasTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Acreedor</th>
                            <th>Concepto</th>
                            <th class="num">Monto</th>
                            <th class="num">Tasa</th>
                            <th class="num">Capital Vte.</th>
                            <th class="num">Int./Mes</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deudas as $i => $d): ?>
                            <tr data-href="ver.php?id=<?= intval($d['id']) ?>">
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><?= sanitize($d['acreedor_nombre']) ?></strong></td>
                                <td><?= sanitize($d['concepto'] ?? '-') ?></td>
                                <td class="num"><?= formatMoney($d['monto_recibido']) ?></td>
                                <td class="num"><?= $d['tasa_mensual'] ?>%</td>
                                <td class="num" style="color:var(--danger);"><?= formatMoney($d['capital_vigente']) ?></td>
                                <td class="num"><?= formatMoney($d['interes_mensual']) ?></td>
                                <td><span class="badge badge-<?= $d['estado'] ?>"><?= ucfirst($d['estado']) ?></span></td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="ver.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-info" title="Ver detalle"><i class="bi bi-eye"></i></a>
                                        <?php if ($d['estado'] !== 'pagado'): ?>
                                            <a href="pagar.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-success" title="Registrar pago"><i class="bi bi-credit-card"></i></a>
                                        <?php endif; ?>
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

<?php renderDataTable('deudasTable', [8]); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
