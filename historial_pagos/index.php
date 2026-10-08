<?php
$pageTitle = 'Historial de Pagos';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/datatables.php';

$pdo = getConnection();
$filtroFecha = trim($_GET['fecha'] ?? '');
if ($filtroFecha !== '' && !fechaValida($filtroFecha)) $filtroFecha = '';
$filtroTipo = $_GET['tipo'] ?? '';

$where = [];
$params = [];

if ($filtroFecha) { $where[] = "DATE(fecha_pago) = ?"; $params[] = $filtroFecha; }
if ($filtroTipo === 'cobro') { $where[] = "tipo = 'Cobro'"; }
if ($filtroTipo === 'pago') { $where[] = "tipo = 'Pago'"; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $sql = "
        SELECT * FROM (
            SELECT
                pg.id,
                'Cobro' AS tipo,
                CONCAT(c.nombre, ' ', c.apellido) AS persona_nombre,
                c.cedula AS persona_doc,
                pg.monto_pagado AS monto,
                pg.capital_pagado AS capital,
                pg.interes_pagado AS interes,
                pg.mora_pagada AS mora,
                pg.fecha_pago,
                pg.fecha_registro,
                pg.observaciones,
                CONCAT('Préstamo #', pg.prestamo_id) AS referencia,
                pg.prestamo_id AS referencia_id
            FROM pagos pg
            INNER JOIN prestamos p ON pg.prestamo_id = p.id
            INNER JOIN clientes c ON p.cliente_id = c.id

            UNION ALL

            SELECT
                pd.id,
                'Pago' AS tipo,
                a.nombre AS persona_nombre,
                a.rfc AS persona_doc,
                pd.monto_pagado AS monto,
                pd.capital_pagado AS capital,
                pd.interes_pagado AS interes,
                0 AS mora,
                pd.fecha_pago,
                pd.fecha_registro,
                pd.notas AS observaciones,
                CONCAT('Deuda #', pd.deuda_id) AS referencia,
                pd.deuda_id AS referencia_id
            FROM pagos_deudas pd
            INNER JOIN mis_deudas d ON pd.deuda_id = d.id
            INNER JOIN acreedores a ON d.acreedor_id = a.id
        ) AS historial
        $whereSql
        ORDER BY fecha_pago DESC, id DESC
    ";

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ($sql) AS cnt");
    $countStmt->execute($params);
    $totalRegistros = $countStmt->fetchColumn();

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll();

    // QA: totales respetan el filtro aplicado (fecha + tipo).
    $totC = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado),0) FROM pagos" . ($filtroFecha ? " WHERE DATE(fecha_pago) = ?" : ""));
    $totC->execute($filtroFecha ? [$filtroFecha] : []);
    $totalCobrado = ($filtroTipo === '' || $filtroTipo === 'cobro') ? $totC->fetchColumn() : 0;
    $totP = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado),0) FROM pagos_deudas" . ($filtroFecha ? " WHERE DATE(fecha_pago) = ?" : ""));
    $totP->execute($filtroFecha ? [$filtroFecha] : []);
    $totalPagado = ($filtroTipo === '' || $filtroTipo === 'pago') ? $totP->fetchColumn() : 0;
} catch (Exception $e) {
    error_log("Error historial pagos: " . $e->getMessage());
    $totalRegistros = 0;
    $registros = [];
    $totalCobrado = 0;
    $totalPagado = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-journal-text"></i> Historial de Pagos</h4>
            <span class="page-subtitle"><?= $totalRegistros ?> registro(s)</span>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Total Cobrado</div>
                <div class="stat-value" style="color:var(--success);"><?= formatMoney($totalCobrado) ?></div>
            </div>
            <div>
                <div class="stat-label">Total Pagado</div>
                <div class="stat-value" style="color:var(--danger);"><?= formatMoney($totalPagado) ?></div>
            </div>
            <div>
                <div class="stat-label">Flujo Neto</div>
                <div class="stat-value" style="color:<?= ($totalCobrado - $totalPagado) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;"><?= formatMoney($totalCobrado - $totalPagado) ?></div>
            </div>
            <div>
                <div class="stat-label">Total Registros</div>
                <div class="stat-value"><?= $totalRegistros ?></div>
            </div>
        </div>
    </div>
</div>

<div class="search-bar animate-in">
    <i class="bi bi-funnel"></i>
    <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
        <select name="tipo" style="padding:6px 10px; border:1px solid var(--border-strong); border-radius:6px; font-size:0.82rem; background:var(--bg-card); color:var(--text);">
            <option value="">Todos los tipos</option>
            <option value="cobro" <?= $filtroTipo === 'cobro' ? 'selected' : '' ?>>Cobros (me pagan)</option>
            <option value="pago" <?= $filtroTipo === 'pago' ? 'selected' : '' ?>>Pagos (yo pago)</option>
        </select>
        <input type="date" name="fecha" value="<?= sanitize($filtroFecha) ?>" style="padding:6px 10px; border:1px solid var(--border-strong); border-radius:6px; font-size:0.82rem; background:var(--bg-card); color:var(--text);">
        <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
        <?php if ($filtroFecha || $filtroTipo): ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<?php renderDataTableStyles(); ?>

<div class="card animate-in">
    <div class="card-body p-0">
        <?php if (empty($registros)): ?>
            <div class="empty-state">
                <i class="bi bi-journal-text"></i>
                <h5>Sin registros</h5>
                <p>Los pagos aparecerán aquí una vez registrados desde un préstamo o mis deudas.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="historialTable" class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Tipo</th>
                            <th>Persona</th>
                            <th>Referencia</th>
                            <th class="num">Capital</th>
                            <th class="num">Interés</th>
                            <th class="num">Total</th>
                            <th>Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registros as $i => $r): ?>
                            <tr>
                                <td style="color:var(--text-muted);"><?= $i + 1 ?></td>
                                <td>
                                    <?php if ($r['tipo'] === 'Cobro'): ?>
                                        <span class="badge badge-success"><i class="bi bi-arrow-down-left"></i> Cobro</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger"><i class="bi bi-arrow-up-right"></i> Pago</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= sanitize($r['persona_nombre']) ?></strong>
                                    <?php if ($r['persona_doc']): ?>
                                        <br><small style="color:var(--text-muted);"><?= sanitize($r['persona_doc']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size:0.82rem;">
                                    <?php if ($r['tipo'] === 'Cobro'): ?>
                                        <a href="../prestamos/ver.php?id=<?= $r['referencia_id'] ?>"><?= sanitize($r['referencia']) ?></a>
                                    <?php else: ?>
                                        <a href="../mis_deudas/ver.php?id=<?= $r['referencia_id'] ?>"><?= sanitize($r['referencia']) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td class="num"><?= formatMoney($r['capital']) ?></td>
                                <td class="num"><?= formatMoney($r['interes']) ?></td>
                                <td class="num fw-bold"><?= formatMoney($r['monto']) ?></td>
                                <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($r['fecha_pago'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php renderDataTable('historialTable'); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
