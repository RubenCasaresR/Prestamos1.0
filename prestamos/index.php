<?php
$pageTitle = 'Préstamos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();
$configIdx = [];
$diasGraciaIdx = 0;
try { $configIdx = getConfiguracion($pdo); $diasGraciaIdx = intval($configIdx['dias_gracia'] ?? 0); } catch (Exception $e) { $diasGraciaIdx = 0; $configIdx = []; }
$estado = $_GET['estado'] ?? '';

try {
if ($estado === 'pagado') {
    $stmt = $pdo->prepare("
        SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula,
               a.nombre AS acreedor_nombre
        FROM prestamos p
        INNER JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN acreedores a ON p.acreedor_id = a.id
        WHERE p.estado = 'pagado'
        ORDER BY p.fecha_prestamo DESC
    ");
    $stmt->execute();
    $prestamos = $stmt->fetchAll();
} else {
    $stmt = $pdo->query("
        SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula,
               a.nombre AS acreedor_nombre
        FROM prestamos p
        INNER JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN acreedores a ON p.acreedor_id = a.id
        WHERE p.estado IN ('activo','moroso')
        ORDER BY p.fecha_prestamo DESC
    ");
    $prestamos = [];
    $cobMap = [];
    try {
        foreach ($pdo->query("SELECT prestamo_id, COUNT(*) AS n, COALESCE(SUM(monto_pagado),0) AS t FROM pagos GROUP BY prestamo_id")->fetchAll() as $cr) {
            $cobMap[$cr['prestamo_id']] = $cr;
        }
    } catch (Exception $e) { $cobMap = []; }
    $graciaIntIdx = intval($configIdx['dias_gracia_interes'] ?? 0);
    foreach ($stmt->fetchAll() as $row) {
        // Misma fórmula de ver.php: interés vigente (con gracia) + total a cobrar hoy
        $dRow = diasAtrasoPrestamo($pdo, $row, $configIdx);
        $baseDev = substr($row['fecha_ultimo_devengo'] ?? $row['fecha_prestamo'], 0, 10);
        $calcCb = interesCausadoPorMeses(floatval($row['saldo_pendiente'] ?? 0), $row['tasa_interes'] ?? 0, $baseDev, hoyFecha(), $graciaIntIdx);
        $row['calc_intVig'] = round(floatval($row['interes_vigente'] ?? 0) + $calcCb['total'], 2);
        $row['calc_mesesInt'] = $calcCb['meses'];
        // Moroso por vencimiento o por 1+ meses de interés sin pagar
        $row['calc_intMoroso'] = esMorosoPorInteres($calcCb['meses'], $row['calc_intVig']);
        $row['calc_moroso'] = (floatval($row['saldo_pendiente'] ?? 0) > 0 && $dRow['dias'] > $dRow['gracia']) || $row['calc_intMoroso'];
        $row['calc_totalHoy'] = round(floatval($row['saldo_pendiente'] ?? 0) + $row['calc_intVig'] + floatval($dRow['mora'] ?? 0), 2);
        if ($estado === 'moroso' && !$row['calc_moroso']) continue;
        if ($estado === 'activo' && $row['calc_moroso']) continue;
        $prestamos[] = $row;
    }
}
} catch (Exception $e) {
    error_log("Error en listado de préstamos: " . $e->getMessage());
    $prestamos = [];
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-cash-stack"></i> Préstamos</h4>
        </div>
        <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Préstamo</a>
    </div>
</div>

<div class="search-bar animate-in">
    <i class="bi bi-filter"></i>
    <form method="GET">
        <select name="estado" style="background:transparent; border:none; border-bottom:1px solid var(--border-strong); color:var(--text); font-size:0.85rem; padding:6px 0; font-family:inherit;">
            <option value="">Todos los estados</option>
            <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activos</option>
            <option value="moroso" <?= $estado === 'moroso' ? 'selected' : '' ?>>Morosos</option>
            <option value="pagado" <?= $estado === 'pagado' ? 'selected' : '' ?>>Pagados</option>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <?php if ($estado): ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($prestamos)): ?>
            <div class="empty-state">
                <i class="bi bi-cash-stack"></i>
                <h5><?= $estado ? 'Sin resultados' : 'Sin préstamos' ?></h5>
                <p><?= $estado ? 'No hay préstamos con ese estado.' : 'Comience creando un nuevo préstamo.' ?></p>
                <?php if (!$estado): ?>
                    <a href="crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Préstamo</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="prestamosTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cliente</th>
                            <th class="num">Monto</th>
                            <th>Fuente</th>
                            <th class="num">Saldo</th>
                            <th class="num">Interés Vig.</th>
                            <th class="num">Total a Cobrar</th>
                            <th>Próx. Pago</th>
                            <th class="num">Int. Cobrar/Mes</th>
                            <th>Interés</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prestamos as $i => $p): ?>
                            <tr data-href="ver.php?id=<?= intval($p['id']) ?>">
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= sanitize($p['cliente_nombre']) ?></strong>
                                    <br><small style="color:var(--text-muted);"><?= sanitize($p['cedula']) ?></small>
                                </td>
                                <td class="num"><?= formatMoney($p['monto_prestado']) ?><br><small style="color:var(--text-muted);"><?= $p['tasa_interes'] ?>% · <?= $p['plazo_meses'] ? $p['plazo_meses'] . ' ' . textoPlazo($p['tipo_plazo'] ?? 'meses') . ' (' . ($p['num_cuotas'] ?? '?') . ' c.)' : 'Indefinido' ?></small></td>
                                <td><span class="badge badge-<?= ($p['fuente_fondos'] ?? 'propio') === 'apalancado' ? 'warning' : 'primary' ?>" style="font-size:0.7rem;"><?= ($p['fuente_fondos'] ?? 'propio') === 'apalancado' ? 'Apa.' : 'Propio' ?></span></td>
                                <?php $cobRow = $cobMap[$p['id']] ?? ['n' => 0, 't' => 0]; ?>
                                <td class="num" style="color:<?= $p['saldo_pendiente'] > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= formatMoney($p['saldo_pendiente']) ?><br><small style="color:var(--text-muted);">cobrado <?= formatMoney($cobRow['t']) ?> (<?= $cobRow['n'] ?>)</small></td>
                                <td class="num" style="color:var(--warning, #d97706);"><?= formatMoney($p['calc_intVig'] ?? 0) ?><br><small style="color:var(--text-muted);"><?= intval($p['calc_mesesInt'] ?? 0) ?> mes(es)</small></td>
                                <td class="num fw-bold" style="color:var(--accent);"><?= formatMoney($p['calc_totalHoy'] ?? $p['saldo_pendiente']) ?></td>
                                <?php $prox = ($p['estado'] !== 'pagado') ? proximoVencimiento($pdo, $p, $diasGraciaIdx ?? 0) : null; ?>
                                <td style="font-size:0.78rem;"><?= $prox ? ('#' . $prox['cuota'] . ' · ' . date('d/m/Y', strtotime($prox['fecha']))) : (esPrestamoIndefinido($p) ? '∞' : '-') ?></td>
                                <td class="num" style="color:var(--success);"><?= formatMoney($p['utilidad_mensual'] ?? 0) ?></td>
                                <td><?php if (!empty($p['calc_intMoroso'])): ?><span class="badge badge-moroso" title="<?= intval($p['calc_mesesInt']) ?> mes(es) de interés sin pagar (<?= formatMoney($p['calc_intVig']) ?>)">Moroso</span><br><small style="color:var(--text-muted);"><?= intval($p['calc_mesesInt']) ?> mes(es)</small><?php else: ?><span class="badge badge-activo">Al día</span><?php endif; ?></td>
                                <?php $badgeEstado = ($p['estado'] === 'pagado') ? 'pagado' : (!empty($p['calc_moroso']) ? 'moroso' : 'activo'); ?>
                                <td><span class="badge badge-<?= $badgeEstado ?>"<?php if ($badgeEstado !== $p['estado']): ?> title="Estado en BD: <?= ucfirst($p['estado']) ?>"<?php endif; ?>><?= ucfirst($badgeEstado) ?></span><?php if (empty($p['plazo_meses'])): ?> <span class="badge badge-primary" style="font-size:0.65rem;" title="Sin plazo definido"><i class="bi bi-infinity"></i></span><?php endif; ?></td>
                                <td>
                                    <div class="d-flex gap-1 justify-content-end">
                                        <a href="ver.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info" title="Ver detalle"><i class="bi bi-eye"></i></a>
                                        <?php if ($p['estado'] !== 'pagado'): ?>
                                            <a href="pagar.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-success" title="Registrar pago"><i class="bi bi-credit-card"></i></a>
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

<?php renderDataTable('prestamosTable', [11]); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
