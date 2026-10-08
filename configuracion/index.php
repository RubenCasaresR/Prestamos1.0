<?php
$pageTitle = 'Configuración';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
// Toda la pantalla de configuración es solo-admin (incluye capital,
// tasas y acciones destructivas). El POST ya lo exige; el GET también.
requireRole('admin');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // P0: toda la configuración exige admin (antes cualquier lector podía cambiar tasa/moneda/capital).
    requireRole('admin');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } elseif (($_POST['limpiar_db'] ?? '') === '1') {
        // P0: TRUNCATE exige doble confirmación + password del admin. Sin eso se bloquea y audita.
        $confirm = trim($_POST['confirmar_limpieza'] ?? '');
        $pwd = $_POST['password_confirm'] ?? '';
        $me = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ? AND estado='activo'");
        $me->execute([$_SESSION['user_id'] ?? 0]);
        $hash = $me->fetchColumn();
        if ($confirm !== 'ELIMINAR' || !$hash || !password_verify($pwd, $hash)) {
            audit($pdo, 'limpiar_db_bloqueado', null, null, 'Intento sin confirmación/password');
            $errors[] = 'Limpieza bloqueada: escriba ELIMINAR y su contraseña para confirmar. Haga un backup antes.';
        } else {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            $pdo->exec("TRUNCATE TABLE pagos_deudas");
            $pdo->exec("TRUNCATE TABLE pagos");
            $pdo->exec("TRUNCATE TABLE prestamos");
            $pdo->exec("TRUNCATE TABLE mis_deudas");
            $pdo->exec("TRUNCATE TABLE acreedores");
            $pdo->exec("TRUNCATE TABLE clientes");
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

            $clientesSeed = [
                [1, 'María Elena', 'García López', '12345678', '5551234567', 'Av. Reforma 123, Col. Centro', 'maria.garcia@email.com', 'Juan García - 5559876543', '2025-10-15 10:00:00'],
                [2, 'Roberto Carlos', 'Hernández Cruz', '23456789', '5552345678', 'Calle Juárez 456, Col. Norte', 'roberto.h@email.com', 'Pedro Hernández - 5558765432', '2025-11-20 11:30:00'],
                [3, 'Ana Patricia', 'Martínez Silva', '34567890', '5553456789', 'Blvd. Insurgentes 789, Col. Sur', 'ana.martinez@email.com', 'Laura Silva - 5557654321', '2026-01-10 09:15:00'],
                [4, 'Carlos Eduardo', 'Rodríguez Díaz', '45678901', '5554567890', 'Calle Morelos 321, Col. Este', 'carlos.rodriguez@email.com', 'Mario Díaz - 5556543210', '2025-09-05 14:00:00'],
                [5, 'Laura Sofía', 'Sánchez Ruiz', '56789012', '5555678901', 'Av. Universidad 654, Col. Oeste', 'laura.sanchez@email.com', 'Carmen Ruiz - 5555432109', '2026-02-18 08:45:00'],
                [6, 'Fernando', 'Torres Luna', '67890123', '5556789012', 'Calle Hidalgo 987, Col. Jardín', 'fernando.torres@email.com', 'Roberto Luna - 5554321098', '2026-03-25 16:20:00'],
                [7, 'Patricia Alejandra', 'Flores Castro', '78901234', '5557890123', 'Blvd. López Mateos 147, Col. Roma', 'patricia.f@email.com', 'Sofía Castro - 5553210987', '2025-08-12 12:00:00'],
                [8, 'Diego Armando', 'Morales Vega', '89012345', '5558901234', 'Av. Insurgentes 258, Col. Condesa', 'diego.morales@email.com', 'Andrés Vega - 5552109876', '2026-04-01 10:30:00'],
                [9, 'Isabel Cristina', 'Torres Blanco', '90123456', '5559012345', 'Calle Gobernador 369, Col. Centro', 'isabel.t@email.com', 'Elena Blanco - 5551098765', '2026-05-15 09:00:00'],
                [10, 'Miguel Ángel', 'Ríos Pereira', '01234567', '5550123456', 'Calle Madero 741, Col. Juárez', 'miguel.rios@email.com', 'Jorge Pereira - 5550987654', '2025-07-20 11:00:00'],
            ];
            $stmtC = $pdo->prepare("INSERT INTO clientes (id, nombre, apellido, cedula, telefono, direccion, email, referencia_personal, fecha_registro, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
            foreach ($clientesSeed as $c) {
                $stmtC->execute($c);
            }

            $acreedoresSeed = [
                [1, 'Banco Nacional S.A.', '5551112233', 'creditos@bancona.com', 'BNA850101XXX', 'Ciudad de México', 'Banco principal - Línea de crédito aprobada', '2025-06-01 10:00:00'],
                [2, 'Financiera del Norte S.A.', '5552223344', 'prestamos@findelno.com', 'FNO920515XXX', 'Monterrey', 'Financiera para crédito comercial', '2025-09-15 14:00:00'],
                [3, 'Juan Pérez Martínez', '5553334455', 'juan.perez@gmail.com', null, 'Guadalajara', 'Préstamo personal - Amigo de confianza', '2026-01-05 09:30:00'],
                [4, 'Grupo Financiero MX S.C.', '5554445566', 'inversiones@gfmx.com', 'GFM030320XXX', 'Puebla', 'Institucional - Fondos de inversión', '2025-11-01 11:00:00'],
            ];
            $stmtA = $pdo->prepare("INSERT INTO acreedores (id, nombre, telefono, email, rfc, ciudad, notas, fecha_registro, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
            foreach ($acreedoresSeed as $a) {
                $stmtA->execute($a);
            }

            $pdo->exec("UPDATE configuracion SET nombre_empresa = 'Préstamos MX - Grupo Financiero', tasa_mora_diaria = 1.00, dias_gracia = 3, dias_gracia_interes = 3, dias_inactividad = 30, moneda = 'MXN', capital_propio_inicial = 0, preguntar_capital_inicial = 1 WHERE id = 1");
            $pdo->exec("ALTER TABLE clientes AUTO_INCREMENT = 11");
            $pdo->exec("ALTER TABLE prestamos AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE pagos AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE acreedores AUTO_INCREMENT = 5");
            $pdo->exec("ALTER TABLE mis_deudas AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE pagos_deudas AUTO_INCREMENT = 1");

            flash('success', 'Base de datos limpiada: préstamos, pagos y deudas eliminados. Clientes y acreedores de prueba restaurados.');
            audit($pdo, 'limpiar_db', null, null, 'TRUNCATE pagos, prestamos, deudas; seeds restaurados');
        } catch (Exception $e) {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            error_log("Error limpiando BD: " . $e->getMessage());
            flash('danger', 'Error al limpiar la base de datos');
        }
        redirect('index.php');
        } // fin else confirmación P0
    } elseif (($_POST['reset_onboarding'] ?? '') === '1') {
        $pdo->exec("UPDATE configuracion SET capital_propio_inicial = 0, preguntar_capital_inicial = 1 WHERE id = 1");
        flash('success', 'Onboarding reiniciado. Al entrar al Dashboard se volverá a preguntar por el capital inicial.');
        redirect('index.php');
    } else {
        $nombreEmpresa = trim($_POST['nombre_empresa'] ?? '');
        $rif = trim($_POST['rif'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $email = trim($_POST['email'] ?? '');
        // QA: números estrictos (antes "abc"→0 sobrescribía capital/tasa con 0).
        foreach (['tasa_mora_diaria','dias_gracia','dias_gracia_interes','dias_inactividad','capital_propio_inicial'] as $k) {
            if (!isset($_POST[$k]) || trim((string)$_POST[$k]) === '' || !is_numeric(trim((string)$_POST[$k]))) $errors[] = "El campo $k debe ser un número";
        }
        $tasaMora = floatval($_POST['tasa_mora_diaria'] ?? 1);
        $diasGracia = intval($_POST['dias_gracia'] ?? 0);
        $diasGraciaInteres = intval($_POST['dias_gracia_interes'] ?? 0);
        $diasInactividad = intval($_POST['dias_inactividad'] ?? 30);
        $moneda = strtoupper(trim($_POST['moneda'] ?? 'MXN'));
        $capitalPropioInicial = floatval($_POST['capital_propio_inicial'] ?? 0);

        if (empty($nombreEmpresa)) $errors[] = 'El nombre de la empresa es obligatorio';
        if (mb_strlen($nombreEmpresa) > 200 || mb_strlen($rif) > 20 || mb_strlen($telefono) > 20 || mb_strlen($direccion) > 255 || mb_strlen($email) > 150) $errors[] = 'Un campo excede su longitud máxima';
        if ($tasaMora < 0 || $tasaMora > 100) $errors[] = 'La tasa de mora debe estar entre 0 y 100%';
        if ($diasGracia < 0 || $diasGracia > 365) $errors[] = 'Los días de gracia deben estar entre 0 y 365';
        if ($diasGraciaInteres < 0 || $diasGraciaInteres > 365) $errors[] = 'Los días de gracia de interés deben estar entre 0 y 365';
        if ($diasInactividad < 0 || $diasInactividad > 365) $errors[] = 'Los días de inactividad deben estar entre 0 y 365';
        if ($capitalPropioInicial < 0 || $capitalPropioInicial > 1000000000) $errors[] = 'El capital propio inicial no es válido';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no es válido';
        if (!preg_match('/^[A-Z]{3,10}$/', $moneda)) $errors[] = 'La moneda debe ser un código de 3-10 letras (ej. MXN)';

        if (empty($errors)) {
            try {
            $stmt = $pdo->prepare("UPDATE configuracion SET nombre_empresa=?, rif=?, telefono=?, direccion=?, email=?, tasa_mora_diaria=?, dias_gracia=?, dias_gracia_interes=?, dias_inactividad=?, moneda=?, capital_propio_inicial=? WHERE id=1");
            $stmt->execute([$nombreEmpresa, $rif, $telefono, $direccion, $email, $tasaMora, $diasGracia, $diasGraciaInteres, $diasInactividad, $moneda, $capitalPropioInicial]);
            audit($pdo, 'actualizar_config', 'configuracion', 1, $nombreEmpresa);
            if ($capitalPropioInicial > 0) {
                $pdo->exec("UPDATE configuracion SET preguntar_capital_inicial = 0 WHERE id = 1");
            }
            flash('success', 'Configuración actualizada exitosamente');
            redirect('index.php');
            } catch (PDOException $e) {
                error_log("Error config: " . $e->getMessage());
                $errors[] = 'Error al guardar (revise longitudes). Intente de nuevo.';
            }
        }
    }
}

$config = getConfiguracion($pdo);

$capitalPropioInicial = floatval($config['capital_propio_inicial'] ?? 0);
$capitalPropioDisponible = 0;
try {
    $capitalPropioDisponible = getCapitalPropioDisponible($pdo);
} catch (Exception $e) {
    $capitalPropioDisponible = $capitalPropioInicial;
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-gear"></i> Configuración</h4>
        </div>
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

<form method="POST" id="formConfig" data-loading>
<?= csrfField() ?>
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-building"></i> Datos de la Empresa</div>
            <div class="card-body">
                <div class="floating-group">
                    <input type="text" name="nombre_empresa" placeholder=" " value="<?= sanitize($config['nombre_empresa']) ?>" required maxlength="200">
                    <label>Nombre de la Empresa *</label>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="floating-group">
                            <input type="text" name="rif" placeholder=" " value="<?= sanitize($config['rif'] ?? '') ?>" maxlength="20">
                            <label>RIF / NIT</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="floating-group">
                            <input type="text" name="telefono" placeholder=" " value="<?= sanitize($config['telefono'] ?? '') ?>" maxlength="20">
                            <label>Teléfono</label>
                        </div>
                    </div>
                </div>
                <div class="floating-group">
                    <input type="text" name="direccion" placeholder=" " value="<?= sanitize($config['direccion'] ?? '') ?>" maxlength="255">
                    <label>Dirección</label>
                </div>
                <div class="floating-group">
                    <input type="email" name="email" placeholder=" " value="<?= sanitize($config['email'] ?? '') ?>" maxlength="150">
                    <label>Email</label>
                </div>
                <div class="floating-group">
                    <input type="text" name="moneda" placeholder=" " value="<?= sanitize($config['moneda']) ?>" maxlength="10">
                    <label>Símbolo de Moneda</label>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-piggy-bank"></i> Capital Propio</div>
            <div class="card-body">
                <div class="floating-group">
                    <input type="number" name="capital_propio_inicial" placeholder=" " step="0.01" min="0" value="<?= $capitalPropioInicial ?>">
                    <label>Capital Propio Inicial *</label>
                    <div class="form-text">Dinero que aportas de tu bolsillo al negocio. Se suma al capital disponible para préstamos con fuente "propio".</div>
                </div>
                <div class="alert alert-success py-2 px-3 mb-0 mt-3" style="font-size:0.8rem;">
                    <i class="bi bi-cash-stack"></i> Tu capital propio disponible actual es
                    <strong><?= formatMoney($capitalPropioDisponible) ?></strong>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-clock-history"></i> Gracia de Interés</div>
            <div class="card-body">
                <!-- Campos de mora ocultos: no se usan (se conservan para no perder valores al guardar) -->
                <div class="floating-group" style="display:none;">
                    <input type="number" name="tasa_mora_diaria" placeholder=" " step="0.01" min="0" max="100" value="<?= $config['tasa_mora_diaria'] ?>">
                    <label>Tasa de Mora Diaria (%) *</label>
                    <div class="form-text">Porcentaje diario sobre el saldo pendiente por cada día de atraso.</div>
                </div>
                <div class="floating-group" style="display:none;">
                    <input type="number" name="dias_gracia" placeholder=" " min="0" max="365" value="<?= $config['dias_gracia'] ?>">
                    <label>Días de Gracia *</label>
                    <div class="form-text">Días después del vencimiento antes de aplicar mora.</div>
                </div>
                <div class="floating-group">
                    <input type="number" name="dias_gracia_interes" placeholder=" " min="0" max="365" value="<?= intval($config['dias_gracia_interes'] ?? 0) ?>">
                    <label>Días de Gracia de Interés *</label>
                    <div class="form-text">En cada corte mensual: días extra para pagar sin que se genere el interés de ese mes.</div>
                </div>
                <div class="floating-group" style="display:none;">
                    <input type="number" name="dias_inactividad" placeholder=" " min="0" max="365" value="<?= $config['dias_inactividad'] ?? 30 ?>">
                    <label>Días de Inactividad *</label>
                    <div class="form-text">En préstamos sin plazo: días sin pagos (tras la gracia) antes de aplicar mora.</div>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<form method="POST" id="formDanger" class="mt-3 d-flex flex-wrap gap-2 align-items-end" data-loading>
    <?= csrfField() ?>
    <button type="submit" name="reset_onboarding" value="1" class="btn btn-outline-secondary btn-sm" onclick="return confirmarAccion('¿Reiniciar el onboarding? Se pondrá el capital inicial en 0 y el modal de bienvenida volverá a aparecer al entrar al Dashboard.');"><i class="bi bi-arrow-counterclockwise"></i> Reiniciar onboarding</button>
    <input type="text" name="confirmar_limpieza" placeholder="Escriba ELIMINAR" class="form-control form-control-sm" style="max-width:170px;" autocomplete="off">
    <input type="password" name="password_confirm" placeholder="Su contraseña" class="form-control form-control-sm" style="max-width:170px;" autocomplete="current-password">
    <button type="submit" name="limpiar_db" value="1" class="btn btn-outline-danger btn-sm" onclick="return confirmarLimpiarDb();"><i class="bi bi-database-dash"></i> Limpiar base de datos</button>
</form>
<form method="POST" action="backup.php" class="mt-2 d-flex flex-wrap gap-2 align-items-end">
    <?= csrfField() ?>
    <input type="password" name="password_confirm" placeholder="Su contraseña (backup)" class="form-control form-control-sm" style="max-width:220px;" autocomplete="current-password" required>
    <button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-download"></i> Descargar backup SQL (sin hashes)</button>
</form>

<div class="card animate-in mb-4">
    <div class="card-body">
        <div class="text-end">
            <button type="submit" form="formConfig" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar Configuración</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
