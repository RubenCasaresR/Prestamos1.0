<?php
$pageTitle = 'Préstamos Activos';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);

try {
$graciaIntPA = intval($config['dias_gracia_interes'] ?? 0);
$stmt = $pdo->query("
    SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula, c.telefono
    FROM prestamos p INNER JOIN clientes c ON p.cliente_id = c.id
    WHERE p.estado IN ('activo', 'moroso') ORDER BY p.fecha_vencimiento ASC
");
$prestamos = $stmt->fetchAll();
foreach ($prestamos as &$pPA) {
    // Misma regla de prestamos/index.php: vencimiento o 1+ meses de interés
    $dPA = diasAtrasoPrestamo($pdo, $pPA, $config);
    $basePA = substr($pPA['fecha_ultimo_devengo'] ?? $pPA['fecha_prestamo'], 0, 10);
    $calcPA = interesCausadoPorMeses(floatval($pPA['saldo_pendiente'] ?? 0), floatval($pPA['tasa_interes'] ?? 0), $basePA, hoyFecha(), $graciaIntPA);
    $intVigPA = round(floatval($pPA['interes_vigente'] ?? 0) + $calcPA['total'], 2);
    $pPA['calc_mesesInt'] = $calcPA['meses'];
    $pPA['calc_intVig'] = $intVigPA;
    $pPA['calc_intMoroso'] = esMorosoPorInteres($calcPA['meses'], $intVigPA);
    $pPA['calc_moroso'] = (floatval($pPA['saldo_pendiente'] ?? 0) > 0 && $dPA['dias'] > $dPA['gracia']) || $pPA['calc_intMoroso'];
}
unset($pPA);

$totalMonto = 0; $totalSaldo = 0;
foreach ($prestamos as $p) { $totalMonto += $p['monto_prestado']; $totalSaldo += $p['saldo_pendiente']; }
} catch (Exception $e) {
    error_log("Error en reporte préstamos activos: " . $e->getMessage());
    $prestamos = [];
    $totalMonto = 0;
    $totalSaldo = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-cash-stack"></i> Préstamos Activos</h4>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Imprimir</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($prestamos)): ?>
            <div class="empty-state">
                <i class="bi bi-cash-stack"></i>
                <h5>Sin préstamos activos</h5>
                <p>Todos los préstamos han sido pagados.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>Cliente</th><th>Cédula</th><th>Teléfono</th><th class="num">Monto</th><th class="num">Cuota</th><th class="num">Saldo</th><th>Vence</th><th>Interés</th><th>Estado</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prestamos as $i => $p): ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><?= sanitize($p['cliente_nombre']) ?></strong></td>
                                <td><?= sanitize($p['cedula']) ?></td>
                                <td><?= sanitize($p['telefono']) ?></td>
                                <td class="num"><?= formatMoney($p['monto_prestado']) ?></td>
                                <td class="num"><?= $p['cuota_mensual'] ? formatMoney($p['cuota_mensual']) : '-' ?></td>
                                <td class="num fw-bold" style="color:var(--danger);"><?= formatMoney($p['saldo_pendiente']) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= $p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : 'Indefinido' ?></td>
                                <?php $badgePA = ($p['estado'] === 'pagado') ? 'pagado' : (!empty($p['calc_moroso']) ? 'moroso' : 'activo'); ?>
                                <td><?php if (!empty($p['calc_intMoroso'])): ?><span class="badge badge-moroso" title="<?= intval($p['calc_mesesInt']) ?> mes(es) de interés sin pagar (<?= formatMoney($p['calc_intVig']) ?>)">Moroso</span><br><small style="color:var(--text-muted);"><?= intval($p['calc_mesesInt']) ?> mes(es)</small><?php else: ?><span class="badge badge-activo">Al día</span><?php endif; ?></td>
                                <td><span class="badge badge-<?= $badgePA ?>"<?php if ($badgePA !== $p['estado']): ?> title="Estado en BD: <?= ucfirst($p['estado']) ?>"<?php endif; ?>><?= ucfirst($badgePA) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="4">TOTALES (<?= count($prestamos) ?>)</td>
                            <td class="num"><?= formatMoney($totalMonto) ?></td>
                            <td></td>
                            <td class="num" style="color:var(--danger);"><?= formatMoney($totalSaldo) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
