<?php
$pageTitle = 'Nueva Deuda';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$acreedorPreseleccionado = intval($_GET['acreedor_id'] ?? 0);
$errors = [];

try {
$acreedoresStmt = $pdo->query("SELECT id, nombre FROM acreedores WHERE estado = 'activo' ORDER BY nombre");
$acreedores = $acreedoresStmt->fetchAll();
} catch (Exception $e) {
    $acreedores = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $acreedorId = intval($_POST['acreedor_id'] ?? 0);
        $concepto = trim($_POST['concepto'] ?? '');
        $fechaInicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
        $fechaVencimiento = $_POST['fecha_vencimiento'] ?? '';
        $montoRecibido = floatval($_POST['monto_recibido'] ?? 0);
        $tasaMensual = floatval($_POST['tasa_mensual'] ?? 0);
        $notas = trim($_POST['notas'] ?? '');

        if ($acreedorId <= 0) $errors[] = 'Seleccione un acreedor';
        else {
            $chk = $pdo->prepare("SELECT id FROM acreedores WHERE id = ? AND estado = 'activo'");
            $chk->execute([$acreedorId]);
            if (!$chk->fetch()) $errors[] = 'El acreedor no existe o está inactivo';
        }
        if (!numeroValido($_POST['monto_recibido'] ?? null)) $errors[] = 'El monto debe ser un número mayor a 0';
        elseif ($montoRecibido <= 0) $errors[] = 'El monto debe ser mayor a 0';
        if ($montoRecibido > 100000000) $errors[] = 'El monto excede el límite permitido';
        if (!isset($_POST['tasa_mensual']) || trim((string)$_POST['tasa_mensual']) === '' || !is_numeric(trim((string)$_POST['tasa_mensual']))) $errors[] = 'La tasa debe ser un número (0-100)';
        elseif ($tasaMensual < 0 || $tasaMensual > 100) $errors[] = 'La tasa debe estar entre 0 y 100%';
        if (empty($fechaInicio)) $errors[] = 'La fecha de inicio es obligatoria';
        elseif (!fechaValida($fechaInicio)) $errors[] = 'Fecha de inicio inválida (use fecha real YYYY-MM-DD)';
        elseif ($fechaInicio > hoyFecha()) $errors[] = 'La fecha de inicio no puede ser futura';
        if ($fechaVencimiento !== '') {
            if (!fechaValida($fechaVencimiento)) $errors[] = 'Fecha de vencimiento inválida (use fecha real YYYY-MM-DD)';
            elseif ($fechaVencimiento < $fechaInicio) $errors[] = 'El vencimiento no puede ser anterior al inicio';
        }
        if (mb_strlen($concepto) > 200) $errors[] = 'El concepto no puede exceder 200 caracteres';
        if (mb_strlen($notas) > 1000) $errors[] = 'Las notas no pueden exceder 1000 caracteres';

        if (empty($errors)) {
            try {
            $interesMensual = round($montoRecibido * ($tasaMensual / 100), 2);
            $interesPagadoHasta = $fechaInicio;
            $stmt = $pdo->prepare("INSERT INTO mis_deudas (acreedor_id, concepto, fecha_inicio, fecha_vencimiento, monto_recibido, tasa_mensual, capital_vigente, interes_mensual, interes_pagado_hasta, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$acreedorId, $concepto, $fechaInicio, $fechaVencimiento ?: null, $montoRecibido, $tasaMensual, $montoRecibido, $interesMensual, $interesPagadoHasta, $notas]);
            audit($pdo, 'crear_deuda', 'mis_deudas', intval($pdo->lastInsertId()), "acreedor=$acreedorId monto=$montoRecibido tasa=$tasaMensual");
            flash('success', 'Deuda registrada exitosamente');
            redirect('index.php');
            } catch (Exception $e) {
                error_log("Error creando deuda: " . $e->getMessage());
                $errors[] = 'Error al registrar la deuda. Intente de nuevo.';
            }
        }
    }
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-credit-card"></i> Nueva Deuda</h4>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
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
    <div class="col-lg-8">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-credit-card"></i> Datos de la Deuda</div>
            <div class="card-body">
                <form method="POST" data-loading>
                    <?= csrfField() ?>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="floating-group">
                                <select name="acreedor_id" required>
                                    <option value="">Seleccione un acreedor...</option>
                                    <?php $selectedId = intval($_POST['acreedor_id'] ?? $acreedorPreseleccionado); ?>
                                    <?php foreach ($acreedores as $a): ?>
                                        <option value="<?= $a['id'] ?>" <?= $selectedId == $a['id'] ? 'selected' : '' ?>>
                                            <?= sanitize($a['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Acreedor *</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="number" name="monto_recibido" placeholder=" " step="0.01" min="0.01" value="<?= sanitize($_POST['monto_recibido'] ?? '') ?>" required>
                                <label>Monto Recibido *</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="number" name="tasa_mensual" placeholder=" " step="0.01" min="0" value="<?= sanitize($_POST['tasa_mensual'] ?? '') ?>" required>
                                <label>Tasa Mensual (%) *</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="date" name="fecha_inicio" value="<?= sanitize($_POST['fecha_inicio'] ?? date('Y-m-d')) ?>" required>
                                <label>Fecha Inicio *</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="date" name="fecha_vencimiento" value="<?= sanitize($_POST['fecha_vencimiento'] ?? '') ?>">
                                <label>Fecha Vencimiento (opcional)</label>
                            </div>
                        </div>
                    </div>
                    <div class="floating-group">
                        <input type="text" name="concepto" placeholder=" " value="<?= sanitize($_POST['concepto'] ?? '') ?>" maxlength="200">
                        <label>Concepto</label>
                    </div>
                    <div class="floating-group">
                        <textarea name="notas" placeholder=" " rows="2"><?= sanitize($_POST['notas'] ?? '') ?></textarea>
                        <label>Notas</label>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar Deuda</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-info-circle"></i> Resumen</div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="info-label">Monto</span><span class="info-value" id="resumenMonto">MXN 0.00</span></li>
                    <li><span class="info-label">Tasa mensual</span><span class="info-value" id="resumenTasa">0%</span></li>
                    <li><span class="info-label">Interés mensual</span><span class="info-value fw-bold" id="resumenInteres" style="color:var(--danger);">MXN 0.00</span></li>
                    <li><span class="info-label">Interés anual (est.)</span><span class="info-value" id="resumenInteresAnual">MXN 0.00</span></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const montoInput = document.querySelector('[name="monto_recibido"]');
    const tasaInput = document.querySelector('[name="tasa_mensual"]');

    function calcular() {
        const monto = parseFloat(montoInput.value) || 0;
        const tasa = parseFloat(tasaInput.value) || 0;
        const interesMes = monto * (tasa / 100);
        document.getElementById('resumenMonto').textContent = formatMoney(monto);
        document.getElementById('resumenTasa').textContent = tasa + '% mensual';
        document.getElementById('resumenInteres').textContent = formatMoney(interesMes);
        document.getElementById('resumenInteresAnual').textContent = formatMoney(interesMes * 12);
    }

    if (montoInput) montoInput.addEventListener('input', calcular);
    if (tasaInput) tasaInput.addEventListener('input', calcular);
    calcular();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
