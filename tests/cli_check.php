<?php
// Suite CLI de verificación — SOLO lectura + transacciones con ROLLBACK (no deja writes).
// Uso: php tests/cli_check.php
error_reporting(E_ALL & ~E_WARNING);
require __DIR__ . '/../config/database.php';

$pass = 0; $fail = 0;
function ok($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "PASS $label\n"; }
    else { $fail++; echo "FAIL $label\n"; }
}
function hasColumn($pdo, $t, $c) {
    $t = preg_replace('/[^a-z_]/', '', $t); $c = preg_replace('/[^a-z_]/', '', $c);
    $s = $pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return (bool)$s->fetch();
}
function hasIndex($pdo, $t, $i) {
    foreach ($pdo->query("SHOW INDEX FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['Key_name'] === $i) return true;
    }
    return false;
}

$pdo = getConnection();
echo "== Esquema Fase 1/3/indefinidos/interes_vigente ==\n";
ok('uq_pagos_prestamo_cuota', hasIndex($pdo, 'pagos', 'uq_pagos_prestamo_cuota'));
ok('idx_prestamos_estado_venc', hasIndex($pdo, 'prestamos', 'idx_prestamos_estado_venc'));
ok('idx_pagos_fecha', hasIndex($pdo, 'pagos', 'idx_pagos_fecha'));
ok('usuarios.rol', hasColumn($pdo, 'usuarios', 'rol'));
ok('auditoria existe', (bool)$pdo->query("SHOW TABLES LIKE 'auditoria'")->fetch());
ok('config.dias_inactividad', hasColumn($pdo, 'configuracion', 'dias_inactividad'));
ok('config.dias_gracia_interes', hasColumn($pdo, 'configuracion', 'dias_gracia_interes'));
ok('prestamos.interes_vigente', hasColumn($pdo, 'prestamos', 'interes_vigente'));
ok('prestamos.fecha_ultimo_devengo', hasColumn($pdo, 'prestamos', 'fecha_ultimo_devengo'));
ok('pagos.tipo_pago', hasColumn($pdo, 'pagos', 'tipo_pago'));

echo "== Helpers negocio ==\n";
ok('calcularMora base', calcularMora(10000, 10, 1.0, 3) == 700.0);
ok('calcularMora con gracia', calcularMora(10000, 2, 1.0, 3) == 0.0);
ok('devengarInteres 30d', devengarInteres(10000, 5, 30) == 500.0);
ok('devengarInteres 0d', devengarInteres(10000, 5, 0) == 0.0);
ok('devengarInteres dias neg', devengarInteres(10000, 5, -9) == 0.0);
ok('escapeLike', escapeLike('a%b_c\\d') === 'a\\%b\\_c\\\\d');
ok('esIndefinido plazo0', esPrestamoIndefinido(['plazo_meses' => 0, 'fecha_vencimiento' => null]) === true);
ok('esIndefinido normal', esPrestamoIndefinido(['plazo_meses' => 6, 'fecha_vencimiento' => '2026-12-01']) === false);
ok('calcularCuota 0%', calcularCuota(12000, 0, 12, 'meses') == 1000.0);
ok('cuota sin_interes 12000/6', calcularCuota(12000, 0, 6, 'meses') == 2000.0);
ok('interesInicial indef 500000x5', interesInicialPrestamo(500000, 5) == 25000.0);
ok('interesInicial tasa0', interesInicialPrestamo(500000, 0) == 0.0);
ok('morosoInteres 2m+75000', esMorosoPorInteres(2, 75000.0) === true);
ok('morosoInteres 1m+1', esMorosoPorInteres(1, 0.01) === true);
ok('morosoInteres 0m con inicial', esMorosoPorInteres(0, 25000.0) === false);
ok('morosoInteres sin deuda', esMorosoPorInteres(3, 0.0) === false);
$cfgAll = getConfiguracion($pdo);
$cmbInd = esMorosoCombinado($pdo, ['saldo_pendiente' => 500000, 'estado' => 'activo', 'plazo_meses' => 0, 'fecha_vencimiento' => null, 'fecha_prestamo' => '2026-06-22', 'fecha_ultimo_devengo' => '2026-06-22', 'tasa_interes' => 5, 'interes_vigente' => 25000], $cfgAll);
ok('combinado indefinido con interes', $cmbInd === true);
$cmbDia = esMorosoCombinado($pdo, ['saldo_pendiente' => 500000, 'estado' => 'activo', 'plazo_meses' => 0, 'fecha_vencimiento' => null, 'fecha_prestamo' => hoyFecha(), 'fecha_ultimo_devengo' => hoyFecha(), 'tasa_interes' => 5, 'interes_vigente' => 25000], $cfgAll);
ok('combinado indefinido al dia', $cmbDia === false);
$cmbPag = esMorosoCombinado($pdo, ['saldo_pendiente' => 0, 'estado' => 'pagado', 'plazo_meses' => 0, 'fecha_vencimiento' => null, 'fecha_prestamo' => '2026-01-01', 'tasa_interes' => 5, 'interes_vigente' => 0], $cfgAll);
ok('combinado pagado nunca', $cmbPag === false);
ok('tasaPorPeriodo dias', abs(tasaPorPeriodo(30, 'dias') - 1.0) < 0.0001);
ok('mesesCumpl 01/08-18/09=1', mesesCalendarioCumplidos('2026-08-01', '2026-09-18') === 1);
ok('mesesCumpl 01/08-01/10=2', mesesCalendarioCumplidos('2026-08-01', '2026-10-01') === 2);
ok('mesesCumpl mismo dia=0', mesesCalendarioCumplidos('2026-08-01', '2026-08-01') === 0);
ok('mesesCumpl mes en curso=0', mesesCalendarioCumplidos('2026-08-15', '2026-09-14') === 0);
ok('mesesCumpl 31/01-28/02=0', mesesCalendarioCumplidos('2026-01-31', '2026-02-28') === 0);
ok('mesesCumpl ref anterior=0', mesesCalendarioCumplidos('2026-09-01', '2026-08-01') === 0);
$ic = interesCausadoPorMeses(500000, 5, '2026-08-01', '2026-09-18');
ok('interesMes 1 mes=25000', $ic['meses'] === 1 && $ic['mensual'] == 25000.0 && $ic['total'] == 25000.0);
$ic2 = interesCausadoPorMeses(500000, 5, '2026-08-01', '2026-10-01');
ok('interesMes 2 meses=50000', $ic2['meses'] === 2 && $ic2['total'] == 50000.0);
ok('mesesCumpl gracia 01/08-03/09=0', mesesCalendarioCumplidos('2026-08-01', '2026-09-03', 3) === 0);
ok('mesesCumpl gracia 01/08-04/09=1', mesesCalendarioCumplidos('2026-08-01', '2026-09-04', 3) === 1);
ok('mesesCumpl gracia0 sin cambio', mesesCalendarioCumplidos('2026-08-01', '2026-09-18', 0) === 1);
$icg = interesCausadoPorMeses(500000, 5, '2026-08-01', '2026-09-03', 3);
ok('interes gracia en ventana=0', $icg['meses'] === 0 && $icg['total'] == 0.0);
$icg2 = interesCausadoPorMeses(500000, 5, '2026-08-01', '2026-09-04', 3);
ok('interes gracia vencida=25000', $icg2['meses'] === 1 && $icg2['total'] == 25000.0);

echo "== generarCalendario (fixture, sin BD) ==\n";
$fx = ['id' => 1, 'monto_prestado' => 12000, 'tasa_interes' => 5, 'tipo_plazo' => 'meses', 'num_cuotas' => 3, 'cuota_mensual' => calcularCuota(12000, 5, 3, 'meses'), 'plazo_meses' => 3, 'fecha_prestamo' => '2026-01-01', 'fecha_vencimiento' => '2026-04-01'];
$cal = generarCalendario($fx, [], 0);
ok('cal 3 cuotas', count($cal['tabla']) === 3);
ok('cal proxima=1', $cal['proxima']['cuota'] === 1);
ok('cal suma capital=monto', abs(array_sum(array_column($cal['tabla'], 'capital')) - 12000) < 0.05);
$pagada = [1 => ['total_pagado' => $cal['tabla'][0]['total']]];
$cal2 = generarCalendario($fx, $pagada, 0);
ok('cal cuota1 pagada', $cal2['tabla'][0]['estado'] === 'pagada');
ok('cal proxima=2', $cal2['proxima']['cuota'] === 2);
$calInd = generarCalendario(['plazo_meses' => 0, 'fecha_vencimiento' => null, 'num_cuotas' => 0], [], 0);
ok('cal indefinido vacio', $calInd['tabla'] === [] && $calInd['proxima'] === null);

echo "== Constraints en transacción (rollback) ==\n";
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO clientes (nombre, apellido, cedula, telefono, direccion, estado) VALUES ('T','C','TESTCLI001','000','d','activo')")->execute();
    $cid = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO prestamos (cliente_id, monto_prestado, tasa_interes, plazo_meses, tipo_plazo, num_cuotas, cuota_mensual, total_intereses, total_pagar, saldo_pendiente, fecha_prestamo, fecha_vencimiento, fuente_fondos) VALUES (?,1000,5,1,'meses',1,1050,50,1050,1000,'2026-01-01','2026-02-01','propio')")->execute([$cid]);
    $pid = $pdo->lastInsertId();
    // vencimiento NULL aceptado
    $pdo->prepare("UPDATE prestamos SET plazo_meses=0, num_cuotas=0, cuota_mensual=0, total_intereses=0, total_pagar=1000, fecha_vencimiento=NULL WHERE id=?")->execute([$pid]);
    ok('vencimiento NULL aceptado', true);
    $pdo->prepare("INSERT INTO pagos (prestamo_id, monto_pagado, capital_pagado, interes_pagado, mora_pagada, numero_cuota, fecha_pago, tipo_pago) VALUES (?,?,?,?,?,?,?,?)")->execute([$pid, 100, 0, 100, 0, 1, '2026-01-15', 'interes']);
    $dupOk = false;
    try {
        $pdo->prepare("INSERT INTO pagos (prestamo_id, monto_pagado, capital_pagado, interes_pagado, mora_pagada, numero_cuota, fecha_pago, tipo_pago) VALUES (?,?,?,?,?,?,?,?)")->execute([$pid, 100, 0, 100, 0, 1, '2026-01-15', 'cuota']);
    } catch (PDOException $e) { $dupOk = stripos($e->getMessage(), 'Duplicate') !== false; }
    ok('UNIQUE(prestamo,cuota) bloquea duplicado', $dupOk);
    // audit() dentro de transacción
    $_SESSION['user_id'] = 1; $_SESSION['user_username'] = 'test';
    audit($pdo, 'test_suite', 'prestamos', $pid, 'check');
    ok('audit() inserta', $pdo->query("SELECT COUNT(*) FROM auditoria WHERE accion='test_suite'")->fetchColumn() == 1);
    // mora por inactividad eliminada: indefinidos no generan mora
    $cfg = getConfiguracion($pdo);
    $prow = $pdo->query("SELECT * FROM prestamos WHERE id=$pid")->fetch();
    $mi = moraInactividad($pdo, $prow, $cfg);
    ok('moraInactividad deprecada=0', isset($mi['dias'], $mi['mora'], $mi['ultima'], $mi['gracia']) && $mi['mora'] == 0.0 && $mi['dias'] === 0);
    $dInd = diasAtrasoPrestamo($pdo, ['id' => $pid, 'plazo_meses' => 0, 'fecha_vencimiento' => null, 'saldo_pendiente' => 1000, 'estado' => 'activo', 'fecha_prestamo' => '2026-01-01'], $cfg);
    ok('indefinido sin mora', $dInd['mora'] == 0.0 && $dInd['dias'] === 0 && $dInd['tipo'] === 'ninguno');
    $pdo->rollBack();
    ok('rollback limpia fixtures', $pdo->query("SELECT COUNT(*) FROM clientes WHERE cedula='TESTCLI001'")->fetchColumn() == 0
        && $pdo->query("SELECT COUNT(*) FROM auditoria WHERE accion='test_suite'")->fetchColumn() == 0);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ok('bloque transaccional (EX: ' . substr($e->getMessage(), 0, 80) . ')', false);
}

echo "\nRESULT cli: PASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
