<?php
// P0/P1 smoke test sin BD: php tests/p0_check.php
// Valida guards puros (cuota, mora, json seguro, rol fail-closed).
$ok = 0; $fail = 0;
function t($name, $cond) { global $ok, $fail; if ($cond) { $ok++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// Stubs mínimos para cargar database.php sin sesión/DB realista.
if (!function_exists('date_default_timezone_set')) { function date_default_timezone_set($x) { return true; } }
require __DIR__ . '/../config/database.php';

t('calcularCuota plazo 0 no revienta', calcularCuota(10000, 10, 0, 'meses') === 0.0);
t('calcularCuota tasa 0 reparte', calcularCuota(12000, 0, 6, 'meses') === 2000.0);
t('calcularCuota 10k/10%/6m ~2296.07', abs(calcularCuota(10000, 10, 6, 'meses') - 2296.07) < 0.02);
t('calcularMora respeta gracia', calcularMora(10000, 10, 1.0, 3) === 700.0);
t('calcularMora sin atraso 0', calcularMora(10000, 2, 1.0, 3) === 0.0);
t('jsonSeguro escapa </script>', strpos(jsonSeguro(['c' => '</script><script>alert(1)</script>']), '</script>') === false);
t('currentUserRole fail-closed lector', (function() { $_SESSION = []; return currentUserRole() === 'lector'; })());
t('numCuotas anios=2 => 24 (documentado como inconsistente, no usar)', numCuotasPorPlazo(2, 'anios') === 24);
t('mesesCalendario respeta gracia', mesesCalendarioCumplidos('2026-01-01', '2026-02-01', 3) === 0);
t('fechaValida rechaza 2026-02-30', fechaValida('2026-02-30') === false);
t('fechaValida acepta 2026-02-28', fechaValida('2026-02-28') === true);
t('fechaValida rechaza next thursday', fechaValida('next thursday') === false);
t('numeroValido rechaza abc', numeroValido('abc') === false);
t('numeroValido rechaza 10abc', numeroValido('10abc') === false);
t('numeroValido acepta 10.5', numeroValido('10.5') === true);
t('formatMoney null-safe 0', formatMoney(null) === 'MXN 0,00');
t('formatMoney ignora basura', formatMoney('abc') === 'MXN 0,00');

echo "\n$ok pass, $fail fail\n";
exit($fail > 0 ? 1 : 0);
