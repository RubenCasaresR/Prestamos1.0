# Suite HTTP E2E — requiere MySQL + servidor PHP builtin en 127.0.0.1:8099
#   php -S 127.0.0.1:8099 (docroot = raíz del proyecto)
# Crea datos PRUEBA_* y los borra al final; restaura capital_propio_inicial.
# Uso: powershell -ExecutionPolicy Bypass -File tests/e2e.ps1
$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8099'
$global:pass = 0; $global:fail = 0
function Assert($label, $cond) {
    if ($cond) { $global:pass++; Write-Output "PASS $label" }
    else { $global:fail++; Write-Output "FAIL $label" }
}
function Get-Csrf($html) { if ($html -match 'name="csrf_token" value="([^"]+)"') { return $Matches[1] }; throw 'no csrf' }
function DbOne($sql) {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $out = (& php 'tests/db_one.php' "$sql" 2>&1 | Where-Object { "$_" -match '^\d+(\.\d+)?$' } | Select-Object -Last 1)
    $ErrorActionPreference = $prev
    return "$out".Trim()
}
function NoRedirect($url) {
    $req = [System.Net.HttpWebRequest]::Create($url); $req.AllowAutoRedirect = $false; $req.Timeout = 10000
    try { $res = $req.GetResponse(); return @{ code = [int]$res.StatusCode; loc = $res.Headers['Location'] } }
    catch [System.Net.WebException] { $r = $_.Exception.Response; if ($r) { return @{ code = [int]$r.StatusCode; loc = $r.Headers['Location'] } }; throw }
}

Write-Output '== Setup idempotente =='
DbOne "DELETE FROM pagos WHERE prestamo_id IN (SELECT id FROM prestamos WHERE cliente_id IN (SELECT id FROM clientes WHERE cedula='E2E200'))" | Out-Null
DbOne "DELETE FROM prestamos WHERE cliente_id IN (SELECT id FROM clientes WHERE cedula='E2E200')" | Out-Null
DbOne "DELETE FROM clientes WHERE cedula='E2E200'" | Out-Null
Write-Output '== Auth y guards =='
Assert 'login 200' ((Invoke-WebRequest -Uri "$base/auth/login.php" -SessionVariable sess -UseBasicParsing).StatusCode -eq 200)
$r = NoRedirect "$base/prestamos/crear.php"; Assert 'crear sin login 302 a login' ($r.code -eq 302 -and $r.loc -match 'login')
$r = NoRedirect "$base/pagos/recibo.php?id=1"; Assert 'recibo sin login 302' ($r.code -eq 302)
$notFound = $false
try { Invoke-WebRequest -Uri "$base/auth/instalar.php" -UseBasicParsing | Out-Null } catch { $notFound = $_.Exception.Message -match '404' }
Assert 'instalar.php inexistente (404)' ($notFound)
$notFound2 = $false
try { Invoke-WebRequest -Uri "$base/auth/instalar.php.DESHABILITADO" -UseBasicParsing | Out-Null } catch { $notFound2 = $_.Exception.Message -match '404' }
Assert 'instalador eliminado del servidor (404)' ($notFound2)

# P1: credenciales por entorno, nunca hardcodeadas (E2E_USER, E2E_PASS).
$e2eUser = if ($env:E2E_USER) { $env:E2E_USER } else { 'admin' }
$e2ePass = $env:E2E_PASS
if (-not $e2ePass) { throw 'Falta $env:E2E_PASS con la clave del usuario E2E. Ej: $env:E2E_PASS="..."' }
$tok = Get-Csrf ((Invoke-WebRequest -Uri "$base/auth/login.php" -WebSession $sess -UseBasicParsing).Content)
$b = Invoke-WebRequest -Uri "$base/auth/login.php" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $tok; username = $e2eUser; password = $e2ePass }
Assert 'login admin ok' ($b.Content -match 'Dashboard')

Write-Output '== Validaciones rechazan =='
$tok = Get-Csrf ((Invoke-WebRequest -Uri "$base/clientes/crear.php" -WebSession $sess -UseBasicParsing).Content)
$r = Invoke-WebRequest -Uri "$base/clientes/crear.php" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $tok; nombre = 'T'; apellido = 'T'; cedula = 'E2E100'; telefono = '1234567'; direccion = 'd'; email = 'no-es-email' }
Assert 'cliente email invalido' ($r.Content -match 'email no es v')
$tok = Get-Csrf ((Invoke-WebRequest -Uri "$base/prestamos/crear.php" -WebSession $sess -UseBasicParsing).Content)
$ayer = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd')
$r = Invoke-WebRequest -Uri "$base/prestamos/crear.php" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $tok; cliente_id = '1'; monto_prestado = '100'; tasa_interes = '500'; plazo = '6'; fecha_prestamo = $ayer; fuente_fondos = 'propio' }
Assert 'tasa>100 rechazada' ($r.Content -match 'exceder 100%')

Write-Output '== Flujo completo =='
$tok = Get-Csrf ((Invoke-WebRequest -Uri "$base/clientes/crear.php" -WebSession $sess -UseBasicParsing).Content)
Invoke-WebRequest -Uri "$base/clientes/crear.php" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $tok; nombre = 'PRUEBA'; apellido = 'E2E'; cedula = 'E2E200'; telefono = '12345678'; direccion = 'dir' } | Out-Null
$cid = DbOne "SELECT id FROM clientes WHERE cedula='E2E200'"
Assert 'cliente creado' ($cid -match '^\d+$')
$tok = Get-Csrf ((Invoke-WebRequest -Uri "$base/prestamos/crear.php" -WebSession $sess -UseBasicParsing).Content)
$hace40 = (Get-Date).AddDays(-40).ToString('yyyy-MM-dd')
$hoy = (Get-Date).ToString('yyyy-MM-dd')
$body = @{ csrf_token = $tok; cliente_id = "$cid"; monto_prestado = '12000'; tasa_interes = '10'; plazo = '6'; fecha_prestamo = $hace40; fuente_fondos = 'propio'; observaciones = 'E2E SUITE' }
Invoke-WebRequest -Uri "$base/prestamos/crear.php" -WebSession $sess -Method POST -UseBasicParsing -Body $body | Out-Null
$loanId = DbOne 'SELECT id FROM prestamos ORDER BY id DESC LIMIT 1'
Assert 'prestamo fijo creado' ($loanId -match '^\d+$')
function Pagar($tipo, $monto, $fecha) {
    $p = (Invoke-WebRequest -Uri "$base/prestamos/pagar.php?id=$loanId" -WebSession $sess -UseBasicParsing).Content
    $t = Get-Csrf $p
    $rr = Invoke-WebRequest -Uri "$base/prestamos/pagar.php?id=$loanId" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $t; tipo_pago = $tipo; monto_pagado = $monto; fecha_pago = $fecha; observaciones = "E2E $tipo" }
    return $rr.Content
}
Assert 'pago cuota parcial 400' ((Pagar 'cuota' '400' $hoy) -match 'Pago registrado')
Assert 'pago solo-interes 200' ((Pagar 'interes' '200' $hoy) -match 'Pago registrado')
Assert 'abono 1500' ((Pagar 'abono' '1500' $hoy) -match 'Pago registrado')
$p = (Invoke-WebRequest -Uri "$base/prestamos/pagar.php?id=$loanId" -WebSession $sess -UseBasicParsing).Content
$t = Get-Csrf $p
$bad = Invoke-WebRequest -Uri "$base/prestamos/pagar.php?id=$loanId" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $t; tipo_pago = 'interes'; monto_pagado = '99999'; fecha_pago = $hoy }
Assert 'interes sobre tope rechazado' ($bad.Content -match 'excede')
$fut = (Get-Date).AddDays(5).ToString('yyyy-MM-dd')
$bad2 = Invoke-WebRequest -Uri "$base/prestamos/pagar.php?id=$loanId" -WebSession $sess -Method POST -UseBasicParsing -Body @{ csrf_token = $t; tipo_pago = 'cuota'; monto_pagado = '100'; fecha_pago = $fut }
Assert 'fecha futura rechazada' ($bad2.Content -match 'no puede ser futura')
$v = (Invoke-WebRequest -Uri "$base/prestamos/ver.php?id=$loanId" -WebSession $sess -UseBasicParsing).Content
Assert 'ver calendario' ($v -match 'Calendario de Pagos')
Assert 'ver interes vigente' ($v -match 'Inter.s Vigente')
Assert 'ver badge tipo' ($v -match 'Abono')
$ven = (Invoke-WebRequest -Uri "$base/reportes/vencimientos.php" -WebSession $sess -UseBasicParsing).Content
Assert 'venc cuotas vencidas' ($ven -match 'Cuotas Vencidas')
$mor = Invoke-WebRequest -Uri "$base/reportes/morosos.php" -WebSession $sess -UseBasicParsing
Assert 'morosos 200' ($mor.StatusCode -eq 200)
Assert 'morosos con datos' ($mor.Content -match 'Clientes Morosos' -and ($mor.Content -match 'Sin clientes en mora' -or $mor.Content -match 'TOTALES'))
$mod = Invoke-WebRequest -Uri "$base/morosos/index.php" -WebSession $sess -UseBasicParsing
Assert 'modulo morosos 200' ($mod.StatusCode -eq 200)
Assert 'modulo morosos contenido' ($mod.Content -match 'Total a Cobrar' -and ($mod.Content -match 'Sin clientes en mora' -or $mod.Content -match 'TOTALES'))
$modCsv = Invoke-WebRequest -Uri "$base/morosos/index.php?export=csv" -WebSession $sess -UseBasicParsing
Assert 'modulo morosos csv' ($modCsv.Headers['Content-Disposition'] -match 'morosos_')
$csv = Invoke-WebRequest -Uri "$base/reportes/vencimientos.php?export=cuotas" -WebSession $sess -UseBasicParsing
Assert 'csv cuotas' ($csv.Headers['Content-Disposition'] -match 'vencimientos_')
$u = (Invoke-WebRequest -Uri "$base/usuarios/index.php" -WebSession $sess -UseBasicParsing).Content
Assert 'usuarios admin 200' ($u -match 'Usuarios')
$a = (Invoke-WebRequest -Uri "$base/auditoria/index.php" -WebSession $sess -UseBasicParsing).Content
Assert 'auditoria lista' ($a -match 'Auditor')
$bk = Invoke-WebRequest -Uri "$base/configuracion/backup.php" -WebSession $sess -UseBasicParsing
Assert 'backup descarga' ($bk.Headers['Content-Disposition'] -match 'backup_')

Write-Output ("RESULT e2e: PASS=$global:pass FAIL=$global:fail")
