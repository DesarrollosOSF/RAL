<?php
/**
 * Layout app para usuarios no administradores (sidebar + barra superior).
 * Variables: $title (string), $userNavCurrent (string): actividades | perfil | requerimientos
 */
$title = $title ?? 'Control Sedes';
$userNavCurrent = $userNavCurrent ?? 'actividades';
$nombreUsuario = (string)($_SESSION['nombre_completo'] ?? 'Usuario');
$inicialesUsuario = '';
foreach (preg_split('/\s+/', trim($nombreUsuario), -1, PREG_SPLIT_NO_EMPTY) as $i => $part) {
    if ($i >= 2) {
        break;
    }
    $inicialesUsuario .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($inicialesUsuario === '') {
    $inicialesUsuario = 'U';
}
$nombrePartes = preg_split('/\s+/', trim($nombreUsuario), -1, PREG_SPLIT_NO_EMPTY);
$primerNombre = $nombrePartes[0] ?? $nombreUsuario;
$h = (int)date('G');
if ($h >= 5 && $h < 12) {
    $saludoHorario = 'Buenos días';
} elseif ($h >= 12 && $h < 19) {
    $saludoHorario = 'Buenas tardes';
} else {
    $saludoHorario = 'Buenas noches';
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
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_URL . 'css/board.css', ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_URL . 'css/user-board.css', ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (($userNavCurrent ?? '') === 'requerimientos'): ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_URL . 'css/user-requerimientos.css', ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
</head>
<body class="admin-dashboard-body user-app-body">
<div class="admin-app user-app-shell">
    <aside class="admin-sidebar user-sidebar" id="user-sidebar" aria-label="Navegación">
        <a class="admin-sidebar-brand" href="<?php echo htmlspecialchars(BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <span>Control Sedes</span>
        </a>
        <nav class="admin-sidebar-nav">
            <a class="admin-sidebar-link <?php echo $userNavCurrent === 'actividades' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-kanban" aria-hidden="true"></i> Actividades
            </a>
            <a class="admin-sidebar-link <?php echo $userNavCurrent === 'perfil' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'perfil.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-person" aria-hidden="true"></i> Perfil
            </a>
            <a class="admin-sidebar-link <?php echo $userNavCurrent === 'requerimientos' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars(BASE_URL . 'requerimientos.php', ENT_QUOTES, 'UTF-8'); ?>">
                <i class="bi bi-clipboard-plus" aria-hidden="true"></i> Requerimientos
            </a>
        </nav>
        <div class="user-jornada-sidebar-card" aria-live="polite">
            <div class="user-jornada-sidebar-title"><i class="bi bi-stopwatch me-1" aria-hidden="true"></i>Jornada activa</div>
            <div class="user-jornada-sidebar-sub text-muted" id="user-jornada-sub">Tiempo acumulado hoy</div>
            <div class="user-jornada-sidebar-time" id="user-jornada-time">00:00:00</div>
            <div class="progress user-jornada-sidebar-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="user-jornada-progress-root">
                <div class="progress-bar bg-primary" id="user-jornada-bar" style="width:0%"></div>
            </div>
            <div class="user-jornada-pct small text-muted" id="user-jornada-pct-label">0% completado</div>
        </div>
    </aside>
    <div class="admin-main user-main">
        <header class="admin-topbar user-topbar">
            <button type="button" class="user-menu-toggle d-lg-none" id="user-menu-toggle" aria-label="Abrir menú" aria-controls="user-sidebar" aria-expanded="false">
                <i class="bi bi-list fs-4" aria-hidden="true"></i>
            </button>
            <div class="user-topbar-greeting d-none d-sm-block">
                <span class="user-greeting-text"><?php echo htmlspecialchars($saludoHorario, ENT_QUOTES, 'UTF-8'); ?>, <span class="fw-semibold"><?php echo htmlspecialchars($primerNombre, ENT_QUOTES, 'UTF-8'); ?></span> 👋</span>
            </div>
            <div class="admin-topbar-actions user-topbar-actions">
                <button type="button" class="admin-notify-btn" aria-label="Notificaciones (demo)">
                    <i class="bi bi-bell fs-5" aria-hidden="true"></i>
                    <span class="badge bg-danger rounded-pill">3</span>
                </button>
                <a class="btn btn-outline-secondary btn-sm rounded-3 fw-semibold" href="<?php echo htmlspecialchars(BASE_URL . 'perfil.php', ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-person me-1" aria-hidden="true"></i>Perfil
                </a>
                <a class="btn btn-danger btn-sm rounded-3 fw-semibold" href="<?php echo htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8'); ?>">
                    <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Salir
                </a>
            </div>
        </header>
        <div class="admin-content user-content">
