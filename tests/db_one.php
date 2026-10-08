<?php
// Helper CLI: ejecuta SQL pasada por argv[1] usando la conexión del proyecto (.env).
// SELECT -> primera columna de cada fila; otro -> filas afectadas.
function dbEnv($key, $def) {
    static $env = null;
    if ($env === null) {
        $env = [];
        $f = __DIR__ . '/../.env';
        if (file_exists($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                if (strpos($line, '=') !== false) {
                    [$k, $v] = explode('=', $line, 2);
                    $env[trim($k)] = trim($v);
                }
            }
        }
    }
    return $env[$key] ?? $def;
}
$pdo = new PDO(
    'mysql:host=' . dbEnv('DB_HOST', '127.0.0.1') . ';dbname=' . dbEnv('DB_NAME', 'prestamos_db') . ';charset=utf8mb4',
    dbEnv('DB_USER', 'root'),
    dbEnv('DB_PASS', ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$sql = $argv[1];
if (preg_match('/^\s*SELECT/i', $sql)) {
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) echo $r[0] . "\n";
} else {
    echo $pdo->exec($sql) . "\n";
}
