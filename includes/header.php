<?php
// $title: string (opcional)
$title = $title ?? 'Control Sedes';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>css/board.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo htmlspecialchars(!empty($_SESSION['usuario_id']) ? urlInicioApp() : BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-clock-history me-2"></i>Control Sedes
        </a>
        <div class="d-flex align-items-center gap-2">
            <?php if (!empty($_SESSION['usuario_id'])): ?>
                <span class="text-white-50 small d-none d-md-inline">
                    <?php echo htmlspecialchars($_SESSION['nombre_completo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="<?php echo BASE_URL; ?>perfil.php">
                    <i class="bi bi-person me-1"></i>Perfil
                </a>
                <a class="btn btn-danger btn-sm" href="<?php echo BASE_URL; ?>logout.php">
                    <i class="bi bi-box-arrow-right me-1"></i>Salir
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="container py-4">

