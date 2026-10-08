<?php
require_once __DIR__ . '/../config/database.php';

if (isLoggedIn()) {
    header("Location: " . BASE_PATH . "index.php");
    exit();
}

$errors = [];

// P0: rate-limit 5 intentos / 15min por IP+usuario (fail-closed, sin DoS por archivo).
function loginBloqueado($key) {
    $dir = sys_get_temp_dir() . '/prestamos_login';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $f = $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
    $d = ['n' => 0, 't' => time()];
    if (is_file($f)) { $j = json_decode(@file_get_contents($f), true); if (is_array($j)) $d = $j + $d; }
    if ((time() - intval($d['t'])) > 900) $d = ['n' => 0, 't' => time()];
    return [$f, $d];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de seguridad inválido. Intente de nuevo.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        [$lockFile, $lock] = loginBloqueado($ip . '_' . mb_strtolower($username));
        if (intval($lock['n']) >= 5) {
            $errors[] = 'Demasiados intentos. Espere 15 minutos.';
        } elseif (empty($username) || empty($password)) {
            $errors[] = 'Ingrese usuario y contraseña';
        } else {
            try {
                $pdo = getConnection();
                $stmt = $pdo->prepare("SELECT id, username, nombre, password_hash, rol FROM usuarios WHERE username = ? AND estado = 'activo'");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                // P0: timing uniforme para no enumerar usuarios existentes.
                $ok = false;
                if ($user) $ok = password_verify($password, $user['password_hash']);
                else { password_verify($password, '$2y$10$usesomesillystringfore7hnu.rNO.7zRDG60uFhE5r0k'); usleep(random_int(50000, 150000)); }

                if ($user && $ok) {
                    @unlink($lockFile);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_username'] = $user['username'];
                    $_SESSION['user_nombre'] = $user['nombre'];
                    $_SESSION['user_rol'] = in_array($user['rol'] ?? '', ['admin', 'cajero', 'lector'], true) ? $user['rol'] : 'lector';
                    $_SESSION['session_start'] = time();
                    $_SESSION['last_activity'] = time();
                    session_regenerate_id(true);
                    rotateCSRFToken();
                    audit($pdo, 'login_ok', 'usuarios', $user['id'], 'login ' . $username);
                    session_write_close();
                    header("Location: " . BASE_PATH . "index.php");
                    exit();
                } else {
                    $lock['n'] = intval($lock['n']) + 1;
                    @file_put_contents($lockFile, json_encode($lock), LOCK_EX);
                    try { audit($pdo, 'login_failed', 'usuarios', null, 'login fallido ' . $username . ' ip=' . $ip); } catch (Exception $e) {}
                    usleep(random_int(50000, 150000));
                    $errors[] = 'Usuario o contraseña incorrectos';
                }
            } catch (PDOException $e) {
                error_log("Error de login: " . $e->getMessage());
                $errors[] = 'Error al conectar con la base de datos';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Préstamos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        body { display:flex; justify-content:center; align-items:center; min-height:100vh; background:var(--bg); }
        .login-box { max-width:400px; width:100%; padding:40px; }
        .login-brand { text-align:center; margin-bottom:32px; }
        .login-brand i { font-size:2.5rem; color:var(--accent); }
        .login-brand h4 { margin-top:8px; font-weight:600; }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="login-brand">
            <i class="bi bi-bank2"></i>
            <h4>Sistema de Préstamos</h4>
            <p style="color:var(--text-muted); font-size:0.85rem;">Inicie sesión para continuar</p>
        </div>

        <?php $flash = getFlash(); if ($flash): $flashType = in_array($flash['type'] ?? '', ['success','danger','warning','info'], true) ? $flash['type'] : 'info'; ?>
            <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
                <?= sanitize($flash['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?= sanitize($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="floating-group">
                <input type="text" name="username" placeholder=" " value="<?= sanitize($_POST['username'] ?? '') ?>" required autofocus>
                <label>Usuario *</label>
            </div>
            <div class="floating-group">
                <input type="password" name="password" placeholder=" " value="" required autocomplete="current-password">
                <label>Contraseña *</label>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-3" style="padding:12px;">
                <i class="bi bi-box-arrow-in-right"></i> Iniciar Sesión
            </button>
        </form>
    </div>

    <script src="../assets/js/app.js"></script>
</body>
</html>
