<?php
$pageTitle = 'Clientes Morosos';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);

try {
// Regla combinada: mora por vencimiento o 1+ meses de interés sin pagar
// (los sin plazo no generan recargo). SQL prefiltra amplio, PHP decide.
$graciaIntRM = intval($config['dias_gracia_interes'] ?? 0);
$stmt = $pdo->query("
    SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula, c.telefono, c.direccion
    FROM prestamos p INNER JOIN clientes c ON p.cliente_id = c.id
    WHERE p.estado IN ('activo', 'moroso') AND COALESCE(p.saldo_pendiente, 0) > 0
    ORDER BY p.fecha_vencimiento ASC
");
$prestamos = [];
foreach ($stmt->fetchAll() as $row) {
    $d = diasAtrasoPrestamo($pdo, $row, $config);
    $baseRM = substr($row['fecha_ultimo_devengo'] ?? $row['fecha_prestamo'], 0, 10);
    $calcRM = interesCausadoPorMeses(floatval($row['saldo_pendiente'] ?? 0), floatval($row['tasa_interes'] ?? 0), $baseRM, hoyFecha(), $graciaIntRM);
    $intVigRM = round(floatval($row['interes_vigente'] ?? 0) + $calcRM['total'], 2);
    $porVenc = ($d['dias'] > $d['gracia']);
    $porInt = esMorosoPorInteres($calcRM['meses'], $intVigRM);
    if (!$porVenc && !$porInt) continue;
    $row['calc_dias'] = $porVenc ? $d['dias'] : 0;
    $row['calc_diasTxt'] = $porVenc ? ($d['dias'] . 'd') : ($calcRM['meses'] . ' m');
    $row['calc_diasTitle'] = $porVenc ? ('Venció ' . date('d/m/Y', strtotime($d['base'])) . ' · gracia ' . $d['gracia'] . ' días') : ($calcRM['meses'] . ' mes(es) de interés sin pagar (' . formatMoney($intVigRM) . ')');
    $row['calc_gracia'] = $d['gracia'];
    $row['calc_mora'] = $d['mora'];
    $row['calc_intVig'] = $intVigRM;
    $row['calc_base'] = $d['base'] ?? $baseRM;
    $row['calc_tipo'] = $porVenc ? $d['tipo'] : 'interes';
    $row['es_indefinido'] = esPrestamoIndefinido($row) ? 1 : 0;
    $prestamos[] = $row;
}

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="morosos_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Prestamo', 'Cliente', 'Cedula', 'Telefono', 'Tipo', 'Saldo', 'Vencimiento', 'DiasAtraso']);
    foreach ($prestamos as $p) {
        fputcsv($f, [$p['id'], $p['cliente_nombre'], $p['cedula'], $p['telefono'], !empty($p['es_indefinido']) ? 'indefinido' : 'definido', $p['saldo_pendiente'], $p['fecha_vencimiento'] ?: ('indefinido base ' . $p['calc_base']), $p['calc_diasTxt']]);
    }
    fclose($f);
    exit();
}

$totalSaldo = 0; $totalMora = 0; $totalInteres = 0;
foreach ($prestamos as $p) {
    $totalSaldo += $p['saldo_pendiente'];
    $totalMora += $p['calc_mora'];
    $totalInteres += floatval($p['calc_intVig'] ?? 0);
}
} catch (Exception $e) {
    error_log("Error en reporte morosos: " . $e->getMessage());
    $prestamos = [];
    $totalSaldo = 0;
    $totalMora = 0;
    $totalInteres = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-exclamation-triangle" style="color:var(--danger);"></i> Clientes Morosos</h4>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;"><i class="bi bi-info-circle"></i> Entra en mora con atraso mayor a la gracia (<?= intval($config['dias_gracia'] ?? 0) ?> días tras el vencimiento) o con 1+ meses de interés sin pagar. Mora = (días − gracia) × (saldo × <?= $config['tasa_mora_diaria'] ?>% diario) — los sin plazo no generan recargo.</div>
        </div>
        <div>
            <a href="../morosos/index.php" class="btn btn-primary btn-sm"><i class="bi bi-exclamation-triangle"></i> Abrir módulo</a>
            <a href="?export=csv" class="btn btn-outline-success btn-sm no-print"><i class="bi bi-download"></i> CSV</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Imprimir</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($prestamos)): ?>
            <div class="empty-state">
                <i class="bi bi-check-circle" style="color:var(--success);"></i>
                <h5>Sin clientes en mora</h5>
                <p>Todos los préstamos están al día.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>Cliente</th><th>Cédula</th><th>Teléfono</th><th class="num">Monto</th><th class="num">Saldo</th><th>Días</th><th class="num">Mora</th><th>Vence</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prestamos as $i => $p): ?>
                            <?php
                            $diasAtraso = $p['calc_dias'];
                            $mora = $p['calc_mora'];
                            ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?><?php if (!empty($p['es_indefinido'])): ?> <i class="bi bi-infinity" title="Préstamo sin plazo: moroso por interés"></i><?php endif; ?></td>
                                <td><strong><?= sanitize($p['cliente_nombre']) ?></strong></td>
                                <td><?= sanitize($p['cedula']) ?></td>
                                <td><?= sanitize($p['telefono']) ?></td>
                                <td class="num"><?= formatMoney($p['monto_prestado']) ?></td>
                                <td class="num fw-bold" style="color:var(--danger);"><?= formatMoney($p['saldo_pendiente']) ?></td>
                                <td style="color:var(--danger); font-weight:600;" title="<?= sanitize($p['calc_diasTitle']) ?>"><?= $p['calc_diasTxt'] ?></td>
                                <td class="num fw-bold" style="color:var(--danger);" title="<?= $p['calc_tipo'] === 'interes' ? 'Sin recargo: préstamo sin plazo' : ('(' . $diasAtraso . ' − ' . $p['calc_gracia'] . ') × (' . formatMoney($p['saldo_pendiente']) . ' × ' . $config['tasa_mora_diaria'] . '%)') ?>"><?= formatMoney($mora) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= $p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : ('∞ ' . date('d/m/Y', strtotime($p['calc_base']))) ?></td>
                                <td><a href="../prestamos/ver.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="5">TOTALES (<?= count($prestamos) ?>) · Saldo + Interés + Mora = <?= formatMoney($totalSaldo + $totalInteres + $totalMora) ?></td>
                            <td class="num" style="color:var(--danger);"><?= formatMoney($totalSaldo) ?></td>
                            <td></td>
                            <td class="num" style="color:var(--danger);"><?= formatMoney($totalMora) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
