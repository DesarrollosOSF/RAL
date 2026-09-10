<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = getDBConnection();
ensureActividadNotasServicioColumns($pdo);

/** Texto corto legible para el subtipo de servicio de una nota */
function formatServicioSubtipoNota(string $servicioTipo, bool $esTerceros, bool $esMascota): string
{
    $partes = [];
    if ($servicioTipo !== '') {
        $partes[] = ucfirst($servicioTipo);
    }
    if ($esTerceros) {
        $partes[] = 'Terceros';
    }
    if ($esMascota) {
        $partes[] = 'Mascota';
    }
    return $partes ? implode(' · ', $partes) : '—';
}

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
 * Consulta de registros detallados de notas
 */
function fetchReporteActividadNotasRows(
    PDO $pdo,
    string $fechaDesde,
    string $fechaHasta,
    $usuarioId = 'all',
    $actividadId = 'all',
    $sedeId = 'all',
    $servicioTipo = 'all'
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

    if ($sedeId !== 'all' && (int)$sedeId > 0) {
        $where[] = 'u.sede_id = ?';
        $params[] = (int)$sedeId;
    }

    if ($servicioTipo !== 'all' && $servicioTipo !== '') {
        $where[] = 'n.servicio_tipo = ?';
        $params[] = $servicioTipo;
    }

    $sql = '
        SELECT
            n.id,
            n.actividad_id,
            n.usuario_id,
            n.fallecido,
            n.fecha,
            n.observaciones,
            n.servicio_tipo,
            n.servicio_subtipo,
            n.es_terceros,
            n.es_mascota,
            n.created_at,
            a.titulo AS actividad_titulo,
            u.nombre_completo AS usuario_nombre,
            s.nombre AS sede_nombre
        FROM actividad_notas n
        INNER JOIN actividades a ON a.id = n.actividad_id
        INNER JOIN usuarios u ON u.id = n.usuario_id
        LEFT JOIN sedes s ON s.id = u.sede_id
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
            'servicio_tipo' => (string)($r['servicio_tipo'] ?? ''),
            'servicio_subtipo' => (string)($r['servicio_subtipo'] ?? ''),
            'es_terceros' => (int)($r['es_terceros'] ?? 0) === 1,
            'es_mascota' => (int)($r['es_mascota'] ?? 0) === 1,
            'created_at' => (string)$r['created_at'],
            'actividad_titulo' => (string)$r['actividad_titulo'],
            'usuario_nombre' => (string)$r['usuario_nombre'],
            'sede_nombre' => (string)($r['sede_nombre'] ?? 'Sin Sede'),
            'campo_detalle_label' => $etiq['label'],
        ];
    }

    return $out;
}

/**
 * Consulta agrupada por Tipo y Subtipo con inclusión de subtipos en cero
 */
function fetchResumenServiciosRows(
    PDO $pdo,
    string $fechaDesde,
    string $fechaHasta,
    $sedeId = 'all',
    $servicioTipo = 'all',
    $usuarioId = 'all',
    $actividadId = 'all'
): array {
    // 1. Obtener todos los tipos y subtipos conocidos en el sistema para inicializar en 0
    $sqlKnown = "
        SELECT DISTINCT 
            COALESCE(NULLIF(TRIM(servicio_tipo), ''), 'Sin especificar') AS tipo_servicio,
            COALESCE(NULLIF(TRIM(servicio_subtipo), ''), 'Sin especificar') AS subtipo_servicio
        FROM actividad_notas
        WHERE servicio_tipo IS NOT NULL AND servicio_tipo != ''
    ";
    $paramsKnown = [];
    if ($servicioTipo !== 'all' && $servicioTipo !== '') {
        $sqlKnown .= " AND servicio_tipo = ?";
        $paramsKnown[] = $servicioTipo;
    }
    $sqlKnown .= " ORDER BY tipo_servicio ASC, subtipo_servicio ASC";

    $stmtKnown = $pdo->prepare($sqlKnown);
    $stmtKnown->execute($paramsKnown);
    $knownPairs = $stmtKnown->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($knownPairs as $pair) {
        $t = $pair['tipo_servicio'];
        $s = $pair['subtipo_servicio'];
        if (!isset($grouped[$t])) {
            $grouped[$t] = [
                'subtipos' => [],
                'total_tipo' => 0
            ];
        }
        $grouped[$t]['subtipos'][$s] = 0;
    }

    // 2. Filtrar y contar los registros reales
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

    if ($sedeId !== 'all' && (int)$sedeId > 0) {
        $where[] = 'u.sede_id = ?';
        $params[] = (int)$sedeId;
    }

    if ($servicioTipo !== 'all' && $servicioTipo !== '') {
        $where[] = 'n.servicio_tipo = ?';
        $params[] = $servicioTipo;
    }

    $sql = "
        SELECT 
            COALESCE(NULLIF(TRIM(n.servicio_tipo), ''), 'Sin especificar') AS tipo_servicio,
            COALESCE(NULLIF(TRIM(n.servicio_subtipo), ''), 'General / Único') AS subtipo_servicio,
            COUNT(n.id) AS total_notas
        FROM actividad_notas n
        INNER JOIN usuarios u ON u.id = n.usuario_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY tipo_servicio, subtipo_servicio
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($raw as $r) {
        $t = $r['tipo_servicio'];
        $s = $r['subtipo_servicio'];
        $cant = (int)$r['total_notas'];

        if (!isset($grouped[$t])) {
            $grouped[$t] = [
                'subtipos' => [],
                'total_tipo' => 0
            ];
        }
        $grouped[$t]['subtipos'][$s] = $cant;
    }

    // Formatear la estructura final calculando totales
    $resultado = [];
    foreach ($grouped as $tipo => $datos) {
        $subList = [];
        $totalTipo = 0;
        foreach ($datos['subtipos'] as $subNombre => $cant) {
            $subList[] = [
                'nombre' => $subNombre,
                'cantidad' => $cant
            ];
            $totalTipo += $cant;
        }
        $resultado[$tipo] = [
            'subtipos' => $subList,
            'total_tipo' => $totalTipo
        ];
    }

    return $resultado;
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
    $usuarioId = (int)$usuarioId <= 0 ? 'all' : (int)$usuarioId;
}

$actividadId = $_GET['actividad_id'] ?? 'all';
if ($actividadId !== 'all') {
    $actividadId = (int)$actividadId <= 0 ? 'all' : (int)$actividadId;
}

$sedeId = $_GET['sede_id'] ?? 'all';
if ($sedeId !== 'all') {
    $sedeId = (int)$sedeId <= 0 ? 'all' : (int)$sedeId;
}

$servicioTipoFiltro = $_GET['servicio_tipo'] ?? 'all';

$tiposServiciosOpciones = [
    'Empresarial',
    'Particular',
    'OSF',
    'Terceros',
    'Mascotas',
    'Servicios no prestados'
];

$page = (int)($_GET['page'] ?? 1);
$page = $page > 0 ? $page : 1;
$rowsPerPage = 14;

$baseQueryParams = [
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'usuario_id' => $usuarioId,
    'actividad_id' => $actividadId,
    'sede_id' => $sedeId,
    'servicio_tipo' => $servicioTipoFiltro,
];

$tablaNotasExiste = true;
$rows = [];
$resumenServicios = [];
$errorCarga = '';

try {
    $rows = fetchReporteActividadNotasRows($pdo, $fechaDesde, $fechaHasta, $usuarioId, $actividadId, $sedeId, $servicioTipoFiltro);
    $resumenServicios = fetchResumenServiciosRows($pdo, $fechaDesde, $fechaHasta, $sedeId, $servicioTipoFiltro, $usuarioId, $actividadId);
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

// Exportación a Excel (.xls) unificada con AMBAS tablas
if (isset($_GET['export']) && $_GET['export'] === 'xls' && $tablaNotasExiste && $errorCarga === '') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_completo_notas_' . $fechaDesde . '_' . $fechaHasta . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<!DOCTYPE html>';
    echo '<html><head><meta charset="UTF-8"><style>';
    echo 'table { border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; margin-bottom: 30px; }';
    echo 'th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; font-size: 12px; }';
    echo 'th { background-color: #2563eb; color: #ffffff; font-weight: bold; }';
    echo '.subtotal { background-color: #f3f4f6; font-weight: bold; }';
    echo '.total-general { background-color: #1e3a8a; color: #ffffff; font-weight: bold; font-size: 13px; }';
    echo '.text-end { text-align: right; }';
    echo '.text-center { text-align: center; }';
    echo 'h2 { color: #1e293b; font-family: Arial, sans-serif; margin-top: 20px; }';
    echo '</style></head><body>';

    echo '<h2>1. Resumen por Tipo y Subtipo de Servicio</h2>';
    echo '<p><strong>Rango:</strong> ' . htmlspecialchars($fechaDesde) . ' al ' . htmlspecialchars($fechaHasta) . '</p>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Tipo de Servicio</th>';
    echo '<th>Subtipo de Servicio</th>';
    echo '<th class="text-end">Total Registrados</th>';
    echo '<th class="text-end">Subtotal Tipo</th>';
    echo '</tr></thead><tbody>';

    $grandTotal = 0;
    foreach ($resumenServicios as $tipo => $datos) {
        $subtipos = $datos['subtipos'];
        $rowCount = count($subtipos);
        $first = true;
        $grandTotal += $datos['total_tipo'];

        foreach ($subtipos as $sub) {
            echo '<tr>';
            if ($first) {
                echo '<td rowspan="' . $rowCount . '" style="vertical-align:middle; font-weight:bold; background-color:#f8fafc;">' . htmlspecialchars(ucfirst($tipo), ENT_QUOTES, 'UTF-8') . '</td>';
            }
            echo '<td>' . htmlspecialchars(ucfirst($sub['nombre']), ENT_QUOTES, 'UTF-8') . '</td>';
            echo '<td class="text-end">' . (int)$sub['cantidad'] . '</td>';
            if ($first) {
                echo '<td rowspan="' . $rowCount . '" class="text-end" style="vertical-align:middle; font-weight:bold; background-color:#eff6ff; color:#1d4ed8;">' . (int)$datos['total_tipo'] . '</td>';
                $first = false;
            }
            echo '</tr>';
        }
    }

    echo '<tr class="total-general">';
    echo '<td colspan="3" class="text-end">TOTAL GENERAL</td>';
    echo '<td class="text-end">' . (int)$grandTotal . '</td>';
    echo '</tr>';
    echo '</tbody></table>';

    echo '<h2>2. Detalle de Notas Registradas</h2>';
    echo '<table>';
    echo '<thead><tr>';
    echo '<th>Fecha</th>';
    echo '<th>Actividad</th>';
    echo '<th>Sede</th>';
    echo '<th>Usuario</th>';
    echo '<th>Campo Detalle</th>';
    echo '<th>Valor Detalle</th>';
    echo '<th>Subtipo Servicio</th>';
    echo '<th>Observaciones</th>';
    echo '<th>Registrado</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars(formatFechaHoraNotaAdmin($r['fecha']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['actividad_titulo'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['sede_nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['campo_detalle_label'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['fallecido'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars(formatServicioSubtipoNota($r['servicio_tipo'], $r['es_terceros'], $r['es_mascota']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($r['observaciones'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars(formatFechaHoraNotaAdmin($r['created_at']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></body></html>';
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

$stmtSedes = $pdo->query('SELECT id, nombre FROM sedes ORDER BY nombre ASC');
$sedes = $stmtSedes ? $stmtSedes->fetchAll(PDO::FETCH_ASSOC) : [];

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min(max(1, $page), $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;
$rowsPage = array_slice($rows, $offset, $rowsPerPage);

$title = 'Reportes por actividad - Control Sedes';
$adminNavCurrent = 'reportes_notas';
$adminSidebarFootInclude = __DIR__ . '/../includes/partials/admin_sidebar_help_reportes_notas.php';

$clearUrl = htmlspecialchars(
    BASE_URL . 'admin/reportes_actividad_notas.php?fecha_desde=' . rawurlencode($hoy)
    . '&fecha_hasta=' . rawurlencode($hoy) . '&usuario_id=all&actividad_id=all&sede_id=all&servicio_tipo=all',
    ENT_QUOTES,
    'UTF-8'
);

$xlsUrl = htmlspecialchars(
    BASE_URL . 'admin/reportes_actividad_notas.php?' . http_build_query(array_merge($baseQueryParams, ['export' => 'xls'])),
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

  <!-- Formulario de Filtros General -->
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
          <label class="form-label report-filter-label" for="sede_id"><i class="bi bi-building me-1 text-primary"></i>Sede</label>
          <select class="form-select" id="sede_id" name="sede_id">
            <option value="all" <?php echo $sedeId === 'all' ? 'selected' : ''; ?>>Todas las sedes</option>
            <?php foreach ($sedes as $s): ?>
              <option value="<?php echo (int)$s['id']; ?>" <?php echo ($sedeId !== 'all' && (int)$sedeId === (int)$s['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$s['nombre'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
          <label class="form-label report-filter-label" for="servicio_tipo"><i class="bi bi-tag me-1 text-primary"></i>Tipo Servicio</label>
          <select class="form-select" id="servicio_tipo" name="servicio_tipo">
            <option value="all" <?php echo $servicioTipoFiltro === 'all' ? 'selected' : ''; ?>>Todos los tipos</option>
            <?php foreach ($tiposServiciosOpciones as $tOpt): ?>
              <option value="<?php echo htmlspecialchars($tOpt, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($servicioTipoFiltro === $tOpt) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($tOpt, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-2">
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
        <div class="col-12 col-md-6 col-xl-2">
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
        <div class="col-12 d-flex flex-wrap gap-2 pt-2">
          <button class="btn btn-primary px-4" type="submit"><i class="bi bi-search me-2"></i>Consultar</button>
          <a class="btn btn-outline-secondary px-4" href="<?php echo $clearUrl; ?>"><i class="bi bi-arrow-counterclockwise me-2"></i>Limpiar</a>
          <?php if ($tablaNotasExiste && $errorCarga === '' && $kpiTotal > 0): ?>
            <a class="btn btn-outline-success px-4 ms-sm-auto" href="<?php echo $xlsUrl; ?>"><i class="bi bi-file-earmark-excel me-2"></i>Exportar Excel (.xls)</a>
          <?php endif; ?>
        </div>
      </div>
      <p class="report-filter-foot mb-0 mt-2">
        <i class="bi bi-info-circle me-1"></i>Filtra las notas por <strong>rango de fechas</strong>, <strong>sedes</strong> y <strong>tipo de servicio</strong>.
      </p>
    </form>
  </div>

  <!-- Tabla 1: Detalle de Notas -->
  <div class="admin-act-card report-table-section report-notas-results mb-4">
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
            <div class="report-nota-card-user"><i class="bi bi-person me-1 text-muted"></i><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars($r['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?>)</div>
            <div class="report-nota-card-detalle">
              <span class="report-nota-campo-label"><?php echo htmlspecialchars($r['campo_detalle_label'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="report-nota-campo-valor"><?php echo htmlspecialchars($r['fallecido'] !== '' ? $r['fallecido'] : '—', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php $subtipoTxt = formatServicioSubtipoNota($r['servicio_tipo'], $r['es_terceros'], $r['es_mascota']); ?>
            <?php if ($subtipoTxt !== '—'): ?>
              <span class="badge bg-info-subtle text-info border small mb-1"><?php echo htmlspecialchars($subtipoTxt, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
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
              <th>Sede</th>
              <th>Usuario</th>
              <th style="min-width:200px">Detalle</th>
              <th>Subtipo servicio</th>
              <th>Observaciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rowsPage as $r): ?>
              <tr>
                <td class="text-nowrap fw-semibold"><?php echo htmlspecialchars(formatFechaHoraNotaAdmin($r['fecha']), ENT_QUOTES, 'UTF-8'); ?></td>
                <td><span class="badge rounded-pill text-bg-light border text-dark"><?php echo htmlspecialchars($r['actividad_titulo'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><span class="badge bg-secondary-subtle text-secondary border small"><?php echo htmlspecialchars($r['sede_nombre'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td><?php echo htmlspecialchars($r['usuario_nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <div class="report-nota-detalle-cell">
                    <span class="report-nota-campo-label d-block"><?php echo htmlspecialchars($r['campo_detalle_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="report-nota-campo-valor"><?php echo htmlspecialchars($r['fallecido'] !== '' ? $r['fallecido'] : '—', ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                </td>
                <td><span class="badge bg-info-subtle text-info border small"><?php echo htmlspecialchars(formatServicioSubtipoNota($r['servicio_tipo'], $r['es_terceros'], $r['es_mascota']), ENT_QUOTES, 'UTF-8'); ?></span></td>
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
        <p class="text-muted small mb-0">Prueba ampliando las fechas o quitando filtros de usuario, sede o actividad.</p>
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

  <!-- Tabla 2: Resumen por Tipo y Subtipo de Servicio (Ubicada después de Detalle de notas) -->
  <div class="admin-act-card report-table-section report-resumen-servicios">
    <div class="report-table-toolbar mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <h2 class="report-table-main-title mb-0"><i class="bi bi-pie-chart me-2 text-primary"></i>Resumen por Tipo y Subtipo de Servicio</h2>
        <span class="text-muted small">Totales calculados según los filtros de rango de fechas, sedes y tipo de servicio aplicados.</span>
      </div>
      <?php if ($tablaNotasExiste && $errorCarga === '' && !empty($resumenServicios)): ?>
        <a class="btn btn-sm btn-success px-3" href="<?php echo $xlsUrl; ?>">
          <i class="bi bi-file-earmark-excel me-1"></i>Descargar Reporte Completo .xls
        </a>
      <?php endif; ?>
    </div>

    <?php if ($tablaNotasExiste && $errorCarga === '' && !empty($resumenServicios)): ?>
      <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0 table-report-professional">
          <thead class="table-report-head">
            <tr>
              <th style="width: 25%;">Tipo Servicio</th>
              <th style="width: 35%;">Subtipo de Servicio</th>
              <th class="text-end" style="width: 20%;">Total Registrados</th>
              <th class="text-end" style="width: 20%;">Subtotal Tipo</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $grandTotal = 0;
            foreach ($resumenServicios as $tipo => $datos):
                $subtipos = $datos['subtipos'];
                $rowCount = count($subtipos);
                $first = true;
                $grandTotal += $datos['total_tipo'];
            ?>
              <?php foreach ($subtipos as $sub): ?>
                <tr>
                  <?php if ($first): ?>
                    <td rowspan="<?php echo $rowCount; ?>" class="fw-bold align-middle bg-light">
                      <span class="badge bg-primary fs-6"><?php echo htmlspecialchars(ucfirst($tipo), ENT_QUOTES, 'UTF-8'); ?></span>
                    </td>
                  <?php endif; ?>
                  <td><?php echo htmlspecialchars(ucfirst($sub['nombre']), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end <?php echo $sub['cantidad'] === 0 ? 'text-muted' : 'fw-semibold'; ?>">
                    <?php echo (int)$sub['cantidad']; ?>
                  </td>
                  <?php if ($first): ?>
                    <td rowspan="<?php echo $rowCount; ?>" class="text-end fw-bold align-middle bg-primary-subtle text-primary fs-6">
                      <?php echo (int)$datos['total_tipo']; ?>
                    </td>
                    <?php $first = false; ?>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td colspan="3" class="text-end fw-bold fs-6">TOTAL GENERAL:</td>
              <td class="text-end fw-bold fs-5 text-primary"><?php echo (int)$grandTotal; ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    <?php else: ?>
      <div class="report-notas-empty py-4 text-center text-muted">
        <i class="bi bi-bar-chart-steps fs-1 d-block mb-2"></i>
        <p class="mb-0 fw-semibold">No se encontraron registros de servicios para el filtro seleccionado.</p>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>