<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = getDBConnection();
$csrfToken = getCsrfToken();

function validarFechaReporteReq(?string $s): ?string
{
    if ($s === null || $s === '') {
        return null;
    }
    $s = trim($s);
    $dt = DateTime::createFromFormat('Y-m-d', $s);
    if ($dt && $dt->format('Y-m-d') === $s) {
        return $s;
    }

    return null;
}

function formatFechaHoraReqAdmin(?string $sqlDatetime): string
{
    if ($sqlDatetime === null || trim($sqlDatetime) === '') {
        return '—';
    }
    try {
        $dt = new DateTime($sqlDatetime);
    } catch (Exception $e) {
        return '—';
    }

    return $dt->format('d/m/Y H:i');
}

function labelPrioridadReq(string $p): string
{
    $map = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'];
    return $map[$p] ?? 'Media';
}

function labelEstadoReq(string $e): string
{
    $map = ['pendiente' => 'Pendiente', 'en_tramite' => 'En trámite', 'cerrado' => 'Cerrado'];
    return $map[$e] ?? 'Pendiente';
}

function nombreAdjuntoReq(?string $ruta): string
{
    $ruta = trim((string)$ruta);
    if ($ruta === '') {
        return '';
    }
    $base = basename($ruta);
    if (preg_match('/^\d+_(.+)$/', $base, $m)) {
        return $m[1];
    }
    return $base;
}

/** Vista previa de descripción en tabla admin (máx. caracteres visibles). */
function previewDescripcionReq(string $texto, int $maxChars = 100): array
{
    $completo = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
    if ($completo === '') {
        return ['preview' => '—', 'completo' => '', 'truncado' => false];
    }
    if (mb_strlen($completo) <= $maxChars) {
        return ['preview' => $completo, 'completo' => $completo, 'truncado' => false];
    }

    return [
        'preview' => mb_substr($completo, 0, $maxChars) . '…',
        'completo' => $completo,
        'truncado' => true,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchReporteRequerimientosRows(
    PDO $pdo,
    string $fechaDesde,
    string $fechaHasta,
    $usuarioId,
    $sedeId,
    $estadoFiltro,
    $prioridadFiltro
): array {
    $where = ['DATE(r.creado_at) BETWEEN ? AND ?'];
    $params = [$fechaDesde, $fechaHasta];

    if ($usuarioId !== 'all') {
        $where[] = 'r.id_usuario = ?';
        $params[] = (int)$usuarioId;
    }

    if ($sedeId !== 'all') {
        $where[] = 'r.id_sede = ?';
        $params[] = (int)$sedeId;
    }

    if ($estadoFiltro !== 'all') {
        $where[] = 'r.estado = ?';
        $params[] = (string)$estadoFiltro;
    }

    if ($prioridadFiltro !== 'all') {
        $where[] = 'r.prioridad = ?';
        $params[] = (string)$prioridadFiltro;
    }

    $sql = '
        SELECT
            r.id_requerimiento,
            r.id_usuario,
            r.id_sede,
            r.asunto,
            r.id_dependencia,
            r.prioridad,
            r.descripcion,
            r.adjunto,
            r.estado,
            r.creado_at,
            u.nombre_completo AS usuario_nombre,
            s.nombre AS sede_nombre,
            d.nombre AS dependencia_nombre
        FROM requerimientos r
        LEFT JOIN usuarios u ON u.id = r.id_usuario
        LEFT JOIN sedes s ON s.id = r.id_sede
        LEFT JOIN dependencias d ON d.id = r.id_dependencia
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY r.creado_at DESC, r.id_requerimiento DESC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $adjunto = trim((string)($r['adjunto'] ?? ''));
        $out[] = [
            'id_requerimiento' => (int)$r['id_requerimiento'],
            'id_usuario' => (int)$r['id_usuario'],
            'id_sede' => (int)$r['id_sede'],
            'asunto' => (string)$r['asunto'],
            'id_dependencia' => $r['id_dependencia'] !== null ? (int)$r['id_dependencia'] : null,
            'dependencia_nombre' => (string)($r['dependencia_nombre'] ?? ''),
            'prioridad' => (string)$r['prioridad'],
            'descripcion' => (string)$r['descripcion'],
            'adjunto' => $adjunto !== '' ? $adjunto : null,
            'adjunto_url' => $adjunto !== '' ? BASE_URL . str_replace('\\', '/', $adjunto) : null,
            'adjunto_nombre' => nombreAdjuntoReq($adjunto),
            'estado' => (string)($r['estado'] ?? 'pendiente'),
            'creado_at' => (string)$r['creado_at'],
            'usuario_nombre' => (string)($r['usuario_nombre'] ?? ''),
            'sede_nombre' => (string)($r['sede_nombre'] ?? ''),
        ];
    }

    return $out;
}

$hoy = date('Y-m-d');
$fechaDesde = validarFechaReporteReq($_GET['fecha_desde'] ?? null) ?? $hoy;
$fechaHasta = validarFechaReporteReq($_GET['fecha_hasta'] ?? null) ?? $hoy;

if ($fechaDesde > $fechaHasta) {
    $tmp = $fechaDesde;
    $fechaDesde = $fechaHasta;
    $fechaHasta = $tmp;
}

$usuarioId = $_GET['usuario_id'] ?? 'all';
if ($usuarioId !== 'all') {
    $usuarioId = (int)$usuarioId;
    if ($usuarioId <= 0) {
        $usuarioId = 'all';
    }
}

$sedeIdFiltro = $_GET['sede_id'] ?? 'all';
if ($sedeIdFiltro !== 'all') {
    $sedeIdFiltro = (int)$sedeIdFiltro;
    if ($sedeIdFiltro <= 0) {
        $sedeIdFiltro = 'all';
    }
}

$estadoFiltro = $_GET['estado'] ?? 'all';
if (!in_array($estadoFiltro, ['all', 'pendiente', 'en_tramite', 'cerrado'], true)) {
    $estadoFiltro = 'all';
}

$prioridadFiltro = $_GET['prioridad'] ?? 'all';
if (!in_array($prioridadFiltro, ['all', 'baja', 'media', 'alta', 'urgente'], true)) {
    $prioridadFiltro = 'all';
}

$page = (int)($_GET['page'] ?? 1);
$page = $page > 0 ? $page : 1;
$rowsPerPage = 14;

$baseQueryParams = [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'usuario_id' => $usuarioId,
    'sede_id' => $sedeIdFiltro,
    'estado' => $estadoFiltro,
    'prioridad' => $prioridadFiltro,
];

$tablaExiste = true;
$rows = [];
$errorCarga = '';

try {
    $rows = fetchReporteRequerimientosRows(
        $pdo,
        $fechaDesde,
        $fechaHasta,
        $usuarioId,
        $sedeIdFiltro,
        $estadoFiltro,
        $prioridadFiltro
    );
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'requerimientos') !== false || (string)$e->getCode() === '42S02') {
        $tablaExiste = false;
        $errorCarga = 'La tabla requerimientos no existe. Ejecute install/migrate_requerimientos.sql.';
    } else {
        $errorCarga = $e->getMessage();
    }
} catch (Exception $e) {
    $errorCarga = $e->getMessage();
}

if (isset($_GET['export']) && $_GET['export'] === 'excel' && $tablaExiste && $errorCarga === '') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_requerimientos_' . $fechaDesde . '_' . $fechaHasta . '.xls"');
    header('Cache-Control: max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";
    ?>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <table border="1">
        <thead>
            <tr style="background-color: #0d6efd; color: #ffffff; font-weight: bold;">
                <th>Fecha</th>
                <th>Asunto</th>
                <th>Usuario</th>
                <th>Sede</th>
                <th>Dependencia</th>
                <th>Prioridad</th>
                <th>Estado</th>
                <th>Descripción</th>
                <th>Adjunto</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo htmlspecialchars(formatFechaHoraReqAdmin($r['creado_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['asunto'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['dependencia_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars(labelPrioridadReq($r['prioridad']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars(labelEstadoReq($r['estado']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['descripcion'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['adjunto'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    exit;
}

$kpiTotal = count($rows);
$kpiPendientes = count(array_filter($rows, static fn($r) => ($r['estado'] ?? '') === 'pendiente'));
$kpiTramite = count(array_filter($rows, static fn($r) => ($r['estado'] ?? '') === 'en_tramite'));
$kpiCerrados = count(array_filter($rows, static fn($r) => ($r['estado'] ?? '') === 'cerrado'));

$stmtUsers = $pdo->query('SELECT id, nombre_completo FROM usuarios WHERE activo = 1 ORDER BY nombre_completo ASC');
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$stmtSedes = $pdo->query('SELECT id, nombre FROM sedes ORDER BY nombre ASC');
$sedes = $stmtSedes->fetchAll(PDO::FETCH_ASSOC);

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min(max(1, $page), $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;
$rowsPage = array_slice($rows, $offset, $rowsPerPage);

$title = 'Reportes requerimientos - Control Sedes';
$adminNavCurrent = 'reportes_requerimientos';
$adminSidebarFootInclude = __DIR__ . '/../includes/partials/admin_sidebar_help_reportes_requerimientos.php';
$qUsuario = '';

$clearUrl = htmlspecialchars(
    BASE_URL . 'admin/reportes_requerimientos.php?fecha_desde=' . rawurlencode($hoy)
    . '&fecha_hasta=' . rawurlencode($hoy) . '&usuario_id=all&sede_id=all&estado=all&prioridad=all',
    ENT_QUOTES,
    'UTF-8'
);

$excelUrl = htmlspecialchars(
    BASE_URL . 'admin/reportes_requerimientos.php?' . http_build_query(array_merge($baseQueryParams, ['export' => 'excel'])),
    ENT_QUOTES,
    'UTF-8'
);

require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>

<div class="report-page report-req-page">
  <div class="admin-act-page-head report-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-clipboard-data text-primary me-2"></i>Reportes requerimientos</h1>
      <p class="admin-act-page-lead">Consulta y tramita los reportes enviados por los usuarios desde sus sedes.</p>
    </div>
    <a class="btn admin-act-back-btn" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-arrow-left me-2"></i>Volver al panel
    </a>
  </div>

  <div id="report-req-alert" class="report-req-alert" aria-live="polite"></div>

  <?php if ($errorCarga !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-3" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorCarga, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <div class="report-kpi-row">
    <div class="report-kpi-card report-kpi-card--blue">
      <div class="report-kpi-icon"><i class="bi bi-clipboard-check"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Total</div>
        <div class="report-kpi-value"><?php echo (int)$kpiTotal; ?></div>
        <div class="report-kpi-hint">en el rango filtrado</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--orange">
      <div class="report-kpi-icon"><i class="bi bi-hourglass-split"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Pendientes</div>
        <div class="report-kpi-value"><?php echo (int)$kpiPendientes; ?></div>
        <div class="report-kpi-hint">sin tramitar</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--purple">
      <div class="report-kpi-icon"><i class="bi bi-arrow-repeat"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">En trámite</div>
        <div class="report-kpi-value"><?php echo (int)$kpiTramite; ?></div>
        <div class="report-kpi-hint">en gestión</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--green">
      <div class="report-kpi-icon"><i class="bi bi-check2-circle"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Cerrados</div>
        <div class="report-kpi-value"><?php echo (int)$kpiCerrados; ?></div>
        <div class="report-kpi-hint">finalizados</div>
      </div>
    </div>
  </div>

  <div class="admin-act-card report-filter-card mb-4">
    <form method="get" action="" class="report-filter-form">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="fecha_desde"><i class="bi bi-calendar-event me-1 text-primary"></i>Desde</label>
          <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?php echo htmlspecialchars($fechaDesde, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="fecha_hasta"><i class="bi bi-calendar-check me-1 text-primary"></i>Hasta</label>
          <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?php echo htmlspecialchars($fechaHasta, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="usuario_id"><i class="bi bi-person me-1 text-primary"></i>Usuario</label>
          <select class="form-select" id="usuario_id" name="usuario_id">
            <option value="all" <?php echo $usuarioId === 'all' ? 'selected' : ''; ?>>Todos</option>
            <?php foreach ($usuarios as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>" <?php echo ($usuarioId !== 'all' && (int)$usuarioId === (int)$u['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$u['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="sede_id"><i class="bi bi-building me-1 text-primary"></i>Sede</label>
          <select class="form-select" id="sede_id" name="sede_id">
            <option value="all" <?php echo $sedeIdFiltro === 'all' ? 'selected' : ''; ?>>Todas</option>
            <?php foreach ($sedes as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo ($sedeIdFiltro !== 'all' && (int)$sedeIdFiltro === (int)$s['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$s['nombre'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="estado"><i class="bi bi-flag me-1 text-primary"></i>Estado</label>
          <select class="form-select" id="estado" name="estado">
            <option value="all" <?php echo $estadoFiltro === 'all' ? 'selected' : ''; ?>>Todos</option>
            <option value="pendiente" <?php echo $estadoFiltro === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
            <option value="en_tramite" <?php echo $estadoFiltro === 'en_tramite' ? 'selected' : ''; ?>>En trámite</option>
            <option value="cerrado" <?php echo $estadoFiltro === 'cerrado' ? 'selected' : ''; ?>>Cerrado</option>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="prioridad"><i class="bi bi-exclamation-circle me-1 text-primary"></i>Prioridad</label>
          <select class="form-select" id="prioridad" name="prioridad">
            <option value="all" <?php echo $prioridadFiltro === 'all' ? 'selected' : ''; ?>>Todas</option>
            <option value="baja" <?php echo $prioridadFiltro === 'baja' ? 'selected' : ''; ?>>Baja</option>
            <option value="media" <?php echo $prioridadFiltro === 'media' ? 'selected' : ''; ?>>Media</option>
            <option value="alta" <?php echo $prioridadFiltro === 'alta' ? 'selected' : ''; ?>>Alta</option>
            <option value="urgente" <?php echo $prioridadFiltro === 'urgente' ? 'selected' : ''; ?>>Urgente</option>
          </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button class="btn btn-primary px-4" type="submit"><i class="bi bi-search me-2"></i>Consultar</button>
          <a class="btn btn-outline-secondary px-4" href="<?php echo $clearUrl; ?>"><i class="bi bi-arrow-counterclockwise me-2"></i>Limpiar</a>
          <?php if ($tablaExiste && $errorCarga === '' && $kpiTotal > 0): ?>
            <a class="btn btn-outline-success px-4 ms-sm-auto" href="<?php echo $excelUrl; ?>"><i class="bi bi-file-earmark-excel me-2"></i>Exportar Excel (.xls)</a>
          <?php endif; ?>
        </div>
      </div>
      <p class="report-filter-foot mb-0">
        <i class="bi bi-info-circle me-1"></i>Cambia el <strong>estado</strong> de cada requerimiento desde la columna «Trámite» en la tabla.
      </p>
    </form>
  </div>

  <div class="admin-act-card report-table-section report-req-results">
    <div class="report-table-toolbar mb-3">
      <h2 class="report-table-main-title mb-0"><i class="bi bi-table me-2 text-primary"></i>Detalle de requerimientos</h2>
      <span class="text-muted small"><?php echo (int)min($offset + count($rowsPage), $totalRows); ?> de <?php echo (int)$totalRows; ?> registros</span>
    </div>

    <?php if ($tablaExiste && $errorCarga === '' && $rowsPage !== []): ?>
      <div class="report-req-cards d-lg-none">
        <?php foreach ($rowsPage as $r): ?>
          <article class="report-req-card" data-req-id="<?php echo (int)$r['id_requerimiento']; ?>">
            <div class="report-req-card-head">
              <span class="report-req-card-fecha"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars(formatFechaHoraReqAdmin($r['creado_at']), ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="report-req-priority report-req-priority--<?php echo htmlspecialchars($r['prioridad'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(labelPrioridadReq($r['prioridad']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <h3 class="report-req-card-title"><?php echo htmlspecialchars($r['asunto'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <div class="report-req-card-meta">
              <span><i class="bi bi-person me-1"></i><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span><i class="bi bi-building me-1"></i><?php echo htmlspecialchars($r['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php if ($r['dependencia_nombre'] !== ''): ?>
              <div class="report-req-card-dep"><i class="bi bi-diagram-3 me-1"></i><?php echo htmlspecialchars($r['dependencia_nombre'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <p class="report-req-card-desc"><?php echo nl2br(htmlspecialchars($r['descripcion'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php if ($r['adjunto_url']): ?>
              <a class="report-req-adjunto-link" href="<?php echo htmlspecialchars($r['adjunto_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-paperclip me-1"></i><?php echo htmlspecialchars($r['adjunto_nombre'] ?: 'Ver adjunto', ENT_QUOTES, 'UTF-8'); ?>
              </a>
            <?php endif; ?>
            <label class="report-req-estado-label" for="req-estado-m-<?php echo (int)$r['id_requerimiento']; ?>">Estado</label>
            <select class="form-select form-select-sm report-req-estado-select" id="req-estado-m-<?php echo (int)$r['id_requerimiento']; ?>"
              data-req-id="<?php echo (int)$r['id_requerimiento']; ?>" data-prev="<?php echo htmlspecialchars($r['estado'], ENT_QUOTES, 'UTF-8'); ?>">
              <option value="pendiente" <?php echo $r['estado'] === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
              <option value="en_tramite" <?php echo $r['estado'] === 'en_tramite' ? 'selected' : ''; ?>>En trámite</option>
              <option value="cerrado" <?php echo $r['estado'] === 'cerrado' ? 'selected' : ''; ?>>Cerrado</option>
            </select>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive report-req-table-wrap d-none d-lg-block">
        <table class="table table-sm align-middle mb-0 table-report-professional table-report-req">
          <thead class="table-report-head">
            <tr>
              <th style="width:130px">Fecha</th>
              <th>Asunto</th>
              <th>Usuario</th>
              <th>Sede</th>
              <th>Dependencia</th>
              <th style="width:90px">Prioridad</th>
              <th style="width:220px; max-width:220px">Descripción</th>
              <th style="width:110px">Adjunto</th>
              <th style="width:150px">Trámite</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rowsPage as $r): ?>
              <tr data-req-id="<?php echo (int)$r['id_requerimiento']; ?>">
                <td class="text-nowrap fw-semibold"><?php echo htmlspecialchars(formatFechaHoraReqAdmin($r['creado_at']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="fw-semibold"><?php echo htmlspecialchars($r['asunto'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $r['dependencia_nombre'] !== '' ? htmlspecialchars($r['dependencia_nombre'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">—</span>'; ?></td>
                <td>
                  <span class="report-req-priority report-req-priority--<?php echo htmlspecialchars($r['prioridad'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars(labelPrioridadReq($r['prioridad']), ENT_QUOTES, 'UTF-8'); ?>
                  </span>
                </td>
                <td class="report-req-desc-cell">
                  <?php
                    $descPreview = previewDescripcionReq($r['descripcion']);
                    $titleDesc = $descPreview['completo'] !== '' ? $descPreview['completo'] : '';
                  ?>
                  <?php if ($titleDesc !== ''): ?>
                    <span class="report-req-desc-text" title="<?php echo htmlspecialchars($titleDesc, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo htmlspecialchars($descPreview['preview'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['adjunto_url']): ?>
                    <a class="report-req-adjunto-link" href="<?php echo htmlspecialchars($r['adjunto_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                      <i class="bi bi-paperclip"></i> Ver
                    </a>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <select class="form-select form-select-sm report-req-estado-select report-req-estado-select--<?php echo htmlspecialchars($r['estado'], ENT_QUOTES, 'UTF-8'); ?>"
                    data-req-id="<?php echo (int)$r['id_requerimiento']; ?>"
                    data-prev="<?php echo htmlspecialchars($r['estado'], ENT_QUOTES, 'UTF-8'); ?>"
                    aria-label="Estado del requerimiento <?php echo (int)$r['id_requerimiento']; ?>">
                    <option value="pendiente" <?php echo $r['estado'] === 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                    <option value="en_tramite" <?php echo $r['estado'] === 'en_tramite' ? 'selected' : ''; ?>>En trámite</option>
                    <option value="cerrado" <?php echo $r['estado'] === 'cerrado' ? 'selected' : ''; ?>>Cerrado</option>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($tablaExiste && $errorCarga === ''): ?>
      <div class="report-notas-empty">
        <i class="bi bi-inbox" aria-hidden="true"></i>
        <p class="mb-1 fw-semibold">No hay requerimientos en este rango</p>
        <p class="text-muted small mb-0">Prueba ampliando las fechas o quitando filtros.</p>
      </div>
    <?php endif; ?>

    <?php if ($totalPages > 1 && $errorCarga === ''): ?>
      <?php
        $buildUrl = static function (int $p) use ($baseQueryParams): string {
            return '?' . http_build_query(array_merge($baseQueryParams, ['page' => $p]));
        };
      ?>
      <div class="report-pagination-bar mt-3">
        <span class="text-muted small">Página <?php echo (int)$currentPage; ?> de <?php echo (int)$totalPages; ?></span>
        <nav class="report-pagination-nav" aria-label="Paginación requerimientos">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $currentPage <= 1 ? '#' : htmlspecialchars($buildUrl($currentPage - 1), ENT_QUOTES, 'UTF-8'); ?>">&lsaquo;</a>
            </li>
            <?php for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++): ?>
              <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$p; ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $currentPage >= $totalPages ? '#' : htmlspecialchars($buildUrl($currentPage + 1), ENT_QUOTES, 'UTF-8'); ?>">&rsaquo;</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  window.__REPORT_REQ_CSRF__ = <?php echo json_encode($csrfToken); ?>;
  window.__REPORT_REQ_API__ = <?php echo json_encode(BASE_URL . 'api/requerimientos_admin.php'); ?>;
</script>
<script src="<?php echo htmlspecialchars(ASSETS_URL . 'js/reportes-requerimientos.js', ENT_QUOTES, 'UTF-8'); ?>"></script>

<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>