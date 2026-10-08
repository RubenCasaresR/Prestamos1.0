<?php
$pageTitle = 'Nuevo Acreedor';
require_once __DIR__ . '/../includes/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido';
    } else {
        $pdo = getConnection();
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $rfc = trim($_POST['rfc'] ?? '');
        $ciudad = trim($_POST['ciudad'] ?? '');
        $notas = trim($_POST['notas'] ?? '');

        if (empty($nombre)) $errors[] = 'El nombre es obligatorio';
        if (mb_strlen($nombre) > 100 || mb_strlen($telefono) > 20 || mb_strlen($email) > 150 || mb_strlen($rfc) > 20 || mb_strlen($ciudad) > 100) $errors[] = 'Un campo excede su longitud máxima';
        if (mb_strlen($notas) > 1000) $errors[] = 'Las notas no pueden exceder 1000 caracteres';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'El email no es válido';
        if ($telefono !== '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $telefono)) $errors[] = 'El teléfono no es válido';
        if (strlen($notas) > 1000) $errors[] = 'Las notas no pueden exceder 1000 caracteres';

        if (empty($errors)) {
            try {
            $stmt = $pdo->prepare("INSERT INTO acreedores (nombre, telefono, email, rfc, ciudad, notas) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$nombre, $telefono, $email, $rfc, $ciudad, $notas]);
            audit($pdo, 'crear_acreedor', 'acreedores', intval($pdo->lastInsertId()), "nombre=$nombre");
            flash('success', 'Acreedor registrado exitosamente');
            redirect('index.php');
            } catch (PDOException $e) {
                error_log("Error creando acreedor: " . $e->getMessage());
                $errors[] = 'Error al guardar el acreedor. Intente de nuevo.';
            }
        }
    }
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-person-plus"></i> Nuevo Acreedor</h4>
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
                        <input type="text" name="nombre" placeholder=" " value="<?= sanitize($nombre ?? '') ?>" required maxlength="100">
                        <label>Nombre / Razón Social *</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="telefono" placeholder=" " value="<?= sanitize($telefono ?? '') ?>" maxlength="20">
                        <label>Teléfono</label>
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
                        <input type="text" name="rfc" placeholder=" " value="<?= sanitize($rfc ?? '') ?>" maxlength="20">
                        <label>RFC / NIT</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="floating-group">
                        <input type="text" name="ciudad" placeholder=" " value="<?= sanitize($ciudad ?? '') ?>" maxlength="100">
                        <label>Ciudad</label>
                    </div>
                </div>
            </div>
            <div class="floating-group">
                <textarea name="notas" placeholder=" " rows="2"><?= sanitize($notas ?? '') ?></textarea>
                <label>Notas</label>
            </div>
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Guardar Acreedor</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
