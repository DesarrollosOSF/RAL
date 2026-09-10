<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = getDBConnection();
ensureRalActividadColumns($pdo);

function validarFecha(?string $s): ?string
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

/**
 * @param list<int> $actividadIds Vacío = todas las actividades.
 * @param bool $excluirAlmuerzo Si es true y no hay filtro de actividades, omite ACTIVIDAD_ALMUERZO_ID (totales/tablas).
 *                             Los gráficos del reporte deben llamar con false para incluir almuerzo visualmente.
 * @return array<int, array{actividad_id:int,titulo:string,usuario_id:int,nombre_completo:string,total_seg:int}>
 */
function fetchReporteTiempoRows(PDO $pdo, string $fechaDesde, string $fechaHasta, $usuarioId, array $actividadIds, bool $excluirAlmuerzo = false): array
{
  $params = [$fechaDesde, $fechaHasta];

  $userSql = '';
  if ($usuarioId !== 'all') {
    $userSql = ' AND au.usuario_id = ? ';
    $params[] = (int)$usuarioId;
  }

  $esTodas = $actividadIds === [];
  $actSql = '';
  if (!$esTodas) {
    $placeholders = implode(',', array_fill(0, count($actividadIds), '?'));
    $actSql = " AND au.actividad_id IN ($placeholders) ";
    foreach ($actividadIds as $aid) {
      $params[] = (int)$aid;
    }
  }

  $sqlExclAlmuerzo = '';
  if ($excluirAlmuerzo && $esTodas) {
    $sqlExclAlmuerzo = ' AND au.actividad_id <> ? ';
    $params[] = (int)ACTIVIDAD_ALMUERZO_ID;
  }

  $sql = '
        SELECT
          a.id AS actividad_id,
          a.titulo,
          u.id AS usuario_id,
          u.nombre_completo,
          COALESCE(SUM(au.tiempo_acumulado_seg), 0) +
          COALESCE(SUM(CASE
            WHEN au.tiempo_intervalo_inicio_at IS NULL THEN 0
            WHEN j.fecha <> CURDATE() THEN 0
            ELSE TIMESTAMPDIFF(SECOND, au.tiempo_intervalo_inicio_at, NOW())
          END), 0) AS total_seg
        FROM actividades_usuario au
        INNER JOIN actividades a ON a.id = au.actividad_id
        INNER JOIN usuarios u ON u.id = au.usuario_id
        INNER JOIN jornadas j ON j.id = au.jornada_id
        WHERE j.fecha BETWEEN ? AND ?
          ' . $userSql . $actSql . $sqlExclAlmuerzo . '
        GROUP BY au.actividad_id, a.titulo, au.usuario_id, u.id, u.nombre_completo
        HAVING (
          COALESCE(SUM(au.tiempo_acumulado_seg), 0) +
          COALESCE(SUM(CASE
            WHEN au.tiempo_intervalo_inicio_at IS NULL THEN 0
            WHEN j.fecha <> CURDATE() THEN 0
            ELSE TIMESTAMPDIFF(SECOND, au.tiempo_intervalo_inicio_at, NOW())
          END), 0)
        ) > 0
        ORDER BY a.titulo ASC, u.nombre_completo ASC
    ';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $out = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $out[] = [
      'actividad_id' => (int)$r['actividad_id'],
      'titulo' => (string)$r['titulo'],
      'usuario_id' => (int)$r['usuario_id'],
      'nombre_completo' => (string)$r['nombre_completo'],
      'total_seg' => (int)$r['total_seg'],
    ];
  }

  return $out;
}

/**
 * Normaliza el filtro GET de actividades (una, varias o todas).
 * @return list<int> Vacío = todas.
 */
function parseActividadIdsFiltro($raw): array
{
  if ($raw === null || $raw === '' || $raw === 'all') {
    return [];
  }

  if (!is_array($raw)) {
    $raw = [$raw];
  }

  $ids = [];
  foreach ($raw as $v) {
    if ($v === '' || $v === 'all') {
      continue;
    }
    $id = (int)$v;
    if ($id > 0) {
      $ids[$id] = $id;
    }
  }

  return array_values($ids);
}

/** Convierte segundos a días de jornada (tope diario del contador, p. ej. 8 h). */
function segundosADiasJornada(int $totalSeg): float
{
  $segPorDia = (int)TOPE_HORAS_DIARIAS_CONTADOR_SEG;
  if ($segPorDia <= 0) {
    return 0.0;
  }

  return max(0, $totalSeg) / $segPorDia;
}

function formatDiasJornada(int $totalSeg, int $decimales = 2): string
{
  return number_format(segundosADiasJornada($totalSeg), $decimales, ',', '.');
}

/**
 * @param array<int, array{label:string,seg:int}> $items
 */
function chartDataTopWithOther(array $items, int $maxTop = 7): array
{
  if ($items === []) {
    return ['labels' => [], 'values' => []];
  }
  usort($items, static function ($a, $b) {
    return ($b['seg'] ?? 0) <=> ($a['seg'] ?? 0);
  });
  if (count($items) <= $maxTop) {
    return [
      'labels' => array_column($items, 'label'),
      'values' => array_column($items, 'seg'),
    ];
  }
  $top = array_slice($items, 0, $maxTop);
  $rest = array_slice($items, $maxTop);
  $otherSeg = 0;
  foreach ($rest as $x) {
    $otherSeg += (int)($x['seg'] ?? 0);
  }
  $labels = array_column($top, 'label');
  $values = array_column($top, 'seg');
  if ($otherSeg > 0) {
    $labels[] = 'Otros';
    $values[] = $otherSeg;
  }

  return ['labels' => $labels, 'values' => $values];
}

$hoy = date('Y-m-d');

$fechaDesde = validarFecha($_GET['fecha_desde'] ?? null) ?? $hoy;
$fechaHasta = validarFecha($_GET['fecha_hasta'] ?? null) ?? $hoy;

if ($fechaDesde > $fechaHasta) {
  $tmp = $fechaDesde;
  $fechaDesde = $fechaHasta;
  $fechaHasta = $tmp;
}

$actividadIds = parseActividadIdsFiltro($_GET['actividad_id'] ?? null);
$filtroTodasActividades = $actividadIds === [];

$usuarioId = $_GET['usuario_id'] ?? 'all';
if ($usuarioId !== 'all') {
  $usuarioId = (int)$usuarioId;
  if ($usuarioId <= 0) {
    $usuarioId = 'all';
  }
}

$page = (int)($_GET['page'] ?? 1);
$page = $page > 0 ? $page : 1;
$rowsPerPage = 14;

$baseQueryParams = [
  'fecha_desde' => $fechaDesde,
  'fecha_hasta' => $fechaHasta,
  'usuario_id' => $usuarioId,
];
if ($filtroTodasActividades) {
  $baseQueryParams['actividad_id'] = 'all';
} else {
  $baseQueryParams['actividad_id'] = $actividadIds;
}

if (isset($_GET['export']) && ($_GET['export'] === 'xls' || $_GET['export'] === 'csv')) {
  $exportMode = $_GET['export_mode'] ?? 'detalle';
  $exportType = $_GET['export'];

  if ($exportMode === 'ral') {
    if ($exportType === 'xls') {
      // === Recolectar datos de la Tabla 1: RAL - Días hábiles y cumplimiento (por usuario) ===
      $tmpUsers = $pdo->query("SELECT id,nombre_completo FROM usuarios ORDER BY nombre_completo ASC")->fetchAll();
      $ralRowsExport = [];
      foreach ($tmpUsers as $tu) {
        $uid = (int)$tu['id'];
        if ($usuarioId !== 'all' && (int)$usuarioId !== $uid) continue;

        $calc = calcularDiasEfectivosRal($pdo, $fechaDesde, $fechaHasta, $uid);
        $totSeg = fetchSumaTiemposContadorCapped($pdo, $fechaDesde, $fechaHasta, [$uid]);
        $ausDet = fetchRalAusenciasDetalle($pdo, $fechaDesde, $fechaHasta, $uid);

        $vac = 0;
        $perm = 0;
        $comp = 0;
        foreach ($ausDet as $ad) {
          if ($ad['tipo'] === 'vacaciones') $vac += (float)$ad['dias'];
          elseif ($ad['tipo'] === 'permiso') $perm += (float)$ad['dias'];
          else $comp += (float)$ad['dias'];
        }

        $stmtS = $pdo->prepare("SELECT s.nombre FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.id=?");
        $stmtS->execute([$uid]);
        $sede = $stmtS->fetchColumn() ?: '';

        $cumpl = $calc['efectivos'] > 0 ? (segundosADiasJornada($totSeg) / $calc['efectivos'] * 100) : 0;

        $ralRowsExport[] = [
          'nombre' => (string)$tu['nombre_completo'],
          'sede' => (string)$sede,
          'habiles' => (int)$calc['habiles'],
          'vac' => $vac,
          'perm' => $perm,
          'comp' => $comp,
          'efectivos' => (float)$calc['efectivos'],
          'seg' => (int)$totSeg,
          'cumpl' => $cumpl,
        ];
      }

      // === Recolectar datos de la Tabla 2: Detalle Actividad / Grupo / Usuario / Tiempo ===
      $rowsDetalleExport = fetchReporteTiempoRows($pdo, $fechaDesde, $fechaHasta, $usuarioId, $actividadIds, true);
      $idsUsuariosExport = [];
      $idsActividadesExport = [];
      $sumaTotalDetalle = 0;
      $detalleFilas = [];
      foreach ($rowsDetalleExport as $r) {
        $idsUsuariosExport[(int)$r['usuario_id']] = true;
        $idsActividadesExport[(int)$r['actividad_id']] = true;
        $sumaTotalDetalle += (int)$r['total_seg'];

        $stmtG = $pdo->prepare("SELECT ag.nombre FROM actividades act LEFT JOIN actividad_grupos ag ON ag.id=act.grupo_id WHERE act.id=?");
        $stmtG->execute([(int)$r['actividad_id']]);
        $gNombre = $stmtG->fetchColumn() ?: 'Sin grupo';

        $detalleFilas[] = [
          'actividad' => (string)$r['titulo'],
          'grupo' => (string)$gNombre,
          'usuario' => (string)$r['nombre_completo'],
          'seg' => (int)$r['total_seg'],
        ];
      }
      $kpiUsuariosExport = count($idsUsuariosExport);
      $kpiActividadesExport = count($idsActividadesExport);
      $kpiPromedioExport = $kpiUsuariosExport > 0 ? (int)round($sumaTotalDetalle / $kpiUsuariosExport) : 0;

      // === Texto legible de los filtros aplicados ===
      $filtroUsuarioTxt = 'Todos';
      if ($usuarioId !== 'all') {
        $stmtUn = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE id=?");
        $stmtUn->execute([(int)$usuarioId]);
        $filtroUsuarioTxt = $stmtUn->fetchColumn() ?: ('ID ' . $usuarioId);
      }
      $filtroActividadTxt = 'Todas';
      if (!$filtroTodasActividades) {
        $placeholders = implode(',', array_fill(0, count($actividadIds), '?'));
        $stmtAn = $pdo->prepare("SELECT titulo FROM actividades WHERE id IN ($placeholders)");
        $stmtAn->execute($actividadIds);
        $filtroActividadTxt = implode(', ', $stmtAn->fetchAll(PDO::FETCH_COLUMN));
      }

      // === Cabeceras HTTP para descarga de archivo Excel (.xls) ===
      header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
      header('Content-Disposition: attachment; filename="reporte_RAL_completo_' . $fechaDesde . '_' . $fechaHasta . '.xls"');
      header('Cache-Control: max-age=0');

      echo '<!DOCTYPE html>';
      echo '<html><head><meta charset="UTF-8"><style>';
      echo 'body { font-family: Arial, sans-serif; }';
      echo 'table { border-collapse: collapse; width: 100%; margin-bottom: 22px; }';
      echo 'th, td { border: 1px solid #d1d5db; padding: 8px; text-align: left; font-size: 13px; }';
      echo 'th { background-color: #2563eb; color: #ffffff; font-weight: bold; }';
      echo '.text-end { text-align: right; }';
      echo '.bold { font-weight: bold; }';
      echo '.section-title { font-size: 15px; font-weight: bold; background-color: #1e293b; color: #ffffff; padding: 8px; margin-top: 10px; }';
      echo '.kpi-table { margin-bottom: 10px; }';
      echo '.kpi-table td { border: none; padding: 3px 8px; font-size: 13px; }';
      echo '.kpi-label { color: #475569; }';
      echo '.kpi-value { font-weight: bold; color: #1e293b; }';
      echo '</style></head><body>';

      // ===== Encabezado con filtros aplicados =====
      echo '<table class="kpi-table">';
      echo '<tr><td class="kpi-label">Reporte</td><td class="kpi-value">Reporte de tiempo y cumplimiento RAL</td></tr>';
      echo '<tr><td class="kpi-label">Rango de fechas</td><td class="kpi-value">' . htmlspecialchars($fechaDesde . ' a ' . $fechaHasta, ENT_QUOTES, 'UTF-8') . '</td></tr>';
      echo '<tr><td class="kpi-label">Usuario filtrado</td><td class="kpi-value">' . htmlspecialchars((string)$filtroUsuarioTxt, ENT_QUOTES, 'UTF-8') . '</td></tr>';
      echo '<tr><td class="kpi-label">Actividad(es) filtrada(s)</td><td class="kpi-value">' . htmlspecialchars((string)$filtroActividadTxt, ENT_QUOTES, 'UTF-8') . '</td></tr>';
      echo '<tr><td class="kpi-label">Generado el</td><td class="kpi-value">' . htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') . '</td></tr>';
      echo '</table>';

      // ===== KPIs generales =====
      echo '<table class="kpi-table">';
      echo '<tr><td class="kpi-label">Usuarios con actividad</td><td class="kpi-value">' . (int)$kpiUsuariosExport . '</td></tr>';
      echo '<tr><td class="kpi-label">Actividades distintas</td><td class="kpi-value">' . (int)$kpiActividadesExport . '</td></tr>';
      echo '<tr><td class="kpi-label">Tiempo total (todos los usuarios)</td><td class="kpi-value">' . htmlspecialchars(msOrSegToHMSFromSeconds($sumaTotalDetalle), ENT_QUOTES, 'UTF-8') . '</td></tr>';
      echo '<tr><td class="kpi-label">Promedio de tiempo por usuario</td><td class="kpi-value">' . htmlspecialchars(msOrSegToHMSFromSeconds($kpiPromedioExport), ENT_QUOTES, 'UTF-8') . '</td></tr>';
      echo '</table>';

      // ===== Tabla 1: RAL - Días hábiles y cumplimiento =====
      echo '<div class="section-title">Reporte RAL — Días hábiles y cumplimiento</div>';
      echo '<table>';
      echo '<thead><tr>';
      echo '<th>Usuario</th>';
      echo '<th>Sede</th>';
      echo '<th class="text-end">Días hábiles mes</th>';
      echo '<th class="text-end">Vacaciones</th>';
      echo '<th class="text-end">Permisos</th>';
      echo '<th class="text-end">Compensatorios</th>';
      echo '<th class="text-end">Días efectivos</th>';
      echo '<th class="text-end">Tiempo total (hh:mm:ss)</th>';
      echo '<th class="text-end">Tiempo en días jornada</th>';
      echo '<th class="text-end">Cumplimiento %</th>';
      echo '</tr></thead><tbody>';
      if (empty($ralRowsExport)) {
        echo '<tr><td colspan="10" class="text-end">Sin datos para el filtro seleccionado.</td></tr>';
      }
      foreach ($ralRowsExport as $rr) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($rr['nombre'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($rr['sede'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-end">' . (int)$rr['habiles'] . '</td>';
        echo '<td class="text-end">' . ($rr['vac'] ? number_format($rr['vac'], 2, ',', '.') : '—') . '</td>';
        echo '<td class="text-end">' . ($rr['perm'] ? number_format($rr['perm'], 2, ',', '.') : '—') . '</td>';
        echo '<td class="text-end">' . ($rr['comp'] ? number_format($rr['comp'], 2, ',', '.') : '—') . '</td>';
        echo '<td class="text-end bold">' . number_format($rr['efectivos'], 2, ',', '.') . '</td>';
        echo '<td class="text-end">' . htmlspecialchars(msOrSegToHMSFromSeconds($rr['seg']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-end">' . htmlspecialchars(formatDiasJornada($rr['seg']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-end bold">' . number_format($rr['cumpl'], 1, ',', '.') . '%</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';

      // ===== Tabla 2: Detalle Actividad, Grupo, Usuario y Tiempo =====
      echo '<div class="section-title">Reportes Actividad, Usuarios y Tiempo (detalle)</div>';
      echo '<table>';
      echo '<thead><tr>';
      echo '<th>Actividad</th>';
      echo '<th>Grupo RAL</th>';
      echo '<th>Usuario</th>';
      echo '<th class="text-end">Tiempo total (hh:mm:ss)</th>';
      echo '<th class="text-end">Tiempo en días</th>';
      echo '</tr></thead><tbody>';
      if (empty($detalleFilas)) {
        echo '<tr><td colspan="5" class="text-end">Sin datos para el filtro seleccionado.</td></tr>';
      }
      foreach ($detalleFilas as $df) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($df['actividad'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($df['grupo'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($df['usuario'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-end">' . htmlspecialchars(msOrSegToHMSFromSeconds($df['seg']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="text-end">' . htmlspecialchars(formatDiasJornada($df['seg']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';

      echo '</body></html>';
      exit;
    }

    // Mantener exportación CSV como fallback
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_RAL_' . $fechaDesde . '_' . $fechaHasta . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $tmpUsers = $pdo->query("SELECT id,nombre_completo FROM usuarios ORDER BY nombre_completo ASC")->fetchAll();
    fputcsv($out, ['Usuario', 'Sede', 'Dias habiles mes', 'Vacaciones', 'Permisos', 'Compensatorios', 'Dias efectivos', 'Tiempo total (hh:mm:ss)', 'Tiempo en dias jornada', 'Cumplimiento %']);
    foreach ($tmpUsers as $tu) {
      $uid = (int)$tu['id'];
      if ($usuarioId !== 'all' && (int)$usuarioId !== $uid) continue;
      $calc = calcularDiasEfectivosRal($pdo, $fechaDesde, $fechaHasta, $uid);
      $totSeg = fetchSumaTiemposContadorCapped($pdo, $fechaDesde, $fechaHasta, [$uid]);
      $ausDet = fetchRalAusenciasDetalle($pdo, $fechaDesde, $fechaHasta, $uid);
      $vac = 0;
      $perm = 0;
      $comp = 0;
      foreach ($ausDet as $ad) {
        if ($ad['tipo'] === 'vacaciones') $vac += (float)$ad['dias'];
        elseif ($ad['tipo'] === 'permiso') $perm += (float)$ad['dias'];
        else $comp += (float)$ad['dias'];
      }
      $stmtS = $pdo->prepare("SELECT s.nombre FROM usuarios u LEFT JOIN sedes s ON s.id=u.sede_id WHERE u.id=?");
      $stmtS->execute([$uid]);
      $sede = $stmtS->fetchColumn() ?: '';
      $cumpl = $calc['efectivos'] > 0 ? (segundosADiasJornada($totSeg) / $calc['efectivos'] * 100) : 0;
      fputcsv($out, [$tu['nombre_completo'], $sede, $calc['habiles'], $vac, $perm, $comp, $calc['efectivos'], msOrSegToHMSFromSeconds($totSeg), formatDiasJornada($totSeg), number_format($cumpl, 1, ',', '.') . '%']);
    }
    fclose($out);
    exit;
  }

  // Detalle general en CSV
  $rowsCsv = fetchReporteTiempoRows($pdo, $fechaDesde, $fechaHasta, $usuarioId, $actividadIds, true);
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="reporte_tiempo_' . $fechaDesde . '_' . $fechaHasta . '.csv"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Actividad', 'Grupo RAL', 'Nombre usuario', 'Tiempo total (hh:mm:ss)', 'Tiempo en días']);
  foreach ($rowsCsv as $r) {
    $stmtG = $pdo->prepare("SELECT ag.nombre FROM actividades act LEFT JOIN actividad_grupos ag ON ag.id=act.grupo_id WHERE act.id=?");
    $stmtG->execute([(int)$r['actividad_id']]);
    $gNombre = $stmtG->fetchColumn() ?: 'Sin grupo';
    fputcsv($out, [
      $r['titulo'],
      $gNombre,
      $r['nombre_completo'],
      msOrSegToHMSFromSeconds((int)$r['total_seg']),
      formatDiasJornada((int)$r['total_seg']),
    ]);
  }
  fclose($out);
  exit;
}

$stmtActs = $pdo->query('SELECT id, titulo FROM actividades ORDER BY titulo ASC');
$actividades = $stmtActs->fetchAll();

$stmtUsers = $pdo->query('SELECT id, nombre_completo FROM usuarios ORDER BY nombre_completo ASC');
$usuarios = $stmtUsers->fetchAll();

$rows = fetchReporteTiempoRows($pdo, $fechaDesde, $fechaHasta, $usuarioId, $actividadIds, true);
$rowsCharts = fetchReporteTiempoRows($pdo, $fechaDesde, $fechaHasta, $usuarioId, $actividadIds, false);

$sumaTotalGeneral = 0;
$idsUsuarios = [];
$idsActividades = [];
foreach ($rows as $r) {
  $idsUsuarios[(int)$r['usuario_id']] = true;
  $idsActividades[(int)$r['actividad_id']] = true;
}
if ($filtroTodasActividades) {
  $usuarioFilter = $usuarioId === 'all' ? null : [(int)$usuarioId];
  $sumaTotalGeneral = fetchSumaTiemposContadorCapped($pdo, $fechaDesde, $fechaHasta, $usuarioFilter);
} else {
  foreach ($rows as $r) {
    $sumaTotalGeneral += (int)$r['total_seg'];
  }
}
$kpiUsuarios = count($idsUsuarios);
$kpiActividades = count($idsActividades);
$kpiPromedioSeg = $kpiUsuarios > 0 ? (int)round($sumaTotalGeneral / $kpiUsuarios) : 0;

$byAct = [];
foreach ($rowsCharts as $r) {
  $aid = (int)$r['actividad_id'];
  if (!isset($byAct[$aid])) {
    $byAct[$aid] = ['label' => $r['titulo'], 'seg' => 0];
  }
  $byAct[$aid]['seg'] += (int)$r['total_seg'];
}
$chartActItems = array_values($byAct);

$byUsr = [];
foreach ($rowsCharts as $r) {
  $uid = (int)$r['usuario_id'];
  if (!isset($byUsr[$uid])) {
    $byUsr[$uid] = ['label' => $r['nombre_completo'], 'seg' => 0];
  }
  $byUsr[$uid]['seg'] += (int)$r['total_seg'];
}
$chartUsrItems = array_values($byUsr);

$chartDonut = chartDataTopWithOther($chartActItems, 8);
$chartBars = chartDataTopWithOther($chartUsrItems, 12);

$sumaSegGraficoActividad = array_sum($chartDonut['values'] ?? []);
$sumaSegGraficoUsuario = array_sum($chartBars['values'] ?? []);
$reportHasDonutData = $sumaSegGraficoActividad > 0 && !empty($chartDonut['labels']);
$reportHasBarData = $sumaSegGraficoUsuario > 0 && !empty($chartBars['labels']);

$totalRows = count($rows);
$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min(max(1, $page), $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;
$rowsPage = array_slice($rows, $offset, $rowsPerPage);

$title = 'Reporte de tiempo - Control Sedes';
$adminNavCurrent = 'reportes';
$adminSidebarFootInclude = __DIR__ . '/../includes/partials/admin_sidebar_help_reportes.php';
$qUsuario = '';
$sedeIdFiltro = 0;

$clearUrl = htmlspecialchars(BASE_URL . 'admin/reportes_tiempo_actividad.php?fecha_desde=' . rawurlencode($hoy) . '&fecha_hasta=' . rawurlencode($hoy) . '&usuario_id=all', ENT_QUOTES, 'UTF-8');

$csvUrl = htmlspecialchars(BASE_URL . 'admin/reportes_tiempo_actividad.php?' . http_build_query(array_merge($baseQueryParams, ['export' => 'csv'])), ENT_QUOTES, 'UTF-8');
$csvRalUrl = htmlspecialchars(BASE_URL . 'admin/reportes_tiempo_actividad.php?' . http_build_query(array_merge($baseQueryParams, ['export' => 'csv', 'export_mode' => 'ral'])), ENT_QUOTES, 'UTF-8');
$xlsRalUrl = htmlspecialchars(BASE_URL . 'admin/reportes_tiempo_actividad.php?' . http_build_query(array_merge($baseQueryParams, ['export' => 'xls', 'export_mode' => 'ral'])), ENT_QUOTES, 'UTF-8');

$actividadIdsSeleccionados = array_fill_keys($actividadIds, true);

// === RAL: agrupación y días hábiles ===
$gruposRal = getRalGrupos($pdo);
$grupoIdFiltro = isset($_GET['grupo_id']) ? (int)$_GET['grupo_id'] : 0;
if ($grupoIdFiltro > 0) $baseQueryParams['grupo_id'] = $grupoIdFiltro;
// Mapa actividad -> grupo
$actGrupoMap = [];
try {
  foreach ($pdo->query("SELECT id,grupo_id,servicio_subtipo FROM actividades")->fetchAll() as $am) $actGrupoMap[(int)$am['id']] = ['grupo_id' => (int)($am['grupo_id'] ?? 0), 'subtipo' => (string)($am['servicio_subtipo'] ?? '')];
} catch (Throwable $e) {
}
// Agregado por grupo (solo admin ve)
$byGrupo = [];
foreach ($rows as $r) {
  $gid = (int)($actGrupoMap[(int)$r['actividad_id']]['grupo_id'] ?? 0);
  if ($grupoIdFiltro > 0 && $gid !== $grupoIdFiltro) continue;
  if (!isset($byGrupo[$gid])) $byGrupo[$gid] = ['seg' => 0, 'actividades' => []];
  $byGrupo[$gid]['seg'] += (int)$r['total_seg'];
  $byGrupo[$gid]['actividades'][(int)$r['actividad_id']] = true;
}
// Datos RAL por usuaria (solo si hay config mes)
$ralPorUsuario = [];
$ralMesesSinConfig = [];
$ralHabilesReferencia = null;
try {
  // si rango es un mes calendario, usar ese mes como referencia
  $d1 = new DateTime($fechaDesde);
  $d2 = new DateTime($fechaHasta);
  if ($d1->format('Y-m') === $d2->format('Y-m')) {
    $ralHabilesReferencia = fetchRalDiasHabilesMes($pdo, (int)$d1->format('Y'), (int)$d1->format('n'));
  } else {
    // suma de meses tocados
    $sum = 0;
    $sin = [];
    $pIni = new DateTime($fechaDesde);
    $pIni->modify('first day of this month');
    $pFin = new DateTime($fechaHasta);
    $pFin->modify('first day of next month');
    $period = new DatePeriod($pIni, new DateInterval('P1M'), $pFin);
    foreach ($period as $dt) {
      $a = (int)$dt->format('Y');
      $m = (int)$dt->format('n');
      $dh = fetchRalDiasHabilesMes($pdo, $a, $m);
      if ($dh === null) $sin[] = sprintf('%04d-%02d', $a, $m);
      else $sum += $dh;
    }
    $ralHabilesReferencia = $sum;
    $ralMesesSinConfig = $sin;
  }
  // construir por usuario visible
  $userIdsRal = $usuarioId === 'all' ? array_keys($idsUsuarios) : [(int)$usuarioId];
  if ($userIdsRal === [] && $usuarioId === 'all') {
    // si no hay tiempo, igual listar usuarios filtrados para RAL vacío
    foreach ($usuarios as $u) $userIdsRal[] = (int)$u['id'];
    $userIdsRal = array_slice($userIdsRal, 0, 25);
  }
  $ausMapAll = fetchRalAusenciasPorUsuario($pdo, $fechaDesde, $fechaHasta, $userIdsRal ?: null);
  // para detalle tipo
  $stmtSedeMap = $pdo->query("SELECT id,sede_id FROM usuarios");
  $sedeByUser = [];
  foreach ($stmtSedeMap->fetchAll() as $su) $sedeByUser[(int)$su['id']] = (int)($su['sede_id'] ?? 0);
  $sedesNombres = [];
  foreach ($pdo->query("SELECT id,nombre FROM sedes")->fetchAll() as $sr) $sedesNombres[(int)$sr['id']] = $sr['nombre'];
  foreach ($userIdsRal as $uid) {
    if ($uid <= 0) continue;
    $nombre = '?';
    foreach ($usuarios as $u) if ((int)$u['id'] === $uid) {
      $nombre = $u['nombre_completo'];
      break;
    }
    if ($nombre === '?') {
      $stmtN = $pdo->prepare("SELECT nombre_completo FROM usuarios WHERE id=?");
      $stmtN->execute([$uid]);
      $nombre = $stmtN->fetchColumn() ?: ('ID ' . $uid);
    }
    $totSeg = 0;
    foreach ($rows as $r) if ((int)$r['usuario_id'] === $uid) $totSeg += (int)$r['total_seg'];
    // si filtro todas actividades, usar capped total real
    if ($filtroTodasActividades) {
      $totSeg = fetchSumaTiemposContadorCapped($pdo, $fechaDesde, $fechaHasta, [$uid]);
    }
    $aus = (float)($ausMapAll[$uid] ?? 0);
    $habiles = $ralHabilesReferencia ?? 0;
    $efectivos = max(0, $habiles - $aus);
    $diasTrab = segundosADiasJornada($totSeg);
    $cumpl = $efectivos > 0 ? ($diasTrab / $efectivos * 100) : 0;
    // detalle aus por tipo
    $det = fetchRalAusenciasDetalle($pdo, $fechaDesde, $fechaHasta, $uid);
    $vac = 0;
    $perm = 0;
    $comp = 0;
    foreach ($det as $d) {
      if ($d['tipo'] === 'vacaciones') $vac += (float)$d['dias'];
      elseif ($d['tipo'] === 'permiso') $perm += (float)$d['dias'];
      else $comp += (float)$d['dias'];
    }
    $ralPorUsuario[] = ['usuario_id' => $uid, 'nombre' => $nombre, 'sede' => $sedesNombres[$sedeByUser[$uid] ?? 0] ?? '', 'habiles' => $habiles, 'vac' => $vac, 'perm' => $perm, 'comp' => $comp, 'aus' => $aus, 'efectivos' => $efectivos, 'seg' => $totSeg, 'diasTrab' => $diasTrab, 'cumpl' => $cumpl];
  }
  // ordenar por cumplimiento desc
  usort($ralPorUsuario, function ($a, $b) {
    return $b['cumpl'] <=> $a['cumpl'];
  });
} catch (Throwable $e) {
}

require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>

<div class="report-page">
  <div class="admin-act-page-head report-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-graph-up-arrow text-primary me-2"></i>Reporte de tiempo invertido</h1>
      <p class="admin-act-page-lead">Analiza y descarga el tiempo invertido por actividad, usuario y rango de fechas.</p>
    </div>
    <a class="btn admin-act-back-btn" href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-arrow-left me-2"></i>Volver al panel
    </a>
  </div>

  <div class="report-kpi-row">
    <div class="report-kpi-card report-kpi-card--blue">
      <div class="report-kpi-icon"><i class="bi bi-stopwatch"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Tiempo total</div>
        <div class="report-kpi-value"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds($sumaTotalGeneral), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="report-kpi-hint">horas en el rango</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--green">
      <div class="report-kpi-icon"><i class="bi bi-people"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Usuarios activos</div>
        <div class="report-kpi-value"><?php echo (int)$kpiUsuarios; ?></div>
        <div class="report-kpi-hint">en el rango seleccionado</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--orange">
      <div class="report-kpi-icon"><i class="bi bi-grid-3x3-gap"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Actividades registradas</div>
        <div class="report-kpi-value"><?php echo (int)$kpiActividades; ?></div>
        <div class="report-kpi-hint">diferentes actividades</div>
      </div>
    </div>
    <div class="report-kpi-card report-kpi-card--purple">
      <div class="report-kpi-icon"><i class="bi bi-graph-up-arrow"></i></div>
      <div class="report-kpi-body">
        <div class="report-kpi-label">Promedio por usuario</div>
        <div class="report-kpi-value"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds($kpiPromedioSeg), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="report-kpi-hint">horas en el periodo</div>
      </div>
    </div>
  </div>

  <div class="admin-act-card report-filter-card mb-4">
    <form method="GET" action="" class="report-filter-form">
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
          <label class="form-label report-filter-label" for="usuario_id">Usuario</label>
          <select class="form-select" id="usuario_id" name="usuario_id">
            <option value="all" <?php echo $usuarioId === 'all' ? 'selected' : ''; ?>>Todos los usuarios</option>
            <?php foreach ($usuarios as $u): ?>
              <option value="<?php echo (int)$u['id']; ?>" <?php echo ($usuarioId !== 'all' && (int)$usuarioId === (int)$u['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($u['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <label class="form-label report-filter-label" for="grupo_id">Grupo RAL <span class="badge bg-primary-subtle text-primary border ms-1" title="Solo admin ve este agrupador">admin</span></label>
          <select class="form-select" id="grupo_id" name="grupo_id">
            <option value="0">Todos los grupos</option>
            <?php foreach ($gruposRal as $gr): ?><option value="<?php echo (int)$gr['id']; ?>" <?php echo $grupoIdFiltro === (int)$gr['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($gr['nombre'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
            <option value="-1" <?php echo $grupoIdFiltro === -1 ? 'selected' : ''; ?>>Sin clasificar</option>
          </select>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <label class="form-label report-filter-label" for="actividad_ms_btn">Actividad</label>
          <div class="report-act-ms" id="actividad_ms" data-report-act-ms>
            <button
              type="button"
              class="form-select report-act-ms-btn text-start"
              id="actividad_ms_btn"
              aria-haspopup="listbox"
              aria-expanded="false"
              aria-controls="actividad_ms_panel">
              <span class="report-act-ms-label" id="actividad_ms_label">
                <?php
                if ($filtroTodasActividades) {
                  echo 'Todas las actividades';
                } elseif (count($actividadIds) === 1) {
                  $soloId = (int)$actividadIds[0];
                  $soloTitulo = '1 actividad';
                  foreach ($actividades as $aLab) {
                    if ((int)$aLab['id'] === $soloId) {
                      $soloTitulo = (string)$aLab['titulo'];
                      break;
                    }
                  }
                  echo htmlspecialchars($soloTitulo, ENT_QUOTES, 'UTF-8');
                } else {
                  echo htmlspecialchars(count($actividadIds) . ' actividades seleccionadas', ENT_QUOTES, 'UTF-8');
                }
                ?>
              </span>
            </button>
            <div class="report-act-ms-panel" id="actividad_ms_panel" role="listbox" aria-multiselectable="true" hidden>
              <label class="report-act-ms-option report-act-ms-option--all">
                <input type="checkbox" id="actividad_ms_all" <?php echo $filtroTodasActividades ? 'checked' : ''; ?>>
                <span>Todas las actividades</span>
              </label>
              <div class="report-act-ms-divider" aria-hidden="true"></div>
              <div class="report-act-ms-list">
                <?php foreach ($actividades as $a): ?>
                  <?php $aidOpt = (int)$a['id']; ?>
                  <label class="report-act-ms-option">
                    <input
                      type="checkbox"
                      class="report-act-ms-check"
                      name="actividad_id[]"
                      value="<?php echo $aidOpt; ?>"
                      <?php echo isset($actividadIdsSeleccionados[$aidOpt]) ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars((string)$a['titulo'], ENT_QUOTES, 'UTF-8'); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
          <button class="btn btn-primary px-4" type="submit"><i class="bi bi-search me-2"></i>Consultar</button>
          <a class="btn btn-outline-secondary px-4" href="<?php echo $clearUrl; ?>"><i class="bi bi-arrow-counterclockwise me-2"></i>Limpiar</a>
        </div>
      </div>
      <p class="report-filter-foot mb-0">
        <i class="bi bi-info-circle me-1"></i>El tiempo se suma por jornadas (días) dentro del rango. Si hay intervalos en curso, se incluye el tiempo estimado hasta ahora.
        <?php if ($filtroTodasActividades): ?>
          <span class="d-block mt-1">Los totales, KPIs, tabla y CSV <strong>no incluyen</strong> la actividad de almuerzo; la dona y el gráfico por usuario <strong>sí la muestran</strong> como referencia. Cada día aporta como máximo <?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)TOPE_HORAS_DIARIAS_CONTADOR_SEG), ENT_QUOTES, 'UTF-8'); ?> por usuario al total general.</span>
        <?php endif; ?>
      </p>
    </form>
  </div>

  <!-- RAL: dias habiles + ausencias (solo admin) -->
  <div class="admin-act-card mb-4" style="border-left:4px solid #2563eb;">
    <div class="p-3 p-md-4">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <h2 class="h5 fw-bold mb-1"><i class="bi bi-calendar-week me-2 text-primary"></i>Reporte RAL — Días hábiles y cumplimiento</h2>
          <div class="small text-muted">Solo visible para revisión admin. <code>Días efectivos = Días hábiles − Vacaciones − Permisos − Compensatorios</code>. Cumplimiento = Tiempo registrado (en días de 8 h) / Días efectivos.</div>
        </div>
        <div class="d-flex gap-2">
          <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars(BASE_URL . 'admin/ral_config.php', ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-gear me-1"></i>Configurar días / ausencias</a>
          <a class="btn btn-sm btn-outline-primary" href="<?php echo $xlsRalUrl; ?>">
            <i class="bi bi-file-earmark-excel me-1"></i>Exportar RAL Excel (.xls)
          </a>
        </div>
      </div>
      <?php if ($ralHabilesReferencia === null || $ralHabilesReferencia === 0): ?>
        <div class="alert alert-warning d-flex gap-2 align-items-start py-2"><i class="bi bi-exclamation-triangle-fill mt-1"></i>
          <div><strong>Sin días hábiles configurados</strong> para <?php echo htmlspecialchars($fechaDesde . ' a ' . $fechaHasta, ENT_QUOTES, 'UTF-8'); ?>. Ve a <a href="<?php echo htmlspecialchars(BASE_URL . 'admin/ral_config.php', ENT_QUOTES, 'UTF-8'); ?>">RAL → Configurar días</a> y registra los días hábiles del mes. Mientras tanto el cumplimiento se calcula sobre 0.</div>
        </div>
      <?php elseif (!empty($ralMesesSinConfig)): ?>
        <div class="alert alert-warning py-2 small">Faltan días hábiles para: <?php echo htmlspecialchars(implode(', ', $ralMesesSinConfig), ENT_QUOTES, 'UTF-8'); ?>. El total (<?php echo (int)$ralHabilesReferencia; ?>) excluye esos meses.</div>
      <?php else: ?>
        <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>Días hábiles en el rango: <strong><?php echo (int)$ralHabilesReferencia; ?></strong> (<?php echo htmlspecialchars($fechaDesde . ' a ' . $fechaHasta, ENT_QUOTES, 'UTF-8'); ?>). Se restan automáticamente las ausencias registradas.</div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 table-report-professional">
          <thead class="table-report-head">
            <tr>
              <th>Compañera</th>
              <th>Sede</th>
              <th class="text-end">Días hábiles</th>
              <th class="text-end">Vac.</th>
              <th class="text-end">Perm.</th>
              <th class="text-end">Comp.</th>
              <th class="text-end">Efectivos</th>
              <th class="text-end">Tiempo registrado</th>
              <th class="text-end">Días trabajados</th>
              <th class="text-end">Cumplimiento</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($ralPorUsuario)): ?><tr>
                <td colspan="10" class="text-center text-muted small py-3">Sin usuarias en el filtro.</td>
              </tr>
              <?php else: foreach ($ralPorUsuario as $ral): $cumpl = (float)$ral['cumpl'];
                $cls = $cumpl >= 95 ? 'text-success' : ($cumpl >= 80 ? 'text-warning' : 'text-danger'); ?>
                <tr>
                  <td class="fw-semibold"><?php echo htmlspecialchars($ral['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="small text-muted"><?php echo htmlspecialchars($ral['sede'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end"><?php echo (int)$ral['habiles']; ?></td>
                  <td class="text-end"><?php echo $ral['vac'] ? htmlspecialchars(rtrim(rtrim(number_format($ral['vac'], 2, ',', '.'), '0'), ','), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                  <td class="text-end"><?php echo $ral['perm'] ? htmlspecialchars(rtrim(rtrim(number_format($ral['perm'], 2, ',', '.'), '0'), ','), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                  <td class="text-end"><?php echo $ral['comp'] ? htmlspecialchars(rtrim(rtrim(number_format($ral['comp'], 2, ',', '.'), '0'), ','), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                  <td class="text-end fw-bold"><?php echo htmlspecialchars(number_format($ral['efectivos'], 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end font-monospace small"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$ral['seg']), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end fw-semibold"><?php echo htmlspecialchars(number_format($ral['diasTrab'], 2, ',', '.'), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end fw-bold <?php echo $cls; ?>"><?php echo htmlspecialchars(number_format($cumpl, 1, ',', '.'), ENT_QUOTES, 'UTF-8'); ?>%</td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Agrupación RAL por grupos (solo admin) -->
  <div class="admin-act-card mb-4" id="ral-grupos">
    <div class="p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold mb-0"><i class="bi bi-collection me-2 text-primary"></i>Agrupado por grupo RAL</h2>
        <span class="badge bg-primary"><?php echo count($byGrupo); ?> grupos con tiempo</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Grupo RAL</th>
              <th class="text-end">Tiempo total</th>
              <th class="text-end">Días (8 h)</th>
              <th class="text-end">% del total</th>
              <th>Detalle</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($byGrupo)): ?><tr>
                <td colspan="5" class="text-center text-muted small py-3">Sin datos agrupados. Clasifica las actividades en <a href="<?php echo htmlspecialchars(BASE_URL . 'admin/actividades.php', ENT_QUOTES, 'UTF-8'); ?>">Actividades</a>.</td>
              </tr>
              <?php else: $totalGrupoSeg = array_sum(array_column($byGrupo, 'seg'));
              foreach ($byGrupo as $gid => $info): $gNombre = $gid === 0 ? 'Sin clasificar' : '';
                foreach ($gruposRal as $gr) if ((int)$gr['id'] === $gid) $gNombre = $gr['nombre'];
                $pct = $totalGrupoSeg > 0 ? $info['seg'] / $totalGrupoSeg * 100 : 0; ?>
                <tr>
                  <td class="fw-semibold"><?php echo htmlspecialchars($gNombre, ENT_QUOTES, 'UTF-8'); ?><?php if ($gid === (int)RAL_GRUPO_SERVICIOS): ?><span class="ms-1 badge bg-info-subtle text-info border">incluye completo/inicial/final/terceros/mascotas/pago destino final</span><?php endif; ?></td>
                  <td class="text-end font-monospace small"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$info['seg']), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end"><?php echo htmlspecialchars(formatDiasJornada((int)$info['seg']), ENT_QUOTES, 'UTF-8'); ?></td>
                  <td class="text-end fw-semibold"><?php echo htmlspecialchars(number_format($pct, 1, ',', '.'), ENT_QUOTES, 'UTF-8'); ?>%</td>
                  <td class="small text-muted"><?php echo count($info['actividades']); ?> act.</td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <th>Total filtrado</th>
              <th class="text-end font-monospace"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds(array_sum(array_column($byGrupo, 'seg'))), ENT_QUOTES, 'UTF-8'); ?></th>
              <th class="text-end"><?php echo htmlspecialchars(formatDiasJornada(array_sum(array_column($byGrupo, 'seg'))), ENT_QUOTES, 'UTF-8'); ?></th>
              <th></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <!-- Detalle servicios por subtipo si hay servicios -->
      <?php
      $hasServicios = isset($byGrupo[(int)RAL_GRUPO_SERVICIOS]) && $byGrupo[(int)RAL_GRUPO_SERVICIOS]['seg'] > 0;
      if ($hasServicios):
        // recalcular por subtipo
        $bySubtipo = [];
        foreach ($rows as $r) {
          $gid = (int)($actGrupoMap[(int)$r['actividad_id']]['grupo_id'] ?? 0);
          if ($gid !== (int)RAL_GRUPO_SERVICIOS) continue;
          $st = $actGrupoMap[(int)$r['actividad_id']]['subtipo'] ?: 'completo';
          if (!isset($bySubtipo[$st])) $bySubtipo[$st] = 0;
          $bySubtipo[$st] += (int)$r['total_seg'];
        }
      ?>
        <div class="mt-3 p-3 bg-light rounded-3 border">
          <div class="fw-semibold small mb-2"><i class="bi bi-heart-pulse me-1"></i>Servicios — desglose (completo / inicial / final / terceros / mascotas / pago destino final)</div>
          <div class="table-responsive">
            <table class="table table-sm small mb-0">
              <thead>
                <tr>
                  <th>Subtipo</th>
                  <th class="text-end">Tiempo</th>
                  <th class="text-end">Días</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bySubtipo as $st => $seg): ?><tr>
                    <td class="fw-semibold"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $st)), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end font-monospace"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$seg), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><?php echo htmlspecialchars(formatDiasJornada((int)$seg), ENT_QUOTES, 'UTF-8'); ?></td>
                  </tr><?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="small text-muted mt-2">Clasifica cada actividad de Servicios en <code>actividades.php</code> (columna subtipo) y registra el detalle de servicio completo/inicial/final en las <code>actividad_notas</code> si aplica.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
      <div class="report-chart-card h-100">
        <div class="report-chart-head">
          <h2 class="report-chart-title">Tiempo por actividad</h2>
          <a href="#reportActividadDetail" class="report-chart-link small">Ver detalle</a>
        </div>
        <div class="report-chart-body">
          <?php if (!$reportHasDonutData): ?>
            <div class="report-chart-empty">Sin datos para el rango seleccionado.</div>
          <?php else: ?>
            <div class="report-donut-layout">
              <div class="report-donut-stage">
                <div class="report-donut-wrap">
                  <canvas id="chartActividad" height="220" aria-label="Gráfico tiempo por actividad"></canvas>
                  <div class="report-donut-center" id="chartActividadCenter">
                    <span class="report-donut-total"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds($sumaSegGraficoActividad), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="report-donut-sub">Total (gráfico)</span>
                  </div>
                </div>
              </div>
              <div class="report-actividad-detail" id="reportActividadDetail" tabindex="-1" aria-label="Detalle por actividad">
                <ul class="report-legend list-unstyled mb-0" id="chartActividadLegend"></ul>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="report-chart-card h-100">
        <div class="report-chart-head">
          <h2 class="report-chart-title">Tiempo por usuario</h2>
          <a href="#reporte-tabla-detalle" class="report-chart-link small">Ver detalle</a>
        </div>
        <div class="report-chart-body">
          <?php if (!$reportHasBarData): ?>
            <div class="report-chart-empty">Sin datos para el rango seleccionado.</div>
          <?php else: ?>
            <canvas id="chartUsuario" height="280" aria-label="Gráfico tiempo por usuario"></canvas>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-act-card report-table-section" id="reporte-tabla-detalle" tabindex="-1">
    <div class="report-table-toolbar">
      <div class="d-flex align-items-start gap-3 flex-wrap">
        <div class="report-table-title-wrap">
          <h2 class="report-table-main-title"><i class="bi bi-table me-2 text-primary"></i>Reportes Actividad, usuarios y tiempo</h2>
        </div>
        <div class="ms-lg-auto d-flex flex-wrap align-items-center gap-2">
          <div class="dropdown">
            <button class="btn btn-outline-primary btn-sm dropdown-toggle rounded-3 fw-semibold" type="button" data-bs-toggle="dropdown" aria-expanded="false">
              Exportar
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
              <li><a class="dropdown-item" href="<?php echo $csvUrl; ?>"><i class="bi bi-filetype-csv me-2"></i>CSV detalle</a></li>
              <li>
                <a class="dropdown-item" href="<?php echo $xlsRalUrl; ?>">
                  <i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel RAL (.xls)
                </a>
              </li>
            </ul>
          </div>
          <button type="button" class="btn btn-light border btn-sm rounded-3" title="Vista (próximamente)" disabled aria-disabled="true">
            <i class="bi bi-layout-three-columns"></i>
          </button>
        </div>
      </div>
      <?php if ($usuarioId !== 'all'): ?>
        <div class="mt-2"><span class="badge rounded-pill text-bg-info">Filtrado por usuario</span></div>
      <?php endif; ?>
      <?php if (!$filtroTodasActividades): ?>
        <div class="mt-2"><span class="badge rounded-pill text-bg-primary"><?php echo count($actividadIds); ?> actividad<?php echo count($actividadIds) === 1 ? '' : 'es'; ?> filtrada<?php echo count($actividadIds) === 1 ? '' : 's'; ?></span></div>
      <?php endif; ?>
    </div>

    <div class="table-responsive report-table-responsive">
      <table class="table table-sm align-middle mb-0 table-report-professional">
        <thead class="table-report-head">
          <tr>
            <th>Actividad</th>
            <th>Nombre usuario</th>
            <th style="width: 150px">Tiempo total</th>
            <th style="width: 140px" title="Equivalente en jornadas de <?php echo (int)(TOPE_HORAS_DIARIAS_CONTADOR_SEG / 3600); ?> horas">Tiempo en días</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rowsPage)): ?>
            <tr>
              <td colspan="4" class="text-muted small py-4">No hay registros con tiempo mayor a cero en el rango seleccionado.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rowsPage as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['titulo'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($r['nombre_completo'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="fw-semibold text-nowrap"><?php echo msOrSegToHMSFromSeconds((int)$r['total_seg']); ?></td>
                <td class="fw-semibold text-nowrap"><?php echo htmlspecialchars(formatDiasJornada((int)$r['total_seg']), ENT_QUOTES, 'UTF-8'); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot class="table-report-tfoot">
          <tr>
            <th colspan="2">Total General</th>
            <th class="text-nowrap"><?php echo msOrSegToHMSFromSeconds($sumaTotalGeneral); ?></th>
            <th class="text-nowrap"><?php echo htmlspecialchars(formatDiasJornada((int)$sumaTotalGeneral), ENT_QUOTES, 'UTF-8'); ?></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <?php
      $buildUrl = static function (int $p) use ($baseQueryParams): string {
        $q = array_merge($baseQueryParams, ['page' => $p]);

        return '?' . http_build_query($q);
      };
      ?>
      <div class="report-pagination-bar">
        <span class="text-muted small">Página <?php echo (int)$currentPage; ?> de <?php echo (int)$totalPages; ?></span>
        <nav class="report-pagination-nav" aria-label="Paginación">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $currentPage <= 1 ? '#' : htmlspecialchars($buildUrl(1), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Primera">&laquo;</a>
            </li>
            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $currentPage <= 1 ? '#' : htmlspecialchars($buildUrl($currentPage - 1), ENT_QUOTES, 'UTF-8'); ?>">&lsaquo;</a>
            </li>
            <?php
            $window = 2;
            $start = max(1, $currentPage - $window);
            $end = min($totalPages, $currentPage + $window);
            if ($start > 1) {
              echo '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($buildUrl(1), ENT_QUOTES, 'UTF-8') . '">1</a></li>';
              if ($start > 2) {
                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
              }
            }
            for ($p = $start; $p <= $end; $p++):
            ?>
              <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                <a class="page-link" href="<?php echo htmlspecialchars($buildUrl($p), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$p; ?></a>
              </li>
            <?php endfor; ?>
            <?php if ($end < $totalPages): ?>
              <?php if ($end < $totalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
              <?php endif; ?>
              <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($buildUrl($totalPages), ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$totalPages; ?></a></li>
            <?php endif; ?>
            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $currentPage >= $totalPages ? '#' : htmlspecialchars($buildUrl($currentPage + 1), ENT_QUOTES, 'UTF-8'); ?>">&rsaquo;</a>
            </li>
            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $currentPage >= $totalPages ? '#' : htmlspecialchars($buildUrl($totalPages), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Última">&raquo;</a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$reportLoadCharts = $reportHasDonutData || $reportHasBarData;
?>
<?php if ($reportHasDonutData): ?>
  <script type="application/json" id="report-chart-donut-data">
    <?php echo json_encode($chartDonut, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
  </script>
<?php endif; ?>
<?php if ($reportHasBarData): ?>
  <script type="application/json" id="report-chart-bar-data">
    <?php echo json_encode($chartBars, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
  </script>
<?php endif; ?>
<?php if ($reportLoadCharts): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    (function() {
      function esc(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
      }

      function hmsFromSeg(seg) {
        seg = Math.max(0, Math.floor(Number(seg) || 0));
        var h = Math.floor(seg / 3600);
        var m = Math.floor((seg % 3600) / 60);
        var s = seg % 60;
        return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
      }
      var palette = ['#2563eb', '#22c55e', '#f59e0b', '#a855f7', '#ec4899', '#0ea5e9', '#14b8a6', '#64748b'];
      var elD = document.getElementById('chartActividad');
      var dJson = document.getElementById('report-chart-donut-data');
      if (elD && dJson) {
        try {
          var data = JSON.parse(dJson.textContent || '{}');
          var vals = data.values || [];
          var labels = data.labels || [];
          var total = vals.reduce(function(a, b) {
            return a + Number(b);
          }, 0) || 1;
          var colors = labels.map(function(_, i) {
            return palette[i % palette.length];
          });
          new Chart(elD, {
            type: 'doughnut',
            data: {
              labels: labels,
              datasets: [{
                data: vals,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
              }]
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              cutout: '62%',
              plugins: {
                legend: {
                  display: false
                },
                tooltip: {
                  callbacks: {
                    label: function(ctx) {
                      var v = Number(ctx.raw) || 0;
                      var pct = total ? ((v / total) * 100).toFixed(1) : '0';
                      return ' ' + ctx.label + ': ' + pct + '% · ' + hmsFromSeg(v);
                    }
                  }
                }
              }
            }
          });
          var leg = document.getElementById('chartActividadLegend');
          if (leg) {
            leg.innerHTML = labels.map(function(l, i) {
              var v = Number(vals[i]) || 0;
              var pct = total ? ((v / total) * 100).toFixed(1) : '0';
              return '<li class="report-legend-item"><span class="report-legend-dot" style="background:' + colors[i] + '"></span><span class="report-legend-name">' + esc(String(l)) + '</span><span class="report-legend-meta">' + pct + '% · ' + hmsFromSeg(v) + '</span></li>';
            }).join('');
          }
        } catch (e) {
          console.error(e);
        }
      }
      var elB = document.getElementById('chartUsuario');
      var bJson = document.getElementById('report-chart-bar-data');
      if (elB && bJson) {
        try {
          var bd = JSON.parse(bJson.textContent || '{}');
          var bvals = bd.values || [];
          var blabels = bd.labels || [];
          new Chart(elB, {
            type: 'bar',
            data: {
              labels: blabels,
              datasets: [{
                label: 'Tiempo',
                data: bvals,
                backgroundColor: '#3b82f6',
                borderRadius: 6,
                barThickness: 18
              }]
            },
            options: {
              indexAxis: 'y',
              responsive: true,
              maintainAspectRatio: false,
              scales: {
                x: {
                  beginAtZero: true,
                  ticks: {
                    maxTicksLimit: 6,
                    callback: function(v) {
                      return hmsFromSeg(v);
                    }
                  }
                },
                y: {
                  ticks: {
                    autoSkip: false
                  }
                }
              },
              plugins: {
                legend: {
                  display: false
                },
                tooltip: {
                  callbacks: {
                    label: function(ctx) {
                      return ' ' + hmsFromSeg(ctx.raw);
                    }
                  }
                }
              }
            }
          });
        } catch (e) {
          console.error(e);
        }
      }
    })();
  </script>
<?php endif; ?>

<script>
  (function() {
    var root = document.querySelector('[data-report-act-ms]');
    if (!root) return;

    var btn = root.querySelector('.report-act-ms-btn');
    var panel = root.querySelector('.report-act-ms-panel');
    var label = root.querySelector('.report-act-ms-label');
    var checkAll = root.querySelector('#actividad_ms_all');
    var checks = Array.prototype.slice.call(root.querySelectorAll('.report-act-ms-check'));

    function selectedChecks() {
      return checks.filter(function(c) {
        return c.checked;
      });
    }

    function updateLabel() {
      var selected = selectedChecks();
      if (!selected.length || (checkAll && checkAll.checked)) {
        label.textContent = 'Todas las actividades';
        return;
      }
      if (selected.length === 1) {
        var span = selected[0].parentElement.querySelector('span');
        label.textContent = span ? span.textContent.trim() : '1 actividad';
        return;
      }
      label.textContent = selected.length + ' actividades seleccionadas';
    }

    function setOpen(open) {
      if (!panel || !btn) return;
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.classList.toggle('is-open', open);
    }

    function syncFromItems() {
      var selected = selectedChecks();
      if (checkAll) {
        checkAll.checked = selected.length === 0;
      }
      // Si hay items seleccionados, no enviar "todas"
      if (selected.length > 0 && checkAll) {
        checkAll.checked = false;
      }
      updateLabel();
    }

    if (btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        setOpen(panel.hidden);
      });
    }

    if (checkAll) {
      checkAll.addEventListener('change', function() {
        if (checkAll.checked) {
          checks.forEach(function(c) {
            c.checked = false;
          });
        }
        updateLabel();
      });
    }

    checks.forEach(function(c) {
      c.addEventListener('change', syncFromItems);
    });

    document.addEventListener('click', function(e) {
      if (!root.contains(e.target)) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') setOpen(false);
    });

    // Antes de enviar: si "todas" está marcada, quitar name de checks (no filtrar)
    var form = root.closest('form');
    if (form) {
      form.addEventListener('submit', function() {
        if (checkAll && checkAll.checked) {
          checks.forEach(function(c) {
            c.checked = false;
            c.disabled = true;
          });
        }
      });
    }

    syncFromItems();
  })();
</script>

<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>