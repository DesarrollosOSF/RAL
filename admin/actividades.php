<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

const ESTADO_ASIGNADAS_SLUG = 'asignadas';

/**
 * Fecha de creación en español (ej. 26 de marzo, 2026 • 09:57 AM).
 */
function formatFechaActividadCreada(string $sqlDatetime): string
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

function actividadAvatarInitials(string $titulo): string
{
    $parts = preg_split('/\s+/', trim($titulo), -1, PREG_SPLIT_NO_EMPTY);
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

function actividadAvatarVariant(string $titulo): int
{
    return (int)(abs(crc32($titulo)) % 6);
}

$pdo = getDBConnection();
ensureActividadesActivoColumn($pdo);
ensureRalActividadColumns($pdo);
$jornadaId = getOrCreateJornadaHoy($pdo);

$msg = '';
$error = '';
$postTitulo = '';
$postDesc = '';
$postGrupoId = 0;
$postServicioSubtipo = '';
$gruposRal = getRalGrupos($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = verifyCsrfToken($_POST['csrf_token'] ?? '');
    $accionPost = sanitizar((string)($_POST['accion'] ?? 'crear'));

    if (!$csrfOk) {
        $error = 'Token CSRF inválido.';
    } elseif ($accionPost === 'toggle_activo') {
        $actividadIdToggle = (int)($_POST['actividad_id'] ?? 0);
        $nuevoActivo = (int)($_POST['activo'] ?? 0) === 1 ? 1 : 0;
        if ($actividadIdToggle <= 0) {
            $error = 'Actividad inválida.';
        } else {
            try {
                $stmtExiste = $pdo->prepare('SELECT id FROM actividades WHERE id = ? LIMIT 1');
                $stmtExiste->execute([$actividadIdToggle]);
                if (!$stmtExiste->fetch()) {
                    $error = 'No se encontró la actividad.';
                } else {
                    $stmtUpd = $pdo->prepare('UPDATE actividades SET activo = ? WHERE id = ?');
                    $stmtUpd->execute([$nuevoActivo, $actividadIdToggle]);
                    $msg = $nuevoActivo === 1
                        ? 'Actividad activada. Ya es visible para los usuarios.'
                        : 'Actividad inactivada. Dejó de mostrarse a los usuarios; el historial se conserva.';
                }
            } catch (Exception $e) {
                $error = 'No se pudo actualizar el estado de la actividad.';
            }
        }
    } elseif ($accionPost === 'actualizar_grupo') {
        $actividadIdUpd = (int)($_POST['actividad_id'] ?? 0);
        $grupoIdUpd = (int)($_POST['grupo_id'] ?? 0);
        $subUpd = trim((string)($_POST['servicio_subtipo'] ?? ''));
        if ($subUpd!=='' && !in_array($subUpd, RAL_SERVICIO_SUBTIPOS, true)) $subUpd='';
        if ($actividadIdUpd<=0) $error='Actividad inválida.';
        else {
            // validar grupo existe o 0 (sin grupo)
            $grupoOk = $grupoIdUpd===0;
            foreach($gruposRal as $g) if((int)$g['id']===$grupoIdUpd) $grupoOk=true;
            if(!$grupoOk) $error='Grupo inválido.';
            else {
                $pdo->prepare("UPDATE actividades SET grupo_id=?, servicio_subtipo=? WHERE id=?")->execute([$grupoIdUpd?:null, $subUpd?:null, $actividadIdUpd]);
                $msg='Clasificación RAL actualizada.';
            }
        }
    } else {
        $postTitulo = trim((string)($_POST['titulo'] ?? ''));
        $postDesc = trim((string)($_POST['descripcion'] ?? ''));
        $postGrupoId = (int)($_POST['grupo_id'] ?? 0);
        $postServicioSubtipo = trim((string)($_POST['servicio_subtipo'] ?? ''));
        if ($postServicioSubtipo!=='' && !in_array($postServicioSubtipo, RAL_SERVICIO_SUBTIPOS, true)) $postServicioSubtipo='';
        $titulo = sanitizar($postTitulo);
        $descripcion = $postDesc;

        if ($titulo === '') {
            $error = 'El título es obligatorio.';
        } else {
            $grupoOk = $postGrupoId===0;
            foreach($gruposRal as $g) if((int)$g['id']===$postGrupoId) $grupoOk=true;
            if(!$grupoOk) $error='Grupo RAL inválido.';
            else {
            $enTransaccion = false;
            try {
                $stmtDup = $pdo->prepare('
                    SELECT id, titulo
                    FROM actividades
                    WHERE LOWER(TRIM(titulo)) = LOWER(TRIM(?))
                    LIMIT 1
                ');
                $stmtDup->execute([$titulo]);
                $dup = $stmtDup->fetch(PDO::FETCH_ASSOC);
                if ($dup) {
                    $error = 'Ya existe una actividad con el nombre «' . (string)$dup['titulo'] . '». Usa un título diferente.';
                } else {
                    $pdo->beginTransaction();
                    $enTransaccion = true;

                    $stmtInsAct = $pdo->prepare('INSERT INTO actividades (titulo, descripcion, activo, grupo_id, servicio_subtipo) VALUES (?, ?, 1, ?, ?)');
                    $stmtInsAct->execute([$titulo, $descripcion, $postGrupoId?:null, $postServicioSubtipo?:null]);
                    $actividadId = (int)$pdo->lastInsertId();

                    $stmtEstado = $pdo->prepare('SELECT id FROM actividad_estados WHERE slug = ? LIMIT 1');
                    $stmtEstado->execute([ESTADO_ASIGNADAS_SLUG]);
                    $estadoAsignadas = $stmtEstado->fetch();
                    if (!$estadoAsignadas) {
                        throw new Exception('No existe estado "Asignadas".');
                    }
                    $estadoAsignadasId = (int)$estadoAsignadas['id'];

                    $stmtInsUA = $pdo->prepare('
                        INSERT INTO actividades_usuario (jornada_id, actividad_id, usuario_id, estado_id, tiempo_acumulado_seg, tiempo_intervalo_inicio_at)
                        SELECT ?, ?, u.id, ?, 0, NULL
                        FROM usuarios u
                        WHERE u.activo = 1
                          AND NOT EXISTS (
                            SELECT 1
                            FROM actividades_usuario au
                            WHERE au.jornada_id = ?
                              AND au.actividad_id = ?
                              AND au.usuario_id = u.id
                          )
                    ');
                    $stmtInsUA->execute([$jornadaId, $actividadId, $estadoAsignadasId, $jornadaId, $actividadId]);

                    $pdo->commit();
                    $enTransaccion = false;
                    $msg = 'Actividad creada y asignada a todos los usuarios activos.';
                    $postTitulo = '';
                    $postDesc = '';
                    $postGrupoId = 0;
                    $postServicioSubtipo = '';
                }
            } catch (Exception $e) {
                if ($enTransaccion && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error al crear la actividad.';
            }
        }
        }}
}

$qBuscar = isset($_GET['q'])
    ? trim((string)$_GET['q'])
    : (isset($_POST['q']) ? trim((string)$_POST['q']) : '');
$qBuscar = mb_substr($qBuscar, 0, 160);

$rowsPerPage = 8;
$currentPage = isset($_GET['page'])
    ? (int)$_GET['page']
    : (isset($_POST['page']) ? (int)$_POST['page'] : 1);
if ($currentPage < 1) {
    $currentPage = 1;
}

$patronLike = null;
if ($qBuscar !== '') {
    $patronLike = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qBuscar) . '%';
}

if ($patronLike !== null) {
    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM actividades WHERE titulo LIKE ?');
    $stmtCount->execute([$patronLike]);
    $totalRows = (int)$stmtCount->fetchColumn();
} else {
    $totalRows = (int)$pdo->query('SELECT COUNT(*) FROM actividades')->fetchColumn();
}

$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;

if ($patronLike !== null) {
    $stmtList = $pdo->prepare('
        SELECT id, titulo, descripcion, activo, grupo_id, servicio_subtipo, created_at
        FROM actividades
        WHERE titulo LIKE ?
        ORDER BY activo DESC, created_at DESC
        LIMIT ? OFFSET ?
    ');
    $stmtList->execute([$patronLike, $rowsPerPage, $offset]);
} else {
    $stmtList = $pdo->prepare('
        SELECT id, titulo, descripcion, activo, grupo_id, servicio_subtipo, created_at
        FROM actividades
        ORDER BY activo DESC, created_at DESC
        LIMIT ? OFFSET ?
    ');
    $stmtList->execute([$rowsPerPage, $offset]);
}

$actividades = $stmtList->fetchAll();
$shownTo = min($totalRows, $offset + count($actividades));

$title = 'Crear actividad - Control Sedes';
$adminNavCurrent = 'actividades';
$adminSidebarFootInclude = __DIR__ . '/../includes/partials/admin_sidebar_help_actividades.php';
$qUsuario = '';
$sedeIdFiltro = 0;

require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>

<div class="admin-act-page">
  <div class="admin-act-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-box-seam text-primary me-2"></i>Crear actividad</h1>
      <p class="admin-act-page-lead">Define una nueva actividad para comenzar a registrar tiempo.</p>
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
        <input type="hidden" name="accion" value="crear">

        <div class="mb-3">
          <label class="form-label admin-act-label" for="act-titulo">Título de la actividad <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-pencil" aria-hidden="true"></i>
            <input type="text" class="form-control" id="act-titulo" name="titulo" maxlength="160" required
              placeholder="Ej: Reunión de equipo, Inventario, Capacitación..."
              value="<?php echo htmlspecialchars($postTitulo, ENT_QUOTES, 'UTF-8'); ?>">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="act-desc">Descripción <span class="text-muted fw-normal">(opcional)</span></label>
          <div class="admin-act-input-wrap admin-act-input-wrap--textarea">
            <i class="bi bi-file-text" aria-hidden="true"></i>
            <textarea class="form-control" id="act-desc" name="descripcion" rows="5" maxlength="1000"
              placeholder="Agrega detalles sobre el objetivo, alcance o notas importantes..."><?php echo htmlspecialchars($postDesc, ENT_QUOTES, 'UTF-8'); ?></textarea>
          </div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-12 col-md-7">
            <label class="form-label admin-act-label" for="act-grupo">Grupo RAL <span class="text-muted fw-normal">(solo visible en reportes admin)</span></label>
            <div class="admin-act-input-wrap">
              <i class="bi bi-collection" aria-hidden="true"></i>
              <select class="form-select" id="act-grupo" name="grupo_id">
                <option value="0">— Sin clasificar —</option>
                <?php foreach($gruposRal as $gr): ?><option value="<?php echo (int)$gr['id']; ?>" <?php echo (int)$postGrupoId===(int)$gr['id']?'selected':''; ?>><?php echo htmlspecialchars($gr['nombre'],ENT_QUOTES,'UTF-8'); ?></option><?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="col-12 col-md-5" id="wrap-serv-subtipo" style="<?php echo (int)$postGrupoId===RAL_GRUPO_SERVICIOS?'':'display:none;'; ?>">
            <label class="form-label admin-act-label" for="act-subtipo">Subtipo servicio</label>
            <select class="form-select" id="act-subtipo" name="servicio_subtipo">
              <option value="">— —</option>
              <?php foreach(RAL_SERVICIO_SUBTIPOS as $st): ?><option value="<?php echo $st; ?>" <?php echo $postServicioSubtipo===$st?'selected':''; ?>><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$st)),ENT_QUOTES,'UTF-8'); ?></option><?php endforeach; ?>
            </select>
            <div class="form-text small">Para "Prestación servicio" y "Pagar Destino Final": completo / inicial / final / terceros / mascotas / pago destino final.</div>
          </div>
        </div>
        <script>document.getElementById('act-grupo')?.addEventListener('change',function(){var w=document.getElementById('wrap-serv-subtipo'); w.style.display=parseInt(this.value,10)===<?php echo RAL_GRUPO_SERVICIOS; ?>?'':'none';});</script>

        <div class="admin-act-info-box" role="status">
          <i class="bi bi-info-circle flex-shrink-0" aria-hidden="true"></i>
          <span>Después de crear la actividad, podrás iniciarla, pausarla, reanudarla y finalizarla.</span>
        </div>

        <button class="btn btn-primary w-100 admin-act-submit" type="submit">
          <i class="bi bi-plus-lg me-2"></i>Crear actividad
        </button>

        <p class="admin-act-form-foot mb-0">
          <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Solo los administradores pueden crear actividades.
        </p>
      </form>
    </div>

    <div class="admin-act-card admin-act-list-card">
      <div class="admin-act-list-head">
        <div class="d-flex align-items-start gap-3">
          <div class="admin-act-list-head-icon" aria-hidden="true">
            <i class="bi bi-clock-history"></i>
          </div>
          <div>
            <h2 class="admin-act-list-title">Actividades</h2>
            <p class="admin-act-list-sub">Activa o inactiva para mostrarlas u ocultarlas en el tablero.</p>
          </div>
        </div>
        <span class="admin-act-list-badge"><?php echo (int)$shownTo; ?> de <?php echo (int)$totalRows; ?></span>
      </div>

      <form method="GET" action="" class="admin-act-search-row" id="act-search-form" role="search">
        <div class="admin-act-search-field">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" name="q" value="<?php echo htmlspecialchars($qBuscar, ENT_QUOTES, 'UTF-8'); ?>"
            placeholder="Buscar por nombre de actividad..." maxlength="160" autocomplete="off" aria-label="Buscar actividad">
        </div>
        <button type="submit" class="btn admin-act-filter-btn" title="Buscar" aria-label="Buscar">
          <i class="bi bi-search"></i>
        </button>
        <?php if ($qBuscar !== ''): ?>
          <a class="btn admin-act-filter-btn admin-act-filter-btn--outline" href="<?php echo htmlspecialchars(BASE_URL . 'admin/actividades.php', ENT_QUOTES, 'UTF-8'); ?>" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">
            <i class="bi bi-x-lg"></i>
          </a>
        <?php else: ?>
          <button type="button" class="btn admin-act-filter-btn" title="Filtros" aria-label="Filtros (próximamente)" disabled>
            <i class="bi bi-funnel-fill"></i>
          </button>
        <?php endif; ?>
      </form>

      <div class="admin-act-list-body">
        <?php if (!$actividades): ?>
          <div class="admin-act-list-empty">No hay actividades<?php echo $qBuscar !== '' ? ' que coincidan con la búsqueda.' : '.'; ?></div>
        <?php else: ?>
          <ul class="admin-act-list list-unstyled mb-0">
            <?php foreach ($actividades as $a): ?>
              <?php
                $av = actividadAvatarVariant((string)$a['titulo']);
                $ini = actividadAvatarInitials((string)$a['titulo']);
                $fechaTxt = formatFechaActividadCreada((string)$a['created_at']);
                $estaActiva = (int)($a['activo'] ?? 1) === 1;
                $toggleActivo = $estaActiva ? 0 : 1;
              ?>
              <li class="admin-act-list-item <?php echo $estaActiva ? '' : 'admin-act-list-item--inactive'; ?>" style="flex-wrap:wrap;">
                <div class="admin-act-avatar admin-act-avatar--<?php echo $av; ?>" aria-hidden="true"><?php echo htmlspecialchars($ini, ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="admin-act-list-item-main">
                  <div class="admin-act-list-item-title">
                    <?php echo htmlspecialchars((string)$a['titulo'], ENT_QUOTES, 'UTF-8'); ?>
                    <span class="admin-act-status-pill <?php echo $estaActiva ? 'admin-act-status-pill--on' : 'admin-act-status-pill--off'; ?>">
                      <?php echo $estaActiva ? 'Activa' : 'Inactiva'; ?>
                    </span>
                    <?php $gid=(int)($a['grupo_id']??0); if($gid>0): $gNom=''; foreach($gruposRal as $gr) if((int)$gr['id']===$gid) $gNom=$gr['nombre']; ?>
                      <span class="badge bg-primary-subtle text-primary border small" title="Grupo RAL"><?php echo htmlspecialchars($gNom,ENT_QUOTES,'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if(!empty($a['servicio_subtipo'])): ?><span class="badge bg-info-subtle text-info border small"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$a['servicio_subtipo'])),ENT_QUOTES,'UTF-8'); ?></span><?php endif; ?>
                  </div>
                  <div class="admin-act-list-item-meta">Creada: <?php echo htmlspecialchars($fechaTxt, ENT_QUOTES, 'UTF-8'); ?></div>
                  <form method="POST" class="d-flex gap-1 mt-1 align-items-center" style="flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="accion" value="actualizar_grupo">
                    <input type="hidden" name="actividad_id" value="<?php echo (int)$a['id']; ?>">
                    <select name="grupo_id" class="form-select form-select-sm" style="width:auto;min-width:180px;">
                      <option value="0">— Sin grupo —</option>
                      <?php foreach($gruposRal as $gr): ?><option value="<?php echo (int)$gr['id']; ?>" <?php echo (int)($a['grupo_id']??0)===(int)$gr['id']?'selected':''; ?>><?php echo htmlspecialchars($gr['nombre'],ENT_QUOTES,'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                    
                    <button class="btn btn-sm btn-outline-primary" type="submit" title="Guardar grupo"><i class="bi bi-check-lg"></i></button>
                  </form>
                </div>
                <form method="POST" action="" class="admin-act-toggle-form">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                  <input type="hidden" name="accion" value="toggle_activo">
                  <input type="hidden" name="actividad_id" value="<?php echo (int)$a['id']; ?>">
                  <input type="hidden" name="activo" value="<?php echo (int)$toggleActivo; ?>">
                  <?php if ($qBuscar !== ''): ?>
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($qBuscar, ENT_QUOTES, 'UTF-8'); ?>">
                  <?php endif; ?>
                  <input type="hidden" name="page" value="<?php echo (int)$currentPage; ?>">
                  <button
                    type="submit"
                    class="btn btn-sm <?php echo $estaActiva ? 'btn-outline-secondary' : 'btn-outline-success'; ?> admin-act-toggle-btn"
                    title="<?php echo $estaActiva ? 'Inactivar actividad' : 'Activar actividad'; ?>"
                  >
                    <i class="bi <?php echo $estaActiva ? 'bi-eye-slash' : 'bi-eye'; ?> me-1"></i>
                    <?php echo $estaActiva ? 'Inactivar' : 'Activar'; ?>
                  </button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <?php
          $base = BASE_URL . 'admin/actividades.php';
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
