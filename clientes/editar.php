<?php
$pageTitle = 'Editar Cliente';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$id = intval($_GET['id'] ?? 0);
$errors = [];

$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    flash('danger', 'Cliente no encontrado');
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $apellido = trim($_POST['apellido'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $referencia = trim($_POST['referencia_personal'] ?? '');
        $estado = $_POST['estado'] ?? 'activo';
        if (!in_array($estado, ['activo', 'inactivo'], true)) $estado = 'activo';

        if (empty($nombre)) $errors[] = 'El nombre es obligatorio';
        if (empty($apellido)) $errors[] = 'El apellido es obligatorio';
        if (empty($cedula)) $errors[] = 'La cédula es obligatoria';
        if (empty($telefono)) $errors[] = 'El teléfono es obligatorio';
        if (empty($direccion)) $errors[] = 'La dirección es obligatoria';
        if (mb_strlen($nombre) > 100 || mb_strlen($apellido) > 100 || mb_strlen($cedula) > 150 || mb_strlen($telefono) > 20 || mb_strlen($direccion) > 255 || mb_strlen($email) > 150 || mb_strlen($referencia) > 150) $errors[] = 'Un campo excede su longitud máxima';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no es válido';
        if (!preg_match('/^[0-9+\-\s()]{6,20}$/', $telefono)) $errors[] = 'El teléfono no es válido (6-20 dígitos)';

        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT id FROM clientes WHERE cedula = ? AND id != ?");
            $stmt->execute([$cedula, $id]);
            if ($stmt->fetch()) $errors[] = 'Ya existe otro cliente con esta cédula';
        }

        if (empty($errors)) {
            try {
            $stmt = $pdo->prepare("UPDATE clientes SET nombre=?, apellido=?, cedula=?, telefono=?, direccion=?, email=?, referencia_personal=?, estado=? WHERE id=?");
            $stmt->execute([$nombre, $apellido, $cedula, $telefono, $direccion, $email, $referencia, $estado, $id]);
            audit($pdo, 'editar_cliente', 'clientes', $id, "nombre=$nombre $apellido cedula=$cedula estado=$estado");
            flash('success', 'Cliente actualizado exitosamente');
            redirect('index.php');
            } catch (PDOException $e) {
                error_log("Error editando cliente: " . $e->getMessage());
                if ($e->getCode() == '23000') $errors[] = 'Ya existe otro cliente con esta cédula.';
                else $errors[] = 'Error al actualizar. Intente de nuevo.';
            }
        }
    }
} else {
    $nombre = $cliente['nombre'];
    $apellido = $cliente['apellido'];
    $cedula = $cliente['cedula'];
    $telefono = $cliente['telefono'];
    $direccion = $cliente['direccion'];
    $email = $cliente['email'];
    $referencia = $cliente['referencia_personal'];
    $estado = $cliente['estado'];
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-pencil"></i> Editar Cliente</h4>
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
                        <input type="text" name="nombre" placeholder=" " value="<?= sanitize($nombre) ?>" required maxlength="100">
                        <label>Nombre *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="apellido" placeholder=" " value="<?= sanitize($apellido) ?>" required maxlength="100">
                        <label>Apellido *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="cedula" placeholder=" " value="<?= sanitize($cedula) ?>" required maxlength="20">
                        <label>Cédula *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="telefono" placeholder=" " value="<?= sanitize($telefono) ?>" required maxlength="20">
                        <label>Teléfono *</label>
                    </div>
                </div>
                <div class="col-md-12">
                    <div class="floating-group">
                        <input type="text" name="direccion" placeholder=" " value="<?= sanitize($direccion) ?>" required maxlength="255">
                        <label>Dirección *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="email" name="email" placeholder=" " value="<?= sanitize($email) ?>" maxlength="150">
                        <label>Email</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="referencia_personal" placeholder=" " value="<?= sanitize($referencia) ?>" maxlength="150">
                        <label>Referencia Personal</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <select name="estado">
                            <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activo</option>
                            <option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivo</option>
                        </select>
                        <label>Estado</label>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Actualizar Cliente</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
