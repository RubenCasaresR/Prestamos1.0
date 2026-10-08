<?php
$pageTitle = 'Nuevo Préstamo';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/breadcrumb.php';
requireLogin();
// P0: este script procesa POST antes de header.php, así que el guard global no aplica. Lector no crea.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !in_array(currentUserRole(), ['admin', 'cajero'], true)) {
    audit(getConnection(), 'acceso_denegado_post', null, null, 'prestamos/crear.php rol=' . currentUserRole());
    flash('danger', 'No tiene permisos para crear préstamos');
    redirect(BASE_PATH . 'index.php');
}

$pdo = getConnection();
$clientePreseleccionado = intval($_GET['cliente_id'] ?? 0);
$errors = [];

$capitalDisponible = 0;
try {
    $capitalDisponible = getCapitalApalancadoDisponible($pdo);
} catch (Exception $e) {
    $capitalDisponible = 0;
}

// Capital disponible por acreedor: el wizard lo muestra segun el acreedor
// elegido, para no mezclar el dinero de distintos acreedores.
$capitalPorAcreedor = [];

$capitalPropioInicial = floatval(getConfiguracion($pdo)['capital_propio_inicial'] ?? 0);
$capitalPropioDisponible = 0;
try {
    $capitalPropioDisponible = getCapitalPropioDisponible($pdo);
} catch (Exception $e) {
    $capitalPropioDisponible = $capitalPropioInicial;
}

try {
$clientesStmt = $pdo->query("SELECT id, nombre, apellido, cedula FROM clientes WHERE estado = 'activo' ORDER BY nombre");
$clientes = $clientesStmt->fetchAll();

$acreedoresStmt = $pdo->query("SELECT id, nombre FROM acreedores WHERE estado = 'activo' ORDER BY nombre");
$acreedores = $acreedoresStmt->fetchAll();
foreach ($acreedores as $ac) {
    try {
        $capitalPorAcreedor[(int)$ac['id']] = getCapitalApalancadoDisponible($pdo, (int)$ac['id']);
    } catch (Exception $e) {
        $capitalPorAcreedor[(int)$ac['id']] = 0;
    }
}
} catch (Exception $e) {
    $clientes = [];
    $acreedores = [];
}

// Detalle de deudas activas por acreedor (visible al elegir apalancado).
$deudasAcreedor = [];
try {
    $deuStmt = $pdo->query("SELECT acreedor_id, concepto, monto_recibido, tasa_mensual, capital_vigente, interes_mensual, fecha_inicio, fecha_vencimiento FROM mis_deudas WHERE estado = 'activo' ORDER BY fecha_inicio");
    foreach ($deuStmt->fetchAll() as $row) {
        $deudasAcreedor[(int)$row['acreedor_id']][] = [
            'concepto' => $row['concepto'],
            'monto' => floatval($row['monto_recibido']),
            'tasa' => floatval($row['tasa_mensual']),
            'capital' => floatval($row['capital_vigente']),
            'interes' => floatval($row['interes_mensual']),
            'inicio' => $row['fecha_inicio'],
            'vencimiento' => $row['fecha_vencimiento'],
        ];
    }
} catch (Exception $e) {
    $deudasAcreedor = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $clienteId = intval($_POST['cliente_id'] ?? 0);
        $monto = floatval($_POST['monto_prestado'] ?? 0);
        $tasa = floatval($_POST['tasa_interes'] ?? 0);
        $plazo = intval($_POST['plazo'] ?? 0);
        $tipoPlazo = 'meses';
        $observaciones = trim($_POST['observaciones'] ?? '');
        $fuenteFondos = $_POST['fuente_fondos'] ?? 'propio';
        $acreedorId = !empty($_POST['acreedor_id']) ? intval($_POST['acreedor_id']) : null;
        // trim() en vez de !empty(): un acreedor al 0% es valido y
        // !empty("0") devuelve false, lo tomaba por null.
        $tasaAcreedorRaw = trim((string)($_POST['tasa_acreedor'] ?? ''));
        $tasaAcreedor = ($tasaAcreedorRaw === '') ? null : floatval($tasaAcreedorRaw);
        $esIndefinido = isset($_POST['es_indefinido']) && $_POST['es_indefinido'] === '1';
        if ($esIndefinido) $plazo = 0;

        if (!in_array($fuenteFondos, ['propio', 'apalancado'])) $fuenteFondos = 'propio';
        // Interés siempre activo: la tasa capturada aplica tal cual (sin modo sin_interes).
        // Se permite prestar con capital negativo (sobregiro): el capital
        // disponible es informativo y no bloquea el registro.
        if (!numeroValido($_POST['monto_prestado'] ?? null)) $errors[] = 'El monto debe ser un número mayor a 0';
        if (!numeroValido($_POST['tasa_interes'] ?? null)) $errors[] = 'La tasa debe ser un número mayor a 0';
        if ($fuenteFondos === 'apalancado') {
            if (!$acreedorId) $errors[] = 'Seleccione un acreedor para fondos apalancados';
            else $capitalDisponible = getCapitalApalancadoDisponible($pdo, $acreedorId);
            if ($tasaAcreedorRaw === '' || !is_numeric($tasaAcreedorRaw)) $errors[] = 'Ingrese la tasa que cobra el acreedor (número 0-100)';
            elseif ($tasaAcreedor < 0) $errors[] = 'Ingrese la tasa que cobra el acreedor';
            if ($tasaAcreedor !== null && $tasaAcreedor > 100) $errors[] = 'La tasa del acreedor no puede exceder 100%';
        }
        if ($fuenteFondos === 'propio') {
            // El select de acreedor sigue habilitado en el DOM (esta oculto con
            // display:none), asi que sin esta limpieza se guardaba acreedor_id y
            // tasa_acreedor en prestamos de capital propio.
            $acreedorId = null;
            $tasaAcreedor = null;
            $capitalPropioDisponible = getCapitalPropioDisponible($pdo);
        }
        if ($clienteId <= 0) $errors[] = 'Seleccione un cliente';
        else {
            $chkCli = $pdo->prepare("SELECT id FROM clientes WHERE id = ? AND estado = 'activo'");
            $chkCli->execute([$clienteId]);
            if (!$chkCli->fetch()) $errors[] = 'El cliente seleccionado no existe o está inactivo';
        }
        if ($fuenteFondos === 'apalancado' && $acreedorId) {
            $chkAcr = $pdo->prepare("SELECT id FROM acreedores WHERE id = ? AND estado = 'activo'");
            $chkAcr->execute([$acreedorId]);
            if (!$chkAcr->fetch()) $errors[] = 'El acreedor seleccionado no existe o está inactivo';
        }
        if ($monto <= 0) $errors[] = 'El monto debe ser mayor a 0';
        if ($monto > 100000000) $errors[] = 'El monto excede el límite permitido';
        if ($tasa <= 0) $errors[] = 'La tasa de interés debe ser mayor a 0';
        if ($tasa > 100) $errors[] = 'La tasa de interés no puede exceder 100% mensual';
        if (!$esIndefinido) {
            if ($plazo <= 0) $errors[] = 'El plazo debe ser mayor a 0';
            if ($plazo > 600) $errors[] = 'El plazo excede el límite permitido (600 meses)';
        }
        $fechaPrestamo = trim((string)($_POST['fecha_prestamo'] ?? date('Y-m-d')));
        if (!fechaValida($fechaPrestamo)) $errors[] = 'Fecha de préstamo inválida (use fecha real YYYY-MM-DD)';
        else {
            if ($fechaPrestamo > date('Y-m-d')) $errors[] = 'La fecha de préstamo no puede ser futura';
            if ($fechaPrestamo < '2000-01-01') $errors[] = 'La fecha de préstamo no es válida';
        }
        if (mb_strlen($observaciones) > 1000) $errors[] = 'Las observaciones no pueden exceder 1000 caracteres';

        if (empty($errors)) {
            $interesMes = round($monto * ($tasa / 100), 2);
            // utilidad_mensual guarda SOLO el interes que se cobra al cliente.
            // El % del acreedor y el % del cliente viven sobre bases distintas,
            // asi que no se restan entre si. La columna conserva el nombre
            // historico; su significado ahora es "interes a cobrar por mes".
            $utilidadMensual = $interesMes;
            // Indefinidos: 1er mes por adelantado desde el día 1.
            // Definidos devengan mes a mes.
            $interesInicial = $esIndefinido ? interesInicialPrestamo($monto, $tasa) : 0.0;

            if ($esIndefinido) {
                $numCuotas = 0; $cuotaMensual = 0;
                $totalPagar = $monto; $totalIntereses = 0;
                $fechaVencimiento = null;
            } else {
                $numCuotas = numCuotasPorPlazo($plazo, $tipoPlazo);
                $cuotaMensual = calcularCuota($monto, $tasa, $plazo, $tipoPlazo);
                $totalPagar = round($cuotaMensual * $numCuotas, 2);
                $totalIntereses = round($totalPagar - $monto, 2);
                $intervalos = ['dias' => ' days', 'semanas' => ' weeks', 'meses' => ' months', 'anios' => ' years'];
                $fechaVencimiento = date('Y-m-d', strtotime($fechaPrestamo . " +$plazo " . $intervalos[$tipoPlazo]));
            }

            try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO prestamos (cliente_id, monto_prestado, tasa_interes, plazo_meses, tipo_plazo, num_cuotas, cuota_mensual, total_intereses, total_pagar, saldo_pendiente, interes_vigente, fecha_ultimo_devengo, fecha_prestamo, fecha_vencimiento, observaciones, fuente_fondos, acreedor_id, tasa_acreedor, utilidad_mensual) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$clienteId, $monto, $tasa, $plazo, $tipoPlazo, $numCuotas, $cuotaMensual, $totalIntereses, $totalPagar, $monto, $interesInicial, $fechaPrestamo, $fechaPrestamo, $fechaVencimiento, $observaciones, $fuenteFondos, $acreedorId, $tasaAcreedor, $utilidadMensual]);
            $prestamoId = intval($pdo->lastInsertId());

            // Prestamo apalancado: se registra en la MISMA transaccion la deuda
            // que asumiste con el acreedor. Asi el capital que te presta queda
            // contabilizado una sola vez y no se puede desincronizar. El
            // prestamo_id es lo que permite que el pago del cliente baje el
            // capital de esta deuda concreta y no de otra.
            $deudaId = 0;
            if ($fuenteFondos === 'apalancado') {
                $interesAcreedorMes = round($monto * ($tasaAcreedor / 100), 2);
                $deudaStmt = $pdo->prepare("INSERT INTO mis_deudas (acreedor_id, prestamo_id, concepto, fecha_inicio, fecha_vencimiento, monto_recibido, tasa_mensual, capital_vigente, interes_mensual, interes_pagado_hasta, notas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $deudaStmt->execute([
                    $acreedorId,
                    $prestamoId,
                    'Financiamiento del préstamo #' . $prestamoId,
                    $fechaPrestamo,
                    $fechaVencimiento ?: null,
                    $monto,
                    $tasaAcreedor,
                    $monto,
                    $interesAcreedorMes,
                    $fechaPrestamo,
                    'Generada automáticamente al registrar el préstamo #' . $prestamoId
                ]);
                $deudaId = intval($pdo->lastInsertId());
            }
            $pdo->commit();

            $msg = 'Préstamo registrado exitosamente';
            if ($deudaId > 0) {
                $msg .= '. Se registró la deuda #' . $deudaId . ' con el acreedor por ' . formatMoney($monto) . '.';
            }
            audit($pdo, 'crear_prestamo', 'prestamos', $prestamoId, "cliente=$clienteId monto=$monto tasa=$tasa plazo=" . ($esIndefinido ? 'indefinido' : $plazo) . " fuente=$fuenteFondos" . ($interesInicial > 0 ? " interes_inicial=$interesInicial" : '') . ($deudaId > 0 ? " deuda_creada=$deudaId" : ''));
            flash('success', $msg);
            redirect('index.php');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Error creando préstamo: " . $e->getMessage());
                $errors[] = 'Error al registrar el préstamo. Verifique los datos e intente de nuevo.';
            }
        }
    }
}

// Al fallar la validacion se reabre el wizard en el paso 2 (Datos del
// Prestamo) con los valores ya cargados, en vez de devolver al paso 1.
$clienteEnPost = intval($_POST['cliente_id'] ?? 0);
$pasoInicial = (!empty($errors) && $clienteEnPost > 0) ? 2 : 1;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-cash-stack"></i> Nuevo Préstamo</h4>
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

<div class="wizard-steps animate-in">
    <div class="wizard-step active" id="wizStep1">
        <div class="step-number">1</div>
        <span class="step-label">Cliente</span>
    </div>
    <div class="wizard-connector" id="wizConn1"></div>
    <div class="wizard-step" id="wizStep2">
        <div class="step-number">2</div>
        <span class="step-label">Datos del Préstamo</span>
    </div>
    <div class="wizard-connector" id="wizConn2"></div>
    <div class="wizard-step" id="wizStep3">
        <div class="step-number">3</div>
        <span class="step-label">Confirmar</span>
    </div>
</div>

<form method="POST" data-loading id="formPrestamo">
<?= csrfField() ?>
<div class="wizard-panel<?= $pasoInicial === 1 ? ' active' : '' ?>" id="panel1">
    <div class="card animate-in">
        <div class="card-header"><i class="bi bi-person"></i> Seleccionar Cliente</div>
        <div class="card-body">
            <div class="floating-group">
                <select name="cliente_id" id="clienteSelect" required>
                    <option value="">Seleccione un cliente...</option>
                    <?php foreach ($clientes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $clientePreseleccionado == $c['id'] ? 'selected' : '' ?>>
                            <?= sanitize($c['nombre'] . ' ' . $c['apellido'] . ' - ' . $c['cedula']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label>Cliente *</label>
            </div>
            <div class="text-end mt-3">
                <button type="button" class="btn btn-primary" onclick="wizardNext(2)">Siguiente <i class="bi bi-arrow-right"></i></button>
            </div>
        </div>
    </div>
</div>

<div class="wizard-panel<?= $pasoInicial === 2 ? ' active' : '' ?>" id="panel2">
    <div class="row">
        <div class="col-lg-7">
            <div class="card animate-in">
                <div class="card-header"><i class="bi bi-calculator"></i> Datos del Préstamo</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="number" name="monto_prestado" id="montoPrestado" placeholder=" " step="0.01" min="0.01" value="<?= sanitize($_POST['monto_prestado'] ?? '') ?>" required>
                                <label>Monto Prestado *</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="floating-group">
                                <input type="text" name="tasa_interes" id="tasaInteres" placeholder=" " inputmode="decimal" value="<?= sanitize($_POST['tasa_interes'] ?? '') ?>" required>
                                <label>Tasa Mensual (%) *</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="floating-group">
                                <input type="number" name="plazo" id="plazoInput" placeholder=" " min="1" value="<?= sanitize($_POST['plazo'] ?? '') ?>">
                                <label>Plazo (meses)</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="es_indefinido" id="esIndefinido" value="1" <?= isset($_POST['es_indefinido']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="esIndefinido" style="font-size:0.82rem;"><i class="bi bi-infinity"></i> Sin plazo (indefinido)</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="floating-group">
                                <input type="date" name="fecha_prestamo" id="fechaPrestamo" value="<?= sanitize($_POST['fecha_prestamo'] ?? date('Y-m-d')) ?>">
                                <label>Fecha de Inicio</label>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="floating-group">
                                <select name="fuente_fondos" id="fuenteFondos">
                                    <option value="propio" <?= ($_POST['fuente_fondos'] ?? 'propio') === 'propio' ? 'selected' : '' ?>>Propio</option>
                                    <option value="apalancado" <?= ($_POST['fuente_fondos'] ?? '') === 'apalancado' ? 'selected' : '' ?>>Apalancado</option>
                                </select>
                                <label>Fuente de Fondos</label>
                            </div>
                        </div>
                        <div class="col-md-6" id="acreedorGroup" style="display:none;">
                            <div class="floating-group">
                                <select name="acreedor_id" id="acreedorSelect">
                                    <option value="">Seleccione acreedor...</option>
                                    <?php foreach ($acreedores as $a): ?>
                                        <option value="<?= $a['id'] ?>" <?= ($_POST['acreedor_id'] ?? '') == $a['id'] ? 'selected' : '' ?>><?= sanitize($a['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label>Acreedor</label>
                            </div>
                        </div>
                    </div>
                    <div class="row" id="tasaAcreedorGroup" style="display:none;">
                        <div class="col-12">
                            <div class="floating-group">
                                <input type="number" name="tasa_acreedor" id="tasaAcreedor" placeholder=" " step="0.01" min="0" max="100" value="<?= sanitize($_POST['tasa_acreedor'] ?? '') ?>">
                                <label>Tasa que cobra el acreedor (%)</label>
                            </div>
                            <div style="font-size:0.72rem; color:var(--text-muted); margin-top:4px;">
                                <i class="bi bi-info-circle"></i> Es el % que el acreedor te cobra sobre el monto que te presta. Es independiente de la tasa que le cobras al cliente: no se restan entre si.
                            </div>
                        </div>
                    </div>
                    <div class="row" id="acreedorDetalleGroup" style="display:none;">
                        <div class="col-12">
                            <div style="padding:10px 14px; border:1px solid var(--border-strong); border-radius:8px; background:var(--bg); margin-bottom:16px;">
                                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--text-muted); margin-bottom:6px;"><i class="bi bi-bank"></i> Mi deuda con este acreedor</div>
                                <div id="acreedorDetalleBody" style="font-size:0.8rem;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="row" id="capitalAlertGroup" style="display:none;">
                        <div class="col-12">
                            <div class="alert alert-warning py-2 px-3 mb-0 mt-3" style="font-size:0.8rem;">
                                <i class="bi bi-exclamation-triangle"></i> <span id="capitalAlertText"></span>
                            </div>
                        </div>
                    </div>


                    <div class="floating-group">
                        <textarea name="observaciones" id="observaciones" placeholder=" " rows="2"><?= sanitize($_POST['observaciones'] ?? '') ?></textarea>
                        <label>Observaciones</label>
                    </div>
                    <div class="d-flex justify-content-between mt-3">
                        <button type="button" class="btn btn-outline-secondary" onclick="wizardNext(1)"><i class="bi bi-arrow-left"></i> Anterior</button>
                        <button type="button" class="btn btn-primary" onclick="wizardNext(3)">Siguiente <i class="bi bi-arrow-right"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card animate-in">
                <div class="card-header"><i class="bi bi-info-circle"></i> Resumen</div>
                <div class="card-body">
                    <ul class="info-list mb-3">
                        <li><span class="info-label">Monto</span><span class="info-value" id="resumenMonto">MXN 0.00</span></li>
                        <li id="resumenCapitalRow" style="display:none;"><span class="info-label" id="resumenCapitalLabel">Capital Disponible</span><span class="info-value fw-bold" id="resumenCapital">MXN 0.00</span></li>
                        <li id="resumenExcedenteRow" style="display:none;"><span class="info-label">Excedente</span><span class="info-value fw-bold" id="resumenExcedente" style="color:var(--success);">MXN 0.00</span></li>
                        <li><span class="info-label">Tasa mensual</span><span class="info-value" id="resumenTasa">0%</span></li>
                        <li><span class="info-label">Plazo</span><span class="info-value" id="resumenPlazo">0 meses</span></li>
                        <li><span class="info-label">Cuota</span><span class="info-value fw-bold" id="resumenCuota" style="color:var(--accent);">MXN 0.00</span></li>
                        <li><span class="info-label">Total intereses</span><span class="info-value" id="resumenIntereses" style="color:var(--danger);">MXN 0.00</span></li>
                        <li><span class="info-label">Total a pagar</span><span class="info-value fw-bold" id="resumenTotal">MXN 0.00</span></li>
                        <li><span class="info-label">Nº cuotas</span><span class="info-value" id="resumenCuotas">0</span></li>
                        <li id="resumenCobraRow" style="display:none;"><span class="info-label">— Interés a cobrar / mes</span><span class="info-value fw-bold" id="resumenCobra" style="color:var(--success);">MXN 0.00</span></li>
                        <li id="resumenPagaRow" style="display:none;"><span class="info-label">— Interés a pagar / mes</span><span class="info-value fw-bold" id="resumenPaga" style="color:var(--danger);">MXN 0.00</span></li>
                    </ul>
                    <div id="amortizacionPreview" style="display:none;">
                        <h6 class="mb-2" style="font-size:0.82rem; color:var(--text-muted);">Tabla de Amortización</h6>
                        <div style="max-height: 240px; overflow-y: auto;">
                            <table class="table table-sm amortizacion-table">
                                <thead><tr><th>#</th><th>Fecha Pago</th><th class="num">Capital</th><th class="num">Interés</th><th class="num">Saldo</th></tr></thead>
                                <tbody id="amortizacionBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="wizard-panel" id="panel3">
    <div class="card animate-in">
        <div class="card-header"><i class="bi bi-check-circle"></i> Confirmar Préstamo</div>
        <div class="card-body py-4">
            <div class="p-3 mb-4 text-center" style="background:var(--bg); border:1px solid var(--border-strong); border-radius:8px;">
                <div style="font-size:0.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; font-weight:600;">Total del préstamo</div>
                <div class="fw-bold" id="confTotalHero" style="font-size:1.6rem; color:var(--accent);">—</div>
                <div style="font-size:0.78rem; color:var(--text-muted);" id="confDesglose">—</div>
                <div class="form-text" style="font-size:0.75rem;"><i class="bi bi-percent"></i> Préstamo con interés según la tasa capturada.</div>
            </div>
            <div class="row mb-4">
                <div class="col-md-6">
                    <ul class="info-list">
                        <li><span class="info-label">Cliente</span><span class="info-value" id="confCliente">—</span></li>
                        <li><span class="info-label">Monto</span><span class="info-value" id="confMonto">—</span></li>
                        <li><span class="info-label">Tasa Mensual</span><span class="info-value" id="confTasa">—</span></li>
                        <li><span class="info-label">Plazo</span><span class="info-value" id="confPlazo">—</span></li>
                        <li><span class="info-label">Fecha de Inicio</span><span class="info-value" id="confFecha">—</span></li>
                        <li><span class="info-label">Fuente de Fondos</span><span class="info-value" id="confFondos">—</span></li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <ul class="info-list">
                        <li><span class="info-label">Cuota Mensual</span><span class="info-value fw-bold" id="confCuota" style="color:var(--accent);">—</span></li>
                        <li><span class="info-label">Total Intereses</span><span class="info-value" id="confIntereses" style="color:var(--danger);">—</span></li>
                        <li><span class="info-label">Total a Pagar</span><span class="info-value fw-bold" id="confTotal">—</span></li>
                        <li><span class="info-label">N° de Cuotas</span><span class="info-value" id="confCuotas">—</span></li>
                        <li id="confCobraRow" style="display:none;"><span class="info-label">— Interés a Cobrar / Mes</span><span class="info-value fw-bold" id="confCobra" style="color:var(--success);">—</span></li>
                        <li id="confPagaRow" style="display:none;"><span class="info-label">— Interés a Pagar / Mes</span><span class="info-value fw-bold" id="confPaga" style="color:var(--danger);">—</span></li>
                        <li><span class="info-label">Observaciones</span><span class="info-value" id="confObs">—</span></li>
                    </ul>
                </div>
            </div>
            <div class="text-center">
                <i class="bi bi-check-circle" style="font-size:2rem; color:var(--success); opacity:0.3;"></i>
                <h5 class="mt-2" style="font-size:0.95rem;">¿Registrar este préstamo?</h5>
                <div class="d-flex justify-content-center gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary" onclick="wizardNext(2)"><i class="bi bi-arrow-left"></i> Volver</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Confirmar y Registrar</button>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<script>
function wizardNext(step) {
    document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel' + step).classList.add('active');

    document.querySelectorAll('.wizard-step').forEach(s => { s.classList.remove('active'); s.classList.remove('completed'); });
    for (let i = 1; i <= 3; i++) {
        const el = document.getElementById('wizStep' + i);
        if (i < step) el.classList.add('completed');
        if (i === step) el.classList.add('active');
    }

    document.getElementById('wizConn1').classList.toggle('completed', step > 1);
    document.getElementById('wizConn2').classList.toggle('completed', step > 2);
    window.scrollTo({ top: 0, behavior: 'smooth' });

    if (step === 3) {
        llenarConfirmacion();
    }
}

function llenarConfirmacion() {
        var sel = document.getElementById('clienteSelect');
        document.getElementById('confCliente').textContent = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '—';
        document.getElementById('confMonto').textContent = document.getElementById('resumenMonto').textContent;
        document.getElementById('confTasa').textContent = document.getElementById('resumenTasa').textContent;
        document.getElementById('confPlazo').textContent = document.getElementById('resumenPlazo').textContent;
        document.getElementById('confFecha').textContent = document.getElementById('fechaPrestamo').value;
        document.getElementById('confCuota').textContent = document.getElementById('resumenCuota').textContent;
        document.getElementById('confIntereses').textContent = document.getElementById('resumenIntereses').textContent;
        document.getElementById('confTotal').textContent = document.getElementById('resumenTotal').textContent;
        document.getElementById('confCuotas').textContent = document.getElementById('resumenCuotas').textContent;
        document.getElementById('confCobra').textContent = document.getElementById('resumenCobra').textContent;
        document.getElementById('confPaga').textContent = document.getElementById('resumenPaga').textContent;
        const esApC = document.getElementById('fuenteFondos').value === 'apalancado';
        document.getElementById('confCobraRow').style.display = esApC ? '' : 'none';
        document.getElementById('confPagaRow').style.display = esApC ? '' : 'none';
        document.getElementById('confObs').textContent = document.getElementById('observaciones').value || '(sin observaciones)';

        var fuente = document.getElementById('fuenteFondos');
        var fondosText = fuente.options[fuente.selectedIndex].text;
        if (fuente.value === 'apalancado') {
            var acreedor = document.getElementById('acreedorSelect');
            fondosText += ' - ' + (acreedor.options[acreedor.selectedIndex] ? acreedor.options[acreedor.selectedIndex].text : '');
        }
        document.getElementById('confFondos').textContent = fondosText;

        // Héroe del total con interés cargado (definidos e indefinidos).
        // Indefinidos: 1er mes por adelantado desde el día 1.
        var esInd = document.getElementById('esIndefinido').checked;
        if (esInd) {
            var mInd = parseFloat(document.getElementById('montoPrestado').value) || 0;
            var tInd = parseFloat(document.getElementById('tasaInteres').value) || 0;
            var iniInd = Math.round(mInd * (tInd / 100) * 100) / 100;
            document.getElementById('confTotalHero').textContent = formatMoney(mInd + iniInd);
            document.getElementById('confDesglose').textContent = formatMoney(mInd) + ' + ' + formatMoney(iniInd) + ' de intereses';
        } else {
            document.getElementById('confTotalHero').textContent = document.getElementById('confTotal').textContent;
            document.getElementById('confDesglose').textContent = document.getElementById('confMonto').textContent + ' + ' + document.getElementById('confIntereses').textContent + ' de intereses';
        }
}

document.addEventListener('DOMContentLoaded', function() {
    const montoInput = document.getElementById('montoPrestado');
    const tasaInput = document.getElementById('tasaInteres');
    const plazoInput = document.getElementById('plazoInput');
    const fuenteSelect = document.getElementById('fuenteFondos');
    const tasaAcreedorInput = document.getElementById('tasaAcreedor');
    const acreedorSelect = document.getElementById('acreedorSelect');
    const capitalAcreedorGlobal = <?= jsonSeguro(floatval($capitalDisponible)) ?>;
    const capitalPropioDisponible = <?= jsonSeguro(floatval($capitalPropioDisponible)) ?>;
    const basePath = <?= jsonSeguro(getBasePath()) ?>;
    const capitalPorAcreedor = <?= jsonSeguro($capitalPorAcreedor) ?>;
    const deudasAcreedor = <?= jsonSeguro($deudasAcreedor) ?>;

    function escapeHtml(s) { const d = document.createElement('div'); d.textContent = (s === null || s === undefined) ? '' : String(s); return d.innerHTML; }

    function fmtFechaCorta(f) { if (!f) return '—'; const p = String(f).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : f; }

    function pintarDetalleAcreedor() {
        const group = document.getElementById('acreedorDetalleGroup');
        const body = document.getElementById('acreedorDetalleBody');
        if (!group || !body) return;
        const esApalancado = fuenteSelect.value === 'apalancado';
        const aId = parseInt(acreedorSelect.value) || 0;
        if (!esApalancado || !aId) { group.style.display = 'none'; return; }
        const deudas = deudasAcreedor[aId] || [];
        if (deudas.length === 0) {
            body.innerHTML = '<span style="color:var(--text-muted);">Sin deudas activas con este acreedor.</span>';
            group.style.display = '';
            return;
        }
        let html = '';
        let totCap = 0, totInt = 0;
        deudas.forEach(function(d, i) {
            totCap += d.capital; totInt += d.interes;
            html += '<div style="display:flex; justify-content:space-between; gap:8px; padding:3px 0;' + (i > 0 ? ' border-top:1px dashed var(--border-strong);' : '') + '">'
                + '<span>' + escapeHtml(d.concepto || 'Deuda') + ' · ' + d.tasa + '% <span style="color:var(--text-muted);">(' + fmtFechaCorta(d.inicio) + (d.vencimiento ? ' → ' + fmtFechaCorta(d.vencimiento) : '') + ')</span></span>'
                + '<span class="num" style="white-space:nowrap;">Cap ' + formatMoney(d.capital) + ' · Int ' + formatMoney(d.interes) + '</span></div>';
        });
        if (deudas.length > 1) html += '<div style="display:flex; justify-content:space-between; font-weight:700; padding-top:4px;"><span>Total (' + deudas.length + ')</span><span class="num">Cap ' + formatMoney(totCap) + ' · Int ' + formatMoney(totInt) + '</span></div>';
        body.innerHTML = html;
        group.style.display = '';
    }

    function formatMoney2(n) {
        return 'MXN ' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Capital disponible segun la fuente: en apalancado es solo el del acreedor
    // elegido, nunca la suma global (no se presta el dinero de B con el de A).
    function capitalPorFuente() {
        if (fuenteSelect.value !== 'apalancado') return capitalPropioDisponible;
        const aId = String(acreedorSelect.value || '');
        if (!aId) return 0;
        const v = capitalPorAcreedor[aId];
        return typeof v === 'number' ? v : 0;
    }

    // Los dos flujos se muestran por separado, nunca netos: el % del acreedor
    // y el % del cliente viven sobre bases distintas.
    function pintarMovimientos(cobra, paga, esApalancado) {
        const cobraRow = document.getElementById('resumenCobraRow');
        const pagaRow = document.getElementById('resumenPagaRow');
        if (cobraRow) cobraRow.style.display = '';
        if (pagaRow) pagaRow.style.display = esApalancado ? '' : 'none';
        document.getElementById('resumenCobra').textContent = formatMoney(cobra);
        document.getElementById('resumenPaga').textContent = formatMoney(paga);
    }

    function actualizarAlertCapital(monto) {
        const alertGroup = document.getElementById('capitalAlertGroup');
        const alertText = document.getElementById('capitalAlertText');
        const esApa = fuenteSelect.value === 'apalancado';
        const cap = capitalPorFuente();
        if (monto > cap) {
            alertGroup.style.display = '';
            alertText.innerHTML = esApa
                ? 'Atención: el monto excede el capital disponible de este acreedor (' + formatMoney2(cap) + '). Se registrará igual, con capital negativo (' + formatMoney2(cap - monto) + ').'
                : 'Atención: el monto excede tu capital propio disponible (' + formatMoney2(cap) + '). Se registrará igual, con capital negativo (' + formatMoney2(cap - monto) + ').';
            return;
        }
        alertGroup.style.display = 'none';
    }

    // El % del acreedor NO se deduce de mis_deudas: es la tasa que el acreedor
    // cobra por el capital que te presta, independiente de tu deuda con el.
    // Solo se refresca el detalle de tu deuda y el capital disponible.
    function actualizarReferenciaAcreedor() {
        pintarDetalleAcreedor();
    }

    function toggleAcreedor() {
        const esApalancado = fuenteSelect.value === 'apalancado';
        document.getElementById('acreedorGroup').style.display = esApalancado ? '' : 'none';
        document.getElementById('tasaAcreedorGroup').style.display = esApalancado ? '' : 'none';
        // Sin 'required' dinámico: los paneles del wizard se ocultan con
        // display:none y un required oculto/vacío bloquea el submit con
        // "An invalid form control is not focusable". Valida el servidor.
        if (esApalancado) actualizarReferenciaAcreedor();
        else {
            document.getElementById('acreedorDetalleGroup').style.display = 'none';
            document.getElementById('capitalAlertGroup').style.display = 'none';
        }
    }

    fuenteSelect.addEventListener('change', function() { toggleAcreedor(); calcular(); });
    if (acreedorSelect) acreedorSelect.addEventListener('change', function() { actualizarReferenciaAcreedor(); calcular(); });
    if (tasaAcreedorInput) tasaAcreedorInput.addEventListener('input', actualizarReferenciaAcreedor);

    function calcular() {
        const monto = parseFloat(montoInput.value) || 0;
        const tasa = parseFloat(tasaInput.value) || 0;
        // Interés siempre activo con la tasa capturada.
        const tasaCalc = tasa;
        const indefinido = document.getElementById('esIndefinido').checked;
        const plazo = indefinido ? 0 : (parseInt(plazoInput.value) || 0);
        const tipoPlazo = 'meses';
        const cfg = TIPO_PLAZO[tipoPlazo];
        const tasaAcreedor = parseFloat(tasaAcreedorInput ? tasaAcreedorInput.value : 0) || 0;
        const esApalancado = fuenteSelect.value === 'apalancado';

        document.getElementById('resumenMonto').textContent = formatMoney(monto);
        document.getElementById('resumenTasa').textContent = tasa + '% mensual';
        document.getElementById('resumenPlazo').textContent = indefinido ? 'Indefinido' : (plazo > 0 ? plazo + ' meses' : 'Indefinido');

        const capitalRow = document.getElementById('resumenCapitalRow');
        const excedenteRow = document.getElementById('resumenExcedenteRow');
        const capDisponible = capitalPorFuente();
        capitalRow.style.display = '';
        document.getElementById('resumenCapitalLabel').textContent =
            esApalancado ? 'Capital del acreedor' : 'Capital propio disponible';
        document.getElementById('resumenCapital').textContent = formatMoney(capDisponible);
        if (monto > 0) {
            const excedente = capDisponible - monto;
            excedenteRow.style.display = '';
            const excedenteEl = document.getElementById('resumenExcedente');
            excedenteEl.textContent = formatMoney(excedente);
            excedenteEl.style.color = excedente >= 0 ? 'var(--success)' : 'var(--danger)';
        } else {
            excedenteRow.style.display = 'none';
        }
        actualizarAlertCapital(monto);

        if (monto > 0 && plazo > 0) {
            const fechaInicio = document.getElementById('fechaPrestamo').value;
            const resultado = generarTablaAmortizacion(monto, tasaCalc, plazo, tipoPlazo, fechaInicio);
            document.getElementById('resumenCuota').textContent = formatMoney(resultado.cuotaPeriodo);
            document.getElementById('resumenIntereses').textContent = formatMoney(resultado.totalIntereses);
            document.getElementById('resumenTotal').textContent = formatMoney(resultado.totalPagar);
            document.getElementById('resumenCuotas').textContent = resultado.numCuotas + ' cuota(s)';

            const tbody = document.getElementById('amortizacionBody');
            tbody.innerHTML = '';
            resultado.tabla.forEach(row => {
                tbody.innerHTML += '<tr><td>' + row.cuota + '</td><td>' + row.fecha + '</td><td class="num">' + formatMoney(row.capital) + '</td><td class="num">' + formatMoney(row.interes) + '</td><td class="num">' + formatMoney(row.saldo) + '</td></tr>';
            });
            document.getElementById('amortizacionPreview').style.display = 'block';

            const interesMes = monto * (tasaCalc / 100);
            const interesAcreedor = monto * (tasaAcreedor / 100);
            pintarMovimientos(interesMes, interesAcreedor, esApalancado);
        } else {
            document.getElementById('resumenCuota').textContent = indefinido ? 'Interés mensual' : formatMoney(0);
            document.getElementById('resumenIntereses').textContent = indefinido ? 'Según saldo' : formatMoney(0);
            document.getElementById('resumenTotal').textContent = indefinido ? formatMoney(monto) : formatMoney(0);
            document.getElementById('resumenCuotas').textContent = indefinido ? '∞' : '0';
            document.getElementById('amortizacionPreview').style.display = 'none';
            const interesMesInd = monto * (tasaCalc / 100);
            const interesAcreedorInd = monto * (tasaAcreedor / 100);
            pintarMovimientos(interesMesInd, interesAcreedorInd, esApalancado);
        }
    }

    function toggleIndefinido() {
        const ind = document.getElementById('esIndefinido').checked;
        plazoInput.disabled = ind;
        if (ind) plazoInput.value = '';
        calcular();
    }
    document.getElementById('esIndefinido').addEventListener('change', toggleIndefinido);

    montoInput.addEventListener('input', calcular);
    tasaInput.addEventListener('input', calcular);
    plazoInput.addEventListener('input', calcular);
    document.getElementById('fechaPrestamo').addEventListener('input', calcular);
    if (tasaAcreedorInput) tasaAcreedorInput.addEventListener('input', calcular);

    toggleAcreedor();
    toggleIndefinido();

    // Si la validacion fallo, el servidor reabre el wizard en el paso 2 con los
    // valores ya cargados en vez de devolver al paso 1.
    const pasoInicial = <?= (int)$pasoInicial ?>;
    if (pasoInicial > 1) wizardNext(pasoInicial);
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
