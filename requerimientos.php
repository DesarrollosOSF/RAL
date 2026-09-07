<?php
require_once __DIR__ . '/config/config.php';
requireAuth();

if (($_SESSION['rol'] ?? 'usuario') === 'admin') {
    header('Location: ' . BASE_URL . 'admin/dashboard.php');
    exit;
}

$csrfToken = getCsrfToken();
$title = 'Requerimientos - Control Sedes';
$userNavCurrent = 'requerimientos';
$nombreUsuario = (string)($_SESSION['nombre_completo'] ?? 'Usuario');
$usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

$pdo = getDBConnection();
$stmtSede = $pdo->prepare('
    SELECT u.sede_id, s.nombre AS sede_nombre
    FROM usuarios u
    LEFT JOIN sedes s ON s.id = u.sede_id
    WHERE u.id = ?
    LIMIT 1
');
$stmtSede->execute([$usuarioId]);
$rowSede = $stmtSede->fetch(PDO::FETCH_ASSOC) ?: [];
$sedeId = (int)($rowSede['sede_id'] ?? 0);
$sedeNombre = trim((string)($rowSede['sede_nombre'] ?? ''));
if ($sedeNombre === '') {
    $sedeNombre = 'Sin sede asignada';
}

$dependencias = [];
$errorDependencias = '';
try {
    $stmtDep = $pdo->query('
        SELECT id, nombre, descripcion
        FROM dependencias
        WHERE activo = 1
        ORDER BY nombre ASC
    ');
    $dependencias = $stmtDep->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errorDependencias = 'No se pudo cargar el catálogo de dependencias. Verifica que la tabla exista en la base de datos.';
}

require_once __DIR__ . '/includes/header_user_app.php';
?>

<div class="admin-act-page user-req-page">
  <div class="admin-act-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-clipboard-plus text-primary me-2"></i>Requerimientos</h1>
      <p class="admin-act-page-lead">Reporta novedades, daños o necesidades de tu sede para que el administrador las tramite.</p>
    </div>
    <a class="btn admin-act-back-btn" href="<?php echo htmlspecialchars(BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-arrow-left me-2"></i>Volver a actividades
    </a>
  </div>

  <div id="req-alert-zone" class="user-req-alert-zone" aria-live="polite"></div>
  <?php if ($errorDependencias !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-3 mb-3" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorDependencias, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <div class="admin-act-grid user-req-grid">
    <div class="admin-act-card admin-act-form-card user-req-form-card">
      <div class="user-req-form-head">
        <div class="admin-act-list-head-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></div>
        <div>
          <h2 class="admin-act-list-title mb-0">Nuevo requerimiento</h2>
          <p class="admin-act-list-sub">Describe con claridad qué ocurre y dónde.</p>
        </div>
      </div>

      <form id="req-form" class="admin-act-form mt-3" novalidate>
        <div class="admin-act-info-box user-req-sede-info mb-3" role="status">
          <i class="bi bi-building" aria-hidden="true"></i>
          <div>
            <span class="user-req-sede-info-label">Sede</span>
            <strong class="user-req-sede-info-name"><?php echo htmlspecialchars($sedeNombre, ENT_QUOTES, 'UTF-8'); ?></strong>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="req-titulo">Asunto <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-card-text" aria-hidden="true"></i>
            <input type="text" class="form-control" id="req-titulo" name="titulo" maxlength="120" required
              placeholder="Ej: Papelería, Camara desconectada, etc.">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="req-dependencia">Dependencia</label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
            <select class="form-select" id="req-dependencia" name="dependencia_id" <?php echo empty($dependencias) ? 'disabled' : ''; ?>>
              <option value="" selected>Selecciona una dependencia</option>
              <?php foreach ($dependencias as $dep): ?>
                <?php $depId = (int)$dep['id']; ?>
                <option value="<?php echo $depId; ?>">
                  <?php echo htmlspecialchars((string)$dep['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (empty($dependencias) && $errorDependencias === ''): ?>
            <p class="form-text text-muted small mb-0 mt-1">No hay dependencias activas registradas.</p>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="req-prioridad">Prioridad <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap">
            <i class="bi bi-flag" aria-hidden="true"></i>
            <select class="form-select" id="req-prioridad" name="prioridad" required>
              <option value="baja">Baja</option>
              <option value="media" selected>Media</option>
              <option value="alta">Alta</option>
              <option value="urgente">Urgente</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label admin-act-label" for="req-descripcion">Descripción <span class="text-danger">*</span></label>
          <div class="admin-act-input-wrap admin-act-input-wrap--textarea">
            <i class="bi bi-chat-left-text" aria-hidden="true"></i>
            <textarea class="form-control" id="req-descripcion" name="descripcion" rows="5" maxlength="2000" required
              placeholder="Detalla qué se dañó, desde cuándo ocurre, si afecta el servicio, etc."></textarea>
          </div>
        </div>

        <fieldset class="user-req-adjunto mb-3">
          <legend class="form-label admin-act-label mb-2">Adjunto <span class="text-muted fw-normal">(opcional)</span></legend>
          <p class="user-req-adjunto-hint small text-muted mb-2">Puedes adjuntar una imagen o un documento PDF (máx. 5 MB).</p>
          <div class="user-req-adjunto-tipo" role="radiogroup" aria-label="Tipo de adjunto">
            <label class="user-req-adjunto-tipo-opt">
              <input type="radio" name="adjunto_tipo" value="ninguno" id="req-adjunto-ninguno" checked>
              <span><i class="bi bi-x-circle"></i>Sin adjunto</span>
            </label>
            <label class="user-req-adjunto-tipo-opt">
              <input type="radio" name="adjunto_tipo" value="imagen" id="req-adjunto-imagen">
              <span><i class="bi bi-image"></i>Imagen</span>
            </label>
            <label class="user-req-adjunto-tipo-opt">
              <input type="radio" name="adjunto_tipo" value="pdf" id="req-adjunto-pdf">
              <span><i class="bi bi-file-earmark-pdf"></i>PDF</span>
            </label>
          </div>
          <div id="req-adjunto-panel" class="user-req-adjunto-panel" hidden>
            <label class="user-req-adjunto-drop" for="req-adjunto-file" id="req-adjunto-drop">
              <i class="bi bi-cloud-arrow-up user-req-adjunto-drop-icon" aria-hidden="true"></i>
              <span class="user-req-adjunto-drop-text" id="req-adjunto-drop-text">Selecciona o arrastra tu archivo aquí</span>
              <span class="user-req-adjunto-drop-hint small text-muted" id="req-adjunto-drop-hint"></span>
            </label>
            <input type="file" class="visually-hidden" id="req-adjunto-file" name="adjunto" aria-describedby="req-adjunto-drop-hint">
            <div id="req-adjunto-preview" class="user-req-adjunto-preview" hidden>
              <div class="user-req-adjunto-preview-inner">
                <div id="req-adjunto-preview-media" class="user-req-adjunto-preview-media" aria-hidden="true"></div>
                <div class="user-req-adjunto-preview-info">
                  <strong id="req-adjunto-preview-name" class="user-req-adjunto-preview-name"></strong>
                  <span id="req-adjunto-preview-size" class="user-req-adjunto-preview-size text-muted small"></span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger user-req-adjunto-remove" id="req-adjunto-remove" aria-label="Quitar archivo">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
            </div>
            <p id="req-adjunto-error" class="user-req-adjunto-error small text-danger mb-0 mt-2" role="alert" hidden></p>
          </div>
        </fieldset>

        <button type="submit" class="btn btn-primary admin-act-submit w-100" id="req-submit-btn">
          <i class="bi bi-send me-2"></i>Enviar requerimiento
        </button>
        <p class="admin-act-form-foot mt-3 mb-0">
          <i class="bi bi-shield-check me-1"></i>El administrador recibirá tu reporte para darle trámite.
        </p>
      </form>
    </div>

    <div class="admin-act-card admin-act-list-card user-req-list-card">
      <div class="admin-act-list-head">
        <div class="d-flex align-items-start gap-3">
          <div class="admin-act-list-head-icon"><i class="bi bi-list-check" aria-hidden="true"></i></div>
          <div>
            <h2 class="admin-act-list-title">Mis requerimientos</h2>
            <p class="admin-act-list-sub">Historial de reportes que has registrado.</p>
          </div>
        </div>
        <span class="admin-act-list-badge" id="req-count-badge">0 registrados</span>
      </div>

      <div class="user-req-filter-pills" role="tablist" aria-label="Filtrar por estado">
        <button type="button" class="user-req-pill active" data-req-filter="todos" role="tab" aria-selected="true">Todos</button>
        <button type="button" class="user-req-pill" data-req-filter="pendiente" role="tab" aria-selected="false">Pendientes</button>
        <button type="button" class="user-req-pill" data-req-filter="en_tramite" role="tab" aria-selected="false">En trámite</button>
        <button type="button" class="user-req-pill" data-req-filter="cerrado" role="tab" aria-selected="false">Cerrados</button>
      </div>

      <div class="admin-act-search-row">
        <div class="admin-act-search-field">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" class="form-control" id="req-search" placeholder="Buscar por asunto o dependencia..." autocomplete="off" aria-label="Buscar requerimientos">
        </div>
      </div>

      <div id="req-list" class="user-req-list" aria-live="polite">
        <div class="user-req-empty" id="req-empty">
          <i class="bi bi-inbox" aria-hidden="true"></i>
          <p class="fw-semibold mb-1">Aún no tienes requerimientos</p>
          <p class="text-muted small mb-0">Completa el formulario para registrar el primero.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  window.__REQ_CSRF__ = <?php echo json_encode($csrfToken); ?>;
  window.__REQ_USUARIO__ = <?php echo json_encode($nombreUsuario); ?>;
  window.__REQ_USUARIO_ID__ = <?php echo (int)$usuarioId; ?>;
  window.__REQ_SEDE_ID__ = <?php echo (int)$sedeId; ?>;
  window.__REQ_SEDE_NOMBRE__ = <?php echo json_encode($sedeNombre); ?>;
  window.__REQ_UPLOAD__ = <?php echo json_encode([
      'maxBytes' => REQUERIMIENTO_ARCHIVO_MAX_BYTES,
      'rutaBase' => 'uploads/requerimientos/',
  ]); ?>;
  window.__REQ_API__ = <?php echo json_encode(BASE_URL . 'api/requerimientos.php'); ?>;
</script>
<script src="<?php echo htmlspecialchars(ASSETS_URL . 'js/requerimientos.js', ENT_QUOTES, 'UTF-8'); ?>"></script>

<?php require_once __DIR__ . '/includes/footer_user_app.php'; ?>
