<?php
$pageTitle = 'Historial de Pagos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();
$filtroFecha = $_GET['fecha'] ?? '';
if ($filtroFecha !== '' && !fechaValida($filtroFecha)) $filtroFecha = '';
$filtroTipo = $_GET['tipo'] ?? '';
if (!in_array($filtroTipo, ['', 'cuota', 'interes', 'abono'], true)) $filtroTipo = '';

$where = [];
$params = [];
if ($filtroFecha) { $where[] = "pg.fecha_pago = ?"; $params[] = $filtroFecha; }
if ($filtroTipo) { $where[] = "COALESCE(pg.tipo_pago, 'cuota') = ?"; $params[] = $filtroTipo; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$totFilCap = 0; $totFilInt = 0; $totFilTot = 0;

try {
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pagos pg $whereSql");
$countStmt->execute($params);
$totalRegistros = $countStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT pg.*, p.monto_prestado, p.plazo_meses, p.num_cuotas, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula
    FROM pagos pg INNER JOIN prestamos p ON pg.prestamo_id = p.id INNER JOIN clientes c ON p.cliente_id = c.id
    $whereSql ORDER BY pg.fecha_pago DESC, pg.id DESC
");
$stmt->execute($params);
$pagos = $stmt->fetchAll();

// Totales del historial con los filtros aplicados (todas las páginas, no solo esta)
try {
    $totStmt = $pdo->prepare("SELECT COALESCE(SUM(capital_pagado),0), COALESCE(SUM(interes_pagado),0), COALESCE(SUM(monto_pagado),0) FROM pagos pg $whereSql");
    $totStmt->execute($params);
    $totRow = $totStmt->fetch(PDO::FETCH_NUM);
    $totFilCap = floatval($totRow[0] ?? 0);
    $totFilInt = floatval($totRow[1] ?? 0);
    $totFilTot = floatval($totRow[2] ?? 0);
} catch (Exception $e) { error_log("Error en totales pagos: " . $e->getMessage()); }

if (($_GET['export'] ?? '') === 'csv') {
    while (ob_get_level() > 0) { ob_end_clean(); } // descarta el HTML del header ya generado
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pagos_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM para que Excel muestre bien los acentos
    $f = fopen('php://output', 'w');
    fputcsv($f, ['ID', 'Prestamo', 'Cliente', 'Cedula', 'Cuota', 'Tipo', 'Capital', 'Interes', 'Mora', 'Total', 'Fecha']);
    $tiposCsv = ['cuota' => 'Cuota', 'interes' => 'Interes', 'abono' => 'Abono'];
    foreach ($pagos as $pg) {
        fputcsv($f, [$pg['id'], $pg['prestamo_id'], $pg['cliente_nombre'], $pg['cedula'], $pg['numero_cuota'], $tiposCsv[$pg['tipo_pago'] ?? 'cuota'] ?? 'Cuota', $pg['capital_pagado'], $pg['interes_pagado'], $pg['mora_pagada'], $pg['monto_pagado'], $pg['fecha_pago']]);
    }
    fclose($f);
    exit();
}
} catch (Exception $e) {
    error_log("Error en historial de pagos: " . $e->getMessage());
    $totalRegistros = 0;
    $pagos = [];
}

// Nuevo pago (modal): préstamos activos/morosos con detalle precalculado.
// El modal solo selecciona y redirige a prestamos/pagar.php (sin POST aquí).
$config = getConfiguracion($pdo);
$prestamosActivos = [];
$clientesConDeuda = [];
try {
    $actStmt = $pdo->query("
        SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula
        FROM prestamos p INNER JOIN clientes c ON p.cliente_id = c.id
        WHERE p.estado IN ('activo','moroso')
        ORDER BY cliente_nombre, p.id
    ");
    foreach ($actStmt->fetchAll() as $row) {
        $dCalc = diasAtrasoPrestamo($pdo, $row, $config);
        $baseDev = substr($row['fecha_ultimo_devengo'] ?? $row['fecha_prestamo'], 0, 10);
        $calcDev = interesCausadoPorMeses(floatval($row['saldo_pendiente'] ?? 0), floatval($row['tasa_interes'] ?? 0), $baseDev, hoyFecha(), intval($config['dias_gracia_interes'] ?? 0));
        $interesVig = round(floatval($row['interes_vigente'] ?? 0) + $calcDev['total'], 2);
        $mora = round(floatval($dCalc['mora'] ?? 0), 2);
        $saldo = round(floatval($row['saldo_pendiente'] ?? 0), 2);
        $tasaPeriodo = tasaPorPeriodo(floatval($row['tasa_interes'] ?? 0), $row['tipo_plazo'] ?? 'meses') / 100;
        $interesPeriodo = round($saldo * $tasaPeriodo, 2);
        $esIndef = esPrestamoIndefinido($row);
        $cuotaRef = $esIndef ? ($interesPeriodo > 0 ? $interesPeriodo : null) : floatval($row['cuota_mensual'] ?? 0);
        $prestamosActivos[] = [
            'id' => intval($row['id']),
            'cliente_id' => intval($row['cliente_id']),
            'cliente_nombre' => $row['cliente_nombre'],
            'cedula' => $row['cedula'],
            'saldo' => $saldo,
            'cuota_mensual' => round(floatval($row['cuota_mensual'] ?? 0), 2),
            'cuota_ref' => $cuotaRef === null ? null : round(floatval($cuotaRef), 2),
            'interes_vigente' => $interesVig,
            'mora' => $mora,
            'max' => round($saldo + $interesVig + $mora, 2),
            'vencimiento' => $row['fecha_vencimiento'] ? substr($row['fecha_vencimiento'], 0, 10) : null,
            'estado' => $row['estado'],
            'es_indefinido' => $esIndef ? 1 : 0,
        ];
        if (!isset($clientesConDeuda[intval($row['cliente_id'])])) {
            $clientesConDeuda[intval($row['cliente_id'])] = ['id' => intval($row['cliente_id']), 'nombre' => $row['cliente_nombre'], 'cedula' => $row['cedula']];
        }
    }
} catch (Exception $e) { error_log("Error en préstamos activos (modal nuevo pago): " . $e->getMessage()); }
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-credit-card"></i> Historial de Pagos</h4>
            <span class="page-subtitle"><?= $totalRegistros ?> registro(s)</span>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#nuevoPagoModal" <?= empty($prestamosActivos) ? 'disabled title="Sin préstamos pendientes"' : '' ?>><i class="bi bi-plus-lg"></i> Nuevo pago</button>
            <a href="?fecha=<?= urlencode($filtroFecha) ?>&tipo=<?= urlencode($filtroTipo) ?>&export=csv" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Excel</a>
        </div>
    </div>
</div>

<div class="search-bar animate-in">
    <i class="bi bi-calendar3"></i>
    <form method="GET">
        <input type="date" name="fecha" value="<?= sanitize($filtroFecha) ?>">
        <select name="tipo" style="background:transparent; border:none; border-bottom:1px solid var(--border-strong); color:var(--text); font-size:0.85rem; padding:6px 0; font-family:inherit;">
            <option value="">Todos los tipos</option>
            <option value="cuota" <?= $filtroTipo === 'cuota' ? 'selected' : '' ?>>Cuota</option>
            <option value="interes" <?= $filtroTipo === 'interes' ? 'selected' : '' ?>>Interés</option>
            <option value="abono" <?= $filtroTipo === 'abono' ? 'selected' : '' ?>>Abono</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <?php if ($filtroFecha || $filtroTipo): ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($pagos)): ?>
            <div class="empty-state">
                <i class="bi bi-credit-card"></i>
                <h5><?= ($filtroFecha || $filtroTipo) ? 'Sin pagos con ese filtro' : 'Sin pagos registrados' ?></h5>
                <p>Los pagos aparecerán aquí una vez registrados desde un préstamo.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="pagosTable" class="table table-hover mb-0">
                    <thead>
                        <tr><th>#</th><th>Cliente</th><th>Préstamo</th><th>Cuota</th><th>Tipo</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Total</th><th>Fecha</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagos as $i => $pg): ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td><strong><?= sanitize($pg['cliente_nombre']) ?></strong><br><small style="color:var(--text-muted);"><?= sanitize($pg['cedula']) ?></small></td>
                                <td><a href="../prestamos/ver.php?id=<?= $pg['prestamo_id'] ?>">#<?= $pg['prestamo_id'] ?></a></td>
                                <td><?= ($pg['num_cuotas'] ?? 0) ? $pg['numero_cuota'] . '/' . ($pg['num_cuotas'] ?: $pg['plazo_meses']) : $pg['numero_cuota'] . '/∞' ?></td>
                                <td><span class="badge badge-<?= ($pg['tipo_pago'] ?? 'cuota') === 'abono' ? 'success' : (($pg['tipo_pago'] ?? 'cuota') === 'interes' ? 'warning' : 'primary') ?>" style="font-size:0.65rem;"><?= ['cuota' => 'Cuota', 'interes' => 'Interés', 'abono' => 'Abono'][$pg['tipo_pago'] ?? 'cuota'] ?? 'Cuota' ?></span></td>
                                <td class="num"><?= formatMoney($pg['capital_pagado']) ?></td>
                                <td class="num"><?= formatMoney($pg['interes_pagado']) ?></td>
                                <td class="num fw-bold"><?= formatMoney($pg['monto_pagado']) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></td>
                                <td><a href="recibo.php?id=<?= $pg['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Imprimir recibo"><i class="bi bi-printer"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600; border-top:1px solid var(--border-strong);">
                            <td colspan="5">TOTALES (<?= $totalRegistros ?> mov.)</td>
                            <td class="num" style="color:var(--success);"><?= formatMoney($totFilCap) ?></td>
                            <td class="num" style="color:var(--warning, #d97706);"><?= formatMoney($totFilInt) ?></td>
                            <td class="num fw-bold" style="color:var(--accent);"><?= formatMoney($totFilTot) ?></td>
                            <td colspan="2" style="font-size:0.68rem; color:var(--text-muted); font-weight:400;">todas las páginas</td>                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderDataTable('pagosTable', [9]); ?>

<div class="modal fade" id="nuevoPagoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nuevo pago</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($prestamosActivos)): ?>
                    <div class="empty-state" style="padding:20px 0;">
                        <i class="bi bi-check-circle" style="font-size:2rem; color:var(--success);"></i>
                        <h5>Sin préstamos pendientes</h5>
                        <p style="color:var(--text-muted);">No hay préstamos activos o morosos para cobrar.</p>
                    </div>
                <?php else: ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="floating-group">
                                <select id="npCliente">
                                    <option value="">Seleccione un cliente…</option>
                                    <?php foreach ($clientesConDeuda as $cli): ?>
                                        <option value="<?= intval($cli['id']) ?>"><?= sanitize($cli['nombre']) ?> — <?= sanitize($cli['cedula']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Cliente *</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <select id="npPrestamo" disabled>
                                    <option value="">Primero elija un cliente…</option>
                                </select>
                                <label>Préstamo *</label>
                            </div>
                        </div>
                    </div>
                    <div id="npDetalle" style="font-size:0.85rem;">
                        <p style="color:var(--text-muted);">Seleccione cliente y préstamo para ver el detalle.</p>
                    </div>
                    <div class="form-text" style="font-size:0.75rem; color:var(--text-muted);"><i class="bi bi-info-circle"></i> El monto se digita en la pantalla de pago (inicia en 0.00). Aquí se muestra la cuota como referencia.</div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <?php if (!empty($prestamosActivos)): ?>
                    <a id="npIrPagar" href="#" class="btn btn-success btn-sm disabled" aria-disabled="true"><i class="bi bi-arrow-right"></i> Ir a pagar</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($prestamosActivos)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const data = <?= jsonSeguro($prestamosActivos) ?>;
    const selCli = document.getElementById('npCliente');
    const selPre = document.getElementById('npPrestamo');
    const det = document.getElementById('npDetalle');
    const btn = document.getElementById('npIrPagar');
    function fmt(n) { return 'MXN ' + Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function fmtFecha(iso) {
        if (!iso) return 'Indefinido';
        const p = String(iso).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
    }
    function badge(estado) {
        return '<span class="badge badge-' + estado + '">' + estado.charAt(0).toUpperCase() + estado.slice(1) + '</span>';
    }
    selCli.addEventListener('change', function() {
        const cid = parseInt(this.value, 10);
        selPre.innerHTML = '';
        det.innerHTML = '<p style="color:var(--text-muted);">Seleccione cliente y préstamo para ver el detalle.</p>';
        btn.classList.add('disabled');
        btn.setAttribute('aria-disabled', 'true');
        btn.href = '#';
        if (!cid) {
            selPre.disabled = true;
            selPre.innerHTML = '<option value="">Primero elija un cliente…</option>';
            return;
        }
        const lista = data.filter(function(p) { return p.cliente_id === cid; });
        selPre.disabled = false;
        selPre.innerHTML = '<option value="">Seleccione un préstamo…</option>' + lista.map(function(p) {
            return '<option value="' + p.id + '">#' + p.id + ' — ' + fmt(p.saldo) + ' saldo — ' + p.estado + '</option>';
        }).join('');
    });
    selPre.addEventListener('change', function() {
        const pid = parseInt(this.value, 10);
        const p = data.find(function(x) { return x.id === pid; });
        if (!p) {
            det.innerHTML = '<p style="color:var(--text-muted);">Seleccione cliente y préstamo para ver el detalle.</p>';
            btn.classList.add('disabled');
            btn.setAttribute('aria-disabled', 'true');
            btn.href = '#';
            return;
        }
        det.innerHTML = '<ul class="info-list">'
            + '<li><span class="info-label">Cliente</span><span class="info-value">' + p.cliente_nombre.replace(/</g, '&lt;') + ' <small style="color:var(--text-muted);">' + String(p.cedula).replace(/</g, '&lt;') + '</small></span></li>'
            + '<li><span class="info-label">Préstamo</span><span class="info-value fw-bold">#' + p.id + ' ' + badge(p.estado) + '</span></li>'
            + '<li><span class="info-label">Saldo pendiente</span><span class="info-value fw-bold">' + fmt(p.saldo) + '</span></li>'
            + '<li><span class="info-label">Cuota (ref.)</span><span class="info-value">' + (p.cuota_ref === null ? '—' : fmt(p.cuota_ref)) + '</span></li>'
            + '<li><span class="info-label">Interés vigente</span><span class="info-value">' + fmt(p.interes_vigente) + '</span></li>'
            + '<li><span class="info-label">Mora</span><span class="info-value">' + fmt(p.mora) + '</span></li>'
            + '<li><span class="info-label">Máximo a pagar</span><span class="info-value fw-bold">' + fmt(p.max) + '</span></li>'
            + '<li><span class="info-label">Vencimiento</span><span class="info-value">' + fmtFecha(p.vencimiento) + '</span></li>'
            + '</ul>';
        btn.href = '../prestamos/pagar.php?id=' + p.id;
        btn.classList.remove('disabled');
        btn.setAttribute('aria-disabled', 'false');
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
