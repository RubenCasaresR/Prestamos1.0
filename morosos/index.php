<?php
$pageTitle = 'Morosos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);
$tipo = $_GET['tipo'] ?? '';
if (!in_array($tipo, ['', 'definidos', 'indefinidos'], true)) $tipo = '';
$graciaIntMor = intval($config['dias_gracia_interes'] ?? 0);

try {
$stmt = $pdo->query("
    SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula, c.telefono, c.direccion
    FROM prestamos p INNER JOIN clientes c ON p.cliente_id = c.id
    WHERE p.estado IN ('activo', 'moroso') AND COALESCE(p.saldo_pendiente, 0) > 0
    ORDER BY p.fecha_prestamo DESC
");
$morosos = [];
foreach ($stmt->fetchAll() as $row) {
    // Regla combinada: mora por vencimiento o 1+ meses de interés sin pagar
    $d = diasAtrasoPrestamo($pdo, $row, $config);
    $baseDev = substr($row['fecha_ultimo_devengo'] ?? $row['fecha_prestamo'], 0, 10);
    $calcDevRow = interesCausadoPorMeses(floatval($row['saldo_pendiente']), floatval($row['tasa_interes']), $baseDev, hoyFecha(), $graciaIntMor);
    $interesVig = round(floatval($row['interes_vigente'] ?? 0) + $calcDevRow['total'], 2);
    $porVenc = ($d['dias'] > $d['gracia']);
    $porInt = esMorosoPorInteres($calcDevRow['meses'], $interesVig);
    if (!$porVenc && !$porInt) continue;
    $esInd = esPrestamoIndefinido($row);
    if ($tipo === 'definidos' && $esInd) continue;
    if ($tipo === 'indefinidos' && !$esInd) continue;
    $row['calc_meses'] = $calcDevRow['meses'];
    $row['calc_mensual'] = $calcDevRow['mensual'];
    $row['calc_dias'] = $porVenc ? $d['dias'] : 0;
    $row['calc_diasTxt'] = $porVenc ? ($d['dias'] . 'd') : ($calcDevRow['meses'] . ' m');
    $row['calc_diasTitle'] = $porVenc ? ('Venció ' . date('d/m/Y', strtotime($d['base'])) . ' · gracia ' . $d['gracia'] . ' días') : ($calcDevRow['meses'] . ' mes(es) de interés sin pagar (' . formatMoney($interesVig) . ')');
    $row['calc_gracia'] = $d['gracia'];
    $row['calc_mora'] = $d['mora'];
    $row['calc_base'] = $d['base'] ?? $baseDev;
    $row['calc_tipo'] = $porVenc ? $d['tipo'] : 'interes';
    $row['es_indefinido'] = $esInd ? 1 : 0;
    $row['calc_interes'] = $interesVig;
    $row['calc_total'] = round(floatval($row['saldo_pendiente']) + $interesVig + $d['mora'], 2);
    $morosos[] = $row;
}
usort($morosos, function($a, $b) { return $b['calc_dias'] <=> $a['calc_dias']; });

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="morosos_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Prestamo', 'Cliente', 'Cedula', 'Telefono', 'Tipo', 'Saldo', 'InteresVigente', 'Mora', 'TotalCobrar', 'DiasAtraso', 'Base']);
    foreach ($morosos as $p) {
        fputcsv($f, [$p['id'], $p['cliente_nombre'], $p['cedula'], $p['telefono'], !empty($p['es_indefinido']) ? 'indefinido' : 'definido', $p['saldo_pendiente'], $p['calc_interes'], $p['calc_mora'], $p['calc_total'], $p['calc_diasTxt'], $p['calc_base']]);
    }
    fclose($f);
    exit();
}

$totalSaldo = 0; $totalMora = 0; $totalInteres = 0; $totalCobrar = 0;
foreach ($morosos as $p) {
    $totalSaldo += $p['saldo_pendiente'];
    $totalMora += $p['calc_mora'];
    $totalInteres += $p['calc_interes'];
    $totalCobrar += $p['calc_total'];
}
} catch (Exception $e) {
    error_log("Error en módulo morosos: " . $e->getMessage());
    $morosos = [];
    $totalSaldo = 0; $totalMora = 0; $totalInteres = 0; $totalCobrar = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-exclamation-triangle" style="color:var(--danger);"></i> Morosos</h4>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;"><i class="bi bi-info-circle"></i> Entra en mora con atraso mayor a la gracia (<?= intval($config['dias_gracia'] ?? 0) ?> días tras el vencimiento) o con 1+ meses de interés sin pagar. Mora = (días − gracia) × (saldo × <?= $config['tasa_mora_diaria'] ?>% diario) — los sin plazo no generan recargo. Total a cobrar = saldo + interés vigente + mora.</div>
        </div>
        <div>
            <a href="?<?= $tipo ? 'tipo=' . $tipo . '&' : '' ?>export=csv" class="btn btn-outline-success btn-sm no-print"><i class="bi bi-download"></i> CSV</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer"></i> Imprimir</button>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">En Mora</div>
                <div class="stat-value" style="color:var(--danger);"><?= count($morosos) ?></div>
            </div>
            <div>
                <div class="stat-label">Saldo</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalSaldo) ?></div>
                <div style="font-size:0.65rem; color:var(--text-muted);">solo capital pendiente</div>
            </div>
            <div>
                <div class="stat-label">Interés Vigente
                    <i class="bi bi-info-circle" style="cursor:help; font-size:0.7rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Interés vencido: meses calendario cumplidos × interés del mes. El mes en curso aún no genera."></i>
                </div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--warning, #d97706);"><?= formatMoney($totalInteres) ?></div>
            </div>
            <div>
                <div class="stat-label">Mora
                    <i class="bi bi-info-circle" style="cursor:help; font-size:0.7rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Recargo por atraso: (días − gracia) × (saldo × tasa de mora diaria)."></i>
                </div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($totalMora) ?></div>
            </div>
            <div>
                <div class="stat-label">Total a Cobrar</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalCobrar) ?></div>
                <div style="font-size:0.65rem; color:var(--text-muted);">saldo + interés + mora</div>
            </div>
        </div>
    </div>
</div>

<div class="search-bar animate-in">
    <i class="bi bi-filter"></i>
    <form method="GET">
        <select name="tipo" style="background:transparent; border:none; border-bottom:1px solid var(--border-strong); color:var(--text); font-size:0.85rem; padding:6px 0; font-family:inherit;">
            <option value="">Todos los tipos</option>
            <option value="definidos" <?= $tipo === 'definidos' ? 'selected' : '' ?>>Definidos (con vencimiento)</option>
            <option value="indefinidos" <?= $tipo === 'indefinidos' ? 'selected' : '' ?>>Indefinidos (por interés)</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <?php if ($tipo): ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($morosos)): ?>
            <div class="empty-state">
                <i class="bi bi-check-circle" style="color:var(--success);"></i>
                <h5>Sin clientes en mora</h5>
                <p>Todos los préstamos están al día.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="morososTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cliente</th>
                            <th>Tipo</th>
                            <th class="num">Días</th>
                            <th class="num">Mora</th>
                            <th class="num">Interés</th>
                            <th class="num">Total a Cobrar</th>
                            <th>Base</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($morosos as $i => $p): ?>
                            <tr data-href="../prestamos/ver.php?id=<?= intval($p['id']) ?>">
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= sanitize($p['cliente_nombre']) ?></strong>
                                    <br><small style="color:var(--text-muted);"><?= sanitize($p['cedula']) ?><?php if ($p['telefono']): ?> · <a href="tel:<?= sanitize($p['telefono']) ?>"><?= sanitize($p['telefono']) ?></a><?php endif; ?></small>
                                </td>
                                <td><?php if (!empty($p['es_indefinido'])): ?><span class="badge badge-primary" title="Sin plazo"><i class="bi bi-infinity"></i> Indef.</span><?php else: ?><span class="badge badge-inactivo">Definido</span><?php endif; ?></td>
                                <td class="num fw-bold" style="color:var(--danger);" title="<?= sanitize($p['calc_diasTitle']) ?>"><?= $p['calc_diasTxt'] ?></td>
                                <td class="num fw-bold" style="color:var(--danger);" title="<?= $p['calc_tipo'] === 'interes' ? 'Sin recargo: préstamo sin plazo' : ('(' . $p['calc_dias'] . ' − ' . $p['calc_gracia'] . ') × (' . formatMoney($p['saldo_pendiente']) . ' × ' . $config['tasa_mora_diaria'] . '%)') ?>"><?= formatMoney($p['calc_mora']) ?></td>
                                <td class="num" title="<?= $p['calc_meses'] ?> mes(es) × <?= formatMoney($p['calc_mensual']) ?> (<?= formatMoney($p['saldo_pendiente']) ?> × <?= $p['tasa_interes'] ?>%)"><?= formatMoney($p['calc_interes']) ?></td>
                                <td class="num fw-bold" title="<?= formatMoney($p['saldo_pendiente']) ?> + <?= formatMoney($p['calc_interes']) ?> + <?= formatMoney($p['calc_mora']) ?>"><?= formatMoney($p['calc_total']) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= $p['calc_tipo'] === 'interes' ? ('∞ ' . date('d/m/Y', strtotime($p['calc_base']))) : date('d/m/Y', strtotime($p['calc_base'])) ?></td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="../prestamos/ver.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info" title="Ver detalle"><i class="bi bi-eye"></i></a>
                                        <a href="../prestamos/pagar.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success" title="Registrar pago"><i class="bi bi-credit-card"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="4">TOTALES (<?= count($morosos) ?>)</td>
                            <td class="num" style="color:var(--danger);"><?= formatMoney($totalMora) ?></td>
                            <td class="num" style="color:var(--warning, #d97706);"><?= formatMoney($totalInteres) ?></td>
                            <td class="num"><?= formatMoney($totalCobrar) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderDataTable('morososTable', [8], 3, 'desc'); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
