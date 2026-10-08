<?php
$pageTitle = 'Reportes';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);

try {
$totalPrestamosActivos = $pdo->query("SELECT COUNT(*) FROM prestamos WHERE estado = 'activo'")->fetchColumn();
$totalPrestamosMorosos = 0;
try {
    $candRep = $pdo->query("SELECT id, saldo_pendiente, estado, fecha_vencimiento, fecha_prestamo, plazo_meses, tasa_interes, interes_vigente, fecha_ultimo_devengo FROM prestamos WHERE estado IN ('activo','moroso') AND COALESCE(saldo_pendiente, 0) > 0")->fetchAll();
    foreach ($candRep as $cr) {
        if (esMorosoCombinado($pdo, $cr, $config)) $totalPrestamosMorosos++;
    }
} catch (Exception $e) { error_log("Error conteo morosos reportes: " . $e->getMessage()); }
$totalPrestamosPagados = $pdo->query("SELECT COUNT(*) FROM prestamos WHERE estado = 'pagado'")->fetchColumn();
$totalCobrado = $pdo->query("SELECT COALESCE(SUM(monto_pagado),0) FROM pagos")->fetchColumn();
$totalCapital = $pdo->query("SELECT COALESCE(SUM(capital_pagado),0) FROM pagos")->fetchColumn();
$totalIntereses = $pdo->query("SELECT COALESCE(SUM(interes_pagado),0) FROM pagos")->fetchColumn();
$totalMora = $pdo->query("SELECT COALESCE(SUM(mora_pagada),0) FROM pagos")->fetchColumn();
$totalDeudas = $pdo->query("SELECT COUNT(*) FROM mis_deudas WHERE estado = 'activo'")->fetchColumn();
$totalDebo = $pdo->query("SELECT COALESCE(SUM(capital_vigente),0) FROM mis_deudas WHERE estado = 'activo'")->fetchColumn();
} catch (Exception $e) {
    $totalPrestamosActivos = 0; $totalPrestamosMorosos = 0; $totalPrestamosPagados = 0;
    $totalCobrado = 0; $totalCapital = 0; $totalIntereses = 0; $totalMora = 0;
    $totalDeudas = 0; $totalDebo = 0;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-graph-up"></i> Reportes</h4>
        </div>
    </div>
</div>

<div class="card animate-in mb-4">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Activos</div>
                <div class="stat-value"><?= $totalPrestamosActivos ?></div>
            </div>
            <div style="display:none;"><!-- Morosos oculto: no se usa -->
                <div class="stat-label">Morosos</div>
                <div class="stat-value" style="color:var(--danger);"><?= $totalPrestamosMorosos ?></div>
            </div>
            <div>
                <div class="stat-label">Pagados</div>
                <div class="stat-value" style="color:var(--success);"><?= $totalPrestamosPagados ?></div>
            </div>
            <div>
                <div class="stat-label">Total Cobrado</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--success);"><?= formatMoney($totalCobrado) ?></div>
            </div>
            <div>
                <div class="stat-label">Total que Debo</div>
                <div class="stat-value" style="font-size:1.15rem; color:var(--danger);"><?= formatMoney($totalDebo) ?></div>
            </div>
        </div>
    </div>
</div>

<h6 class="mb-3" style="font-size:0.82rem; color:var(--text-muted);">ANÁLISIS</h6>
<div class="row mb-4">
    <div class="col-lg-4 mb-3">
        <a href="resumen_deudores.php" class="report-card animate-in">
            <i class="bi bi-people" style="color:var(--accent);"></i>
            <h5>Resumen Deudores</h5>
            <p>Cuánto debe cada deudor y su estatus</p>
        </a>
    </div>
    <div class="col-lg-4 mb-3">
        <a href="vencimientos.php" class="report-card animate-in">
            <i class="bi bi-signpost-2" style="color:#dc2626;"></i>
            <h5>Vencimientos</h5>
            <p>Semáforo de urgencia por préstamo</p>
        </a>
    </div>
    <div class="col-lg-4 mb-3">
        <a href="estado_cuenta.php" class="report-card animate-in">
            <i class="bi bi-search" style="color:var(--accent);"></i>
            <h5>Estado de Cuenta</h5>
            <p>Historial completo por deudor</p>
        </a>
    </div>
    <div class="col-lg-4 mb-3">
        <a href="rentabilidad.php" class="report-card animate-in">
            <i class="bi bi-graph-up-arrow" style="color:var(--success);"></i>
            <h5>Rentabilidad</h5>
            <p>Utilidad y margen por deudor</p>
        </a>
    </div>
    <div class="col-lg-4 mb-3">
        <a href="estado_resultados.php" class="report-card animate-in">
            <i class="bi bi-bar-chart-line" style="color:var(--accent);"></i>
            <h5>Estado de Resultados</h5>
            <p>Ingresos, egresos y remanente mensual</p>
        </a>
    </div>
</div>

<h6 class="mb-3" style="font-size:0.82rem; color:var(--text-muted);">OPERACIÓN</h6>
<div class="row">
    <div class="col-lg-4 mb-3">
        <a href="prestamos_activos.php" class="report-card animate-in">
            <i class="bi bi-cash-stack" style="color:var(--accent);"></i>
            <h5>Préstamos Activos</h5>
            <p>Lista detallada de préstamos vigentes</p>
        </a>
    </div>
    <div class="col-lg-4 mb-3" style="display:none;"><!-- Clientes Morosos oculto: no se usa -->
        <a href="morosos.php" class="report-card animate-in">
            <i class="bi bi-exclamation-triangle" style="color:var(--danger);"></i>
            <h5>Clientes Morosos</h5>
            <p>Pagos atrasados y monto adeudado</p>
        </a>
    </div>
    <div class="col-lg-4 mb-3">
        <a href="flujo_caja.php" class="report-card animate-in">
            <i class="bi bi-wallet2" style="color:var(--success);"></i>
            <h5>Flujo de Caja</h5>
            <p>Ingresos por período con desglose</p>
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
