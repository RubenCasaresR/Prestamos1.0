<?php
$pageTitle = 'Nuevo Cliente';
require_once __DIR__ . '/../includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $pdo = getConnection();
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $referencia = trim($_POST['referencia_personal'] ?? '');

        // QA: nombre/apellido obligatorios + topes BD (evita vacío y 1406 oculto)
        if ($nombre === '' || $apellido === '') $errors[] = 'Nombre y apellido son obligatorios';
        if (mb_strlen($nombre) > 100 || mb_strlen($apellido) > 100) $errors[] = 'Nombre/apellido máx 100 caracteres';
        if (mb_strlen($telefono) > 20 || mb_strlen($direccion) > 255 || mb_strlen($email) > 150 || mb_strlen($referencia) > 150) $errors[] = 'Un campo excede su longitud máxima';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no es válido';
        if ($telefono !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $telefono)) $errors[] = 'El teléfono no es válido (6-20 dígitos)';
        if ($cedula !== '' && (strlen($cedula) > 150 || strlen($cedula) < 3)) $errors[] = 'La razón social no es válida (3-150 caracteres)';

        if (empty($errors) && $cedula !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clientes WHERE cedula = ?");
            $stmt->execute([$cedula]);
            if ($stmt->fetch()) $errors[] = 'Ya existe un cliente con esta razón social';
        }

        if (empty($errors)) {
            try {
            $cedulaDb = $cedula !== '' ? $cedula : null;
            $stmt = $pdo->prepare("INSERT INTO clientes (nombre, apellido, cedula, telefono, direccion, email, referencia_personal) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $apellido, $cedulaDb, $telefono, $direccion, $email, $referencia]);
            audit($pdo, 'crear_cliente', 'clientes', intval($pdo->lastInsertId()), "nombre=$nombre $apellido cedula=$cedula");
            flash('success', 'Cliente registrado exitosamente');
            redirect('index.php');
            } catch (PDOException $e) {
                error_log("Error creando cliente: " . $e->getMessage());
                if ($e->getCode() == '23000') {
                    $errors[] = 'Ya existe un cliente con esta razón social (evite doble clic).';
                } else {
                    $errors[] = 'Error al guardar. Intente de nuevo.';
                }
            }
        }
    }
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-person-plus"></i> Nuevo Cliente</h4>
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

<div class="card animate-in">
    <div class="card-body">
        <form method="POST" data-loading>
            <?= csrfField() ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="nombre" placeholder=" " value="<?= sanitize($nombre ?? '') ?>" maxlength="100">
                        <label>Nombre</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="apellido" placeholder=" " value="<?= sanitize($apellido ?? '') ?>" maxlength="100">
                        <label>Apellido</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="cedula" placeholder=" " value="<?= sanitize($cedula ?? '') ?>" maxlength="150">
                        <label>Razón Social</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="telefono" placeholder=" " value="<?= sanitize($telefono ?? '') ?>" maxlength="20">
                        <label>Teléfono</label>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="floating-group">
                        <input type="text" name="direccion" placeholder=" " value="<?= sanitize($direccion ?? '') ?>" maxlength="255">
                        <label>Dirección</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="email" name="email" placeholder=" " value="<?= sanitize($email ?? '') ?>" maxlength="150">
                        <label>Email</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="referencia_personal" placeholder=" " value="<?= sanitize($referencia ?? '') ?>" maxlength="150">
                        <label>Referencia Personal</label>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar Cliente</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
