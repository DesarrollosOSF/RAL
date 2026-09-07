<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Filtros (GET)
$qUsuario = isset($_GET['q_usuario']) ? trim((string)$_GET['q_usuario']) : '';
$qUsuario = mb_substr($qUsuario, 0, 120);
$sedeIdFiltro = isset($_GET['sede_id']) ? (int)$_GET['sede_id'] : 0;

// Catálogo de sedes (para filtro)
$stmtSedes = $pdo->query('SELECT id, nombre FROM sedes ORDER BY nombre ASC');
$sedes = $stmtSedes->fetchAll(PDO::FETCH_ASSOC);
$sedesById = [];
foreach ($sedes as $s) {
    $sedesById[(int)$s['id']] = (string)$s['nombre'];
}

// Resumen por sede
$stmtResumen = $pdo->query('
    SELECT
        s.id,
        s.nombre,
        COUNT(u.id) AS total_usuarios
    FROM sedes s
    LEFT JOIN usuarios u ON u.sede_id = s.id
    GROUP BY s.id, s.nombre
    ORDER BY s.nombre ASC
');
$resumenSedes = $stmtResumen->fetchAll(PDO::FETCH_ASSOC);

$maxUsuariosSede = 0;
foreach ($resumenSedes as $r) {
    $maxUsuariosSede = max($maxUsuariosSede, (int)$r['total_usuarios']);
}

// KPIs globales
$kpiSedes = (int)$pdo->query('SELECT COUNT(*) FROM sedes')->fetchColumn();
$kpiUsuarios = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$kpiUsuariosActivos = (int)$pdo->query('SELECT COUNT(*) FROM usuarios WHERE activo = 1')->fetchColumn();

$hoy = date('Y-m-d');
$kpiTiempoHoySeg = fetchSumaTiemposContadorCapped($pdo, $hoy, $hoy);

$segSemActual = fetchSumaTiemposContadorCapped(
    $pdo,
    date('Y-m-d', strtotime('-6 days')),
    $hoy
);
$segSemPrev = fetchSumaTiemposContadorCapped(
    $pdo,
    date('Y-m-d', strtotime('-13 days')),
    date('Y-m-d', strtotime('-7 days'))
);
if ($segSemPrev > 0) {
    $kpiProductividadPct = (($segSemActual - $segSemPrev) / $segSemPrev) * 100;
} elseif ($segSemActual > 0) {
    $kpiProductividadPct = 100.0;
} else {
    $kpiProductividadPct = 0.0;
}

// Listado de usuarios (reciente primero) con paginación
$rowsPerPage = 15;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) {
    $currentPage = 1;
}

// WHERE dinámico según filtros
$where = [];
$params = [];

// En "Usuarios registrados" solo se muestran auxiliares administrativos.
$where[] = "u.rol IN ('" . ROL_AUXILIAR_ADMINISTRATIVO . "', 'auxiliar_administr', 'auxiliar_administrativos')";

if ($qUsuario !== '') {
    $patron = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $qUsuario) . '%';
    $where[] = 'u.nombre_completo LIKE :q_usuario';
    $params['q_usuario'] = $patron;
}
if ($sedeIdFiltro > 0 && isset($sedesById[$sedeIdFiltro])) {
    $where[] = 'u.sede_id = :sede_id';
    $params['sede_id'] = $sedeIdFiltro;
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM usuarios u $whereSql");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $rowsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;

$stmtUsers = $pdo->prepare('
    SELECT
        u.id,
        u.nombre_completo,
        u.email,
        u.activo,
        u.rol,
        u.created_at,
        s.nombre AS sede_nombre
    FROM usuarios u
    LEFT JOIN sedes s ON s.id = u.sede_id
    ' . $whereSql . '
    ORDER BY u.created_at DESC
    LIMIT :limit OFFSET :offset
');
$stmtUsers->bindValue(':limit', $rowsPerPage, PDO::PARAM_INT);
$stmtUsers->bindValue(':offset', $offset, PDO::PARAM_INT);
if (isset($params['q_usuario'])) {
    $stmtUsers->bindValue(':q_usuario', $params['q_usuario'], PDO::PARAM_STR);
}
if (isset($params['sede_id'])) {
    $stmtUsers->bindValue(':sede_id', (int)$params['sede_id'], PDO::PARAM_INT);
}
$stmtUsers->execute();
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
$shownTo = min($totalRows, $offset + count($usuarios));

// Tiempos agregados (diario, semanal, quincenal, mensual) para los usuarios mostrados
$tiemposByUsuarioId = [];
$userIds = array_values(array_filter(array_map(static fn($u) => (int)($u['id'] ?? 0), $usuarios), static fn($id) => $id > 0));
if (!empty($userIds)) {
    $tiemposByUsuarioId = fetchTiemposContadorPorUsuario($pdo, $userIds);
}

$title = 'Admin - Dashboard';
$adminNavCurrent = 'dashboard';
$nombreCompletoSesion = (string)($_SESSION['nombre_completo'] ?? '');
$saludoNombre = $nombreCompletoSesion;
if (preg_match('/^\s*(\S+)/u', $nombreCompletoSesion, $m)) {
    $saludoNombre = $m[1];
}

require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>

<div class="admin-hero">
  <div>
    <h1>¡Buen día, <?php echo htmlspecialchars($saludoNombre, ENT_QUOTES, 'UTF-8'); ?>! 👋</h1>
    <p>Aquí tienes un resumen general del sistema de control de sedes.</p>
  </div>
  <div class="admin-hero-actions">
    <a class="btn btn-primary" href="<?php echo htmlspecialchars(BASE_URL . 'admin/actividades.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-plus-lg me-1"></i>Crear actividad
    </a>
    <a class="btn btn-success" href="<?php echo htmlspecialchars(BASE_URL . 'admin/reportes_tiempo_actividad.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-bar-chart-line me-1"></i>Reportes de tiempo
    </a>
    <a class="btn btn-outline-secondary" href="<?php echo htmlspecialchars(BASE_URL . 'admin/usuarios.php', ENT_QUOTES, 'UTF-8'); ?>">
      <i class="bi bi-person-plus me-1"></i>Crear usuarios
    </a>
  </div>
</div>

<div class="admin-stats">
  <div class="admin-stat-card blue">
    <div>
      <div class="label">Sedes registradas</div>
      <div class="value"><?php echo $kpiSedes; ?></div>
      <div class="hint">Activas en el sistema</div>
    </div>
    <div class="admin-stat-icon"><i class="bi bi-building"></i></div>
  </div>
  <div class="admin-stat-card green">
    <div>
      <div class="label">Usuarios activos</div>
      <div class="value"><?php echo $kpiUsuariosActivos; ?></div>
      <div class="hint"><?php echo $kpiUsuarios; ?> registrados en total</div>
    </div>
    <div class="admin-stat-icon"><i class="bi bi-people"></i></div>
  </div>
  <div class="admin-stat-card purple">
    <div>
      <div class="label">Tiempo total hoy</div>
      <div class="value" style="font-size:1.35rem;"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds($kpiTiempoHoySeg), ENT_QUOTES, 'UTF-8'); ?></div>
      <div class="hint">De todos los usuarios</div>
    </div>
    <div class="admin-stat-icon"><i class="bi bi-stopwatch"></i></div>
  </div>
  <div class="admin-stat-card orange">
    <div>
      <div class="label">Productividad</div>
      <div class="value" style="font-size:1.35rem;">
        <?php if ($kpiProductividadPct >= 0): ?>+<?php endif; ?><?php echo htmlspecialchars(number_format($kpiProductividadPct, 1, ',', ''), ENT_QUOTES, 'UTF-8'); ?>%
        <?php if ($kpiProductividadPct >= 0): ?><i class="bi bi-arrow-up-short"></i><?php else: ?><i class="bi bi-arrow-down-short"></i><?php endif; ?>
      </div>
      <div class="hint">vs. semana anterior</div>
    </div>
    <div class="admin-stat-icon"><i class="bi bi-graph-up-arrow"></i></div>
  </div>
</div>

<div class="admin-widgets">
  <div class="admin-widget" id="usuarios-por-sede">
    <div class="admin-widget-header">
      <h2>Usuarios por sede</h2>
    </div>
    <div class="admin-widget-body">
      <?php if (!$resumenSedes): ?>
        <div class="admin-empty">No hay sedes para mostrar.</div>
      <?php else: ?>
        <?php foreach ($resumenSedes as $r): ?>
          <?php
            $cnt = (int)$r['total_usuarios'];
            $pctBar = $maxUsuariosSede > 0 ? (int)round(100 * $cnt / $maxUsuariosSede) : 0;
            $lbl = $cnt === 1 ? '1 usuario' : $cnt . ' usuarios';
          ?>
          <div class="sede-progress-row">
            <div class="sede-progress-top">
              <span class="name"><?php echo htmlspecialchars((string)$r['nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="count"><?php echo htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <div class="sede-progress-bar" role="progressbar" aria-valuenow="<?php echo $pctBar; ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="sede-progress-fill" style="width: <?php echo $pctBar; ?>%;"></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php
          $pctActivos = $kpiUsuarios > 0 ? (int)round(100 * $kpiUsuariosActivos / $kpiUsuarios) : 0;
        ?>
        <div class="admin-widget-footer-total">
          <span>Total de usuarios: <?php echo $kpiUsuarios; ?></span>
          <span class="badge bg-success rounded-pill"><?php echo $pctActivos; ?>% activos</span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="admin-widget">
    <div class="admin-widget-header">
      <h2>Usuarios registrados</h2>
      <span class="small text-muted"><?php echo (int)$shownTo; ?> de <?php echo (int)$totalRows; ?></span>
    </div>
    <div class="admin-widget-body">
      <form method="get" action="" class="admin-table-filters">
        <div class="flex-grow-1" style="min-width: 180px;">
          <input type="search" class="form-control" name="q_usuario" value="<?php echo htmlspecialchars($qUsuario, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Buscar por nombre..." maxlength="120" autocomplete="off" id="dash-filter-nombre">
        </div>
        <div style="min-width: 160px;">
          <select class="form-select" name="sede_id" aria-label="Filtrar por sede">
            <option value="0">Todas las sedes</option>
            <?php foreach ($sedes as $s): ?>
              <?php $sid = (int)$s['id']; ?>
              <option value="<?php echo $sid; ?>" <?php echo ($sid === $sedeIdFiltro) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars((string)$s['nombre'], ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary btn-search" type="submit"><i class="bi bi-search me-1"></i>Buscar</button>
        <button type="button" class="btn btn-outline-secondary" title="Filtros" aria-label="Filtros"><i class="bi bi-funnel"></i></button>
      </form>

      <?php if (!$usuarios): ?>
        <div class="admin-empty">No hay usuarios.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-admin-users align-middle mb-0">
            <thead>
              <tr>
                <th>Usuario</th>
                <th>Sede</th>
                <th class="text-end">Tiempo diario</th>
                <th class="text-end">Tiempo semanal</th>
                <th class="text-end">Tiempo quincenal</th>
                <th class="text-end">Tiempo mensual</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u): ?>
              <?php
                $uid = (int)($u['id'] ?? 0);
                $t = $tiemposByUsuarioId[$uid] ?? ['diario' => 0, 'semanal' => 0, 'quincenal' => 0, 'mensual' => 0];
                $nom = (string)($u['nombre_completo'] ?? '');
                $ini = '';
                foreach (preg_split('/\s+/', trim($nom), -1, PREG_SPLIT_NO_EMPTY) as $ix => $part) {
                    if ($ix >= 2) {
                        break;
                    }
                    $ini .= mb_strtoupper(mb_substr($part, 0, 1));
                }
                if ($ini === '') {
                    $ini = '?';
                }
              ?>
              <tr>
                <td>
                  <div class="user-cell">
                    <div class="user-avatar-sm" aria-hidden="true"><?php echo htmlspecialchars($ini, ENT_QUOTES, 'UTF-8'); ?></div>
                    <span class="fw-semibold"><?php echo htmlspecialchars($nom, ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                </td>
                <td><?php echo htmlspecialchars((string)($u['sede_nombre'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="text-end"><span class="time-mono"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$t['diario']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td class="text-end"><span class="time-mono"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$t['semanal']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td class="text-end"><span class="time-mono"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$t['quincenal']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                <td class="text-end"><span class="time-mono"><?php echo htmlspecialchars(msOrSegToHMSFromSeconds((int)$t['mensual']), ENT_QUOTES, 'UTF-8'); ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($totalPages > 1): ?>
        <?php
          $qs = [];
          if ($qUsuario !== '') {
              $qs[] = 'q_usuario=' . rawurlencode($qUsuario);
          }
          if ($sedeIdFiltro > 0) {
              $qs[] = 'sede_id=' . (int)$sedeIdFiltro;
          }
          $extra = !empty($qs) ? ('&' . implode('&', $qs)) : '';
        ?>
        <div class="admin-pagination">
          <a
            class="btn btn-sm btn-pager <?php echo ($currentPage <= 1) ? 'disabled' : ''; ?>"
            href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php?page=' . max(1, $currentPage - 1) . $extra, ENT_QUOTES, 'UTF-8'); ?>"
            aria-label="Página anterior"
          >&lt; Anterior</a>
          <span class="page-info">Página <?php echo (int)$currentPage; ?> de <?php echo (int)$totalPages; ?></span>
          <a
            class="btn btn-sm btn-pager <?php echo ($currentPage >= $totalPages) ? 'disabled' : ''; ?>"
            href="<?php echo htmlspecialchars(BASE_URL . 'admin/dashboard.php?page=' . min($totalPages, $currentPage + 1) . $extra, ENT_QUOTES, 'UTF-8'); ?>"
            aria-label="Página siguiente"
          >Siguiente &gt;</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  setInterval(() => {
    window.location.reload();
  }, 3 * 60 * 1000);

  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
      const top = document.querySelector('.admin-topbar input[name="q_usuario"]');
      if (top) {
        e.preventDefault();
        top.focus();
      }
    }
  });
</script>

<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>
