<?php
/**
 * Shell layout admin (sidebar + topbar). Cierra con footer_admin_dashboard.php.
 * Variables esperadas: $title (string), $adminNavCurrent (string): dashboard|actividades|reportes|ral_config|reportes_notas|reportes_requerimientos|usuarios
 */
$title = $title ?? 'Admin - Dashboard';
$adminNavCurrent = $adminNavCurrent ?? 'dashboard';
$adminSidebarFootInclude = $adminSidebarFootInclude ?? null;
$sedeIdFiltro = (int)($sedeIdFiltro ?? 0);
$qUsuario = (string)($qUsuario ?? '');

$nombreAdmin = (string)($_SESSION['nombre_completo'] ?? 'Administrador');
$adminTopbarGreetNombre = $nombreAdmin;
if (preg_match('/^\s*(\S+)/u', $nombreAdmin, $gm)) {
    $adminTopbarGreetNombre = $gm[1];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_URL . 'css/admin-dashboard.css', ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="admin-dashboard-body">
<div class="admin-app">
    <aside class="admin-sidebar" aria-label="Navegación principal">
        <a class="admin-sidebar-brand" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-clock-history"></i>
            <span>Control Sedes</span>
        </a>
        <nav class="admin-sidebar-nav">
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'dashboard' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-house-door"></i> Dashboard
            </a>
            <div class="admin-sidebar-section">Gestión</div>
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'actividades' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/actividades.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-kanban"></i> Actividades
            </a>
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'reportes' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/reportes_tiempo_actividad.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-bar-chart-line"></i> Reportes de tiempo
            </a>
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'ral_config' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/ral_config.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-calendar3"></i> Configuración RAL
            </a>
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'usuarios' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/usuarios.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-people"></i> Usuarios
            </a>
            <a class="admin-sidebar-link" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php#usuarios-por-sede', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-building"></i> Sedes
            </a>
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'reportes_notas' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/reportes_actividad_notas.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-journal-text"></i> Reportes por actividad
            </a>
            <a class="admin-sidebar-link <?php echo $adminNavCurrent === 'reportes_requerimientos' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'admin/reportes_requerimientos.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-clipboard-data"></i> Reportes requerimientos
            </a>
        </nav>
        <?php if (!empty($adminSidebarFootInclude) && is_string($adminSidebarFootInclude) && is_readable($adminSidebarFootInclude)): ?>
            <?php include $adminSidebarFootInclude; ?>
        <?php else: ?>
        <div class="admin-sidebar-foot">
            <i class="bi bi-people-fill"></i>
            Control total del tiempo, actividades y rendimiento de tu equipo.
        </div>
        <?php endif; ?>
    </aside>
    <div class="admin-main">
        <header class="admin-topbar">
            <div class="admin-topbar-greet d-none d-md-flex align-items-center" role="presentation">
                ¡Hola, <span class="fw-semibold ms-1"><?php echo htmlspecialchars($adminTopbarGreetNombre, ENT_QUOTES, 'UTF-8'); ?></span>! 👋
            </div>
            <form class="admin-search-wrap" method="get" action="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>" role="search">
                <?php if ($sedeIdFiltro > 0): ?>
                    <input type="hidden" name="sede_id" value="<?php echo (int)$sedeIdFiltro; ?>">
                <?php endif; ?>
                <div class="admin-search">
                    <i class="bi bi-search"></i>
                    <input type="search" name="q_usuario" value="<?php echo htmlspecialchars($qUsuario, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Buscar..." maxlength="120" autocomplete="off" aria-label="Buscar usuario">
                    <span class="admin-search-kbd" title="Atajo (solo referencia)">Ctrl + K</span>
                </div>
            </form>
            <div class="admin-topbar-actions">
                <button type="button" class="admin-notify-btn" aria-label="Notificaciones">
                    <i class="bi bi-bell fs-5"></i>
                    <span class="badge bg-danger rounded-pill">3</span>
                </button>
                <a class="btn btn-outline-secondary btn-sm rounded-3 fw-semibold admin-topbar-profile" href="<?php echo htmlspecialchars(BASE_URL . 'perfil.php', ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-person me-1"></i><span class="d-none d-md-inline">Perfil</span>
                </a>
                <a class="btn btn-danger btn-sm rounded-3 fw-semibold admin-topbar-logout-text" href="<?php echo htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-box-arrow-right me-1"></i><span class="d-none d-md-inline">Salir</span>
                </a>
            </div>
        </header>
        <div class="admin-content">