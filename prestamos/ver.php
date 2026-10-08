<?php
$pageTitle = 'Detalle del Préstamo';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);
$id = intval($_GET['id'] ?? 0);

// P0: simulador de mora DESHABILITADO. Mutaba fecha_vencimiento real con
// solo requireLogin. Cualquier intento se audita y se bloquea.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['simular_atraso'] ?? '') === '1' || ($_POST['restaurar_fecha'] ?? '') === '1')) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Token de seguridad inválido');
        redirect('ver.php?id=' . $id);
    }
    requireRole('admin');
    audit($pdo, 'simulador_bloqueado', 'prestamos', $id, 'Intento de simular/restaurar vencimiento bloqueado por P0');
    flash('danger', 'Simulador deshabilitado por seguridad (P0). La fecha de vencimiento no se modifica desde esta pantalla.');
    redirect('ver.php?id=' . $id);
}

try {
$stmt = $pdo->prepare("
    SELECT p.*, CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula, c.telefono AS cliente_telefono,
           a.nombre AS acreedor_nombre
    FROM prestamos p
    INNER JOIN clientes c ON p.cliente_id = c.id
    LEFT JOIN acreedores a ON p.acreedor_id = a.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$prestamo = $stmt->fetch();

if (!$prestamo) { flash('danger', 'Préstamo no encontrado'); redirect('index.php'); }

// Deuda asumida con el acreedor al dar de alta este préstamo apalancado. Su
// capital baja solo cuando el cliente devuelve capital, así que aquí se ve
// cuánto se le debe todavía al acreedor por este préstamo.
$deudaVinculada = null; $deudaHuerfana = false;
if ($prestamo['fuente_fondos'] === 'apalancado') {
    $dv = $pdo->prepare("SELECT id, capital_vigente, monto_recibido, estado FROM mis_deudas WHERE prestamo_id = ? ORDER BY id DESC LIMIT 1");
    $dv->execute([$id]);
    $deudaVinculada = $dv->fetch() ?: null;
    // QA D1: si no hay deuda activa, los pagos de capital no sincronizan (solo error_log).
    if (!$deudaVinculada || ($deudaVinculada['estado'] ?? '') !== 'activo') $deudaHuerfana = true;
}

$stmt = $pdo->prepare("SELECT * FROM pagos WHERE prestamo_id = ? ORDER BY fecha_pago ASC, id ASC");
$stmt->execute([$id]);
$pagos = $stmt->fetchAll();

$totalPagado = 0; $totalCapital = 0; $totalIntereses = 0; $totalMora = 0;
foreach ($pagos as $pg) {
    $totalPagado += $pg['monto_pagado']; $totalCapital += $pg['capital_pagado'];
    $totalIntereses += $pg['interes_pagado']; $totalMora += $pg['mora_pagada'];
}

$esIndefinido = esPrestamoIndefinido($prestamo);
$dCalcVer = diasAtrasoPrestamo($pdo, $prestamo, $config);
$diasAtraso = $dCalcVer['dias'];
$moraActual = $dCalcVer['mora'];
$graciaAplicable = $dCalcVer['gracia'];

$capitalVigente = $prestamo['monto_prestado'] - $prestamo['abonos_capital'];
$utilidadMensual = $prestamo['utilidad_mensual'] ?? 0;
$baseDevengoVer = substr($prestamo['fecha_ultimo_devengo'] ?? $prestamo['fecha_prestamo'], 0, 10);
$calcInteresVer = interesCausadoPorMeses($prestamo['saldo_pendiente'], $prestamo['tasa_interes'], $baseDevengoVer, hoyFecha(), intval($config['dias_gracia_interes'] ?? 0));
$interesVigenteHoy = round(floatval($prestamo['interes_vigente'] ?? 0) + $calcInteresVer['total'], 2);
// Total a cobrar hoy (misma fórmula que pagar.php): saldo + interés vigente + mora.
// Si está pagado, todo queda en 0 para no mostrar cobros fantasma.
$saldoBaseVer = round(floatval($prestamo['saldo_pendiente'] ?? 0), 2);
if (($prestamo['estado'] ?? '') === 'pagado' || $saldoBaseVer <= 0) {
    $interesVigenteHoy = 0.0;
    $moraActual = 0.0;
    $diasAtraso = 0;
    $totalCobrarHoy = 0.0;
} else {
    $totalCobrarHoy = round($saldoBaseVer + $interesVigenteHoy + $moraActual, 2);
}
$calendario = generarCalendario($prestamo, pagosPorCuota($pdo, $id), $config['dias_gracia'] ?? 0);
// Calendario de cortes de interés: próximos aniversarios desde la base de
// devengo (último movimiento), con ventana de gracia y día de generación.
$calCortes = [];
$corteEnGracia = null;
if (in_array($prestamo['estado'] ?? '', ['activo', 'moroso'], true)) {
    $graciaInteresCal = intval($config['dias_gracia_interes'] ?? 0);
    $hoyCal = hoyFecha();
    try {
        $baseCal = new DateTime($baseDevengoVer);
        for ($i = 1; $i <= 6; $i++) {
            $corte = clone $baseCal;
            $corte->modify('+' . $i . ' months');
            $fechaCorte = $corte->format('Y-m-d');
            $genera = clone $corte;
            if ($graciaInteresCal > 0) $genera->modify('+' . $graciaInteresCal . ' days');
            $fechaGenera = $genera->format('Y-m-d');
            if ($hoyCal >= $fechaGenera) $estadoCorte = 'generado';
            elseif ($hoyCal >= $fechaCorte) $estadoCorte = 'gracia';
            else $estadoCorte = 'programado';
            $row = ['num' => $i, 'corte' => $fechaCorte, 'genera' => $fechaGenera, 'estado' => $estadoCorte];
            if ($estadoCorte === 'gracia' && $corteEnGracia === null) $corteEnGracia = $row;
            $calCortes[] = $row;
        }
    } catch (Exception $e) { $calCortes = []; }
}
// Datos para el calendario mensual: días de gracia expandidos y pagos por fecha.
$fcGraciaDias = [];
if (intval($config['dias_gracia_interes'] ?? 0) > 0) {
    foreach ($calCortes as $cc) {
        $d = $cc['corte'];
        $guard = 0;
        while ($d < $cc['genera'] && $guard < 400) { $fcGraciaDias[] = $d; $d = date('Y-m-d', strtotime($d . ' +1 day')); $guard++; }
    }
}
$fcPagos = [];
foreach ($pagos as $pg) {
    $f = substr($pg['fecha_pago'] ?? '', 0, 10);
    if ($f === '' || $f === false) continue;
    if (!isset($fcPagos[$f])) $fcPagos[$f] = ['n' => 0, 'monto' => 0.0, 'cap' => 0.0, 'inte' => 0.0, 'mora' => 0.0];
    $fcPagos[$f]['n']++;
    $fcPagos[$f]['monto'] = round($fcPagos[$f]['monto'] + floatval($pg['monto_pagado'] ?? 0), 2);
    $fcPagos[$f]['cap'] = round($fcPagos[$f]['cap'] + floatval($pg['capital_pagado'] ?? 0), 2);
    $fcPagos[$f]['inte'] = round($fcPagos[$f]['inte'] + floatval($pg['interes_pagado'] ?? 0), 2);
    $fcPagos[$f]['mora'] = round($fcPagos[$f]['mora'] + floatval($pg['mora_pagada'] ?? 0), 2);
}
foreach ($fcPagos as $f => $tot) {
    $fcPagos[$f]['txt'] = formatMoney($tot['monto']);
    $partesDet = [];
    if ($tot['cap'] > 0) $partesDet[] = 'Cap ' . formatMoney($tot['cap']);
    if ($tot['inte'] > 0) $partesDet[] = 'Int ' . formatMoney($tot['inte']);
    if ($tot['mora'] > 0) $partesDet[] = 'Mora ' . formatMoney($tot['mora']);
    $fcPagos[$f]['det'] = implode(' · ', $partesDet);
}
// Inicio del préstamo (desembolso, fijo): se marca en el calendario aunque
// la base de cortes se haya movido por pagos (fecha_ultimo_devengo).
$fcInicio = substr($prestamo['fecha_prestamo'] ?? '', 0, 10);
if ($fcInicio === '' || $fcInicio === false) $fcInicio = null;

} catch (Exception $e) {
    error_log("Error en préstamo ver: " . $e->getMessage());
    flash('danger', 'Error al cargar los datos del préstamo');
    redirect('index.php');
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-cash-stack"></i> Préstamo #<?= $prestamo['id'] ?><?php if ($esIndefinido): ?> <span class="badge badge-primary" style="font-size:0.65rem;"><i class="bi bi-infinity"></i> Indefinido</span><?php endif; ?></h4>
        </div>
        <div>
            <?php if ($prestamo['estado'] !== 'pagado'): ?>
                <a href="pagar.php?id=<?= $prestamo['id'] ?>" class="btn btn-success btn-sm"><i class="bi bi-credit-card"></i> Registrar Pago</a>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <div class="stat-row compact">
            <div>
                <div class="stat-label">Monto Prestado</div>
                <div class="stat-value"><?= formatMoney($prestamo['monto_prestado']) ?></div>
            </div>
            <div>
                <div class="stat-label">Capital Vigente</div>
                <div class="stat-value" style="color:var(--danger);"><?= formatMoney($capitalVigente) ?></div>
                <div style="font-size:0.65rem; color:var(--text-muted); line-height:1.2;">solo capital, sin intereses</div>
            </div>
            <div>
                <div class="stat-label">Total Pagado</div>
                <div class="stat-value" style="color:var(--success);"><?= formatMoney($totalPagado) ?></div>
                <div style="font-size:0.68rem; color:var(--text-muted); line-height:1.2;">
                    Cap <?= formatMoney($totalCapital) ?> · Int <?= formatMoney($totalIntereses) ?>
                </div>
            </div>
            <div>
                <div class="stat-label">Interés Vigente
                    <i class="bi bi-info-circle" style="cursor:help; font-size:0.7rem;" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Definidos: interés vencido (mes cumplido, mes generado). Indefinidos: 1er mes por adelantado desde el día 1 + un mes por cada mes cumplido. Cada corte incluye <?= intval($config['dias_gracia_interes'] ?? 0) ?> día(s) de gracia para pagar sin generar ese interés."></i>
                </div>
                <div class="stat-value" style="color:var(--warning, #d97706);"><?= formatMoney($interesVigenteHoy) ?></div>
                <div style="font-size:0.65rem; color:var(--text-muted); line-height:1.5;">causado no pagado al día de hoy</div>
            </div>
            <div style="background:var(--bg); border-left:1px solid var(--border-strong);">
                <div class="stat-label" style="color:var(--accent); font-weight:700;"><i class="bi bi-calculator"></i> Total a cobrar hoy</div>
                <div class="stat-value" style="font-size:1.3rem; color:var(--accent);"><?= formatMoney($totalCobrarHoy) ?></div>
                <div style="font-size:0.65rem; color:var(--text-muted); line-height:1.5;">
                    <?= formatMoney($saldoBaseVer) ?> capital + <?= formatMoney($interesVigenteHoy) ?> interés + <?= formatMoney($moraActual) ?> mora
                </div>
            </div>
        </div>
        <div class="px-3 py-2" style="font-size:0.78rem; color:var(--text-muted); border-top:1px solid var(--border); background:var(--bg); border-radius:0 0 8px 8px;">
            <i class="bi bi-calculator"></i> Total a cobrar hoy = <?= formatMoney($saldoBaseVer) ?> capital + <?= formatMoney($interesVigenteHoy) ?> interés + <?= formatMoney($moraActual) ?> mora = <strong style="color:var(--accent); font-size:0.9rem;"><?= formatMoney($totalCobrarHoy) ?></strong>
        </div>
    </div>
</div>

<div class="row mt-3 align-items-stretch">
    <?php $hayCalCortesVer = !empty($calCortes); ?>
    <div class="col-lg-<?= $hayCalCortesVer ? '3' : '6' ?> mb-3 order-lg-1">
        <div class="card animate-in dash-card">
            <div class="card-header"><i class="bi bi-info-circle"></i> Datos del Préstamo</div>
            <div class="card-body dash-scroll">
                <ul class="info-list">
                    <li><span class="info-label">Cliente</span><span class="info-value"><a href="../clientes/ver.php?id=<?= $prestamo['cliente_id'] ?>"><?= sanitize($prestamo['cliente_nombre']) ?></a></span></li>
                    <li><span class="info-label">Cédula</span><span class="info-value"><?= sanitize($prestamo['cedula']) ?></span></li>
                    <li><span class="info-label">Monto</span><span class="info-value"><?= formatMoney($prestamo['monto_prestado']) ?></span></li>
                    <li><span class="info-label">Tasa <?= textoPeriodo($prestamo['tipo_plazo'] ?? 'meses') ?></span><span class="info-value"><?= $prestamo['tasa_interes'] ?>%</span></li>
                    <li><span class="info-label">Plazo</span><span class="info-value"><?= $prestamo['plazo_meses'] ? $prestamo['plazo_meses'] . ' ' . textoPlazo($prestamo['tipo_plazo'] ?? 'meses') . ' (' . ($prestamo['num_cuotas'] ?? '?') . ' cuotas)' : 'Indefinido' ?></span></li>
                    <?php if (!$esIndefinido): ?>
                    <li><span class="info-label"><?= textoCuota($prestamo['tipo_plazo'] ?? 'meses') ?></span><span class="info-value fw-bold"><?= $prestamo['cuota_mensual'] ? formatMoney($prestamo['cuota_mensual']) : '-' ?></span></li>
                    <li><span class="info-label">Total intereses</span><span class="info-value" style="color:var(--danger);"><?= $prestamo['total_intereses'] ? formatMoney($prestamo['total_intereses']) : '-' ?></span></li>
                    <li><span class="info-label">Total a pagar</span><span class="info-value fw-bold"><?= $prestamo['total_pagar'] ? formatMoney($prestamo['total_pagar']) : '-' ?></span></li>
                    <?php endif; ?>
                    <li><span class="info-label">Fuente fondos</span><span class="info-value"><span class="badge badge-<?= $prestamo['fuente_fondos'] === 'apalancado' ? 'warning' : 'primary' ?>"><?= ucfirst($prestamo['fuente_fondos'] ?? 'Propio') ?></span></span></li>
                    <?php if ($prestamo['fuente_fondos'] === 'apalancado' && $prestamo['acreedor_nombre']): ?>
                        <li><span class="info-label">Acreedor</span><span class="info-value"><a href="../acreedores/ver.php?id=<?= $prestamo['acreedor_id'] ?>"><?= sanitize($prestamo['acreedor_nombre']) ?></a></span></li>
                        <li><span class="info-label">Tasa que cobra el acreedor</span><span class="info-value" style="color:var(--danger);"><?= $prestamo['tasa_acreedor'] ?>%</span></li>
                        <?php if ($deudaVinculada): ?>
                            <li><span class="info-label">Deuda con el acreedor</span><span class="info-value"><a href="../mis_deudas/ver.php?id=<?= (int)$deudaVinculada['id'] ?>">#<?= (int)$deudaVinculada['id'] ?></a> <span style="font-size:0.7rem; color:var(--text-muted); display:block;">capital pendiente <?= formatMoney($deudaVinculada['capital_vigente']) ?> de <?= formatMoney($deudaVinculada['monto_recibido']) ?></span><?php if (!empty($deudaHuerfana)): ?> <span class="badge badge-danger" title="Sin deuda activa: los abonos de capital no liberan crédito del acreedor">Sin deuda activa</span><?php endif; ?></span></li>
                        <?php else: ?>
                            <li><span class="info-label">Deuda con el acreedor</span><span class="info-value"><span class="badge badge-danger">No registrada — abonos no sincronizan</span></span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <li><span class="info-label">Abonos a capital</span><span class="info-value" style="color:var(--success);"><?= formatMoney($prestamo['abonos_capital'] ?? 0) ?></span></li>
                    <li><span class="info-label">Capital vigente</span><span class="info-value fw-bold" style="color:var(--danger);"><?= formatMoney($capitalVigente) ?></span> <span style="font-size:0.7rem; color:var(--text-muted); display:block;">(solo capital, sin intereses)</span></li>
                    <li><span class="info-label">Interés a cobrar / mes</span><span class="info-value fw-bold" style="color:var(--success);"><?= formatMoney($utilidadMensual) ?></span></li>
                    <li><span class="info-label">Fecha préstamo</span><span class="info-value"><?= date('d/m/Y', strtotime($prestamo['fecha_prestamo'])) ?></span></li>
                    <li><span class="info-label">Vencimiento</span><span class="info-value"><?= $prestamo['fecha_vencimiento'] ? date('d/m/Y', strtotime($prestamo['fecha_vencimiento'])) : 'Indefinido' ?></span></li>
                    <?php if (!empty($prestamo['fecha_fin'])): ?>
                        <li><span class="info-label">Fecha fin</span><span class="info-value"><?= date('d/m/Y', strtotime($prestamo['fecha_fin'])) ?></span></li>
                    <?php endif; ?>
                    <li><span class="info-label">Estado</span><span class="info-value"><span class="badge badge-<?= $prestamo['estado'] ?>"><?= ucfirst($prestamo['estado']) ?></span></span></li>
                    <?php if ($prestamo['observaciones']): ?>
                        <li><span class="info-label">Obs</span><span class="info-value"><?= sanitize($prestamo['observaciones']) ?></span></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php if (!empty($calCortes)): ?>
    <?php
    $showDesgloseInd = $esIndefinido && in_array($prestamo['estado'] ?? '', ['activo', 'moroso'], true);
    if ($showDesgloseInd) {
        $detInd = detalleInteresIndefinido($prestamo, null, intval($config['dias_gracia_interes'] ?? 0));
        $acarreadoInd = round(floatval($prestamo['interes_vigente'] ?? 0), 2);
        $diasProxCorteInd = $detInd['proximo_corte'] ? diasEntreFechas(hoyFecha(), $detInd['proximo_corte']) : 0;
    }
    ?>
    <div class="col-lg-6 mb-3 order-lg-2">
        <div class="card animate-in dash-card cal-dash">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="bi bi-calendar-heart"></i> Interés y Gracia</span>
                <span style="font-size:0.7rem; color:var(--text-muted); font-weight:400;">gracia <?= intval($config['dias_gracia_interes'] ?? 0) ?> día(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if ($showDesgloseInd): ?>
                <div class="px-3 pt-3">
                    <div class="mb-2" style="font-size:0.78rem; color:var(--text-muted); line-height:1.6;">
                        <i class="bi bi-info-circle"></i> Cada mes cumplido genera <strong><?= $prestamo['tasa_interes'] ?>%</strong> sobre el saldo (<?= formatMoney($prestamo['saldo_pendiente']) ?>).
                        El mes en curso aún no genera interés.
                    </div>
                    <div class="stat-row compact" style="border:1px solid var(--border); border-radius:8px; overflow:hidden;">
                        <div>
                            <div class="stat-label">Interés del mes</div>
                            <div class="stat-value" style="color:var(--accent);"><?= formatMoney($detInd['interes_mensual']) ?></div>
                            <div style="font-size:0.65rem; color:var(--text-muted);"><?= $prestamo['tasa_interes'] ?>% × <?= formatMoney($prestamo['saldo_pendiente']) ?></div>
                        </div>
                        <div>
                            <div class="stat-label">Meses cumplidos</div>
                            <div class="stat-value"><?= $detInd['meses_completos'] ?></div>
                            <div style="font-size:0.65rem; color:var(--text-muted);">= <?= formatMoney($detInd['interes_meses']) ?></div>
                        </div>
                        <div style="background:var(--bg);">
                            <div class="stat-label" style="color:var(--warning, #d97706); font-weight:700;">Causado a hoy</div>
                            <div class="stat-value" style="color:var(--warning, #d97706);"><?= formatMoney($detInd['interes_total']) ?></div>
                            <div style="font-size:0.65rem; color:var(--text-muted);"><?= formatMoney($acarreadoInd) ?> acarreado + <?= formatMoney($detInd['interes_meses']) ?> meses</div>
                        </div>
                    </div>
                    <?php if ($corteEnGracia !== null): ?>
                        <div class="alert alert-success py-2 px-3 mt-2 mb-0" style="font-size:0.78rem;">
                            <i class="bi bi-clock-history"></i> <strong>En gracia:</strong>
                            faltan <?= $diasProxCorteInd ?> día(s) — paga antes del <?= date('d/m/Y', strtotime($corteEnGracia['genera'])) ?> y evitas <?= formatMoney($detInd['interes_mensual']) ?>.
                        </div>
                    <?php elseif ($detInd['proximo_corte']): ?>
                        <div class="alert alert-info py-2 px-3 mt-2 mb-0" style="font-size:0.78rem;">
                            <i class="bi bi-calendar-event"></i> <strong>Próximo corte:</strong>
                            <?= date('d/m/Y', strtotime($detInd['proximo_corte'])) ?> (en <?= $diasProxCorteInd ?> día(s)) — generará <?= formatMoney($detInd['interes_mensual']) ?>.
                        </div>
                    <?php endif; ?>
                </div>
                <?php elseif ($corteEnGracia !== null): ?>
                    <div class="alert alert-success py-2 px-3 m-2 mb-0" style="font-size:0.75rem;">
                        <i class="bi bi-clock-history"></i> <strong>Gracia activa:</strong>
                        paga antes del <?= date('d/m/Y', strtotime($corteEnGracia['genera'])) ?> y evitas <?= formatMoney($calcInteresVer['mensual']) ?>.
                    </div>
                <?php endif; ?>
                <div class="cal-nav">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="calPrev"><i class="bi bi-chevron-left"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="calToday">Hoy</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="calNext"><i class="bi bi-chevron-right"></i></button>
                    <?php if ($fcInicio): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="calInicio" title="Ir al mes del desembolso (<?= date('d/m/Y', strtotime($fcInicio)) ?>)"><i class="bi bi-flag"></i> Inicio</button>
                    <?php endif; ?>
                    <strong id="calTitle" style="margin-left:8px; text-transform:capitalize; font-size:0.85rem;"></strong>
                </div>
                <div class="cal-grid cal-weekdays">
                    <span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>
                </div>
                <div class="cal-grid dash-scroll-sm" id="calGrid"></div>
                <div class="px-3 py-2 cal-legend" style="font-size:0.72rem; color:var(--text-muted); border-top:1px solid var(--border);">
                    <span><i class="cal-dot dot-inicio"></i>Inicio</span>
                    <span><i class="cal-dot dot-corte"></i>Corte (pagar)</span>
                    <span><i class="cal-dot dot-gracia"></i>Gracia (sin interés)</span>
                    <span><i class="cal-dot dot-genera"></i>Genera interés</span>
                    <span><i class="cal-dot dot-pago"></i>Pago registrado</span>
                    <span style="margin-left:auto;">Click en un día para ver el detalle</span>
                </div>
                <?php if ($showDesgloseInd): ?>
                <div class="px-3 pb-3">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#cortesCollapse" aria-expanded="false" aria-controls="cortesCollapse">
                        <i class="bi bi-table"></i> Ver detalle de cortes (<?= count($calCortes) ?>)
                    </button>
                    <div class="collapse mt-2" id="cortesCollapse">
                        <div class="table-responsive" style="border:1px solid var(--border); border-radius:8px;">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr><th>#</th><th>Corte</th><th>Genera el</th><th class="num">Interés</th><th>Estado</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($calCortes as $cc): ?>
                                        <tr class="<?= $cc['estado'] === 'gracia' ? 'row-muted' : '' ?>" style="<?= $cc['estado'] === 'generado' ? 'opacity:0.65;' : '' ?>">
                                            <td><strong><?= $cc['num'] ?></strong></td>
                                            <td style="font-size:0.78rem;"><?= date('d/m/Y', strtotime($cc['corte'])) ?></td>
                                            <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($cc['genera'])) ?></td>
                                            <td class="num"><?= formatMoney($detInd['interes_mensual']) ?></td>
                                            <td><span class="badge badge-<?= $cc['estado'] === 'generado' ? 'pagado' : ($cc['estado'] === 'gracia' ? 'warning' : 'inactivo') ?>"><?= $cc['estado'] === 'generado' ? 'Generado' : ($cc['estado'] === 'gracia' ? 'En gracia' : 'Programado') ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function() {
            var grid = document.getElementById('calGrid');
            if (!grid) return;
            var cortes = <?= jsonSeguro(array_map(function($c) { return ['num' => $c['num'], 'corte' => $c['corte'], 'genera' => $c['genera']]; }, $calCortes)) ?>;
            var graciaSet = new Set(<?= jsonSeguro(array_values(array_unique($fcGraciaDias))) ?>);
            var pagosByDate = <?= jsonSeguro($fcPagos) ?>;
            var interesMesTxt = <?= jsonSeguro(formatMoney($calcInteresVer['mensual'])) ?>;
            var fechaInicio = <?= jsonSeguro($fcInicio) ?>;
            var fechaInicioTxt = <?= jsonSeguro($fcInicio ? date('d/m/Y', strtotime($fcInicio)) : '') ?>;
            var clienteNombre = <?= jsonSeguro($prestamo['cliente_nombre'] ?? '') ?>;
            var infoPrestamo = {
                monto: <?= jsonSeguro(formatMoney($prestamo['monto_prestado'])) ?>,
                tasa: <?= jsonSeguro(($prestamo['tasa_interes'] ?? 0) . '% ' . textoPeriodo($prestamo['tipo_plazo'] ?? 'meses')) ?>,
                saldo: <?= jsonSeguro(formatMoney($prestamo['saldo_pendiente'])) ?>,
                capitalVigente: <?= jsonSeguro(formatMoney($capitalVigente)) ?>,
                totalCobrar: <?= jsonSeguro(formatMoney($totalCobrarHoy)) ?>,
                estado: <?= jsonSeguro(ucfirst($prestamo['estado'] ?? '')) ?>,
                vencimiento: <?= jsonSeguro($prestamo['fecha_vencimiento'] ? date('d/m/Y', strtotime($prestamo['fecha_vencimiento'])) : 'Indefinido') ?>,
                plazo: <?= jsonSeguro($prestamo['plazo_meses'] ? ($prestamo['plazo_meses'] . ' ' . textoPlazo($prestamo['tipo_plazo'] ?? 'meses')) : 'Indefinido') ?>
            };
            var corteByDate = {}, generaByDate = {};
            cortes.forEach(function(c) { corteByDate[c.corte] = c; generaByDate[c.genera] = c; });
            var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            var view = new Date(); view.setDate(1);
            function pad(n) { return (n < 10 ? '0' : '') + n; }
            function dstr(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
            var _now = new Date();
            var hoyStr = dstr(_now.getFullYear(), _now.getMonth(), _now.getDate());
            function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
            function render() {
                var y = view.getFullYear(), m = view.getMonth();
                document.getElementById('calTitle').textContent = meses[m] + ' ' + y;
                var first = new Date(y, m, 1);
                var lead = (first.getDay() + 6) % 7;
                var days = new Date(y, m + 1, 0).getDate();
                var html = '';
                for (var i = 0; i < lead; i++) html += '<div class="cal-day cal-empty"></div>';
                for (var d = 1; d <= days; d++) {
                    var ds = dstr(y, m, d);
                    var cls = 'cal-day', chips = '';
                    if (ds === hoyStr) cls += ' is-today';
                    if (fechaInicio && ds === fechaInicio) { cls += ' has-inicio'; chips += '<span class="cal-chip chip-inicio" title="Desembolso de ' + esc(clienteNombre) + ' · ' + esc(infoPrestamo.monto) + ' · tasa ' + esc(infoPrestamo.tasa) + '">Inicio · ' + esc(clienteNombre) + '</span>'; }
                    if (corteByDate[ds]) { cls += ' has-corte'; chips += '<span class="cal-chip chip-corte" title="Corte #' + corteByDate[ds].num + ' · ' + esc(clienteNombre) + ' · saldo ' + esc(infoPrestamo.saldo) + ' · interés ' + esc(interesMesTxt) + '">Corte #' + corteByDate[ds].num + '</span>'; }
                    if (graciaSet.has(ds)) { cls += ' in-gracia'; chips += '<span class="cal-chip chip-gracia" title="Día de gracia de ' + esc(clienteNombre) + ': paga sin generar interés">Gracia</span>'; }
                    if (generaByDate[ds]) { cls += ' is-genera'; chips += '<span class="cal-chip chip-genera" title="Genera el interés del mes de ' + esc(clienteNombre) + ' · tasa ' + esc(infoPrestamo.tasa) + ' sobre ' + esc(infoPrestamo.saldo) + '">Interés ' + esc(interesMesTxt) + '</span>'; }
                    if (pagosByDate[ds]) {
                        cls += ' has-pago';
                        var p = pagosByDate[ds];
                        chips += '<span class="cal-chip chip-pago" title="Pago de ' + esc(clienteNombre) + ' · ' + esc(p.txt) + '">' + (p.n > 1 ? p.n + ' pagos' : 'Pago') + '</span>';
                    }
                    html += '<div class="' + cls + '" data-fecha="' + ds + '" data-label="' + d + ' de ' + meses[m] + ' de ' + y + '" style="cursor:pointer;"><span class="cal-num">' + d + '</span><div class="cal-chips">' + chips + '</div></div>';
                }
                grid.innerHTML = html;
            }
            function renderConClicks() {
                render();
                grid.querySelectorAll('.cal-day[data-fecha]').forEach(function(cell) {
                    cell.addEventListener('click', function() { detalleDia(cell.getAttribute('data-fecha'), cell.getAttribute('data-label')); });
                });
            }
            document.getElementById('calPrev').addEventListener('click', function() { view.setMonth(view.getMonth() - 1); renderConClicks(); });
            document.getElementById('calNext').addEventListener('click', function() { view.setMonth(view.getMonth() + 1); renderConClicks(); });
            document.getElementById('calToday').addEventListener('click', function() { view = new Date(); view.setDate(1); renderConClicks(); });
            var btnInicio = document.getElementById('calInicio');
            if (btnInicio && fechaInicio) btnInicio.addEventListener('click', function() { var p = fechaInicio.split('-'); view = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, 1); renderConClicks(); });
            // Modal de detalle por día (como el calendario global)
            var prestamoId = <?= intval($id) ?>;
            var montoInicioTxt = <?= json_encode(formatMoney($prestamo['monto_prestado'])) ?>;
            var porFecha = {};
            function pushEv(fecha, ev) { (porFecha[fecha] = porFecha[fecha] || []).push(ev); }
            if (fechaInicio) pushEv(fechaInicio, {t: 'inicio'});
            cortes.forEach(function(c) { pushEv(c.corte, {t: 'corte', num: c.num}); pushEv(c.genera, {t: 'genera'}); });
            Object.keys(pagosByDate).forEach(function(f) { pushEv(f, {t: 'pago', n: pagosByDate[f].n, txt: pagosByDate[f].txt, det: pagosByDate[f].det || ''}); });
            function detalleDia(ds, dLabel) {
                var evs = porFecha[ds] || [];
                if (graciaSet.has(ds)) evs = evs.concat([{t: 'gracia'}]);
                document.getElementById('calModalFecha').textContent = dLabel + ' · ' + clienteNombre;
                var box = document.getElementById('calModalBody');
                if (!evs.length) { box.innerHTML = '<span style="color:var(--text-muted);">Sin eventos este día.</span>'; }
                else {
                    var html = '<ul class="info-list">';
                    evs.forEach(function(e) {
                        if (e.t === 'inicio') html += '<li><span class="info-label"><span class="badge badge-warning">Inicio</span></span><span class="info-value"><strong>' + esc(clienteNombre) + '</strong> · desembolso <strong>' + esc(montoInicioTxt) + '</strong> (' + esc(fechaInicioTxt) + ') · tasa ' + esc(infoPrestamo.tasa) + ' · plazo ' + esc(infoPrestamo.plazo) + '</span></li>';
                        else if (e.t === 'corte') html += '<li><span class="info-label"><span class="badge badge-primary">Corte #' + e.num + '</span></span><span class="info-value"><strong>' + esc(clienteNombre) + '</strong> · saldo ' + esc(infoPrestamo.saldo) + ' · interés mensual <strong>' + esc(interesMesTxt) + '</strong> · vence ' + esc(infoPrestamo.vencimiento) + ' · <a href="pagar.php?id=' + prestamoId + '">Registrar pago</a></span></li>';
                        else if (e.t === 'genera') html += '<li><span class="info-label"><span class="badge badge-moroso">Interés</span></span><span class="info-value"><strong>' + esc(clienteNombre) + '</strong> · genera <strong>' + esc(interesMesTxt) + '</strong> (tasa ' + esc(infoPrestamo.tasa) + ' sobre ' + esc(infoPrestamo.saldo) + ') · total a cobrar ' + esc(infoPrestamo.totalCobrar) + '</span></li>';
                        else if (e.t === 'gracia') html += '<li><span class="info-label"><span class="badge badge-success">Gracia</span></span><span class="info-value"><strong>' + esc(clienteNombre) + '</strong> · día de gracia: paga sin generar interés</span></li>';
                        else if (e.t === 'pago') html += '<li><span class="info-label"><span class="badge badge-success">Pago</span></span><span class="info-value"><strong>' + esc(clienteNombre) + '</strong> · ' + (e.n > 1 ? e.n + ' pagos · ' : '') + '<strong>' + esc(e.txt) + '</strong>' + (e.det ? ' (' + esc(e.det) + ')' : '') + '</span></li>';
                    });
                    box.innerHTML = html + '</ul>';
                }
                var m = document.getElementById('calModal');
                if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(m).show();
            }
            renderConClicks();
        })();
        </script>

        <div class="modal fade" id="calModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-calendar3"></i> Detalle del día <span id="calModalFecha"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body" id="calModalBody" style="font-size:0.85rem;"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="col-lg-<?= $hayCalCortesVer ? '3' : '6' ?> mb-3 order-lg-3">
        <div class="card animate-in dash-card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="bi bi-credit-card"></i> Pagos (<?= count($pagos) ?>)</span>
                <span style="font-size:0.7rem; color:var(--text-muted); font-weight:400;">
                    Cap: <?= formatMoney($totalCapital) ?> · Int: <?= formatMoney($totalIntereses) ?>
                </span>
            </div>
            <div class="card-body p-0 dash-scroll">
                <?php if (empty($pagos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-credit-card"></i>
                        <h5>Sin pagos registrados</h5>
                        <p>Registre el primer pago para este préstamo.</p>
                        <?php if ($prestamo['estado'] !== 'pagado'): ?>
                            <a href="pagar.php?id=<?= $id ?>" class="btn btn-success btn-sm"><i class="bi bi-credit-card"></i> Registrar Pago</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr><th>#</th><th>Fecha</th><th>Tipo</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Total</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagos as $pg): ?>
                                    <tr>
                                        <td><?= $pg['numero_cuota'] ?></td>
                                        <td style="font-size:0.78rem; color:var(--text-muted);"><?= date('d/m/Y', strtotime($pg['fecha_pago'])) ?></td>
                                        <td><span class="badge badge-<?= ($pg['tipo_pago'] ?? 'cuota') === 'abono' ? 'success' : (($pg['tipo_pago'] ?? 'cuota') === 'interes' ? 'warning' : 'primary') ?>" style="font-size:0.62rem;"><?= ['cuota' => 'Cuota', 'interes' => 'Interés', 'abono' => 'Abono'][$pg['tipo_pago'] ?? 'cuota'] ?? 'Cuota' ?></span></td>
                                        <td class="num"><?= formatMoney($pg['capital_pagado']) ?></td>
                                        <td class="num"><?= formatMoney($pg['interes_pagado']) ?></td>
                                        <td class="num fw-bold"><?= formatMoney($pg['monto_pagado']) ?></td>
                                        <td><a href="../pagos/recibo.php?id=<?= $pg['id'] ?>" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="bi bi-printer"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-1 align-items-stretch">
<?php if (!empty($calendario['tabla'])): ?>
<div class="col-lg-<?= empty($calCortes) ? '12' : '7' ?> mb-3">
<div class="card animate-in dash-card">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="bi bi-calendar-check"></i> Calendario de Pagos</span>
        <?php if ($calendario['proxima']): ?>
        <span style="font-size:0.75rem; color:var(--text-muted);">Próxima: cuota <?= $calendario['proxima']['cuota'] ?> · <?= date('d/m/Y', strtotime($calendario['proxima']['fecha'])) ?> · <?= formatMoney($calendario['proxima']['total']) ?></span>
        <?php else: ?>
        <span class="badge badge-pagado">Completo</span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0 dash-scroll-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>#</th><th>Vence</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Total</th><th class="num">Pagado</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($calendario['tabla'] as $c): ?>
                        <tr class="<?= $c['es_proxima'] ? 'row-muted' : '' ?>" style="<?= $c['estado'] === 'pagada' ? 'opacity:0.6;' : '' ?>">
                            <td><strong><?= $c['cuota'] ?></strong><?php if ($c['es_proxima']): ?> <i class="bi bi-arrow-right-circle-fill" style="color:var(--accent);" title="Próxima cuota"></i><?php endif; ?></td>
                            <td style="font-size:0.78rem;"><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                            <td class="num"><?= formatMoney($c['capital']) ?></td>
                            <td class="num"><?= formatMoney($c['interes']) ?></td>
                            <td class="num fw-bold"><?= formatMoney($c['total']) ?></td>
                            <td class="num"><?= $c['pagado'] > 0 ? formatMoney($c['pagado']) : '-' ?></td>
                            <td><span class="badge badge-<?= $c['estado'] === 'pagada' ? 'pagado' : ($c['estado'] === 'vencida' ? 'moroso' : ($c['estado'] === 'parcial' ? 'warning' : 'activo')) ?>"><?= ucfirst($c['estado']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<?php endif; ?>

<?php if (false && in_array($prestamo['estado'], ['activo', 'moroso']) && !$esIndefinido): // Simulador de mora oculto: no se usa ?>
<div class="col-lg-5 mb-3" style="display:none;">
<div class="card animate-in dash-card" style="border:1px dashed var(--border-strong);">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="bi bi-tools"></i> Simulador de Mora</span>
        <span class="badge badge-inactivo" style="font-size:0.65rem; letter-spacing:0.05em;">SOLO PRUEBAS</span>
    </div>
    <div class="card-body dash-scroll-sm">
        <div class="mb-2" style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">Estado actual</div>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="p-3" style="background:var(--bg); border:1px solid var(--border-strong); border-radius:8px;">
                    <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Vencimiento</div>
                    <div class="fw-bold" style="font-size:1.05rem;"><?= $prestamo['fecha_vencimiento'] ? date('d/m/Y', strtotime($prestamo['fecha_vencimiento'])) : 'Indefinido' ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3" style="background:var(--bg); border:1px solid var(--border-strong); border-radius:8px;">
                    <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Días de atraso</div>
                    <div class="fw-bold" style="font-size:1.05rem; color:<?= $diasAtraso > 0 ? 'var(--danger)' : 'var(--success)' ?>;"><?= $diasAtraso ?> días</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-3" style="background:var(--bg); border:1px solid var(--border-strong); border-radius:8px;">
                    <div style="font-size:0.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Mora actual</div>
                    <div class="fw-bold" style="font-size:1.05rem; color:var(--danger);"><?= formatMoney($moraActual) ?></div>
                </div>
            </div>
        </div>

        <div class="mb-4" style="font-size:0.78rem; color:var(--text-muted); line-height:1.6;">
            <i class="bi bi-question-circle"></i> La mora se calcula comparando el vencimiento con la fecha de hoy.
            Al simular un atraso, el vencimiento pasa a <strong>hoy − N días</strong> y la mora aparece en la cabecera de esta página:
            <code>(N días − <?= (int)$config['dias_gracia'] ?> de gracia) × (saldo × <?= $config['tasa_mora_diaria'] ?>%)</code>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="p-3" style="background:var(--bg); border:1px solid var(--border-strong); border-radius:8px;">
                    <div class="mb-2" style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">Paso 1 · Simular atraso</div>
                    <form method="POST" class="d-flex flex-wrap align-items-end gap-2" data-loading>
                        <?= csrfField() ?>
                        <input type="hidden" name="simular_atraso" value="1">
                        <div>
                            <label for="diasAtraso" style="font-size:0.72rem; color:var(--text-muted); display:block; margin-bottom:4px;">Días de atraso</label>
                            <input type="number" name="dias_atraso" id="diasAtraso" class="form-control form-control-sm" style="width:120px;" step="1" min="0" max="365" value="10">
                        </div>
                        <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-clock-history"></i> Simular atraso</button>
                        <label style="font-size:0.72rem; display:block; margin-top:6px;"><input type="checkbox" name="confirmar_simulador" value="1" required> Entiendo que modifica la fecha real</label>
                    </form>
                    <div class="mt-2" style="font-size:0.72rem; color:var(--text-muted);">
                        Ejemplo: con 10 días → mora = <?= (10 - (int)$config['dias_gracia']) ?> × (saldo × <?= $config['tasa_mora_diaria'] ?>%)
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="p-3" style="background:var(--bg); border:1px solid var(--border-strong); border-radius:8px;">
                    <div class="mb-2" style="font-size:0.72rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">Paso 2 · Terminar</div>
                    <form method="POST" onsubmit="return confirmarAccion('¿Restaurar el vencimiento original del préstamo?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="restaurar_fecha" value="1">
                        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Restaurar fecha original</button>
                    </form>
                    <div class="mt-2" style="font-size:0.72rem; color:var(--text-muted);">Devuelve el vencimiento a <strong>fecha de préstamo + plazo</strong>.</div>
                </div>
            </div>
        </div>

        <div class="alert alert-warning py-2 px-3 mt-3 mb-0" style="font-size:0.75rem;">
            <i class="bi bi-exclamation-triangle"></i> Si registras un pago con el vencimiento simulado, el estado pasará a <strong>"moroso"</strong> y la mora se cobrará y quedará en el historial. Restaura la fecha antes de terminar.
        </div>
    </div>
</div>
</div>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
