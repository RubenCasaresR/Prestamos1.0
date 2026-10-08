<?php

function renderDataTableStyles() {
    echo '<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">' . "\n";
}

function dataTableConfigJs($columnasNoOrdenables = [], $columnaOrden = 0, $direccionOrden = 'asc') {
    $targets = [];
    foreach ($columnasNoOrdenables as $col) $targets[] = (int) $col;
    $targetsJson = empty($targets) ? '' : json_encode($targets);
    $columnaOrden = (int) $columnaOrden;
    $direccionOrden = $direccionOrden === 'desc' ? 'desc' : 'asc';

    return <<<JS
language: {
    processing: "Procesando...",
    search: "Buscar:",
    lengthMenu: "Mostrar _MENU_ registros",
    info: "Mostrando _START_ a _END_ de _TOTAL_ registros",
    infoEmpty: "Mostrando 0 a 0 de 0 registros",
    infoFiltered: "(filtrado de _MAX_ registros totales)",
    infoPostFix: "",
    loadingRecords: "Cargando...",
    zeroRecords: "No se encontraron registros",
    emptyTable: "No hay datos disponibles",
    paginate: {
        first: "Primero",
        previous: "Anterior",
        next: "Siguiente",
        last: "Último"
    },
    aria: {
        sortAscending: ": Activar para ordenar ascendente",
        sortDescending: ": Activar para ordenar descendente"
    }
},
order: [[$columnaOrden, '$direccionOrden']],
columnDefs: [
    { orderable: false, targets: $targetsJson }
]
JS;
}

function renderDataTable($tableId, $columnasNoOrdenables = [], $columnaOrden = 0, $direccionOrden = 'asc') {
    $tableId = preg_replace('/[^A-Za-z0-9_]/', '', (string) $tableId);
    $config = dataTableConfigJs($columnasNoOrdenables, $columnaOrden, $direccionOrden);
    echo '<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>' . "\n";
    echo '<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>' . "\n";
    echo '<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>' . "\n";
    echo '<script>' . "\n";
    echo '$(document).ready(function() {' . "\n";
    echo "    \$('#$tableId').DataTable({" . "\n";
    echo '        ' . str_replace("\n", "\n        ", $config) . "\n";
    echo '    });' . "\n";
    echo '});' . "\n";
    echo '</script>' . "\n";
}
