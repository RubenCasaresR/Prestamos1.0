<?php
$pageTitle = 'Vencimientos';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);

try {
$stmt = $pdo->query("
    SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula,
           a.nombre AS acreedor_nombre
    FROM prestamos p
    INNER JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN acreedores a ON p.acreedor_id = a.id
    WHERE p.estado IN ('activo', 'moroso')
    ORDER BY p.fecha_vencimiento ASC
");
$prestamos = $stmt->fetchAll();

$contVencidos = 0; $contPorVencer = 0; $saldoVencido = 0; $interesPorVencer = 0;
$interesMes = 0;
$cuotasVencidas = []; $cuotasProximas = []; $interesesVencidos = [];
$graciaIntVenc = intval($config['dias_gracia_interes'] ?? 0);
$limiteProximas = date('Y-m-d', strtotime('+30 days'));
foreach ($prestamos as $p) {
    $capitalVigente = $p['monto_prestado'] - $p['abonos_capital'];
    $interesMes += round($capitalVigente * ($p['tasa_interes'] / 100), 2);
    $dCalc = diasAtrasoPrestamo($pdo, $p, $config);
    // Misma regla de prestamos/index.php: mora por vencimiento o 1+ meses de interés sin pagar
    $baseVenc = substr($p['fecha_ultimo_devengo'] ?? $p['fecha_prestamo'], 0, 10);
    $calcVenc = interesCausadoPorMeses(floatval($p['saldo_pendiente'] ?? 0), floatval($p['tasa_interes'] ?? 0), $baseVenc, hoyFecha(), $graciaIntVenc);
    $intVigVenc = round(floatval($p['interes_vigente'] ?? 0) + $calcVenc['total'], 2);
    $morosoVenc = ($dCalc['dias'] > $dCalc['gracia']) || esMorosoPorInteres($calcVenc['meses'], $intVigVenc);
    if ($morosoVenc) {
        $contVencidos++;
        $saldoVencido += round($capitalVigente * ($p['tasa_interes'] / 100), 2);
    } else {
        $contPorVencer++;
        $interesPorVencer += round($capitalVigente * ($p['tasa_interes'] / 100), 2);
    }
    if (!esPrestamoIndefinido($p) && $p['estado'] === 'activo') {
        $cal = generarCalendario($p, pagosPorCuota($pdo, $p['id']), $config['dias_gracia']);
        foreach ($cal['tabla'] as $c) {
            if ($c['estado'] === 'vencida') $cuotasVencidas[] = ['prestamo' => $p] + $c;
            elseif (in_array($c['estado'], ['pendiente', 'parcial'], true) && $c['fecha'] <= $limiteProximas) $cuotasProximas[] = ['prestamo' => $p] + $c;
        }
    } elseif (esPrestamoIndefinido($p) && esMorosoPorInteres($calcVenc['meses'], $intVigVenc)) {
        // Equivalente a cuotas vencidas para préstamos sin plazo: meses de interés sin pagar
        $interesesVencidos[] = ['prestamo' => $p, 'meses' => $calcVenc['meses'], 'mensual' => $calcVenc['mensual'], 'total' => $intVigVenc, 'base' => $baseVenc];
    }
}
usort($cuotasProximas, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

if (($_GET['export'] ?? '') === 'cuotas') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vencimientos_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    fputcsv($f, ['Seccion', 'Prestamo', 'Cliente', 'Cuota', 'Vence', 'Total', 'Pagado', 'Estado']);
    foreach ($cuotasVencidas as $c) fputcsv($f, ['vencida', $c['prestamo']['id'], $c['prestamo']['cliente_nombre'], $c['cuota'], $c['fecha'], $c['total'], $c['pagado'], $c['estado']]);
    foreach ($cuotasProximas as $c) fputcsv($f, ['proxima', $c['prestamo']['id'], $c['prestamo']['cliente_nombre'], $c['cuota'], $c['fecha'], $c['total'], $c['pagado'], $c['estado']]);
    foreach ($interesesVencidos as $iv) fputcsv($f, ['interes', $iv['prestamo']['id'], $iv['prestamo']['cliente_nombre'], $iv['meses'] . ' m', $iv['base'], $iv['total'], 0, 'vencida']);
    fclose($f);
    exit();
}
} catch (Exception $e) {
    $prestamos = [];
    $contVencidos = 0; $contPorVencer = 0; $saldoVencido = 0; $interesPorVencer = 0; $interesMes = 0;
    $interesesVencidos = [];
}
?>

<div class="d-none d-print-block">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; border-bottom:3px double #1e40af; padding-bottom:10px; margin-bottom:12px;">
        <div>
            <div style="font-size:1.3rem; font-weight:700; color:#1e40af;"><?= sanitize($config['nombre_empresa'] ?? 'Sistema de Préstamos') ?></div>
            <div style="font-size:1rem; font-weight:600;">Vencimientos</div>
        </div>
        <div style="text-align:right; font-size:0.78rem; color:#444;">
            Generado el <?= date('d/m/Y H:i') ?><br><?= $contVencidos ?> vencido(s) · <?= $contPorVencer ?> por vencer
        </div>
    </div>
    <table style="width:100%; border-collapse:collapse; margin-bottom:12px; font-size:0.82rem;">
        <tr>
            <td style="border:1px solid #999; padding:6px 10px; background:#fef2f2;"><strong>Vencidos</strong><br><span style="font-size:0.95rem;"><?= $contVencidos ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fffbeb;"><strong>Vigentes (no morosos)</strong><br><span style="font-size:0.95rem;"><?= $contPorVencer ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fef2f2;"><strong>Interés Vencido</strong><br><span style="font-size:0.95rem;"><?= formatMoney($saldoVencido, $config['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0fdf4;"><strong>Interés por Vencer</strong><br><span style="font-size:0.95rem;"><?= formatMoney($interesPorVencer, $config['moneda'] ?? 'MXN') ?></span></td>
        </tr>
    </table>
</div>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-signpost-2"></i> Vencimientos</h4>
        </div>
        <div class="no-print">
            <a href="?export=cuotas" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel cuotas</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Imprimir / PDF</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label" style="color:#dc2626;"><i class="bi bi-x-circle-fill"></i> Vencidos</div>
                <div class="stat-value" style="color:#dc2626;"><?= $contVencidos ?></div>
            </div>
            <div>
                <div class="stat-label" style="color:#f59e0b;"><i class="bi bi-check-circle-fill"></i> Vigentes (no morosos)</div>
                <div class="stat-value" style="color:#f59e0b;"><?= $contPorVencer ?></div>
                <div class="stat-trend neutral">QA: antes decía ≤30 días pero contaba todos</div>
            </div>
            <div>
                <div class="stat-label">Interés mensual vencidos</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($saldoVencido) ?></div>
            </div>
            <div>
                <div class="stat-label">Interés mensual vigentes</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($interesPorVencer) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-header"><i class="bi bi-exclamation-circle" style="color:var(--danger);"></i> Cuotas Vencidas · con plazo (<?= count($cuotasVencidas) ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($cuotasVencidas)): ?>
            <div class="empty-state"><i class="bi bi-check-circle" style="color:var(--success);"></i><p>Sin cuotas vencidas</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Deudor</th><th>Prést.</th><th>Cuota</th><th>Venció</th><th class="num">Total</th><th class="num">Pagado</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($cuotasVencidas as $c): ?>
                            <tr>
                                <td><strong><?= sanitize($c['prestamo']['cliente_nombre']) ?></strong></td>
                                <td><a href="../prestamos/ver.php?id=<?= $c['prestamo']['id'] ?>">#<?= $c['prestamo']['id'] ?></a></td>
                                <td>#<?= $c['cuota'] ?></td>
                                <td style="color:var(--danger); font-weight:600;"><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                                <td class="num fw-bold"><?= formatMoney($c['total']) ?></td>
                                <td class="num"><?= $c['pagado'] > 0 ? formatMoney($c['pagado']) : '-' ?></td>
                                <td><a href="../prestamos/pagar.php?id=<?= $c['prestamo']['id'] ?>" class="btn btn-sm btn-success">Pagar</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-header"><i class="bi bi-calendar-event"></i> Próximas Cuotas · con plazo (30 días) (<?= count($cuotasProximas) ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($cuotasProximas)): ?>
            <div class="empty-state"><i class="bi bi-calendar-check"></i><p>Sin cuotas próximas</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Deudor</th><th>Prést.</th><th>Cuota</th><th>Vence</th><th class="num">Total</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($cuotasProximas as $c): ?>
                            <tr>
                                <td><strong><?= sanitize($c['prestamo']['cliente_nombre']) ?></strong></td>
                                <td><a href="../prestamos/ver.php?id=<?= $c['prestamo']['id'] ?>">#<?= $c['prestamo']['id'] ?></a></td>
                                <td>#<?= $c['cuota'] ?></td>
                                <td><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                                <td class="num fw-bold"><?= formatMoney($c['total']) ?></td>
                                <td><span class="badge badge-<?= $c['estado'] === 'parcial' ? 'warning' : 'activo' ?>"><?= ucfirst($c['estado']) ?></span></td>
                                <td><a href="../prestamos/ver.php?id=<?= $c['prestamo']['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-header"><i class="bi bi-infinity" style="color:var(--danger);"></i> Intereses Vencidos · sin plazo (<?= count($interesesVencidos) ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($interesesVencidos)): ?>
            <div class="empty-state"><i class="bi bi-check-circle" style="color:var(--success);"></i><p>Sin intereses vencidos</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Deudor</th><th>Prést.</th><th class="num">Meses</th><th class="num">Interés/Mes</th><th class="num">Total Interés</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($interesesVencidos as $iv): ?>
                            <tr>
                                <td><strong><?= sanitize($iv['prestamo']['cliente_nombre']) ?></strong></td>
                                <td><a href="../prestamos/ver.php?id=<?= $iv['prestamo']['id'] ?>">#<?= $iv['prestamo']['id'] ?></a></td>
                                <td class="num fw-bold" style="color:var(--danger);"><?= $iv['meses'] ?> m</td>
                                <td class="num"><?= formatMoney($iv['mensual']) ?></td>
                                <td class="num fw-bold" style="color:var(--danger);"><?= formatMoney($iv['total']) ?></td>
                                <td><a href="../prestamos/pagar.php?id=<?= $iv['prestamo']['id'] ?>" class="btn btn-sm btn-success">Pagar</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header"><i class="bi bi-list-check"></i> Todos los Préstamos Activos</div>
    <div class="card-body p-0">
        <?php if (empty($prestamos)): ?>
            <div class="empty-state">
                <i class="bi bi-check-circle" style="color:var(--success);"></i>
                <h5>Sin préstamos activos</h5>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Deudor</th><th>Descripción</th><th class="num">Capital Vigente</th><th class="num">Tasa</th><th class="num">Interés/Mes</th><th>Inicio</th><th>Próx. Pago</th><th>Días</th><th>Estatus</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prestamos as $p): ?>
                            <?php
                            $capitalVigente = $p['monto_prestado'] - $p['abonos_capital'];
                            $interesM = round($capitalVigente * ($p['tasa_interes'] / 100), 2);
                            $dRow = diasAtrasoPrestamo($pdo, $p, $config);
                            $dias = $dRow['dias'];
                            $baseRow = substr($p['fecha_ultimo_devengo'] ?? $p['fecha_prestamo'], 0, 10);
                            $calcRow = interesCausadoPorMeses(floatval($p['saldo_pendiente'] ?? 0), floatval($p['tasa_interes'] ?? 0), $baseRow, hoyFecha(), $graciaIntVenc);
                            $intVigRow = round(floatval($p['interes_vigente'] ?? 0) + $calcRow['total'], 2);
                            $intMorRow = esMorosoPorInteres($calcRow['meses'], $intVigRow);
                            if ($dRow['dias'] > $dRow['gracia'] || $intMorRow) {
                                $color = '#dc2626'; $label = 'VENCIDO'; $badgeClass = 'badge-danger';
                                $diasTxt = ($dias > 0) ? $dias . 'd' : ($calcRow['meses'] . ' m');
                                $diasTitle = ($dias > 0) ? '' : (' title="' . $calcRow['meses'] . ' mes(es) de interés sin pagar (' . formatMoney($intVigRow) . ')"');
                            } elseif (!esPrestamoIndefinido($p) && $dias > 0) {
                                $color = '#f59e0b'; $label = 'Por vencer'; $badgeClass = 'badge-warning';
                                $diasTxt = $dias . 'd'; $diasTitle = '';
                            } elseif (esPrestamoIndefinido($p)) {
                                $color = '#16a34a'; $label = 'Indefinido'; $badgeClass = 'badge-success';
                                $diasTxt = '0d'; $diasTitle = '';
                            } else {
                                $color = '#16a34a'; $label = 'Vigente'; $badgeClass = 'badge-success';
                                $diasTxt = 'Hoy'; $diasTitle = '';
                            }
                            ?>
                            <tr>
                                <td><strong><?= sanitize($p['cliente_nombre']) ?></strong></td>
                                <td><?= sanitize($p['observaciones'] ?: 'Préstamo #' . $p['id']) ?></td>
                                <td class="num fw-bold" style="color:var(--danger);"><?= formatMoney($capitalVigente) ?></td>
                                <td class="num"><?= $p['tasa_interes'] ?>%</td>
                                <td class="num"><?= formatMoney($interesM) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($p['fecha_prestamo'])) ?></td>
                                <?php $proxRow = ($p['estado'] !== 'pagado' && !esPrestamoIndefinido($p)) ? proximoVencimiento($pdo, $p, $config['dias_gracia']) : null; ?>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= $proxRow ? ('#' . $proxRow['cuota'] . ' · ' . date('d/m/Y', strtotime($proxRow['fecha']))) : ($p['fecha_vencimiento'] ? date('d/m/Y', strtotime($p['fecha_vencimiento'])) : 'Indefinido') ?></td>
                                <td style="color:<?= $color ?>; font-weight:600;"<?= $diasTitle ?>><?= $diasTxt ?></td>
                                <td><span class="badge <?= $badgeClass ?>"<?= $diasTitle ?>><?= $label ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="d-none d-print-block" style="margin-top:12px; border-top:1px solid #999; padding-top:6px; font-size:0.72rem; color:#555;">
    <span>Documento generado por <?= sanitize($config['nombre_empresa'] ?? 'Sistema de Préstamos') ?> el <?= date('d/m/Y H:i') ?></span>
    <span style="float:right;"><?= $contVencidos ?> vencido(s) · <?= $contPorVencer ?> por vencer</span>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
