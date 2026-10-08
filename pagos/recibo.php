<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();

$pdo = getConnection();
$config = getConfiguracion($pdo);
$id = intval($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT pg.*, p.monto_prestado, p.cuota_mensual, p.tasa_interes, p.plazo_meses, p.tipo_plazo, p.num_cuotas, p.saldo_pendiente AS prestamo_saldo_actual,
           CONCAT(c.nombre, ' ', c.apellido) AS cliente_nombre, c.cedula, c.telefono AS cliente_telefono, c.direccion AS cliente_direccion
    FROM pagos pg
    INNER JOIN prestamos p ON pg.prestamo_id = p.id
    INNER JOIN clientes c ON p.cliente_id = c.id
    WHERE pg.id = ?
");
$stmt->execute([$id]);
$pago = $stmt->fetch();

if (!$pago) {
    http_response_code(404);
    die('<html><body style="font-family:sans-serif; text-align:center; padding:60px;"><h2>Pago no encontrado</h2><p>El recibo solicitado no existe o ha sido eliminado.</p><a href="javascript:window.close()">Cerrar</a></body></html>');
}

$tipoTxt = ['cuota' => 'Cuota normal', 'interes' => 'Solo interés + mora', 'abono' => 'Abono a capital'][$pago['tipo_pago'] ?? 'cuota'] ?? 'Cuota normal';
$totCuotas = intval($pago['num_cuotas'] ?? $pago['plazo_meses'] ?? 0);
$cuotaTxt = $totCuotas > 0 ? intval($pago['numero_cuota']) . ' de ' . $totCuotas : 'Pago ' . intval($pago['numero_cuota']) . ' (indefinido)';
$folio = str_pad($pago['id'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pago #<?= $folio ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, Helvetica, sans-serif; font-size: 13px; color: #1f2937; background: #e5e7eb; }
        .page { max-width: 800px; margin: 24px auto; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.12); }
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 32px 40px 20px; border-bottom: 3px solid #1e40af; }
        .doc-header .empresa h1 { font-size: 20px; color: #1e40af; margin-bottom: 4px; }
        .doc-header .empresa p { font-size: 11px; color: #6b7280; line-height: 1.5; }
        .doc-header .folio { text-align: right; }
        .doc-header .folio .tag { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .08em; color: #fff; background: #1e40af; padding: 4px 12px; border-radius: 4px; margin-bottom: 6px; }
        .doc-header .folio p { font-size: 12px; color: #374151; line-height: 1.6; }
        .doc-body { padding: 24px 40px; }
        .doc-body h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; }
        .doc-body h3:first-child { margin-top: 0; }
        .kv { display: table; width: 100%; }
        .kv-row { display: table-row; }
        .kv-row > span { display: table-cell; padding: 4px 0; }
        .kv-row > span:first-child { width: 32%; color: #6b7280; font-size: 12px; }
        .kv-row > span:last-child { font-weight: 600; }
        table.detalle { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.detalle th { background: #1e40af; color: #fff; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; padding: 8px 12px; text-align: left; }
        table.detalle th.num, table.detalle td.num { text-align: right; }
        table.detalle td { padding: 8px 12px; border-bottom: 1px solid #e5e7eb; }
        table.detalle tr.total td { font-weight: 700; font-size: 15px; background: #f3f4f6; border-bottom: 2px solid #1e40af; }
        .saldo-box { margin-top: 16px; padding: 12px 16px; background: #f3f4f6; border-left: 4px solid #1e40af; font-size: 13px; }
        .obs { margin-top: 12px; font-size: 12px; color: #4b5563; }
        .firmas { display: flex; justify-content: space-between; gap: 40px; margin: 56px 40px 0; padding-bottom: 12px; }
        .firmas div { flex: 1; text-align: center; border-top: 1px solid #374151; padding-top: 6px; font-size: 11px; color: #6b7280; }
        .doc-footer { margin: 0 40px; padding: 12px 0 28px; border-top: 1px solid #e5e7eb; font-size: 10px; color: #9ca3af; display: flex; justify-content: space-between; }
        .no-print { text-align: center; padding: 16px; }
        .no-print .btn-print { display: inline-block; padding: 10px 30px; background: #0d6efd; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; }
        .no-print .btn-print:hover { background: #0b5ed7; }
        .no-print a { font-size: 12px; }
        @page { size: letter; margin: 12mm; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .page { max-width: 100%; margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button class="btn-print" onclick="window.print()">Imprimir / Guardar PDF</button>
        <br><br>
        <a href="javascript:window.close()">Cerrar</a>
        <p style="font-size:11px; color:#6b7280; margin-top:6px;">En el diálogo de impresión elija destino "Guardar como PDF".</p>
    </div>

    <div class="page">
        <div class="doc-header">
            <div class="empresa">
                <h1><?= sanitize($config['nombre_empresa']) ?></h1>
                <?php if ($config['rif']): ?><p>RIF: <?= sanitize($config['rif']) ?></p><?php endif; ?>
                <?php if (!empty($config['direccion'])): ?><p><?= sanitize($config['direccion']) ?></p><?php endif; ?>
                <?php if (!empty($config['telefono'])): ?><p>Tel: <?= sanitize($config['telefono']) ?></p><?php endif; ?>
            </div>
            <div class="folio">
                <span class="tag">RECIBO DE PAGO</span>
                <p><strong>Folio N° <?= $folio ?></strong></p>
                <p>Fecha: <?= fechaValida(substr($pago['fecha_pago'] ?? '', 0, 10)) ? date('d/m/Y', strtotime($pago['fecha_pago'])) : '—' ?></p>
                <p>Hora: <?= strtotime($pago['fecha_registro'] ?? '') !== false ? date('h:i A', strtotime($pago['fecha_registro'])) : '—' ?></p>
            </div>
        </div>

        <div class="doc-body">
            <h3>Datos del cliente</h3>
            <div class="kv">
                <div class="kv-row"><span>Cliente</span><span><?= sanitize($pago['cliente_nombre']) ?></span></div>
                <div class="kv-row"><span>Cédula</span><span><?= sanitize($pago['cedula']) ?></span></div>
                <?php if (!empty($pago['cliente_telefono'])): ?><div class="kv-row"><span>Teléfono</span><span><?= sanitize($pago['cliente_telefono']) ?></span></div><?php endif; ?>
                <?php if (!empty($pago['cliente_direccion'])): ?><div class="kv-row"><span>Dirección</span><span><?= sanitize($pago['cliente_direccion']) ?></span></div><?php endif; ?>
            </div>

            <h3>Detalle del pago</h3>
            <div class="kv">
                <div class="kv-row"><span>Préstamo N°</span><span><?= $pago['prestamo_id'] ?></span></div>
                <div class="kv-row"><span>Tipo de pago</span><span><?= sanitize($tipoTxt) ?></span></div>
                <div class="kv-row"><span>Cuota</span><span><?= sanitize($cuotaTxt) ?></span></div>
            </div>
            <table class="detalle">
                <thead>
                    <tr><th>Concepto</th><th class="num">Monto</th></tr>
                </thead>
                <tbody>
                    <tr><td>Capital</td><td class="num"><?= formatMoney($pago['capital_pagado'], $config['moneda']) ?></td></tr>
                    <tr><td>Interés</td><td class="num"><?= formatMoney($pago['interes_pagado'], $config['moneda']) ?></td></tr>
                    <?php if (floatval($pago['mora_pagada'] ?? 0) > 0): ?>
                        <tr><td>Mora</td><td class="num"><?= formatMoney($pago['mora_pagada'], $config['moneda']) ?></td></tr>
                    <?php endif; ?>
                    <?php if (floatval($pago['descuento'] ?? 0) > 0): ?>
                        <tr><td>Descuento/condonación</td><td class="num">−<?= formatMoney($pago['descuento'], $config['moneda']) ?></td></tr>
                    <?php endif; ?>
                    <tr class="total"><td>TOTAL PAGADO</td><td class="num"><?= formatMoney($pago['monto_pagado'], $config['moneda']) ?></td></tr>
                </tbody>
            </table>

            <div class="saldo-box">
                Saldo pendiente tras este pago: <strong><?= formatMoney($pago['saldo_despues_pago'] ?? null, $config['moneda']) ?></strong>
                <span style="font-size:11px;color:#6b7280;">(histórico del pago, no el saldo actual)</span>
            </div>

            <?php if ($pago['observaciones']): ?>
                <p class="obs"><strong>Observaciones:</strong> <?= sanitize($pago['observaciones']) ?></p>
            <?php endif; ?>
        </div>

        <div class="firmas">
            <div>Recibido por<br><br><br>Nombre y firma</div>
            <div>Entregado por (cliente)<br><br><br>Nombre y firma</div>
        </div>
        <div class="doc-footer">
            <span>Comprobante generado el <?= date('d/m/Y H:i') ?></span>
            <span>¡Gracias por su pago!</span>
        </div>
    </div>
</body>
</html>
