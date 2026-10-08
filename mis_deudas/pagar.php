<?php
$pageTitle = 'Registrar Pago de Deuda';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$id = intval($_GET['id'] ?? 0);
$errors = [];

$stmt = $pdo->prepare("
    SELECT d.*, a.nombre AS acreedor_nombre
    FROM mis_deudas d INNER JOIN acreedores a ON d.acreedor_id = a.id WHERE d.id = ?
");
$stmt->execute([$id]);
$deuda = $stmt->fetch();

if (!$deuda) { flash('danger', 'Deuda no encontrada'); redirect('../mis_deudas/index.php'); }
if ($deuda['estado'] === 'pagado') { flash('warning', 'Esta deuda ya está liquidada'); redirect('ver.php?id=' . $id); }

$interesMensualCalc = round($deuda['capital_vigente'] * ($deuda['tasa_mensual'] / 100), 2);
$maxPermitido = round($deuda['capital_vigente'] + $interesMensualCalc, 2);

$totalPagadoStmt = $pdo->prepare("SELECT COALESCE(SUM(monto_pagado), 0) FROM pagos_deudas WHERE deuda_id = ?");
$totalPagadoStmt->execute([$id]);
$totalPagado = $totalPagadoStmt->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $montoPagado = floatval($_POST['monto_pagado'] ?? 0);
        $fechaPago = $_POST['fecha_pago'] ?? date('Y-m-d');
        $observaciones = trim($_POST['observaciones'] ?? '');

        if (!numeroValido($_POST['monto_pagado'] ?? null)) $errors[] = 'El monto debe ser un número mayor a 0';
        elseif ($montoPagado <= 0) $errors[] = 'El monto debe ser mayor a 0';
        if (mb_strlen($observaciones) > 1000) $errors[] = 'Las observaciones no pueden exceder 1000 caracteres';
        $fechaPago = trim((string)$fechaPago);
        if (!fechaValida($fechaPago)) $errors[] = 'Fecha de pago inválida (use fecha real YYYY-MM-DD)';
        else {
            if ($fechaPago > hoyFecha()) $errors[] = 'La fecha de pago no puede ser futura';
            $hastaBase = $deuda['interes_pagado_hasta'] ?? $deuda['fecha_inicio'];
            if ($hastaBase && $fechaPago < substr($hastaBase, 0, 10)) $errors[] = 'La fecha de pago no puede ser anterior al último pago de interés';
        }

        if (empty($errors)) {
            $capitalVigente = $deuda['capital_vigente'];
            $tasa = $deuda['tasa_mensual'] / 100;

            // Calcular interés acumulado desde último pago (base diaria)
            $ultimoPagoInteres = $deuda['interes_pagado_hasta'] ?? $deuda['fecha_inicio'];
            try {
                $hoy = new DateTime($fechaPago);
                $ultimo = new DateTime($ultimoPagoInteres);
            } catch (Exception $e) {
                $errors[] = 'Fecha de interés corrupta en la deuda. Contacte al administrador.';
                $hoy = null; $ultimo = null;
            }
            if (empty($errors)) {
            $diasTranscurridos = ($ultimo === null || $hoy === null || $ultimo > $hoy) ? 0 : (int)$ultimo->diff($hoy)->days;
            $interesAcumulado = round($capitalVigente * $tasa * $diasTranscurridos / 30, 2);
            $maxReal = round($capitalVigente + $interesAcumulado, 2);
            if ($montoPagado > $maxReal) $errors[] = 'El monto excede el saldo de la deuda. Máximo a pagar: ' . formatMoney($maxReal);

            if (empty($errors)) {
            if ($interesAcumulado <= 0) {
                $interesPagar = 0;
                $capitalPagar = round($montoPagado, 2);
            } else {
                $interesPagar = min($interesAcumulado, $montoPagado);
                $capitalPagar = round($montoPagado - $interesPagar, 2);
            }

            $capitalPagar = min($capitalPagar, $capitalVigente);
            if ($capitalPagar < 0) {
                $capitalPagar = 0;
                $interesPagar = $montoPagado;
            }

            $interesPagar = round($interesPagar, 2);
            $capitalPagar = round($capitalPagar, 2);

            $nuevoCapital = round($capitalVigente - $capitalPagar, 2);
            $nuevosAbonos = round($deuda['abonos_capital_pagados'] + $capitalPagar, 2);
            $nuevoEstado = ($nuevoCapital <= 0) ? 'pagado' : 'activo';
            if ($nuevoCapital < 0) $nuevoCapital = 0;

            // Actualizar interes_pagado_hasta proporcionalmente
            $nuevoInteresPagadoHasta = $deuda['interes_pagado_hasta'];
            if ($interesAcumulado > 0) {
                if ($interesPagar >= $interesAcumulado) {
                    $nuevoInteresPagadoHasta = $fechaPago;
                } else {
                    $proporcion = $interesPagar / $interesAcumulado;
                    $diasPagados = round($diasTranscurridos * $proporcion);
                    if ($diasPagados > 0) {
                        $nuevoInteresPagadoHasta = date('Y-m-d', strtotime($ultimoPagoInteres . " + $diasPagados days"));
                    }
                }
            }

            try {
                $pdo->beginTransaction();
                // Fix concurrencia (espejo prestamos/pagar.php): releer fila bloqueada
                // y recalcular interés diario DENTRO de la transacción con FOR UPDATE.
                // El cálculo previo (líneas 46-55) queda como validación superficial;
                // los valores autoritativos son los recalculados aquí.
                $lockStmt = $pdo->prepare("SELECT capital_vigente, tasa_mensual, interes_pagado_hasta, fecha_inicio, abonos_capital_pagados, estado FROM mis_deudas WHERE id = ? FOR UPDATE");
                $lockStmt->execute([$id]);
                $locked = $lockStmt->fetch();
                if (!$locked || $locked['estado'] === 'pagado') {
                    $pdo->rollBack();
                    $errors[] = 'La deuda ya fue liquidada o no existe';
                } else {
                $capitalVigenteL = round(floatval($locked['capital_vigente']), 2);
                $tasaL = floatval($locked['tasa_mensual']) / 100;
                $ultimoPagoInteresL = $locked['interes_pagado_hasta'] ?? $locked['fecha_inicio'];
                $hoyL = new DateTime($fechaPago);
                $ultimoL = new DateTime($ultimoPagoInteresL);
                $diasTranscurridosL = $ultimoL > $hoyL ? 0 : (int)$ultimoL->diff($hoyL)->days;
                $interesAcumuladoL = round($capitalVigenteL * $tasaL * $diasTranscurridosL / 30, 2);
                $maxRealL = round($capitalVigenteL + $interesAcumuladoL, 2);
                if ($montoPagado > $maxRealL) {
                    $pdo->rollBack();
                    $errors[] = 'El monto excede el saldo de la deuda. Máximo a pagar: ' . formatMoney($maxRealL);
                } else {
                if ($interesAcumuladoL <= 0) {
                    $interesPagar = 0;
                    $capitalPagar = round($montoPagado, 2);
                } else {
                    $interesPagar = min($interesAcumuladoL, $montoPagado);
                    $capitalPagar = round($montoPagado - $interesPagar, 2);
                }
                $capitalPagar = min($capitalPagar, $capitalVigenteL);
                if ($capitalPagar < 0) {
                    $capitalPagar = 0;
                    $interesPagar = $montoPagado;
                }
                $interesPagar = round($interesPagar, 2);
                $capitalPagar = round($capitalPagar, 2);
                $nuevoCapital = round($capitalVigenteL - $capitalPagar, 2);
                $nuevosAbonos = round(floatval($locked['abonos_capital_pagados']) + $capitalPagar, 2);
                $nuevoEstado = ($nuevoCapital <= 0) ? 'pagado' : 'activo';
                if ($nuevoCapital < 0) $nuevoCapital = 0;
                $nuevoInteresPagadoHasta = $locked['interes_pagado_hasta'];
                if ($interesAcumuladoL > 0) {
                    if ($interesPagar >= $interesAcumuladoL) {
                        $nuevoInteresPagadoHasta = $fechaPago;
                    } else {
                        $proporcion = $interesPagar / $interesAcumuladoL;
                        $diasPagados = round($diasTranscurridosL * $proporcion);
                        if ($diasPagados > 0) {
                            $nuevoInteresPagadoHasta = date('Y-m-d', strtotime($ultimoPagoInteresL . " + $diasPagados days"));
                        }
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO pagos_deudas (deuda_id, monto_pagado, capital_pagado, interes_pagado, fecha_pago, notas) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$id, $montoPagado, $capitalPagar, $interesPagar, $fechaPago, $observaciones]);

                // QA D7: interes_mensual se recalcula sobre el nuevo capital (antes quedaba stale del alta).
                $nuevoInteresMensual = round(floatval($nuevoCapital) * floatval($locked['tasa_mensual']) / 100, 2);
                $stmt = $pdo->prepare("UPDATE mis_deudas SET capital_vigente = ?, abonos_capital_pagados = ?, estado = ?, interes_pagado_hasta = ?, interes_mensual = ? WHERE id = ?");
                $stmt->execute([$nuevoCapital, $nuevosAbonos, $nuevoEstado, $nuevoInteresPagadoHasta, $nuevoInteresMensual, $id]);

                $pdo->commit();
                audit($pdo, 'pago_deuda', 'pagos_deudas', $pdo->lastInsertId(), "deuda=$id monto=$montoPagado capital=$capitalPagar interes=$interesPagar");
                flash('success', 'Pago registrado exitosamente');
                redirect('ver.php?id=' . $id);
                }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Error al registrar pago de deuda: " . $e->getMessage());
                $errors[] = 'Error al registrar el pago. Intente de nuevo.';
            }
            } // fin validación superficial con fechas válidas
            }
        }
    }
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-credit-card"></i> Pago Deuda #<?= $deuda['id'] ?></h4>
        </div>
        <a href="ver.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger animate-in">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= sanitize($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card animate-in">
            <div class="card-body">
                <form method="POST" data-loading>
                    <?= csrfField() ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="number" name="monto_pagado" placeholder=" " step="0.01" min="0.01" max="<?= $maxPermitido ?>" value="<?= number_format($deuda['interes_mensual'], 2, '.', '') ?>" required>
                                <label>Monto a Pagar *</label>
                            </div>
                            <small class="text-muted">Interés/mes: <?= formatMoney($interesMensualCalc) ?> · Máximo: <?= formatMoney($maxPermitido) ?></small>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="date" name="fecha_pago" value="<?= date('Y-m-d') ?>" required>
                                <label>Fecha de Pago *</label>
                            </div>
                        </div>
                    </div>
                    <div class="floating-group">
                        <textarea name="observaciones" placeholder=" " rows="2"></textarea>
                        <label>Observaciones</label>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Registrar Pago</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-info-circle"></i> Resumen de la Deuda</div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="info-label">Acreedor</span><span class="info-value"><?= sanitize($deuda['acreedor_nombre']) ?></span></li>
                    <li><span class="info-label">Concepto</span><span class="info-value"><?= sanitize($deuda['concepto'] ?? '-') ?></span></li>
                    <li><span class="info-label">Monto recibido</span><span class="info-value fw-bold"><?= formatMoney($deuda['monto_recibido']) ?></span></li>
                    <li><span class="info-label">Capital vigente</span><span class="info-value fw-bold" style="color:var(--danger);"><?= formatMoney($deuda['capital_vigente']) ?></span></li>
                    <li><span class="info-label">Tasa mensual</span><span class="info-value"><?= $deuda['tasa_mensual'] ?>%</span></li>
                    <li><span class="info-label">Interés mensual</span><span class="info-value"><?= formatMoney($interesMensualCalc) ?></span></li>
                    <li><span class="info-label">Abonos a capital</span><span class="info-value" style="color:var(--success);"><?= formatMoney($deuda['abonos_capital_pagados']) ?></span></li>
                    <li><span class="info-label">Total pagado</span><span class="info-value fw-bold" style="color:var(--success);"><?= formatMoney($totalPagado) ?></span></li>
                    <li><span class="info-label">Estado</span><span class="info-value"><span class="badge badge-<?= $deuda['estado'] ?>"><?= ucfirst($deuda['estado']) ?></span></span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
