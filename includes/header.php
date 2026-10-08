<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/breadcrumb.php';

// P0: cabeceras de seguridad (defensa clickjacking/MIME-sniffing).
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// P1 producción: HTTPS obligatorio solo si FORCE_HTTPS=1 en el entorno del
// servidor. En local (XAMPP por HTTP) queda apagado y no rompe el desarrollo.
if (!headers_sent() && defined('FORCE_HTTPS') && FORCE_HTTPS === '1' && PHP_SAPI !== 'cli') {
    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$esHttps) {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if ($host !== '') {
            header('Location: https://' . $host . $uri, true, 301);
            exit();
        }
    } else {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

if (basename($_SERVER['PHP_SELF']) !== 'login.php' && basename($_SERVER['PHP_SELF']) !== 'instalar.php') {
    requireLogin();
    // P0: lector es solo-lectura. Todo POST (crear/pagar/editar/config) exige admin o cajero.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $rol = currentUserRole();
        if (!in_array($rol, ['admin', 'cajero'], true)) {
            audit(getConnection(), 'acceso_denegado_post', null, null, ($_SERVER['PHP_SELF'] ?? '') . ' rol=' . $rol);
            flash('danger', 'No tiene permisos para esta acción (rol lector: solo lectura)');
            redirect(BASE_PATH . 'index.php');
        }
    }
}

function getBasePath() {
    return defined('BASE_PATH') ? BASE_PATH : '/';
}

function isMenuActive($section) {
    $self = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
    if ($section === 'dashboard') {
        // P1: funciona también cuando la app vive en subdirectorio.
        return (basename($self) === 'index.php' && substr_count(trim($self, '/'), '/') <= 1) ? 'active' : '';
    }
    return strpos($self, '/' . $section . '/') !== false ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle ?? 'Sistema de Préstamos') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= getBasePath() ?>assets/css/style.css?v=6" rel="stylesheet">
</head>
<body>
    <div class="app-layout">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <i class="bi bi-bank2"></i>
            </div>
            <nav class="sidebar-nav">
                <a class="nav-link <?= isMenuActive('dashboard') ?>" href="<?= getBasePath() ?>index.php">
                    <i class="bi bi-grid-1x2"></i>
                    <span class="nav-text">Dashboard</span>
                </a>

                <div class="nav-section-divider"></div>

                <a class="nav-link <?= isMenuActive('clientes') ?>" href="<?= getBasePath() ?>clientes/index.php">
                    <i class="bi bi-people"></i>
                    <span class="nav-text">Clientes</span>
                </a>
                <a class="nav-link <?= isMenuActive('acreedores') ?>" href="<?= getBasePath() ?>acreedores/index.php">
                    <i class="bi bi-bank"></i>
                    <span class="nav-text">Acreedores</span>
                </a>

                <div class="nav-section-divider"></div>

                <a class="nav-link <?= isMenuActive('prestamos') ?>" href="<?= getBasePath() ?>prestamos/index.php">
                    <i class="bi bi-cash-stack"></i>
                    <span class="nav-text">Préstamos</span>
                </a>
                <a class="nav-link <?= isMenuActive('morosos') ?>" href="<?= getBasePath() ?>morosos/index.php">
                    <i class="bi bi-exclamation-triangle"></i>
                    <span class="nav-text">Morosos</span>
                </a>
                <a class="nav-link <?= isMenuActive('pagos') ?>" href="<?= getBasePath() ?>pagos/index.php">
                    <i class="bi bi-credit-card"></i>
                    <span class="nav-text">Pagos</span>
                </a>
                <a class="nav-link <?= isMenuActive('calendario') ?>" href="<?= getBasePath() ?>calendario/index.php">
                    <i class="bi bi-calendar3"></i>
                    <span class="nav-text">Calendario</span>
                </a>

                <div class="nav-section-divider"></div>

                <a class="nav-link <?= isMenuActive('historial_pagos') ?>" href="<?= getBasePath() ?>historial_pagos/index.php">
                    <i class="bi bi-journal-text"></i>
                    <span class="nav-text">Historial</span>
                </a>
                <a class="nav-link <?= isMenuActive('mis_deudas') ?>" href="<?= getBasePath() ?>mis_deudas/index.php">
                    <i class="bi bi-wallet2"></i>
                    <span class="nav-text">Mis Deudas</span>
                </a>

                <div class="nav-section-divider"></div>

                <a class="nav-link <?= isMenuActive('reportes') ?>" href="<?= getBasePath() ?>reportes/index.php">
                    <i class="bi bi-graph-up"></i>
                    <span class="nav-text">Reportes</span>
                </a>
                <a class="nav-link <?= isMenuActive('configuracion') ?>" href="<?= getBasePath() ?>configuracion/index.php">
                    <i class="bi bi-gear"></i>
                    <span class="nav-text">Config</span>
                </a>
                <?php if (isLoggedIn() && currentUserRole() === 'admin'): ?>
                <a class="nav-link <?= isMenuActive('usuarios') ?>" href="<?= getBasePath() ?>usuarios/index.php">
                    <i class="bi bi-person-gear"></i>
                    <span class="nav-text">Usuarios</span>
                </a>
                <a class="nav-link <?= isMenuActive('auditoria') ?>" href="<?= getBasePath() ?>auditoria/index.php">
                    <i class="bi bi-shield-check"></i>
                    <span class="nav-text">Auditoría</span>
                </a>
                <?php endif; ?>
            </nav>
        </aside>

        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="main-content">
            <header class="topbar">
                <button class="topbar-toggle" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
                <div></div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <?php if (isLoggedIn()): ?>
                        <span style="font-size:0.82rem; color:var(--text-muted);">
                            <i class="bi bi-person-circle"></i> <?= sanitize($_SESSION['user_nombre'] ?? $_SESSION['user_username'] ?? '') ?>
                        </span>
                        <a href="<?= getBasePath() ?>auth/logout.php" class="btn btn-sm btn-outline-secondary" title="Cerrar sesión" style="font-size:0.78rem;">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    <?php endif; ?>
                    <button class="dark-mode-toggle" id="darkModeToggle" title="Cambiar tema">
                        <i class="bi bi-moon"></i>
                    </button>
                </div>
            </header>

            <div class="content-area">
                <?php $flash = getFlash(); if ($flash): $flashType = in_array($flash['type'] ?? '', ['success','danger','warning','info'], true) ? $flash['type'] : 'info'; ?>
                    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show animate-in" role="alert">
                        <?= sanitize($flash['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
