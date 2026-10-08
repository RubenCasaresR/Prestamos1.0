<?php
$pageTitle = 'Nuevo Usuario';
require_once __DIR__ . '/../includes/header.php';
requireRole('admin');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $pdo = getConnection();
        $username = trim($_POST['username'] ?? '');
        $nombre = trim($_POST['nombre'] ?? '');
        $password = $_POST['password'] ?? '';
        $rol = $_POST['rol'] ?? 'cajero';
        if (!in_array($rol, ['admin', 'cajero', 'lector'], true)) $rol = 'cajero';
        if (strlen($username) < 3 || strlen($username) > 50) $errors[] = 'Usuario debe tener 3-50 caracteres';
        if (empty($nombre)) $errors[] = 'El nombre es obligatorio';
        if (strlen($password) < 8) $errors[] = 'La contraseña debe tener al menos 8 caracteres';
        if (empty($errors)) {
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE username = ?");
            $chk->execute([$username]);
            if ($chk->fetch()) $errors[] = 'Ya existe ese nombre de usuario';
        }
        if (empty($errors)) {
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO usuarios (username, nombre, password_hash, rol, estado) VALUES (?, ?, ?, ?, 'activo')");
                $stmt->execute([$username, $nombre, $hash, $rol]);
                audit($pdo, 'crear_usuario', 'usuarios', $pdo->lastInsertId(), "username=$username rol=$rol");
                flash('success', 'Usuario creado exitosamente');
                redirect('index.php');
            } catch (Exception $e) {
                error_log("Error creando usuario: " . $e->getMessage());
                $errors[] = 'Error al crear el usuario';
            }
        }
    }
}
?>
<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-plus-lg"></i> Nuevo Usuario</h4>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
    </div>
</div>
<?php if (!empty($errors)): ?>
    <div class="alert alert-danger animate-in"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="card animate-in">
    <div class="card-body">
        <form method="POST" data-loading>
            <?= csrfField() ?>
            <div class="floating-group"><input type="text" name="username" placeholder=" " value="<?= sanitize($_POST['username'] ?? '') ?>" required maxlength="50"><label>Usuario *</label></div>
            <div class="floating-group"><input type="text" name="nombre" placeholder=" " value="<?= sanitize($_POST['nombre'] ?? '') ?>" required maxlength="100"><label>Nombre *</label></div>
            <div class="floating-group"><input type="password" name="password" placeholder=" " required minlength="8" autocomplete="new-password"><label>Contraseña (mín. 8) *</label></div>
            <div class="floating-group">
                <select name="rol"><option value="admin">admin</option><option value="cajero" selected>cajero</option><option value="lector">lector</option></select>
                <label>Rol</label>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar</button></div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
