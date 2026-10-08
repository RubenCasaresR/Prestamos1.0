<?php
$pageTitle = 'Calendario Global';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$config = getConfiguracion($pdo);
$graciaCfg = intval($config['dias_gracia_interes'] ?? 0);

$cortes = []; $graciaDias = []; $genera = []; $pagosCal = []; $inicios = [];
$nCortes30 = 0; $int30 = 0.0; $nPagosMes = 0; $sumPagosMes = 0.0; $numVigilados = 0;

try {
$loans = $pdo->query("
    SELECT p.id, p.saldo_pendiente, p.tasa_interes, p.monto_prestado, p.fecha_ultimo_devengo, p.fecha_prestamo,
           p.estado, p.plazo_meses, p.tipo_plazo, p.num_cuotas, p.fecha_vencimiento,
           COALESCE(p.abonos_capital,0) AS abonos_capital,
           (p.monto_prestado - COALESCE(p.abonos_capital,0)) AS capital_vigente,
           COALESCE(p.interes_vigente,0) AS interes_vigente,
           CONCAT(c.nombre, ' ', c.apellido) AS cliente
    FROM prestamos p
    INNER JOIN clientes c ON p.cliente_id = c.id
    WHERE p.estado IN ('activo','moroso') AND COALESCE(p.saldo_pendiente, 0) > 0
    ORDER BY p.id
")->fetchAll();
$numVigilados = count($loans);

foreach ($loans as $ln) {
    $base = substr($ln['fecha_ultimo_devengo'] ?? $ln['fecha_prestamo'], 0, 10);
    // Día de inicio del préstamo (desembolso, fijo aunque la base se mueva)
    $fInicio = substr($ln['fecha_prestamo'] ?? '', 0, 10);
    if ($fInicio !== '' && $fInicio !== false) {
        $inicios[] = ['fecha' => $fInicio, 'id' => intval($ln['id']), 'cli' => $ln['cliente'], 'monto' => round(floatval($ln['monto_prestado']), 2), 'tasa' => round(floatval($ln['tasa_interes']), 2), 'estado' => $ln['estado']];
    }
    $mensual = round(floatval($ln['saldo_pendiente']) * floatval($ln['tasa_interes']) / 100, 2);
    $saldoLn = round(floatval($ln['saldo_pendiente']), 2);
    $tasaLn = round(floatval($ln['tasa_interes']), 2);
    $vencLn = substr($ln['fecha_vencimiento'] ?? '', 0, 10) ?: null;
    $vencTxt = $vencLn ? date('d/m/Y', strtotime($vencLn)) : null;
    try { $b = new DateTime($base); } catch (Exception $e) { continue; }
    for ($i = 1; $i <= 6; $i++) {
        $c = clone $b;
        $c->modify('+' . $i . ' months');
        $fc = $c->format('Y-m-d');
        $g = clone $c;
        if ($graciaCfg > 0) $g->modify('+' . $graciaCfg . ' days');
        $fg = $g->format('Y-m-d');
        $cortes[] = ['fecha' => $fc, 'id' => intval($ln['id']), 'num' => $i, 'cli' => $ln['cliente'], 'saldo' => $saldoLn, 'tasa' => $tasaLn, 'venc' => $vencTxt, 'estado' => $ln['estado']];
        $genera[] = ['fecha' => $fg, 'id' => intval($ln['id']), 'num' => $i, 'cli' => $ln['cliente'], 'monto' => $mensual, 'saldo' => $saldoLn, 'tasa' => $tasaLn, 'estado' => $ln['estado']];
        if ($graciaCfg > 0) {
            $d = $fc; $guard = 0;
            while ($d < $fg && $guard < 400) { $graciaDias[] = ['fecha' => $d, 'id' => intval($ln['id'])]; $d = date('Y-m-d', strtotime($d . ' +1 day')); $guard++; }
        }
    }
}

foreach ($pdo->query("
    SELECT substr(pg.fecha_pago, 1, 10) AS fecha, pg.prestamo_id, pg.monto_pagado,
           pg.capital_pagado, pg.interes_pagado, pg.mora_pagada,
           CONCAT(c.nombre, ' ', c.apellido) AS cli
    FROM pagos pg
    INNER JOIN prestamos p ON pg.prestamo_id = p.id
    INNER JOIN clientes c ON p.cliente_id = c.id
    ORDER BY pg.fecha_pago ASC
")->fetchAll() as $pg) {
    $pagosCal[] = ['fecha' => $pg['fecha'], 'id' => intval($pg['prestamo_id']), 'cli' => $pg['cli'], 'monto' => round(floatval($pg['monto_pagado']), 2), 'cap' => round(floatval($pg['capital_pagado'] ?? 0), 2), 'inte' => round(floatval($pg['interes_pagado'] ?? 0), 2), 'mora' => round(floatval($pg['mora_pagada'] ?? 0), 2)];
}

$hoyCal = hoyFecha();
$lim30 = date('Y-m-d', strtotime('+30 days'));
foreach ($genera as $gn) {
    if ($gn['fecha'] >= $hoyCal && $gn['fecha'] <= $lim30) { $nCortes30++; $int30 = round($int30 + $gn['monto'], 2); }
}
$mesCal = substr($hoyCal, 0, 7);
foreach ($pagosCal as $pg) {
    if (substr($pg['fecha'], 0, 7) === $mesCal) { $nPagosMes++; $sumPagosMes = round($sumPagosMes + $pg['monto'], 2); }
}
} catch (Exception $e) {
    error_log("Error en calendario global: " . $e->getMessage());
    flash('danger', 'Error al cargar el calendario');
    redirect('../index.php');
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-calendar3"></i> Calendario Global de Préstamos</h4>
        </div>
        <div>
            <a href="../prestamos/crear.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nuevo Préstamo</a>
        </div>
    </div>
</div>

<div class="card dash-stats animate-in mb-3">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Intereses a Generar (30 días)</div>
                <div class="stat-value" style="color:var(--danger);"><?= formatMoney($int30) ?></div>
                <div style="font-size:0.68rem; color:var(--text-muted);"><?= $nCortes30 ?> corte(s) próximos</div>
            </div>
            <div>
                <div class="stat-label">Pagos Este Mes</div>
                <div class="stat-value" style="color:var(--success);"><?= formatMoney($sumPagosMes) ?></div>
                <div style="font-size:0.68rem; color:var(--text-muted);"><?= $nPagosMes ?> pago(s) registrados</div>
            </div>
            <div>
                <div class="stat-label">Préstamos Vigilados</div>
                <div class="stat-value"><?= $numVigilados ?></div>
                <div style="font-size:0.68rem; color:var(--text-muted);">activos con saldo · 6 cortes c/u</div>
            </div>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
        <span><i class="bi bi-calendar3"></i> Mes</span>
        <span style="font-size:0.72rem; color:var(--text-muted); font-weight:400;">gracia <?= $graciaCfg ?> día(s) por corte</span>
    </div>
    <div class="card-body p-0">
                <div class="cal-nav">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="gCalPrev"><i class="bi bi-chevron-left"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="gCalToday">Hoy</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="gCalNext"><i class="bi bi-chevron-right"></i></button>
                    <strong id="gCalTitle" style="margin-left:8px; text-transform:capitalize;"></strong>
                    <select id="gCalFiltro" class="form-select form-select-sm" style="margin-left:auto; width:auto; font-size:0.75rem;">
                        <option value="0">Todos los préstamos</option>
                        <?php foreach ($loans as $ln): ?>
                            <option value="<?= intval($ln['id']) ?>">#<?= intval($ln['id']) ?> — <?= sanitize($ln['cliente']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
        <div class="cal-grid cal-weekdays">
            <span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span>
        </div>
        <div class="cal-grid" id="gCalGrid"></div>
        <div class="px-3 py-2 cal-legend" style="font-size:0.72rem; color:var(--text-muted); border-top:1px solid var(--border);">
            <span><i class="cal-dot dot-inicio"></i>Inicio</span>
            <span><i class="cal-dot dot-corte"></i>Corte (pagar)</span>
            <span><i class="cal-dot dot-gracia"></i>Gracia (sin interés)</span>
            <span><i class="cal-dot dot-genera"></i>Genera interés</span>
            <span><i class="cal-dot dot-pago"></i>Pago registrado</span>
            <span style="margin-left:auto;">Click en un día para ver el detalle</span>
        </div>
    </div>
</div>

<div class="modal fade" id="gCalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar3"></i> Detalle del día <span id="gCalModalFecha"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="gCalModalBody" style="font-size:0.85rem;"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var cortes = <?= jsonSeguro(array_map(function($c) { return ['fecha' => $c['fecha'], 'id' => $c['id'], 'num' => $c['num'], 'cli' => $c['cli'], 'saldo' => $c['saldo'], 'tasa' => $c['tasa'], 'venc' => $c['venc'], 'estado' => $c['estado']]; }, $cortes)) ?>;
    var genera = <?= jsonSeguro(array_map(function($c) { return ['fecha' => $c['fecha'], 'id' => $c['id'], 'num' => $c['num'], 'cli' => $c['cli'], 'monto' => $c['monto'], 'saldo' => $c['saldo'], 'tasa' => $c['tasa'], 'estado' => $c['estado']]; }, $genera)) ?>;
    var graciaEv = <?= jsonSeguro($graciaDias) ?>;
    var graciaSet = new Set(graciaEv.map(function(g) { return typeof g === 'string' ? g : g.fecha; }));
    function enGracia(ds, evs) {
        // QA: gracia por préstamo (antes global mostraba gracia ajena con filtro).
        var ids = {};
        (evs || []).forEach(function(e) { if (e && e.id) ids[e.id] = true; });
        for (var i = 0; i < graciaEv.length; i++) {
            var g = graciaEv[i];
            var gf = typeof g === 'string' ? g : g.fecha;
            var gi = typeof g === 'string' ? null : g.id;
            if (gf === ds && (!gi || ids[gi] || !filtroId)) return true;
        }
        return false;
    }
    var pagos = <?= jsonSeguro($pagosCal) ?>;
    var inicios = <?= jsonSeguro($inicios) ?>;
    var basePath = <?= jsonSeguro(getBasePath()) ?>;
    var porFecha = {};
    function push(fecha, ev) { (porFecha[fecha] = porFecha[fecha] || []).push(ev); }
    inicios.forEach(function(s) { push(s.fecha, {t: 'inicio', id: s.id, cli: s.cli, monto: s.monto, tasa: s.tasa, estado: s.estado}); });
    cortes.forEach(function(c) { push(c.fecha, {t: 'corte', id: c.id, num: c.num, cli: c.cli, saldo: c.saldo, tasa: c.tasa, venc: c.venc, estado: c.estado}); });
    genera.forEach(function(c) { push(c.fecha, {t: 'genera', id: c.id, num: c.num, cli: c.cli, monto: c.monto, saldo: c.saldo, tasa: c.tasa, estado: c.estado}); });
    pagos.forEach(function(p) { push(p.fecha, {t: 'pago', id: p.id, cli: p.cli, monto: p.monto, cap: p.cap, inte: p.inte, mora: p.mora}); });
    var meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var filtroId = 0;
    function visibles(ds) {
        var evs = porFecha[ds] || [];
        if (!filtroId) return evs;
        return evs.filter(function(e) { return e.id === filtroId; });
    }
    var view = new Date(); view.setDate(1);
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function dstr(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    var _now = new Date();
    var hoyStr = dstr(_now.getFullYear(), _now.getMonth(), _now.getDate());
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmtMoney(n) { return 'MXN ' + Number(n).toFixed(2); }
    function chipsFor(ds) {
        var evs = visibles(ds);
        var html = '', cls = '';
        var iniciosId = [], cortesId = [], generaId = [], nPago = 0;
        evs.forEach(function(e) { if (e.t === 'inicio') iniciosId.push(e); else if (e.t === 'corte') cortesId.push(e); else if (e.t === 'genera') generaId.push(e); else if (e.t === 'pago') nPago++; });
        if (iniciosId.length > 0) {
            cls += ' has-inicio';
            var showS = iniciosId.slice(0, 2);
            showS.forEach(function(e) { html += '<span class="cal-chip chip-inicio" title="Desembolso · ' + esc(e.cli) + ' · ' + esc(fmtMoney(e.monto)) + ' · tasa ' + esc(e.tasa) + '%">Inicio · ' + esc(e.cli) + '</span>'; });
            if (iniciosId.length > 2) html += '<span class="cal-chip chip-inicio">+' + (iniciosId.length - 2) + '</span>';
        }
        if (cortesId.length > 0) {
            cls += ' has-corte';
            var showC = cortesId.slice(0, 2);
            showC.forEach(function(e) { html += '<span class="cal-chip chip-corte" title="Corte #' + e.num + ' · ' + esc(e.cli) + ' · saldo ' + esc(fmtMoney(e.saldo)) + ' · tasa ' + esc(e.tasa) + '%">Corte · ' + esc(e.cli) + '</span>'; });
            if (cortesId.length > 2) html += '<span class="cal-chip chip-corte">+' + (cortesId.length - 2) + '</span>';
        }
        if (enGracia(ds, evs) && evs.length > 0) { cls += ' in-gracia'; html += '<span class="cal-chip chip-gracia">Gracia</span>'; }
        if (generaId.length > 0) {
            cls += ' is-genera';
            var showG = generaId.slice(0, 2);
            showG.forEach(function(e) { html += '<span class="cal-chip chip-genera" title="Genera ' + esc(fmtMoney(e.monto)) + ' · ' + esc(e.cli) + ' · saldo ' + esc(fmtMoney(e.saldo)) + '">Interés · ' + esc(e.cli) + '</span>'; });
            if (generaId.length > 2) html += '<span class="cal-chip chip-genera">+' + (generaId.length - 2) + '</span>';
        }
        if (nPago > 0) { cls += ' has-pago'; html += '<span class="cal-chip chip-pago">' + (nPago > 1 ? nPago + ' pagos' : 'Pago') + '</span>'; }
        return { cls: cls, html: html };
    }
    function detalleDia(ds, dLabel, mostrar) {
        var evs = visibles(ds);
        document.getElementById('gCalModalFecha').textContent = dLabel;
        var box = document.getElementById('gCalModalBody');
        if (!evs.length) { box.innerHTML = '<span style="color:var(--text-muted);">Sin eventos este día.</span>'; }
        else {
            var html = '<ul class="info-list">';
            evs.forEach(function(e) {
                var link = '<a href="' + basePath + 'prestamos/ver.php?id=' + e.id + '">Ver préstamo</a>';
                if (e.t === 'inicio') html += '<li><span class="info-label"><span class="badge badge-warning">Inicio</span></span><span class="info-value"><strong>' + esc(e.cli) + '</strong> · desembolso <strong>' + fmtMoney(e.monto) + '</strong> · tasa ' + esc(e.tasa) + '% · ' + link + '</span></li>';
                else if (e.t === 'corte') html += '<li><span class="info-label"><span class="badge badge-primary">Corte #' + e.num + '</span></span><span class="info-value"><strong>' + esc(e.cli) + '</strong> · saldo ' + esc(fmtMoney(e.saldo)) + ' · tasa ' + esc(e.tasa) + '%' + (e.venc ? ' · vence ' + esc(e.venc) : '') + ' · ' + link + '</span></li>';
                else if (e.t === 'genera') html += '<li><span class="info-label"><span class="badge badge-moroso">Interés</span></span><span class="info-value"><strong>' + esc(e.cli) + '</strong> · genera <strong>' + fmtMoney(e.monto) + '</strong> (' + esc(e.tasa) + '% de ' + esc(fmtMoney(e.saldo)) + ') · ' + link + '</span></li>';
                else if (e.t === 'pago') html += '<li><span class="info-label"><span class="badge badge-success">Pago</span></span><span class="info-value"><strong>' + esc(e.cli) + '</strong> · <strong>' + fmtMoney(e.monto) + '</strong> (Cap ' + fmtMoney(e.cap) + ' · Int ' + fmtMoney(e.inte) + (e.mora > 0 ? ' · Mora ' + fmtMoney(e.mora) : '') + ') · ' + link + '</span></li>';
            });
            box.innerHTML = html + '</ul>';
        }
        if (mostrar === false) return;
        var m = document.getElementById('gCalModal');
        if (window.bootstrap && bootstrap.Modal) bootstrap.Modal.getOrCreateInstance(m).show();
    }
    function render() {
        var y = view.getFullYear(), m = view.getMonth();
        document.getElementById('gCalTitle').textContent = meses[m] + ' ' + y;
        var first = new Date(y, m, 1);
        var lead = (first.getDay() + 6) % 7;
        var days = new Date(y, m + 1, 0).getDate();
        var html = '';
        for (var i = 0; i < lead; i++) html += '<div class="cal-day cal-empty"></div>';
        for (var d = 1; d <= days; d++) {
            var ds = dstr(y, m, d);
            var info = chipsFor(ds);
            var cls = 'cal-day' + info.cls + (ds === hoyStr ? ' is-today' : '');
            html += '<div class="' + cls + '" data-fecha="' + ds + '" data-label="' + d + ' de ' + meses[m] + ' de ' + y + '" style="cursor:pointer;"><span class="cal-num">' + d + '</span><div class="cal-chips">' + info.html + '</div></div>';
        }
        var grid = document.getElementById('gCalGrid');
        grid.innerHTML = html;
        grid.querySelectorAll('.cal-day[data-fecha]').forEach(function(cell) {
            cell.addEventListener('click', function() { detalleDia(cell.getAttribute('data-fecha'), cell.getAttribute('data-label')); });
        });
    }
    document.getElementById('gCalPrev').addEventListener('click', function() { view.setMonth(view.getMonth() - 1); render(); });
    document.getElementById('gCalNext').addEventListener('click', function() { view.setMonth(view.getMonth() + 1); render(); });
    document.getElementById('gCalToday').addEventListener('click', function() { view = new Date(); view.setDate(1); render(); });
    document.getElementById('gCalFiltro').addEventListener('change', function() { filtroId = parseInt(this.value, 10) || 0; render(); });
    render();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
