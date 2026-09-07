<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = getDBConnection();

function validarFechaReporteNotas(?string $s): ?string
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

function formatFechaHoraNotaAdmin(?string $sqlDatetime): string
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

/**
 * @return array<int, array<string, mixed>>
 */
function fetchReporteActividadNotasRows(
    PDO $pdo,
    string $fechaDesde,
    string $fechaHasta,
    $usuarioId,
    $actividadId
): array {
    $where = ['DATE(n.fecha) BETWEEN ? AND ?'];
    $params = [$fechaDesde, $fechaHasta];

    if ($usuarioId !== 'all') {
        $where[] = 'n.usuario_id = ?';
        $params[] = (int)$usuarioId;
    }

    if ($actividadId !== 'all') {
        $where[] = 'n.actividad_id = ?';
        $params[] = (int)$actividadId;
    }

    $sql = '
        SELECT
            n.id,
            n.actividad_id,
            n.usuario_id,
            n.fallecido,
            n.fecha,
            n.observaciones,
            n.created_at,
            a.titulo AS actividad_titulo,
            u.nombre_completo AS usuario_nombre
        FROM actividad_notas n
        INNER JOIN actividades a ON a.id = n.actividad_id
        INNER JOIN usuarios u ON u.id = n.usuario_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY n.fecha DESC, n.id DESC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $aid = (int)$r['actividad_id'];
        $etiq = getEtiquetasCampoPrincipalNotas($aid, $pdo);
        $out[] = [
            'id' => (int)$r['id'],
            'actividad_id' => $aid,
            'usuario_id' => (int)$r['usuario_id'],
            'fallecido' => (string)$r['fallecido'],
            'fecha' => (string)$r['fecha'],
            'observaciones' => (string)$r['observaciones'],
            'created_at' => (string)$r['created_at'],
            'actividad_titulo' => (string)$r['actividad_titulo'],
            'usuario_nombre' => (string)$r['usuario_nombre'],
            'campo_detalle_label' => $etiq['label'],
        ];
    }

    return $out;
}

$hoy = date('Y-m-d');
$fechaDesde = validarFechaReporteNotas($_GET['fecha_desde'] ?? null) ?? $hoy;
$fechaHasta = validarFechaReporteNotas($_GET['fecha_hasta'] ?? null) ?? $hoy;

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

$actividadId = $_GET['actividad_id'] ?? 'all';
if ($actividadId !== 'all') {
    $actividadId = (int)$actividadId;
    if ($actividadId <= 0) {
        $actividadId = 'all';
    }
}

$page = (int)($_GET['page'] ?? 1);
$page = $page > 0 ? $page : 1;
$rowsPerPage = 14;

$baseQueryParams = [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'usuario_id' => $usuarioId,
    'actividad_id' => $actividadId,
];

$tablaNotasExiste = true;
$rows = [];
$errorCarga = '';

try {
    $rows = fetchReporteActividadNotasRows($pdo, $fechaDesde, $fechaHasta, $usuarioId, $actividadId);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'actividad_notas') !== false || (string)$e->getCode() === '42S02') {
        $tablaNotasExiste = false;
        $errorCarga = 'La tabla actividad_notas no existe. Ejecute install/migrate_actividad_notas.sql.';
    } else {
        $errorCarga = $e->getMessage();
    }
} catch (Exception $e) {
    $errorCarga = $e->getMessage();
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tablaNotasExiste && $errorCarga === '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_notas_actividad_' . $fechaDesde . '_' . $fechaHasta . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fecha', 'Actividad', 'Usuario', 'Campo detalle', 'Valor detalle', 'Observaciones', 'Registrado']);
    foreach ($rows as $r) {
        fputcsv($out, [
            formatFechaHoraNotaAdmin($r['fecha']),
            $r['actividad_titulo'],
            $r['usuario_nombre'],
            $r['campo_detalle_label'],
            $r['fallecido'],
            $r['observaciones'],
            formatFechaHoraNotaAdmin($r['created_at']),
        ]);
    }
    fclose($out);
    exit;
}

$kpiTotal = count($rows);
$kpiUsuarios = count(array_unique(array_column($rows, 'usuario_id')));
$kpiActividades = count(array_unique(array_column($rows, 'actividad_id')));

$kpiHoy = 0;
foreach ($rows as $r) {
    if (strpos((string)$r['fecha'], $hoy) === 0) {
        $kpiHoy++;
    }
}

$idsActividadesNotas = getActividadesConModalNotasIds($pdo);
$actividadesFiltro = [];
if (!empty($idsActividadesNotas)) {
    $placeholders = implode(',', array_fill(0, count($idsActividadesNotas), '?'));
    $stmtActs = $pdo->prepare("SELECT id, titulo FROM actividades WHERE id IN ($placeholders) ORDER BY titulo ASC");
    $stmtActs->execute($idsActividadesNotas);
    $actividadesFiltro = $stmtActs->fetchAll(PDO::FETCH_ASSOC);
}

$stmtUsers = $pdo->query('SELECT id, nombre_completo FROM usuarios WHERE activo = 1 ORDER BY nombre_completo ASC');
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min(max(1, $page), $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;
$rowsPage = array_slice($rows, $offset, $rowsPerPage);

$title = 'Reportes por actividad - Control Sedes';
$adminNavCurrent = 'reportes_notas';
$adminSidebarFootInclude = __DIR__ . '/../includes/partials/admin_sidebar_help_reportes_notas.php';
$qUsuario = '';
$sedeIdFiltro = 0;

$clearUrl = htmlspecialchars(
    BASE_URL . 'admin/reportes_actividad_notas.php?fecha_desde=' . rawurlencode($hoy)
    . '&fecha_hasta=' . rawurlencode($hoy) . '&usuario_id=all&actividad_id=all',
    ENT_QUOTES,
    'UTF-8'
);

$csvUrl = htmlspecialchars(
    BASE_URL . 'admin/reportes_actividad_notas.php?' . http_build_query(array_merge($baseQueryParams, ['export' => 'csv'])),
    ENT_QUOTES,
    'UTF-8'
);

require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>

<div class="report-page report-notas-page">
  <div class="admin-act-page-head report-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-journal-text text-primary me-2"></i>Reportes por actividad</h1>
      <p class="admin-act-page-lead">Consulta las notas registradas desde el tablero (funerario, Otros, Facturación y demás actividades con formulario).</p>
    </div>
    <a class="btn admin-act-back-btn" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-arrow-left me-2"></i>Volver al panel
    </a>
  </div>

  <?php if ($errorCarga !== ''): ?>
    <div class="alert alert-warning border-0 shadow-sm rounded-3" role="alert">
      <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorCarga, ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <div class="report-kpi-row">
    <div class="report-kpi-card report-kpi-card--blue">
      <div class="report-kpi-icon"><i class="bi bi-journal-check"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Total de notas</div>
        <div class="report-kpi-value"><?php echo (int)$kpiTotal; ?></div>
        <div class="report-kpi-hint">en el rango filtrado</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--green">
      <div class="report-kpi-icon"><i class="bi bi-people"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Usuarios</div>
        <div class="report-kpi-value"><?php echo (int)$kpiUsuarios; ?></div>
        <div class="report-kpi-hint">con al menos una nota</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--orange">
      <div class="report-kpi-icon"><i class="bi bi-kanban"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Actividades</div>
        <div class="report-kpi-value"><?php echo (int)$kpiActividades; ?></div>
        <div class="report-kpi-hint">diferentes en el resultado</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--purple">
      <div class="report-kpi-icon"><i class="bi bi-calendar-day"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Notas hoy</div>
        <div class="report-kpi-value"><?php echo (int)$kpiHoy; ?></div>
        <div class="report-kpi-hint"><?php echo htmlspecialchars(date('d/m/Y'), ENT_QUOTES, 'UTF-8'); ?></div>
      </div>
    </div>
  </div>

  <div class="admin-act-card report-filter-card mb-4">
    <form method="get" action="" class="report-filter-form">
      <div class="row g-3 align-items-end">
        <div class="col-12 col-md-6 col-xl-3">
          <label class="form-label report-filter-label" for="fecha_desde"><i class="bi bi-calendar-event me-1 text-primary"></i>Desde</label>
          <input type="date" class="form-control" id="fecha_desde" name="fecha_desde" value="<?php echo htmlspecialchars($fechaDesde, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <label class="form-label report-filter-label" for="fecha_hasta"><i class="bi bi-calendar-check me-1 text-primary"></i>Hasta</label>
          <input type="date" class="form-control" id="fecha_hasta" name="fecha_hasta" value="<?php echo htmlspecialchars($fechaHasta, ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <label class="form-label report-filter-label" for="usuario_id"><i class="bi bi-person me-1 text-primary"></i>Usuario</label>
          <select class="form-select" id="usuario_id" name="usuario_id">
            <option value="all" <?php echo $usuarioId === 'all' ? 'selected' : ''; ?>>Todos los usuarios</option>
            <?php foreach ($usuarios as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>" <?php echo ($usuarioId !== 'all' && (int)$usuarioId === (int)$u['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$u['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <label class="form-label report-filter-label" for="actividad_id"><i class="bi bi-grid me-1 text-primary"></i>Actividad</label>
          <select class="form-select" id="actividad_id" name="actividad_id">
            <option value="all" <?php echo $actividadId === 'all' ? 'selected' : ''; ?>>Todas las actividades</option>
            <?php foreach ($actividadesFiltro as $a): ?>
              <option value="<?php echo (int)$a['id']; ?>" <?php echo ($actividadId !== 'all' && (int)$actividadId === (int)$a['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$a['titulo'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button class="btn btn-primary px-4" type="submit"><i class="bi bi-search me-2"></i>Consultar</button>
          <a class="btn btn-outline-secondary px-4" href="<?php echo $clearUrl; ?>"><i class="bi bi-arrow-counterclockwise me-2"></i>Limpiar</a>
          <?php if ($tablaNotasExiste && $errorCarga === '' && $kpiTotal > 0): ?>
            <a class="btn btn-outline-primary px-4 ms-sm-auto" href="<?php echo $csvUrl; ?>"><i class="bi bi-filetype-csv me-2"></i>Exportar CSV</a>
          <?php endif; ?>
        </div>
      </div>
      <p class="report-filter-foot mb-0">
        <i class="bi bi-info-circle me-1"></i>Filtra por la <strong>fecha de la nota</strong>. Cada guardado en el tablero genera un registro nuevo en la base de datos.
      </p>
    </form>
  </div>

  <div class="admin-act-card report-table-section report-notas-results">
    <div class="report-table-toolbar mb-3">
      <h2 class="report-table-main-title mb-0"><i class="bi bi-table me-2 text-primary"></i>Detalle de notas</h2>
      <span class="text-muted small"><?php echo (int)min($offset + count($rowsPage), $totalRows); ?> de <?php echo (int)$totalRows; ?> registros</span>
    </div>

    <?php if ($tablaNotasExiste && $errorCarga === '' && $rowsPage !== []): ?>
      <div class="report-notas-cards d-lg-none">
        <?php foreach ($rowsPage as $r): ?>
          <article class="report-nota-card">
            <div class="report-nota-card-head">
              <span class="report-nota-card-fecha"><i class="bi bi-clock me-1"></i><?php echo htmlspecialchars(formatFechaHoraNotaAdmin($r['fecha']), ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="badge rounded-pill text-bg-primary"><?php echo htmlspecialchars($r['actividad_titulo'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="report-nota-card-user"><i class="bi bi-person me-1 text-muted"></i><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="report-nota-card-detalle">
              <span class="report-nota-campo-label"><?php echo htmlspecialchars($r['campo_detalle_label'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="report-nota-campo-valor"><?php echo htmlspecialchars($r['fallecido'] !== '' ? $r['fallecido'] : '—', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php if (trim($r['observaciones']) !== ''): ?>
              <p class="report-nota-obs mb-0"><?php echo nl2br(htmlspecialchars($r['observaciones'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="table-responsive report-notas-table-wrap d-none d-lg-block">
        <table class="table table-sm align-middle mb-0 table-report-professional table-report-notas">
          <thead class="table-report-head">
            <tr>
              <th style="width:130px">Fecha</th>
              <th>Actividad</th>
              <th>Usuario</th>
              <th style="min-width:200px">Detalle</th>
              <th>Observaciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rowsPage as $r): ?>
              <tr>
                <td class="text-nowrap fw-semibold"><?php echo htmlspecialchars(formatFechaHoraNotaAdmin($r['fecha']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="badge rounded-pill text-bg-light border text-dark"><?php echo htmlspecialchars($r['actividad_titulo'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <div class="report-nota-detalle-cell">
                    <span class="report-nota-campo-label d-block"><?php echo htmlspecialchars($r['campo_detalle_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="report-nota-campo-valor"><?php echo htmlspecialchars($r['fallecido'] !== '' ? $r['fallecido'] : '—', ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                </td>
                <td class="report-nota-obs-cell" title="<?php echo htmlspecialchars($r['observaciones'], ENT_QUOTES, 'UTF-8'); ?>">
                  <?php echo $r['observaciones'] !== '' ? nl2br(htmlspecialchars($r['observaciones'], ENT_QUOTES, 'UTF-8')) : '<span class="text-muted">—</span>'; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($tablaNotasExiste && $errorCarga === ''): ?>
      <div class="report-notas-empty">
        <i class="bi bi-inbox" aria-hidden="true"></i>
        <p class="mb-1 fw-semibold">No hay notas en este rango</p>
        <p class="text-muted small mb-0">Prueba ampliando las fechas o quitando filtros de usuario o actividad.</p>
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
        <nav class="report-pagination-nav" aria-label="Paginación notas">
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

<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>
