<?php
require_once __DIR__ . '/config/config.php';
requireAuth();

$esAdmin = (($_SESSION['rol'] ?? 'usuario') === 'admin');
$title = 'Perfil - Control Sedes';

if ($esAdmin) {
    require_once __DIR__ . '/includes/header.php';
} else {
    $userNavCurrent = 'perfil';
    require_once __DIR__ . '/includes/header_user_app.php';
}
?>

<h3 class="mb-3"><i class="bi bi-person-circle me-2"></i>Perfil</h3>

<div class="card<?php echo $esAdmin ? '' : ' border-0 shadow-sm rounded-4'; ?>" style="<?php echo $esAdmin ? '' : 'max-width: 720px;'; ?>">
  <div class="card-body<?php echo $esAdmin ? '' : ' p-4'; ?>">
    <div class="row g-3">
      <div class="col-12 col-md-6">
        <div class="text-muted small">Nombre</div>
        <div class="fw-semibold"><?php echo htmlspecialchars($_SESSION['nombre_completo'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="col-12 col-md-6">
        <div class="text-muted small">Email</div>
        <div class="fw-semibold"><?php echo htmlspecialchars($_SESSION['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="col-12 col-md-6">
        <div class="text-muted small">Rol</div>
        <div class="fw-semibold"><?php echo htmlspecialchars($_SESSION['rol'] ?? 'usuario', ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
      <div class="col-12 col-md-6">
        <div class="text-muted small">Acciones</div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <a class="btn btn-outline-secondary btn-sm" href="<?php echo htmlspecialchars(urlInicioApp(), ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-arrow-left me-1"></i>Volver
          </a>
          <a class="btn btn-outline-danger btn-sm" href="<?php echo BASE_URL; ?>logout.php"><i class="bi bi-box-arrow-right me-1"></i>Salir</a>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
if ($esAdmin) {
    require_once __DIR__ . '/includes/footer.php';
} else {
    require_once __DIR__ . '/includes/footer_user_app.php';
}
