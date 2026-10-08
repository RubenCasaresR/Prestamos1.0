<?php
require_once __DIR__ . '/../config/database.php';

// P2: auditar cierre antes de destruir sesión.
try { if (!empty($_SESSION['user_id'])) audit(getConnection(), 'logout', 'usuarios', intval($_SESSION['user_id']), 'logout ' . ($_SESSION['user_username'] ?? '')); } catch (Exception $e) {}
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
}
session_destroy();
session_start();
session_regenerate_id(true);

flash('success', 'Sesión cerrada exitosamente');
header("Location: " . BASE_PATH . "auth/login.php");
exit();
