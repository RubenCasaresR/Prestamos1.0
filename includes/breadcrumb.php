<?php
function getBreadcrumb() {
    $self = $_SERVER['PHP_SELF'];

    $map = [
        '/' => ['Dashboard' => 'index.php'],
        '/index.php' => ['Dashboard' => 'index.php'],
        '/clientes/' => ['Clientes' => 'clientes/index.php'],
        '/clientes/index.php' => ['Clientes' => 'clientes/index.php'],
        '/clientes/crear.php' => ['Clientes' => 'clientes/index.php', 'Nuevo Cliente' => ''],
        '/clientes/editar.php' => ['Clientes' => 'clientes/index.php', 'Editar Cliente' => ''],
        '/clientes/ver.php' => ['Clientes' => 'clientes/index.php', 'Detalle' => ''],
        '/prestamos/' => ['Préstamos' => 'prestamos/index.php'],
        '/prestamos/index.php' => ['Préstamos' => 'prestamos/index.php'],
        '/prestamos/crear.php' => ['Préstamos' => 'prestamos/index.php', 'Nuevo Préstamo' => ''],
        '/prestamos/ver.php' => ['Préstamos' => 'prestamos/index.php', 'Detalle' => ''],
        '/prestamos/pagar.php' => ['Préstamos' => 'prestamos/index.php', 'Registrar Pago' => ''],
        '/morosos/' => ['Morosos' => 'morosos/index.php'],
        '/morosos/index.php' => ['Morosos' => 'morosos/index.php'],
        '/pagos/' => ['Pagos' => 'pagos/index.php'],
        '/pagos/index.php' => ['Pagos' => 'pagos/index.php'],
        '/pagos/recibo.php' => ['Pagos' => 'pagos/index.php', 'Recibo' => ''],
        '/calendario/' => ['Calendario' => 'calendario/index.php'],
        '/calendario/index.php' => ['Calendario' => 'calendario/index.php'],
        '/acreedores/' => ['Acreedores' => 'acreedores/index.php'],
        '/acreedores/index.php' => ['Acreedores' => 'acreedores/index.php'],
        '/acreedores/crear.php' => ['Acreedores' => 'acreedores/index.php', 'Nuevo Acreedor' => ''],
        '/acreedores/editar.php' => ['Acreedores' => 'acreedores/index.php', 'Editar Acreedor' => ''],
        '/acreedores/ver.php' => ['Acreedores' => 'acreedores/index.php', 'Detalle' => ''],
        '/mis_deudas/' => ['Mis Deudas' => 'mis_deudas/index.php'],
        '/mis_deudas/index.php' => ['Mis Deudas' => 'mis_deudas/index.php'],
        '/mis_deudas/crear.php' => ['Mis Deudas' => 'mis_deudas/index.php', 'Nueva Deuda' => ''],
        '/mis_deudas/ver.php' => ['Mis Deudas' => 'mis_deudas/index.php', 'Detalle' => ''],
        '/mis_deudas/pagar.php' => ['Mis Deudas' => 'mis_deudas/index.php', 'Registrar Pago' => ''],
        '/reportes/' => ['Reportes' => 'reportes/index.php'],
        '/reportes/index.php' => ['Reportes' => 'reportes/index.php'],
        '/reportes/prestamos_activos.php' => ['Reportes' => 'reportes/index.php', 'Préstamos Activos' => ''],
        '/reportes/morosos.php' => ['Reportes' => 'reportes/index.php', 'Clientes Morosos' => ''],
        '/reportes/flujo_caja.php' => ['Reportes' => 'reportes/index.php', 'Flujo de Caja' => ''],
        '/reportes/resumen_deudores.php' => ['Reportes' => 'reportes/index.php', 'Resumen Deudores' => ''],
        '/reportes/vencimientos.php' => ['Reportes' => 'reportes/index.php', 'Vencimientos' => ''],
        '/reportes/estado_cuenta.php' => ['Reportes' => 'reportes/index.php', 'Estado de Cuenta' => ''],
        '/reportes/rentabilidad.php' => ['Reportes' => 'reportes/index.php', 'Rentabilidad' => ''],
        '/reportes/estado_resultados.php' => ['Reportes' => 'reportes/index.php', 'Estado de Resultados' => ''],
        '/configuracion/index.php' => ['Configuración' => ''],
    ];

    if (isset($map[$self])) {
        return $map[$self];
    }

    foreach ($map as $key => $value) {
        if (strpos($self, $key) !== false && $key !== '/') {
            return $value;
        }
    }

    return ['Dashboard' => 'index.php'];
}

function renderBreadcrumb() {
    $items = getBreadcrumb();
    $count = count($items);
    $parts = [];
    $i = 0;

    foreach ($items as $label => $url) {
        $isLast = ($i === $count - 1);
        if ($isLast || empty($url)) {
            $parts[] = '<li class="bc-current">' . sanitize($label) . '</li>';
        } else {
            $parts[] = '<li><a href="' . $url . '">' . sanitize($label) . '</a></li>';
            $parts[] = '<li class="bc-sep"><i class="bi bi-chevron-right"></i></li>';
        }
        $i++;
    }

    echo '<ol class="breadcrumb-inline">' . implode('', $parts) . '</ol>';
}
