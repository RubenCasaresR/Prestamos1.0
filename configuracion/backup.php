<?php
require_once __DIR__ . '/../config/database.php';
requireLogin();
requireRole('admin');

// P0: backup solo por POST+CSRF+re-auth, sin password_hash, sin GET CSRFable.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    flash('danger', 'Use el formulario de backup con su contraseña');
    redirect(BASE_PATH . 'configuracion/index.php');
}
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    flash('danger', 'Token de seguridad inválido');
    redirect(BASE_PATH . 'configuracion/index.php');
}
$pdo = getConnection();
$me = $pdo->prepare("SELECT password_hash FROM usuarios WHERE id = ? AND estado='activo'");
$me->execute([$_SESSION['user_id'] ?? 0]);
$hash = $me->fetchColumn();
if (!$hash || !password_verify($_POST['password_confirm'] ?? '', $hash)) {
    audit($pdo, 'backup_bloqueado', null, null, 'Intento backup sin re-auth');
    flash('danger', 'Contraseña incorrecta. Backup bloqueado.');
    redirect(BASE_PATH . 'configuracion/index.php');
}

$tablas = ['clientes', 'prestamos', 'pagos', 'acreedores', 'mis_deudas', 'pagos_deudas', 'configuracion', 'auditoria'];
$out = "-- Backup Sistema Préstamos " . date('Y-m-d H:i:s') . " (P0: sin tabla usuarios)\nSET FOREIGN_KEY_CHECKS=0;\n";
foreach ($tablas as $t) {
    try {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
        if (!$create) continue;
        $out .= "\nDROP TABLE IF EXISTS `$t`;\n" . ($create['Create Table'] ?? '') . ";\n";
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll();
        foreach ($rows as $r) {
            $cols = array_map(fn($c) => "`$c`", array_keys($r));
            $vals = array_map(fn($v) => $v === null ? "NULL" : $pdo->quote($v), array_values($r));
            $out .= "INSERT INTO `$t` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
        }
    } catch (Exception $e) { $out .= "-- omitida $t: " . $e->getMessage() . "\n"; }
}
$out .= "SET FOREIGN_KEY_CHECKS=1;\n";
audit($pdo, 'backup_db', null, null, 'Descarga backup manual P0 sin usuarios');
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="backup_' . date('Ymd_His') . '.sql"');
header('Content-Length: ' . strlen($out));
header('X-Content-Type-Options: nosniff');
echo $out;
exit();
