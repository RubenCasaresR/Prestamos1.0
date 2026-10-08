<?php
$pageTitle = 'Estado de Cuenta';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$configEC = [];
try { $configEC = getConfiguracion($pdo); } catch (Exception $e) { $configEC = []; }
$clienteSeleccionado = intval($_GET['cliente_id'] ?? 0);

try {
$clientesStmt = $pdo->query("SELECT id, nombre, apellido, cedula FROM clientes WHERE estado = 'activo' ORDER BY nombre");
$clientes = $clientesStmt->fetchAll();
} catch (Exception $e) { $clientes = []; }

$prestamos = [];
$prestamosLiq = [];
$pagosCliente = [];
$cobPorPrestamo = [];
$totalPrestado = 0; $capitalVigente = 0; $interesMes = 0; $totalCobrado = 0;
$totalIntVig = 0; $totalCobrarHoy = 0;
$totalLiqPrestado = 0; $totalLiqCobrado = 0;
$clienteInfo = null;
$graciaIntEC = intval($configEC['dias_gracia_interes'] ?? 0);

if ($clienteSeleccionado > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
        $stmt->execute([$clienteSeleccionado]);
        $clienteInfo = $stmt->fetch();

        if ($clienteInfo) {
            $stmt = $pdo->prepare("SELECT * FROM prestamos WHERE cliente_id = ? ORDER BY fecha_prestamo DESC");
            $stmt->execute([$clienteSeleccionado]);
            $todosPrestamos = $stmt->fetchAll();

            $totalPrestadoVig = 0;
            foreach ($todosPrestamos as &$pRef) {
                if (($pRef['estado'] ?? '') !== 'pagado' && floatval($pRef['saldo_pendiente'] ?? 0) > 0) $totalPrestadoVig += $pRef['monto_prestado'];
                $totalPrestado += $pRef['monto_prestado'];
                $saldoRef = floatval($pRef['saldo_pendiente'] ?? 0);
                if (($pRef['estado'] ?? '') === 'pagado' || $saldoRef <= 0) {
                    $pRef['calc_intVig'] = 0.0; $pRef['calc_mesesInt'] = 0;
                    $pRef['calc_mora'] = 0.0; $pRef['calc_totalHoy'] = 0.0;
                    $pRef['calc_moroso'] = false; $pRef['calc_intMoroso'] = false;
                    $totalLiqPrestado += $pRef['monto_prestado'];
                    continue;
                }
                // Misma fórmula de prestamos/index.php y ver.php
                $dRef = diasAtrasoPrestamo($pdo, $pRef, $configEC);
                $baseRef = substr($pRef['fecha_ultimo_devengo'] ?? $pRef['fecha_prestamo'], 0, 10);
                $calcRef = interesCausadoPorMeses($saldoRef, floatval($pRef['tasa_interes'] ?? 0), $baseRef, hoyFecha(), $graciaIntEC);
                $pRef['calc_intVig'] = round(floatval($pRef['interes_vigente'] ?? 0) + $calcRef['total'], 2);
                $pRef['calc_mesesInt'] = $calcRef['meses'];
                $pRef['calc_mora'] = round(floatval($dRef['mora'] ?? 0), 2);
                $pRef['calc_totalHoy'] = round($saldoRef + $pRef['calc_intVig'] + $pRef['calc_mora'], 2);
                $pRef['calc_intMoroso'] = esMorosoPorInteres($calcRef['meses'], $pRef['calc_intVig']);
                $pRef['calc_moroso'] = ($saldoRef > 0 && $dRef['dias'] > $dRef['gracia']) || $pRef['calc_intMoroso'];
                $capitalVigente += ($pRef['monto_prestado'] - $pRef['abonos_capital']);
                $interesMes += round(($pRef['monto_prestado'] - $pRef['abonos_capital']) * ($pRef['tasa_interes'] / 100), 2);
                $totalIntVig += $pRef['calc_intVig'];
                $totalCobrarHoy += $pRef['calc_totalHoy'];
            }
            unset($pRef);
            // Vigentes y liquidados en filas separadas
            $prestamosVig = []; $prestamosLiq = [];
            foreach ($todosPrestamos as $p) {
                if (($p['estado'] ?? '') === 'pagado') $prestamosLiq[] = $p;
                else $prestamosVig[] = $p;
            }
            $prestamos = $prestamosVig;

            $stmt = $pdo->prepare("
                SELECT pg.*, p.monto_prestado, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre
                FROM pagos pg
                INNER JOIN prestamos p ON pg.prestamo_id = p.id
                INNER JOIN clientes c ON p.cliente_id = c.id
                WHERE p.cliente_id = ?
                ORDER BY pg.fecha_pago ASC, pg.id ASC
            ");
            $stmt->execute([$clienteSeleccionado]);
            $pagosCliente = $stmt->fetchAll();
            $cobPorPrestamo = [];
            foreach ($pagosCliente as $pg) {
                $totalCobrado += $pg['monto_pagado'];
                $cobPorPrestamo[$pg['prestamo_id']] = round(($cobPorPrestamo[$pg['prestamo_id']] ?? 0) + floatval($pg['monto_pagado']), 2);
            }
            foreach ($prestamosLiq as $pl) {
                $totalLiqCobrado = round($totalLiqCobrado + floatval($cobPorPrestamo[$pl['id']] ?? 0), 2);
            }

            if (($_GET['export'] ?? '') === 'csv') {
                while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="estado_cuenta_' . $clienteSeleccionado . '_' . date('Ymd') . '.csv"');
                echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
                $f = fopen('php://output', 'w');
                fputcsv($f, ['#', 'Prestamo', 'Inicio', 'CapitalOriginal', 'Tasa', 'Abonos', 'CapitalVigente', 'InteresVigente', 'Mora', 'TotalCobrar', 'Estado']);
                foreach ($prestamos as $i => $p) {
                    fputcsv($f, [$i + 1, $p['observaciones'] ?: ('Prestamo #' . $p['id']), substr($p['fecha_prestamo'], 0, 10), $p['monto_prestado'], $p['tasa_interes'], $p['abonos_capital'], $p['monto_prestado'] - $p['abonos_capital'], $p['calc_intVig'], $p['calc_mora'], $p['calc_totalHoy'], !empty($p['calc_moroso']) ? 'Moroso' : 'Activo']);
                }
                fclose($f);
                exit();
            }

            if (($_GET['export'] ?? '') === 'csv_liquidados') {
                while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="estado_cuenta_liquidados_' . $clienteSeleccionado . '_' . date('Ymd') . '.csv"');
                echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
                $f = fopen('php://output', 'w');
                fputcsv($f, ['#', 'Prestamo', 'Inicio', 'CapitalPrestado', 'TotalCobrado']);
                foreach ($prestamosLiq as $i => $pl) {
                    fputcsv($f, [$i + 1, $pl['observaciones'] ?: ('Prestamo #' . $pl['id']), substr($pl['fecha_prestamo'], 0, 10), $pl['monto_prestado'], $cobPorPrestamo[$pl['id']] ?? 0]);
                }
                fclose($f);
                exit();
            }
        }
    } catch (Exception $e) {
        error_log("Error en estado de cuenta: " . $e->getMessage());
    }
}
?>

<?php if ($clienteSeleccionado > 0 && $clienteInfo): ?>
<div class="d-none d-print-block">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; border-bottom:3px double #1e40af; padding-bottom:10px; margin-bottom:12px;">
        <div>
            <div style="font-size:1.3rem; font-weight:700; color:#1e40af;"><?= sanitize($configEC['nombre_empresa'] ?? 'Sistema de Préstamos') ?></div>
            <div style="font-size:1rem; font-weight:600;">Estado de Cuenta — <?= sanitize($clienteInfo['nombre'] . ' ' . $clienteInfo['apellido']) ?></div>
            <div style="font-size:0.78rem; color:#444;">Cédula: <?= sanitize($clienteInfo['cedula']) ?><?php if (!empty($clienteInfo['telefono'])): ?> · Tel: <?= sanitize($clienteInfo['telefono']) ?><?php endif; ?></div>
        </div>
        <div style="text-align:right; font-size:0.78rem; color:#444;">
            Generado el <?= date('d/m/Y H:i') ?>
        </div>
    </div>
    <table style="width:100%; border-collapse:collapse; margin-bottom:12px; font-size:0.82rem;">
        <tr>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0f4ff;"><strong>Total a Cobrar Hoy</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalCobrarHoy, $configEC['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fef2f2;"><strong>Capital Vigente</strong><br><span style="font-size:0.95rem;"><?= formatMoney($capitalVigente, $configEC['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#fffbeb;"><strong>Interés Vigente</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalIntVig, $configEC['moneda'] ?? 'MXN') ?></span></td>
            <td style="border:1px solid #999; padding:6px 10px; background:#f0fdf4;"><strong>Total Cobrado</strong><br><span style="font-size:0.95rem;"><?= formatMoney($totalCobrado, $configEC['moneda'] ?? 'MXN') ?></span></td>
        </tr>
    </table>
</div>
<?php endif; ?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-search"></i> Estado de Cuenta</h4>
        </div>
        <div class="no-print">
            <?php if ($clienteSeleccionado > 0 && $clienteInfo): ?>
            <a href="?cliente_id=<?= $clienteSeleccionado ?>&export=csv" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel</a>
            <a href="?cliente_id=<?= $clienteSeleccionado ?>&export=csv_liquidados" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel Liquidados</a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer"></i> Imprimir / PDF</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card mb-4 animate-in">
    <div class="card-body">
        <form method="GET" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
            <div class="floating-group" style="margin-bottom:0; flex:1 1 300px;">
                <select name="cliente_id" required>
                    <option value="">Seleccione un deudor...</option>
                    <?php foreach ($clientes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $clienteSeleccionado == $c['id'] ? 'selected' : '' ?>><?= sanitize($c['nombre'] . ' ' . $c['apellido'] . ' - ' . $c['cedula']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Deudor</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Ver Estado</button>
        </form>
    </div>
</div>

<?php if ($clienteSeleccionado > 0 && $clienteInfo): ?>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Total Prestado</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalPrestado) ?></div>
            </div>
            <div>
                <div class="stat-label">Capital Vigente</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($capitalVigente) ?></div>
            </div>
            <div>
                <div class="stat-label">Interés / Mes</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--accent);"><?= formatMoney($interesMes) ?></div>
            </div>
            <div>
                <div class="stat-label">Total Cobrado</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($totalCobrado) ?></div>
            </div>
            <div>
                <div class="stat-label">Interés Vigente</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--warning, #d97706);"><?= formatMoney($totalIntVig) ?></div>
            </div>
            <div style="background:var(--bg); border-left:1px solid var(--border-strong);">
                <div class="stat-label" style="color:var(--accent); font-weight:700;">Total a Cobrar Hoy</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--accent);"><?= formatMoney($totalCobrarHoy) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-header"><i class="bi bi-cash-stack"></i> Préstamos Vigentes (<?= count($prestamos) ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($prestamos)): ?>
            <div class="empty-state">
                <i class="bi bi-check-circle" style="color:var(--success);"></i>
                <h5>Sin préstamos vigentes</h5>
                <p>Este deudor no tiene deuda pendiente.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Descripción</th><th>Inicio</th><th class="num">Capital Original</th><th class="num">Tasa</th><th class="num">Abonos</th><th class="num">Capital Vigente</th><th class="num">Int./Mes</th><th class="num">Interés Vig.</th><th class="num">Total a Cobrar</th><th>Fuente</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($prestamos as $p): ?>
                        <?php $badgeEC = !empty($p['calc_moroso']) ? 'moroso' : 'activo'; ?>
                        <tr>
                            <td><a href="../prestamos/ver.php?id=<?= $p['id'] ?>"><?= sanitize($p['observaciones'] ?: 'Préstamo #' . $p['id']) ?></a></td>
                            <td style="font-size:0.78rem;"><?= date('d/m/Y', strtotime($p['fecha_prestamo'])) ?></td>
                            <td class="num"><?= formatMoney($p['monto_prestado']) ?></td>
                            <td class="num"><?= $p['tasa_interes'] ?>%</td>
                            <td class="num" style="color:var(--success);"><?= formatMoney($p['abonos_capital']) ?></td>
                            <td class="num fw-bold" style="color:var(--danger);"><?= formatMoney($p['monto_prestado'] - $p['abonos_capital']) ?></td>
                            <td class="num"><?= formatMoney(round(($p['monto_prestado'] - $p['abonos_capital']) * ($p['tasa_interes'] / 100), 2)) ?></td>
                            <td class="num" style="color:var(--warning, #d97706);"><?= formatMoney($p['calc_intVig']) ?><br><small style="color:var(--text-muted);"><?= intval($p['calc_mesesInt']) ?> mes(es)</small></td>
                            <td class="num fw-bold" style="color:var(--accent);"><?= formatMoney($p['calc_totalHoy']) ?></td>
                            <td><span class="badge badge-<?= ($p['fuente_fondos'] ?? 'propio') === 'apalancado' ? 'warning' : 'primary' ?>"><?= ucfirst($p['fuente_fondos'] ?? 'Propio') ?></span></td>
                            <td><span class="badge badge-<?= $badgeEC ?>"<?php if ($badgeEC !== $p['estado']): ?> title="Estado en BD: <?= ucfirst($p['estado']) ?><?php if (!empty($p['calc_intMoroso'])): ?> · <?= intval($p['calc_mesesInt']) ?> mes(es) de interés sin pagar<?php endif; ?>"<?php endif; ?>><?= ucfirst($badgeEC) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                        <td colspan="2">TOTALES VIGENTES (<?= count($prestamos) ?>)</td>
                        <td class="num"><?= formatMoney($totalPrestadoVig ?? $totalPrestado) ?></td>
                        <td></td><td></td>
                        <td class="num" style="color:var(--danger);"><?= formatMoney($capitalVigente) ?></td>
                        <td class="num"><?= formatMoney($interesMes) ?></td>
                        <td class="num" style="color:var(--warning, #d97706);"><?= formatMoney($totalIntVig) ?></td>
                        <td class="num" style="color:var(--accent);"><?= formatMoney($totalCobrarHoy) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-header"><i class="bi bi-check-circle"></i> Préstamos Liquidados (<?= count($prestamosLiq) ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($prestamosLiq)): ?>
            <div class="empty-state">
                <i class="bi bi-credit-card"></i>
                <h5>Sin liquidados</h5>
                <p>Este deudor no tiene préstamos pagados.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>Descripción</th><th>Inicio</th><th class="num">Capital Prestado</th><th class="num">Total Cobrado</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($prestamosLiq as $pl): ?>
                        <tr style="opacity:0.75;">
                            <td><a href="../prestamos/ver.php?id=<?= $pl['id'] ?>"><?= sanitize($pl['observaciones'] ?: 'Préstamo #' . $pl['id']) ?></a></td>
                            <td style="font-size:0.78rem;"><?= date('d/m/Y', strtotime($pl['fecha_prestamo'])) ?></td>
                            <td class="num"><?= formatMoney($pl['monto_prestado']) ?></td>
                            <td class="num fw-bold" style="color:var(--success);"><?= formatMoney($cobPorPrestamo[$pl['id']] ?? 0) ?></td>
                            <td><span class="badge badge-pagado">Pagado</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                        <td colspan="2">TOTALES (<?= count($prestamosLiq) ?>)</td>
                        <td class="num"><?= formatMoney($totalLiqPrestado) ?></td>
                        <td class="num" style="color:var(--success);"><?= formatMoney($totalLiqCobrado) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header"><i class="bi bi-credit-card"></i> Historial de Pagos (<?= count($pagosCliente) ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($pagosCliente)): ?>
            <div class="empty-state"><p>Sin pagos registrados para este deudor.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th>Fecha</th><th>Tipo</th><th>Préstamo</th><th class="num">Monto</th><th class="num">Interés</th><th class="num">Mora</th><th class="num">Capital</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagosCliente as $pg): ?>
                            <tr>
                                <td style="font-size:0.78rem;"><?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></td>
                                <td><span class="badge badge-<?= ($pg['tipo_pago'] ?? 'cuota') === 'abono' ? 'success' : (($pg['tipo_pago'] ?? 'cuota') === 'interes' ? 'warning' : 'primary') ?>" style="font-size:0.62rem;"><?= ['cuota' => 'Cuota', 'interes' => 'Interés', 'abono' => 'Abono'][$pg['tipo_pago'] ?? 'cuota'] ?? 'Cuota' ?></span></td>
                                <td><a href="../prestamos/ver.php?id=<?= $pg['prestamo_id'] ?>">#<?= $pg['prestamo_id'] ?></a></td>
                                <td class="num fw-bold"><?= formatMoney($pg['monto_pagado']) ?></td>
                                <td class="num"><?= formatMoney($pg['interes_pagado']) ?></td>
                                <td class="num" style="color:var(--danger);"><?= formatMoney($pg['mora_pagada'] ?? 0) ?></td>
                                <td class="num"><?= formatMoney($pg['capital_pagado']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($clienteSeleccionado > 0 && $clienteInfo): ?>
<div class="d-none d-print-block" style="margin-top:12px; border-top:1px solid #999; padding-top:6px; font-size:0.72rem; color:#555;">
    <span>Documento generado por <?= sanitize($configEC['nombre_empresa'] ?? 'Sistema de Préstamos') ?> el <?= date('d/m/Y H:i') ?> — <?= sanitize($clienteInfo['nombre'] . ' ' . $clienteInfo['apellido']) ?></span>
    <span style="float:right;">Total a cobrar: <?= formatMoney($totalCobrarHoy, $configEC['moneda'] ?? 'MXN') ?></span>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
