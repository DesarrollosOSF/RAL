<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

function formatUsuarioCreadoEn(string $sqlDatetime): string
{
    try {
        $dt = new DateTime($sqlDatetime);
    } catch (Exception $e) {
        return $sqlDatetime;
    }
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $mes = $meses[(int)$dt->format('n') - 1];
    $dia = (int)$dt->format('j');
    $anio = $dt->format('Y');
    $hora = $dt->format('h:i A');

    return "{$dia} de {$mes}, {$anio} • {$hora}";
}

function usuarioListInitials(string $nombre): string
{
    $parts = preg_split('/\s+/', trim($nombre), -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return '?';
    }
    if (count($parts) === 0) {
        return '?';
    }
    if (count($parts) === 1) {
        return mb_strtoupper(mb_substr($parts[0], 0, 2));
    }

    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
}

function usuarioAvatarVariant(string $nombre): int
{
    return (int)(abs(crc32($nombre)) % 6);
}

$pdo = getDBConnection();
$jornadaId = getOrCreateJornadaHoy($pdo);

$stmtSedes = $pdo->query('SELECT id, nombre FROM sedes ORDER BY nombre ASC');
$sedes = $stmtSedes->fetchAll(PDO::FETCH_ASSOC);
$sedesById = [];
foreach ($sedes as $s) {
    $sedesById[(int)$s['id']] = (string)$s['nombre'];
}

$msg = '';
$error = '';
$postNombre = '';
$postEmail = '';
$postRol = 'usuario';
$postSedeId = 0;
$editUserId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$isEditMode = $editUserId > 0;

$estadoAsignadasSlug = 'asignadas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = (string)($_POST['form_action'] ?? 'create');
    $postNombre = trim((string)($_POST['nombre_completo'] ?? ''));
    $postEmail = trim((string)($_POST['email'] ?? ''));
    $postRol = sanitizar($_POST['rol'] ?? 'usuario');
    $postSedeId = (int)($_POST['sede_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        if ($formAction === 'delete') {
            $deleteUserId = (int)($_POST['user_id'] ?? 0);
            if ($deleteUserId <= 0) {
                $error = 'Usuario inválido para eliminar.';
            } elseif (!empty($_SESSION['usuario_id']) && $deleteUserId === (int)$_SESSION['usuario_id']) {
                $error = 'No puedes eliminar tu propio usuario en sesión.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $stmtDelUA = $pdo->prepare('DELETE FROM actividades_usuario WHERE usuario_id = ?');
                    $stmtDelUA->execute([$deleteUserId]);
                    $stmtDelUser = $pdo->prepare('DELETE FROM usuarios WHERE id = ? LIMIT 1');
                    $stmtDelUser->execute([$deleteUserId]);
                    if ($stmtDelUser->rowCount() < 1) {
                        throw new Exception('No se encontró el usuario.');
                    }
                    $pdo->commit();
                    $msg = 'Usuario eliminado correctamente.';
                    if ($isEditMode && $editUserId === $deleteUserId) {
                        $isEditMode = false;
                        $editUserId = 0;
                    }
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'No fue posible eliminar el usuario.';
                }
            }
        } else {
            $nombre = sanitizar($postNombre);
            $email = sanitizar($postEmail);
            $rol = in_array($postRol, ['admin', 'usuario', ROL_AUXILIAR_ADMINISTRATIVO, 'auxiliar_administrativos', 'auxiliar_administr'], true) ? $postRol : 'usuario';
            if ($rol === 'auxiliar_administrativos' || $rol === 'auxiliar_administr') {
                $rol = ROL_AUXILIAR_ADMINISTRATIVO;
            }

            if (!isset($sedesById[$postSedeId])) {
                $error = 'Debes seleccionar una sede válida.';
            } elseif ($nombre === '' || $email === '') {
                $error = 'Nombre y email son obligatorios.';
            } elseif ($formAction === 'create' && $password === '') {
                $error = 'La contraseña es obligatoria para crear usuario.';
            }
        }

        if ($error === '' && in_array($formAction, ['create', 'update'], true)) {
            try {
                $pdo->beginTransaction();

                if ($formAction === 'update') {
                    $updateUserId = (int)($_POST['user_id'] ?? 0);
                    if ($updateUserId <= 0) {
                        throw new Exception('Usuario inválido.');
                    }
                    $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ? LIMIT 1');
                    $stmtCheck->execute([$email, $updateUserId]);
                    if ($stmtCheck->fetch()) {
                        throw new Exception('El email ya está registrado.');
                    }
                    if ($password !== '') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmtUpd = $pdo->prepare('UPDATE usuarios SET nombre_completo = ?, email = ?, password_hash = ?, rol = ?, sede_id = ? WHERE id = ? LIMIT 1');
                        $stmtUpd->execute([$nombre, $email, $passwordHash, $rol, $postSedeId, $updateUserId]);
                    } else {
                        $stmtUpd = $pdo->prepare('UPDATE usuarios SET nombre_completo = ?, email = ?, rol = ?, sede_id = ? WHERE id = ? LIMIT 1');
                        $stmtUpd->execute([$nombre, $email, $rol, $postSedeId, $updateUserId]);
                    }
                    $pdo->commit();
                    $msg = 'Usuario actualizado correctamente.';
                    $isEditMode = false;
                    $editUserId = 0;
                    $postNombre = '';
                    $postEmail = '';
                    $postRol = 'usuario';
                    $postSedeId = 0;
                } else {
                    $stmtCheck = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? LIMIT 1');
                    $stmtCheck->execute([$email]);
                    if ($stmtCheck->fetch()) {
                        throw new Exception('El email ya está registrado.');
                    }

                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmtInsU = $pdo->prepare('INSERT INTO usuarios (nombre_completo, email, password_hash, activo, rol, sede_id) VALUES (?, ?, ?, 1, ?, ?)');
                    $stmtInsU->execute([$nombre, $email, $passwordHash, $rol, $postSedeId]);
                    $usuarioId = (int)$pdo->lastInsertId();

                    $stmtEstado = $pdo->prepare('SELECT id FROM actividad_estados WHERE slug = ? LIMIT 1');
                    $stmtEstado->execute([$estadoAsignadasSlug]);
                    $estadoAsignadas = $stmtEstado->fetch();
                    if (!$estadoAsignadas) {
                        throw new Exception('No existe estado "Asignadas".');
                    }
                    $estadoAsignadasId = (int)$estadoAsignadas['id'];

                    $stmtInsUA = $pdo->prepare('
                        INSERT INTO actividades_usuario
                            (jornada_id, actividad_id, usuario_id, estado_id, tiempo_acumulado_seg, tiempo_intervalo_inicio_at)
                        SELECT
                            ?, a.id, ?, ?, 0, NULL
                        FROM actividades a
                        WHERE a.activo = 1
                          AND NOT EXISTS (
                            SELECT 1
                            FROM actividades_usuario au
                            WHERE au.jornada_id = ?
                              AND au.actividad_id = a.id
                              AND au.usuario_id = ?
                        )
                    ');
                    $stmtInsUA->execute([$jornadaId, $usuarioId, $estadoAsignadasId, $jornadaId, $usuarioId]);

                    $pdo->commit();
                    $msg = 'Usuario creado y actividades asignadas.';
                    $postNombre = '';
                    $postEmail = '';
                    $postRol = 'usuario';
                    $postSedeId = 0;
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                if ($e->getMessage() === 'El email ya está registrado.') {
                    $error = 'El email ya está registrado.';
                } else {
                    $error = $formAction === 'update' ? 'Error al actualizar usuario.' : 'Error al crear usuario.';
                }
            }
        }
    }
}

if ($isEditMode && $error === '') {
    $stmtEdit = $pdo->prepare('SELECT id, nombre_completo, email, rol, sede_id FROM usuarios WHERE id = ? LIMIT 1');
    $stmtEdit->execute([$editUserId]);
    $editUser = $stmtEdit->fetch(PDO::FETCH_ASSOC);
    if ($editUser) {
        $postNombre = (string)$editUser['nombre_completo'];
        $postEmail = (string)$editUser['email'];
        $postRol = (string)$editUser['rol'];
        if ($postRol === 'auxiliar_administr' || $postRol === 'auxiliar_administrativos') {
            $postRol = ROL_AUXILIAR_ADMINISTRATIVO;
        }
        $postSedeId = (int)($editUser['sede_id'] ?? 0);
    } else {
        $isEditMode = false;
        $editUserId = 0;
    }
}

$qBuscar = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$qBuscar = mb_substr($qBuscar, 0, 160);

$rowsPerPage = 8;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}

$patronLike = null;
if ($qBuscar !== '') {
    $patronLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qBuscar) . '%';
}

if ($patronLike !== null) {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE nombre_completo LIKE ? OR email LIKE ?');
    $stmtCount->execute([$patronLike, $patronLike]);
    $totalRows = (int)$stmtCount->fetchColumn();
} else {
    $totalRows = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
}

$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;

if ($patronLike !== null) {
    $stmtList = $pdo->prepare('
        SELECT u.id, u.nombre_completo, u.email, u.activo, u.rol, u.created_at, s.nombre AS sede_nombre
        FROM usuarios u
        LEFT JOIN sedes s ON s.id = u.sede_id
        WHERE u.nombre_completo LIKE ? OR u.email LIKE ?
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ');
    $stmtList->execute([$patronLike, $patronLike, $rowsPerPage, $offset]);
} else {
    $stmtList = $pdo->prepare('
        SELECT u.id, u.nombre_completo, u.email, u.activo, u.rol, u.created_at, s.nombre AS sede_nombre
        FROM usuarios u
        LEFT JOIN sedes s ON s.id = u.sede_id
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ');
    $stmtList->execute([$rowsPerPage, $offset]);
}

$usuarios = $stmtList->fetchAll();
$shownTo = min($totalRows, $offset + count($usuarios));

$title = 'Crear usuario - Control Sedes';
$adminNavCurrent = 'usuarios';
$adminSidebarFootInclude = __DIR__ . '/../includes/partials/admin_sidebar_help_usuarios.php';
$qUsuario = '';
$sedeIdFiltro = 0;

require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>

<div class="admin-act-page">
  <div class="admin-act-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-person-plus text-primary me-2"></i><?php echo $isEditMode ? 'Editar usuario' : 'Crear usuario'; ?></h1>
      <p class="admin-act-page-lead"><?php echo $isEditMode ? 'Actualiza datos, sede y rol del usuario seleccionado.' : 'Registra una cuenta nueva y asígnale automáticamente las actividades del sistema.'; ?></p>
    </div>
    <a class="btn admin-act-back-btn" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-arrow-left me-2"></i>Volver al panel
    </a>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success admin-act-alert d-flex align-items-start gap-2" role="alert">
      <i class="bi bi-check-circle-fill flex-shrink-0 mt-1"></i>
      <span><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger admin-act-alert d-flex align-items-start gap-2" role="alert">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
      <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
  <?php endif; ?>

  <div class="admin-act-grid">
    <div class="admin-act-card admin-act-form-card">
      <form method="POST" action="" class="admin-act-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="form_action" value="<?php echo $isEditMode ? 'update' : 'create'; ?>">
        <?php if ($isEditMode): ?>
          <input type="hidden" name="user_id" value="<?php echo (int)$editUserId; ?>">
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="usr-nombre">Nombre completo <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-person" aria-hidden="true"></i>
            <input type="text" class="form-control" id="usr-nombre" name="nombre_completo" maxlength="120" required
              placeholder="Ej: María Pérez, Juan Carlos López..."
              value="<?php echo htmlspecialchars($postNombre, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="usr-email">Correo electrónico <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-envelope" aria-hidden="true"></i>
            <input type="email" class="form-control" id="usr-email" name="email" maxlength="160" required
              placeholder="correo@empresa.com"
              value="<?php echo htmlspecialchars($postEmail, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="usr-pass">Contraseña <?php if (!$isEditMode): ?><span class="text-danger">*</span><?php else: ?><span class="text-muted fw-normal">(opcional)</span><?php endif; ?></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-key" aria-hidden="true"></i>
            <input type="password" class="form-control" id="usr-pass" name="password" <?php echo $isEditMode ? '' : 'required'; ?>
              placeholder="<?php echo $isEditMode ? 'Deja en blanco para conservar la actual' : 'Contraseña segura'; ?>" autocomplete="new-password">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="usr-sede">Sede <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-building" aria-hidden="true"></i>
            <select class="form-select" id="usr-sede" name="sede_id" required>
              <option value="0" <?php echo $postSedeId <= 0 ? 'selected' : ''; ?> disabled>Selecciona una sede</option>
              <?php foreach ($sedes as $s): ?>
                <?php $sid = (int)$s['id']; ?>
                <option value="<?php echo $sid; ?>" <?php echo ($sid === (int)$postSedeId) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars((string)$s['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="usr-rol">Rol</label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-shield-check" aria-hidden="true"></i>
            <select class="form-select" id="usr-rol" name="rol">
              <option value="usuario" <?php echo $postRol === 'usuario' ? 'selected' : ''; ?>>Usuario</option>
              <option value="admin" <?php echo $postRol === 'admin' ? 'selected' : ''; ?>>Administrador</option>
              <option value="<?php echo htmlspecialchars(ROL_AUXILIAR_ADMINISTRATIVO, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $postRol === ROL_AUXILIAR_ADMINISTRATIVO ? 'selected' : ''; ?>>Auxiliar administrativo</option>
            </select>
          </div>
        </div>

        <div class="admin-act-info-box" role="status">
          <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
          <span>Al guardar, el usuario queda activo y se le asignan todas las actividades existentes en estado «Asignadas».</span>
        </div>

        <button class="btn btn-primary w-100 admin-act-submit" type="submit">
          <i class="bi <?php echo $isEditMode ? 'bi-pencil-square' : 'bi-plus-lg'; ?> me-2"></i><?php echo $isEditMode ? 'Guardar cambios' : 'Crear usuario'; ?>
        </button>
        <?php if ($isEditMode): ?>
          <a class="btn btn-outline-secondary w-100 mt-2" href="<?php echo htmlspecialchars(BASE_URL . 'admin/usuarios.php', ENT_QUOTES, 'UTF-8'); ?>">
            Cancelar edición
          </a>
        <?php endif; ?>

        <p class="admin-act-form-foot mb-0">
          <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Solo los administradores pueden crear usuarios.
        </p>
      </form>
    </div>

    <div class="admin-act-card admin-act-list-card">
      <div class="admin-act-list-head">
        <div class="d-flex align-items-start gap-3">
          <div class="admin-act-list-head-icon" aria-hidden="true">
            <i class="bi bi-people"></i>
          </div>
          <div>
            <h2 class="admin-act-list-title">Usuarios recientes</h2>
            <p class="admin-act-list-sub">Listado ordenado por fecha de registro.</p>
          </div>
        </div>
        <span class="admin-act-list-badge"><?php echo (int)$shownTo; ?> de <?php echo (int)$totalRows; ?></span>
      </div>

      <form method="GET" action="" class="admin-act-search-row" id="usr-search-form" role="search">
        <div class="admin-act-search-field">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" name="q" value="<?php echo htmlspecialchars($qBuscar, ENT_QUOTES, 'UTF-8'); ?>"
            placeholder="Buscar por nombre o correo..." maxlength="160" autocomplete="off" aria-label="Buscar usuario">
        </div>
        <button type="submit" class="btn admin-act-filter-btn" title="Buscar" aria-label="Buscar">
          <i class="bi bi-search"></i>
        </button>
        <?php if ($qBuscar !== ''): ?>
          <a class="btn admin-act-filter-btn admin-act-filter-btn--outline" href="<?php echo htmlspecialchars(BASE_URL . 'admin/usuarios.php', ENT_QUOTES, 'UTF-8'); ?>" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">
            <i class="bi bi-x-lg"></i>
          </a>
        <?php else: ?>
          <button type="button" class="btn admin-act-filter-btn" title="Filtros" aria-label="Filtros (próximamente)" disabled>
            <i class="bi bi-funnel-fill"></i>
          </button>
        <?php endif; ?>
      </form>

      <div class="admin-act-list-body">
        <?php if (!$usuarios): ?>
          <div class="admin-act-list-empty">No hay usuarios<?php echo $qBuscar !== '' ? ' que coincidan con la búsqueda.' : '.'; ?></div>
        <?php else: ?>
          <ul class="admin-act-list list-unstyled mb-0">
            <?php foreach ($usuarios as $u): ?>
              <?php
                $nom = (string)$u['nombre_completo'];
                $av = usuarioAvatarVariant($nom);
                $ini = usuarioListInitials($nom);
                $fechaTxt = formatUsuarioCreadoEn((string)$u['created_at']);
                $activoTxt = ((int)$u['activo'] === 1) ? 'Activo' : 'Inactivo';
                $rolTxt = (string)$u['rol'];
              ?>
              <li class="admin-act-list-item">
                <div class="admin-act-avatar admin-act-avatar--<?php echo $av; ?>" aria-hidden="true"><?php echo htmlspecialchars($ini, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="admin-act-list-item-main">
                  <div class="admin-act-list-item-title"><?php echo htmlspecialchars($nom, ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="admin-act-list-item-meta"><?php echo htmlspecialchars((string)$u['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="admin-act-list-item-meta admin-act-list-item-meta--compact">
                    <span class="badge rounded-pill bg-light text-dark border"><?php echo htmlspecialchars((string)($u['sede_nombre'] ?: 'Sin sede'), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-muted">·</span>
                    <span class="badge rounded-pill bg-light text-dark border"><?php echo htmlspecialchars($rolTxt, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-muted">·</span>
                    <span><?php echo htmlspecialchars($activoTxt, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="text-muted">·</span>
                    <span>Creada: <?php echo htmlspecialchars($fechaTxt, ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2 ms-2">
                  <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars(BASE_URL . 'admin/usuarios.php?edit_id=' . (int)$u['id'], ENT_QUOTES, 'UTF-8'); ?>" title="Editar usuario">
                    <i class="bi bi-pencil-square"></i>
                  </a>
                  <form method="POST" action="" onsubmit="return confirm('¿Seguro que deseas eliminar este usuario?');" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="form_action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar usuario">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <?php
          $base = BASE_URL . 'admin/usuarios.php';
          $qParam = $qBuscar !== '' ? ('&q=' . rawurlencode($qBuscar)) : '';
        ?>
        <div class="admin-pagination admin-act-pagination">
          <a
            class="btn btn-sm btn-pager <?php echo ($currentPage <= 1) ? 'disabled' : ''; ?>"
            href="<?php echo htmlspecialchars($base . '?page=' . max(1, $currentPage - 1) . $qParam, ENT_QUOTES, 'UTF-8'); ?>"
            aria-label="Página anterior"
          >&laquo;</a>
          <span class="page-info">Página <?php echo (int)$currentPage; ?> de <?php echo (int)$totalPages; ?></span>
          <a
            class="btn btn-sm btn-pager <?php echo ($currentPage >= $totalPages) ? 'disabled' : ''; ?>"
            href="<?php echo htmlspecialchars($base . '?page=' . min($totalPages, $currentPage + 1) . $qParam, ENT_QUOTES, 'UTF-8'); ?>"
            aria-label="Página siguiente"
          >&raquo;</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>
