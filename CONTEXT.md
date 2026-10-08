# Sistema de Gestión de Préstamos

## Stack
- **PHP** 8.x, procedural, sin framework
- **MySQL/MariaDB** con PDO (utf8mb4, exception mode, FETCH_ASSOC)
- **Bootstrap 5.3.0** + **Bootstrap Icons 1.10.0** (CDN)
- **Chart.js 4.4.0** (CDN, solo dashboard)
- **Google Fonts: DM Sans** 400/500/600/700 (CDN)
- CSS propio en `assets/css/style.css` (1022 líneas, sin preprocesador)
- JS propio en `assets/js/app.js` (189 líneas, vanilla JS)

## Estructura de directorios
```
├── index.php                    (Dashboard)
├── config/database.php          (Conexión DB + TODOS los helpers)
├── includes/
│   ├── header.php               (HTML head, sidebar, topbar, flash messages)
│   ├── footer.php               (Cierre HTML, Bootstrap JS, app.js)
│   └── breadcrumb.php           (getBreadcrumb() + renderBreadcrumb())
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── auth/                        (login, logout, instalar)
├── clientes/                    (CRUD: index, crear, editar, ver, eliminar)
├── prestamos/                   (index, crear [wizard 3 pasos], ver, pagar)
├── pagos/                       (index, recibo [printable])
├── acreedores/                  (CRUD)
├── mis_deudas/                  (index, crear, ver, pagar)
├── historial_pagos/             (vista unificada pagos + pagos_deudas)
├── reportes/                    (8 reportes: activos, morosos, flujo_caja, etc.)
├── configuracion/               (config de empresa + mora)
└── database/                    (SQL de esquema y seeds)
```

## Convenciones de código

### Patrón de página (OBLIGATORIO)
```php
<?php
$pageTitle = 'Título';
require_once __DIR__ . '/../includes/header.php';
$pdo = getConnection();
// lógica de negocio
?>
<div class="page-header animate-in">...</div>
<div class="card animate-in">...</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
```

### Helpers globales (definidos en config/database.php)
- `getConnection(): PDO`
- `getConfiguracion(PDO $pdo): array`
- `redirect(string $url): never`
- `sanitize(string $data): string` — htmlspecialchars para XSS
- `formatMoney(float $amount, string $moneda = 'MXN'): string`
- `flash(string $type, string $message): void` — type: 'success' | 'danger'
- `getFlash(): ?array`
- `calcularMora(float $saldo, int $diasAtraso, float $tasaMoraDiaria, int $diasGracia): float`
- `generateCSRFToken(): string`
- `validateCSRFToken(string $token): bool`
- `csrfField(): string` — input hidden con token
- `requireLogin(): void`
- `isLoggedIn(): bool`
- `tasaPorPeriodo(float $tasaMensual, string $tipoPlazo): float`
- `numCuotasPorPlazo(int $plazo, string $tipoPlazo): int`
- `calcularCuota(float $monto, float $tasaMensual, int $plazo, string $tipoPlazo): float`
- `textoPeriodo(string $tipoPlazo): string`
- `textoPlazo(string $tipoPlazo): string`
- `textoCuota(string $tipoPlazo): string`

### Convenciones de nombres
- **PHP vars**: `$snake_case`
- **PHP functions**: `camelCase()`
- **JS functions**: `camelCase()`
- **CSS classes**: `kebab-case`
- **Tablas DB**: plural snake_case (`clientes`, `prestamos`, `pagos`)
- **Columnas DB**: `snake_case`
- **Archivos**: lowercase descriptivo (`crear.php`, `editar.php`, `ver.php`, `eliminar.php`)

### Reglas de seguridad
- Toda salida HTML debe usar `sanitize()` (htmlspecialchars)
- Todo formulario debe incluir `csrfField()` y validar con `validateCSRFToken()`
- Las queries parametrizadas usan prepared statements con placeholders `?`
- Usar `intval()` para LIMIT/OFFSET antes de inyectar en SQL
- `ON DELETE RESTRICT` en todas las FK

### UI/UX
- Bootstrap dropdown con tres-puntos para acciones: `.dropdown-actions`
- Badges de estado: `<span class="badge badge-{estado}">` (activo, moroso, pagado, inactivo)
- Tablas con clase `.table .table-hover .mb-0`, celdas numéricas con clase `.num`
- Formularios con `.floating-group` + atributo `data-loading` en el form
- Empty state con clase `.empty-state`
- Paginación manual con LIMIT/OFFSET, 20 por página

### Navegación del sidebar
| Sección       | Ruta                    | Icono             |
|---------------|-------------------------|-------------------|
| Dashboard     | index.php               | bi-grid-1x2       |
| Clientes      | clientes/index.php      | bi-people         |
| Préstamos     | prestamos/index.php     | bi-cash-stack     |
| Morosos       | morosos/index.php       | bi-exclamation-triangle |
| Pagos         | pagos/index.php         | bi-credit-card    |
| Historial     | historial_pagos/index.php| bi-journal-text  |
| Acreedores    | acreedores/index.php    | bi-bank           |
| Mis Deudas    | mis_deudas/index.php    | bi-wallet2        |
| Reportes      | reportes/index.php      | bi-graph-up       |
| Config        | configuracion/index.php | bi-gear           |

### BD: tablas principales
- `clientes` (id, nombre, apellido, cedula UNIQUE, telefono, direccion, email, estado)
- `prestamos` (id, cliente_id FK, monto_prestado, tasa_interes, plazo_meses, tipo_plazo, num_cuotas, cuota_mensual, total_intereses, total_pagar, saldo_pendiente, monto_mora_acumulada, fecha_prestamo, fecha_vencimiento, estado, fuente_fondos, acreedor_id FK, abonos_capital)
- `pagos` (id, prestamo_id FK, monto_pagado, capital_pagado, interes_pagado, mora_pagada, descuento, numero_cuota, fecha_pago, saldo_despues_pago, es_abono_capital)
- `acreedores` (id, nombre, telefono, estado)
- `mis_deudas` (id, acreedor_id FK, concepto, monto_recibido, tasa_mensual, capital_vigente, interes_mensual, estado)
- `pagos_deudas` (id, deuda_id FK, monto_pagado, capital_pagado, interes_pagado, fecha_pago)
- `configuracion` (id, nombre_empresa, tasa_mora_diaria, dias_gracia, moneda)
- `usuarios` (id, username UNIQUE, nombre, password_hash, estado)

### Reglas de negocio clave
- La mora se calcula: `max(0, $diasAtraso - $diasGracia) * ($saldo * $tasaMoraDiaria / 100)`
- Tasa de interés siempre es **tasa mensual** en porcentaje
- Tipo de plazo: 'dias' | 'semanas' | 'meses' | 'anios'
- Cálculo de cuota: fórmula de anualidad estándar
- `fuente_fondos`: 'propio' | 'apalancado' (cuando es de acreedor)
- Los préstamos pueden tener `es_abono_capital = 1` para abonos extraordinarios
