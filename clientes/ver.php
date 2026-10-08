<?php
$pageTitle = 'Detalle del Cliente';
require_once __DIR__ . '/../includes/header.php';

$pdo = getConnection();
$configCli = [];
try { $configCli = getConfiguracion($pdo); } catch (Exception $e) { $configCli = []; }
$id = intval($_GET['id'] ?? 0);

try {
$stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    flash('danger', 'Cliente no encontrado');
    redirect('index.php');
}

$stmt = $pdo->prepare("SELECT * FROM prestamos WHERE cliente_id = ? ORDER BY fecha_prestamo DESC");
$stmt->execute([$id]);
$prestamos = $stmt->fetchAll();

$totalPrestado = 0;
$totalPendiente = 0;
$prestamosActivos = 0;
foreach ($prestamos as $p) {
    $totalPrestado += $p['monto_prestado'];
    if ($p['estado'] === 'activo' || $p['estado'] === 'moroso') {
        $totalPendiente += $p['saldo_pendiente'];
        $prestamosActivos++;
    }
}

} catch (Exception $e) {
    error_log("Error en cliente ver: " . $e->getMessage());
    flash('danger', 'Error al cargar los datos del cliente');
    redirect('index.php');
}
?>

<div class="page-header animate-in">
    <div class="page-header-row">
        <div>
            <?php renderBreadcrumb(); ?>
            <h4 class="page-title"><i class="bi bi-person"></i> <?= sanitize($cliente['nombre'] . ' ' . $cliente['apellido']) ?></h4>
        </div>
        <div>
            <a href="editar.php?id=<?= $cliente['id'] ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil"></i> Editar</a>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </div>
    </div>
</div>

<div class="card animate-in">
    <div class="card-body p-0">
        <div class="stat-row">
            <div>
                <div class="stat-label">Total Prestado</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalPrestado) ?></div>
            </div>
            <div>
                <div class="stat-label">Saldo Pendiente</div>
                <div class="stat-value" style="font-size:1.15rem;"><?= formatMoney($totalPendiente) ?></div>
            </div>
            <div>
                <div class="stat-label">Activos</div>
                <div class="stat-value"><?= $prestamosActivos ?></div>
            </div>
            <div>
                <div class="stat-label">Total Préstamos</div>
                <div class="stat-value"><?= count($prestamos) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-lg-5 mb-4">
        <div class="card animate-in">
            <div class="card-header"><i class="bi bi-person-badge"></i> Datos Personales</div>
            <div class="card-body">
                <ul class="info-list">
                    <li><span class="info-label">Cédula</span><span class="info-value"><?= sanitize($cliente['cedula']) ?></span></li>
                    <li><span class="info-label">Teléfono</span><span class="info-value"><?= sanitize($cliente['telefono']) ?></span></li>
                    <li><span class="info-label">Dirección</span><span class="info-value"><?= sanitize($cliente['direccion']) ?></span></li>
                    <li><span class="info-label">Email</span><span class="info-value"><?= sanitize($cliente['email'] ?? '-') ?></span></li>
                    <li><span class="info-label">Referencia</span><span class="info-value"><?= sanitize($cliente['referencia_personal'] ?? '-') ?></span></li>
                    <li><span class="info-label">Estado</span><span class="info-value"><span class="badge badge-<?= $cliente['estado'] ?>"><?= ucfirst($cliente['estado']) ?></span></span></li>
                    <li><span class="info-label">Registro</span><span class="info-value"><?= date('d/m/Y H:i', strtotime($cliente['fecha_registro'])) ?></span></li>
                </ul>
            </div>
        </div>
    </div>
    <div class="col-lg-7 mb-4">
        <div class="card animate-in">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i class="bi bi-cash-stack"></i> Historial de Préstamos</span>
                <a href="../prestamos/crear.php?cliente_id=<?= $cliente['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i> Nuevo</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($prestamos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-cash-stack"></i>
                        <h5>Sin préstamos</h5>
                        <p>Este cliente no tiene préstamos registrados.</p>
                        <a href="../prestamos/crear.php?cliente_id=<?= $cliente['id'] ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Crear Préstamo</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr><th>Monto</th><th>Estado</th><th>Interés</th><th class="num">Saldo</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($prestamos as $p): ?>
                                    <?php
                                    // Mismo criterio que prestamos/index.php: el badge refleja mora
                                    // calculada (vencimiento + gracia o 1+ meses de interés sin pagar).
                                    $dCli = diasAtrasoPrestamo($pdo, $p, $configCli);
                                    $morosoCli = floatval($p['saldo_pendiente'] ?? 0) > 0 && $dCli['dias'] > $dCli['gracia'];
                                    $baseCli = substr($p['fecha_ultimo_devengo'] ?? $p['fecha_prestamo'], 0, 10);
                                    $calcCli = interesCausadoPorMeses(floatval($p['saldo_pendiente'] ?? 0), floatval($p['tasa_interes'] ?? 0), $baseCli, hoyFecha(), intval($configCli['dias_gracia_interes'] ?? 0));
                                    $intVigCli = round(floatval($p['interes_vigente'] ?? 0) + $calcCli['total'], 2);
                                    $intMorosoCli = esMorosoPorInteres($calcCli['meses'], $intVigCli);
                                    $badgeCli = ($p['estado'] === 'pagado') ? 'pagado' : (($morosoCli || $intMorosoCli) ? 'moroso' : 'activo');
                                    ?>
                                    <tr>
                                        <td><?= formatMoney($p['monto_prestado']) ?></td>
                                        <td><span class="badge badge-<?= $badgeCli ?>"<?php if ($badgeCli !== $p['estado']): ?> title="Estado en BD: <?= ucfirst($p['estado']) ?>"<?php endif; ?>><?= ucfirst($badgeCli) ?></span></td>
                                        <td><?php if ($p['estado'] === 'pagado'): ?><span class="badge badge-pagado">—</span><?php elseif ($intMorosoCli): ?><span class="badge badge-moroso" title="<?= intval($calcCli['meses']) ?> mes(es) de interés sin pagar (<?= formatMoney($intVigCli) ?>)">Moroso</span><br><small style="color:var(--text-muted);"><?= intval($calcCli['meses']) ?> mes(es)</small><?php else: ?><span class="badge badge-activo">Al día</span><?php endif; ?></td>
                                        <td class="num" style="color:<?= $p['saldo_pendiente'] > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= formatMoney($p['saldo_pendiente']) ?></td>
                                        <td><a href="../prestamos/ver.php?id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
