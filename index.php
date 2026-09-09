<?php
require_once __DIR__ . '/config/config.php';
requireAuth();

if (($_SESSION['rol'] ?? 'usuario') === 'admin') {
    header('Location: ' . BASE_URL . 'admin/dashboard.php');
    exit;
}

$csrfToken = getCsrfToken();
$pdo = getDBConnection();
$actividadesModalNotasIds = getActividadesConModalNotasIds($pdo);
$actividadNotasOtrosId = getActividadNotasOtrosId($pdo);
$actividadNotasFacturacionId = getActividadNotasFacturacionId($pdo);
// Actividades del grupo "Servicios" a las que se les pide detalle completo/inicial/final + terceros/mascota
$actividadesServicioSubtipoIds = [];
try {
    ensureRalActividadColumns($pdo);
    // Solo actividades de Servicios que NO tengan ya un subtipo fijo (ej. "Pagar Destino Final" sí lo tiene)
    $stmtServ = $pdo->prepare("SELECT id FROM actividades WHERE grupo_id = ? AND (servicio_subtipo IS NULL OR servicio_subtipo = '')");
    $stmtServ->execute([RAL_GRUPO_SERVICIOS]);
    $actividadesServicioSubtipoIds = array_map('intval', $stmtServ->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable $e) {
    $actividadesServicioSubtipoIds = [(int)ACTIVIDAD_NOTAS_FUNERARIO_ID];
}
$title = 'Actividades - Control Sedes';
$userNavCurrent = 'actividades';
require_once __DIR__ . '/includes/header_user_app.php';
?>

<div class="user-activities-hero">
  <div>
    <h1><i class="bi bi-kanban me-2 text-primary"></i>Actividades</h1>
    <p class="user-hero-lead">Inicia, pausa, reanuda y finaliza actividades. El tiempo se guarda por actividad.</p>
  </div>
  <div class="user-hero-search">
    <div class="admin-search">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="search" id="search-actividad" placeholder="Buscar actividad..." autocomplete="off" aria-label="Buscar actividad">
      <span class="admin-search-kbd" title="Atajo de teclado">Ctrl + K</span>
    </div>
  </div>
</div>

<?php if (usuarioEsAuxiliarAdministrativo()): ?>
<div class="user-jornada-main-card jornada-progress-wrap" id="jornada-progress-wrap">
  <div class="d-flex justify-content-between align-items-baseline flex-wrap gap-2 mb-2">
    <span class="text-muted small">Avance de jornada · Meta fija <?php echo htmlspecialchars(msOrSegToHMSFromSeconds(META_JORNADA_SEG_FIJA), ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="small fw-semibold" id="jornada-progress-label">0 % — 00:00:00 / <?php echo htmlspecialchars(msOrSegToHMSFromSeconds(META_JORNADA_SEG_FIJA), ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <div class="progress" style="height:14px;" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="jornada-progress-root">
    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="jornada-progress-bar" style="width:0%"></div>
  </div>
</div>
<?php endif; ?>

<div class="user-board-filters">
  <div class="user-status-pills" role="tablist" aria-label="Filtrar columnas en móvil">
    <button type="button" class="user-status-pill user-status-pill--asignadas active" role="tab" aria-selected="true" data-column="asignadas">
      Asignadas <span class="user-pill-count" data-pill-count="asignadas">0</span>
    </button>
    <button type="button" class="user-status-pill user-status-pill--iniciadas" role="tab" aria-selected="false" data-column="iniciadas">
      Iniciadas <span class="user-pill-count" data-pill-count="iniciadas">0</span>
    </button>
    <button type="button" class="user-status-pill user-status-pill--finalizadas" role="tab" aria-selected="false" data-column="finalizadas">
      Finalizadas <span class="user-pill-count" data-pill-count="finalizadas">0</span>
    </button>
  </div>
  <div class="user-board-sort">
    <label for="board-sort-order">Ordenar por</label>
    <select id="board-sort-order" class="form-select form-select-sm" aria-label="Ordenar actividades">
      <option value="nombre" selected>Nombre</option>
    </select>
  </div>
</div>

<div class="board-wrap user-board-wrap">
  <div class="board-columns" role="region" aria-label="Tablero de actividades">
    <div class="board-column user-col-visible" data-column-key="asignadas">
      <div class="column-title">
        <div><i class="bi bi-list-check me-2 text-primary"></i>Asignadas</div>
      </div>
      <div id="col-asignadas" class="cards-zone"></div>
      <div id="pager-asignadas" class="cards-pager-zone"></div>
    </div>

    <div class="board-column" data-column-key="iniciadas">
      <div class="column-title">
        <div><i class="bi bi-play-circle me-2 text-warning"></i>Iniciadas</div>
      </div>
      <div id="col-iniciadas" class="cards-zone"></div>
      <div id="pager-iniciadas" class="cards-pager-zone"></div>
    </div>

    <div class="board-column" data-column-key="finalizadas">
      <div class="column-title">
        <div><i class="bi bi-check2-circle me-2 text-success"></i>Finalizadas</div>
      </div>
      <div id="col-finalizadas" class="cards-zone"></div>
      <div id="pager-finalizadas" class="cards-pager-zone"></div>
    </div>
  </div>
</div>

<div class="user-board-footer-hint" role="note">
  <i class="bi bi-info-circle" aria-hidden="true"></i>
  <span>El tiempo se guarda automáticamente por actividad. Puedes consultar el historial de cada tarjeta para ver más detalles.</span>
</div>

<!-- Modal notas actividad (funerario, Otros, Facturación, etc.) -->
<div class="modal fade notas-actividad-modal" id="actividadNotasModal" tabindex="-1" aria-labelledby="actividadNotasModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered notas-actividad-dialog">
    <div class="modal-content notas-actividad-content">
      <div class="modal-header notas-actividad-header">
        <div class="notas-actividad-header-main">
          <div class="notas-actividad-icon" aria-hidden="true">
            <i class="bi bi-journal-text"></i>
          </div>
          <div class="notas-actividad-titles">
            <h5 class="modal-title" id="actividadNotasModalLabel">Notas de actividad</h5>
            <p class="notas-actividad-subtitle mb-0" id="actividadNotasModalSub">Actividad</p>
          </div>
        </div>
        <button type="button" class="btn-close notas-actividad-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body notas-actividad-body">
        <div class="row g-3 notas-actividad-fields-row">
          <div class="col-sm-6">
            <label class="notas-actividad-label" for="actividadNotasFallecido" id="actividadNotasCampoLabel">Fallecido</label>
            <input type="text" class="notas-actividad-input" id="actividadNotasFallecido" maxlength="200" placeholder="Nombre del fallecido" autocomplete="off" aria-label="Fallecido">
          </div>
          <div class="col-sm-6">
            <label class="notas-actividad-label" for="actividadNotasFecha">Fecha</label>
            <input type="text" class="notas-actividad-input notas-actividad-input--muted" id="actividadNotasFecha" readonly tabindex="-1" aria-readonly="true">
          </div>
        </div>
        <div class="row g-3 notas-actividad-fields-row" id="actividadNotasServicioWrap" style="display:none;">
          <div class="col-sm-6">
            <label class="notas-actividad-label" for="actividadNotasServicioTipo">Tipo de servicio</label>
            <select class="notas-actividad-input" id="actividadNotasServicioTipo" aria-label="Tipo de servicio">
              <option value="">— seleccionar —</option>
              <option value="empresarial">Empresarial</option>
              <option value="particular">Particular</option>
              <option value="osf">OSF</option>
              <option value="terceros">Terceros</option>
              <option value="mascotas">Mascotas</option>
              <option value="servicios_no_prestados">Servicios no prestados</option>
            </select>
          </div>
          <div class="col-sm-6" id="actividadNotasServicioSubtipoWrap" style="display:none;">
            <label class="notas-actividad-label" for="actividadNotasServicioSubtipo">Subtipo de servicio</label>
            <select class="notas-actividad-input" id="actividadNotasServicioSubtipo" aria-label="Subtipo de servicio" disabled>
              <option value="">— seleccionar —</option>
            </select>
          </div>

          <!-- Campos ocultos de compatibilidad con el API/board.js actual. -->
          <input type="checkbox" id="actividadNotasEsTerceros" class="d-none" tabindex="-1" aria-hidden="true">
          <input type="checkbox" id="actividadNotasEsMascota" class="d-none" tabindex="-1" aria-hidden="true">
        </div>
        <div class="notas-actividad-obs-wrap">
          <label class="notas-actividad-label" for="actividadNotasObservaciones">Observaciones</label>
          <textarea class="notas-actividad-textarea" id="actividadNotasObservaciones" rows="5" maxlength="2000" placeholder="Escriba aquí las novedades, pendientes o actividades realizadas..."></textarea>
        </div>
      </div>
      <div class="modal-footer notas-actividad-footer">
        <button type="button" class="btn notas-actividad-btn-cancel" data-bs-dismiss="modal">
          <i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Cancelar
        </button>
        <button type="button" class="btn notas-actividad-btn-save" id="actividadNotasSaveBtn">
          <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar nota
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal historial -->
<div class="modal fade" id="historialModal" tabindex="-1" aria-labelledby="historialModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title" id="historialModalLabel">
            <i class="bi bi-clock-history me-2"></i>Historial de actividad
          </h5>
          <div class="text-muted small" id="historialModalSub"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-2">
          <div class="col-12 col-md-6">
            <div class="text-muted small">Tiempo acumulado</div>
            <div class="fw-bold" id="historialTotalAcumulado">--</div>
          </div>
          <div class="col-12 col-md-6">
            <div class="text-muted small">Tiempo total (incluye en curso)</div>
            <div class="fw-bold" id="historialTotal">--</div>
          </div>
        </div>

        <hr>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th style="width:52px">#</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th style="width:110px">Duración</th>
                <th>Evento</th>
              </tr>
            </thead>
            <tbody id="historialTbody">
              <tr><td colspan="5" class="text-muted small">Cargando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<script>
  window.__CSRF_TOKEN__ = <?php echo json_encode($csrfToken); ?>;
  window.__META_JORNADA_SEG__ = <?php echo (int)META_JORNADA_SEG_FIJA; ?>;
  window.__TOPE_HORAS_DIARIAS_CONTADOR_SEG__ = <?php echo (int)TOPE_HORAS_DIARIAS_CONTADOR_SEG; ?>;
  window.__ACTIVIDAD_ALMUERZO_ID__ = <?php echo (int)ACTIVIDAD_ALMUERZO_ID; ?>;
  window.__ACTIVIDAD_NOTAS_IDS__ = <?php echo json_encode($actividadesModalNotasIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  window.__ACTIVIDAD_NOTAS_FUNERARIO_ID__ = <?php echo (int)ACTIVIDAD_NOTAS_FUNERARIO_ID; ?>;
  window.__ACTIVIDAD_NOTAS_OTROS_ID__ = <?php echo (int)$actividadNotasOtrosId; ?>;
  window.__ACTIVIDAD_NOTAS_FACTURACION_ID__ = <?php echo (int)$actividadNotasFacturacionId; ?>;
  window.__ACTIVIDAD_SERVICIO_SUBTIPO_IDS__ = <?php echo json_encode($actividadesServicioSubtipoIds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

  /*
   * El board.js existente ya envía servicio_tipo, pero todavía no envía
   * servicio_subtipo. Este pequeño adaptador permite que el formulario nuevo
   * de este index.php también mande el subtipo al API sin tener que modificar
   * el resto del tablero.
   */
  (function () {
    const originalFetch = window.fetch.bind(window);
    window.fetch = function (input, init) {
      try {
        const url = typeof input === 'string'
          ? input
          : (input && input.url ? input.url : '');

        if (String(url).includes('api/actividad_notas.php') && init && init.body instanceof FormData) {
          const subtipo = document.getElementById('actividadNotasServicioSubtipo');
          const tipo = document.getElementById('actividadNotasServicioTipo');

          if (subtipo) {
            init.body.set('servicio_subtipo', subtipo.value || '');
          }

          /* Mantiene las banderas antiguas sincronizadas con el nuevo tipo. */
          if (tipo) {
            init.body.set('es_terceros', tipo.value === 'terceros' ? '1' : '');
            init.body.set('es_mascota', tipo.value === 'mascotas' ? '1' : '');
          }
        }
      } catch (e) {
        console.warn('No se pudo preparar servicio_subtipo:', e);
      }

      return originalFetch(input, init);
    };
  })();
</script>

<script>
  (function () {
    const tipo = document.getElementById('actividadNotasServicioTipo');
    const subtipoWrap = document.getElementById('actividadNotasServicioSubtipoWrap');
    const subtipo = document.getElementById('actividadNotasServicioSubtipo');
    const terceros = document.getElementById('actividadNotasEsTerceros');
    const mascotas = document.getElementById('actividadNotasEsMascota');

    if (!tipo || !subtipoWrap || !subtipo) return;

    function actualizarSubtipos() {
      const valor = String(tipo.value || '');
      let opciones = [];

      if (['empresarial', 'particular', 'osf', 'terceros'].includes(valor)) {
        opciones = [
          ['completo', 'Completo'],
          ['inicial', 'Inicial'],
          ['final', 'Final']
        ];
      } else if (valor === 'mascotas') {
        opciones = [
          ['prevision', 'Previsión'],
          ['particular', 'Particular']
        ];
      } else if (valor === 'servicios_no_prestados') {
        opciones = [
          ['negados', 'Negados'],
          ['no_prestados', 'No prestados']
        ];
      }

      subtipo.innerHTML = '<option value="">— seleccionar —</option>';
      opciones.forEach(function (item) {
        const option = document.createElement('option');
        option.value = item[0];
        option.textContent = item[1];
        subtipo.appendChild(option);
      });

      subtipo.disabled = opciones.length === 0;
      subtipoWrap.style.display = opciones.length ? '' : 'none';

      if (terceros) terceros.checked = valor === 'terceros';
      if (mascotas) mascotas.checked = valor === 'mascotas';
    }

    tipo.addEventListener('change', actualizarSubtipos);

    const modal = document.getElementById('actividadNotasModal');
    if (modal) {
      modal.addEventListener('hidden.bs.modal', function () {
        tipo.value = '';
        subtipo.innerHTML = '<option value="">— seleccionar —</option>';
        subtipo.value = '';
        subtipo.disabled = true;
        subtipoWrap.style.display = 'none';
        if (terceros) terceros.checked = false;
        if (mascotas) mascotas.checked = false;
      });
    }
  })();
</script>
<script src="<?php echo ASSETS_URL; ?>js/board.js"></script>

<?php require_once __DIR__ . '/includes/footer_user_app.php'; ?>