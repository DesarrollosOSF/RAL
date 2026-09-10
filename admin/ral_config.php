<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);
$pdo = getDBConnection();

// Helpers: asegurar tablas RAL existen (por si no se ejecutó migración)
try {
    $pdo->query("SELECT 1 FROM actividad_grupos LIMIT 1");
} catch (Throwable $e) {
    // intentar migración mínima inline
    $pdo->exec("CREATE TABLE IF NOT EXISTS actividad_grupos (id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(50) NOT NULL UNIQUE, nombre VARCHAR(120) NOT NULL, orden INT NOT NULL DEFAULT 0, solo_admin TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
try { $pdo->query("SELECT 1 FROM ral_dias_habiles LIMIT 1"); } catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ral_dias_habiles (id INT AUTO_INCREMENT PRIMARY KEY, anio SMALLINT NOT NULL, mes TINYINT NOT NULL, dias_habiles SMALLINT NOT NULL, updated_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_anio_mes (anio,mes)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
try { $pdo->query("SELECT 1 FROM ral_ausencias LIMIT 1"); } catch (Throwable $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ral_ausencias (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, tipo ENUM('vacaciones','permiso','compensatorio') NOT NULL, fecha DATE NOT NULL, dias DECIMAL(4,2) NOT NULL DEFAULT 1.00, observaciones VARCHAR(255) NULL, creado_por INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_usuario_fecha (usuario_id,fecha)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$msg = ''; $error = '';
$currentUserId = (int)($_SESSION['usuario_id'] ?? 0);

// POST: dias habiles
if ($_SERVER['REQUEST_METHOD']==='POST' && verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion==='guardar_dias') {
        $anio = (int)($_POST['anio'] ?? date('Y'));
        $mes  = (int)($_POST['mes'] ?? date('n'));
        $dias = (int)($_POST['dias_habiles'] ?? 0);
        if ($anio<2020 || $anio>2035 || $mes<1 || $mes>12 || $dias<0 || $dias>31) {
            $error='Datos de días hábiles inválidos.';
        } else {
            $stmt = $pdo->prepare("INSERT INTO ral_dias_habiles (anio,mes,dias_habiles,creado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE dias_habiles=VALUES(dias_habiles), creado_por=VALUES(creado_por)");
            $stmt->execute([$anio,$mes,$dias,$currentUserId]);
            $msg="Días hábiles guardados: $anio-$mes = $dias días.";
        }
    } elseif ($accion==='guardar_ausencia') {
        $usuario_id = (int)($_POST['usuario_id'] ?? 0);
        $fecha = trim((string)($_POST['fecha'] ?? ''));
        $tipo  = trim((string)($_POST['tipo'] ?? ''));
        $dias  = (float)($_POST['dias'] ?? 1);
        $observaciones= trim((string)($_POST['observaciones'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d',$fecha);
        if (!$dt || $dt->format('Y-m-d')!==$fecha) $error='Fecha inválida.';
        elseif ($usuario_id<=0) $error='Usuario requerido.';
        elseif (!in_array($tipo,['vacaciones','permiso','compensatorio'],true)) $error='Tipo inválido.';
        elseif ($dias<=0 || $dias>31) $error='Días inválidos (0.5 - 31).';
        else {
            $stmt=$pdo->prepare("INSERT INTO ral_ausencias (usuario_id,tipo,fecha,dias,observaciones,creado_por) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$usuario_id,$tipo,$fecha,number_format($dias,2,'.',''),mb_substr($observaciones,0,255),$currentUserId]);
            $msg='Ausencia registrada correctamente.';
        }
    } elseif ($accion==='eliminar_ausencia') {
        $id=(int)($_POST['ausencia_id'] ?? 0);
        if ($id>0) { $pdo->prepare("DELETE FROM ral_ausencias WHERE id=?")->execute([$id]); $msg='Ausencia eliminada.'; }
    }
}

// Datos para render
$anioSel = (int)($_GET['anio'] ?? date('Y'));
$mesSel  = (int)($_GET['mes'] ?? date('n'));
if ($anioSel<2020||$anioSel>2035) $anioSel=(int)date('Y');
if ($mesSel<1||$mesSel>12) $mesSel=(int)date('n');

$stmtUsers=$pdo->query("SELECT id,nombre_completo,sede_id FROM usuarios WHERE activo=1 ORDER BY nombre_completo ASC");
$usuarios=$stmtUsers->fetchAll();
$stmtSedes=$pdo->query("SELECT id,nombre FROM sedes ORDER BY nombre");
$sedes=$stmtSedes->fetchAll(PDO::FETCH_KEY_PAIR);

// Dias habiles mes seleccionado + historico 12 meses
$stmtDias=$pdo->prepare("SELECT dias_habiles FROM ral_dias_habiles WHERE anio=? AND mes=?");
$stmtDias->execute([$anioSel,$mesSel]);
$diasActual = $stmtDias->fetchColumn();
if ($diasActual===false) $diasActual=null;

$stmtHist=$pdo->query("SELECT anio,mes,dias_habiles,updated_at FROM ral_dias_habiles ORDER BY anio DESC, mes DESC LIMIT 12");
$historico=$stmtHist->fetchAll();

// Ausencias mes seleccionado
$primerDia = sprintf('%04d-%02d-01',$anioSel,$mesSel);
$ultimoDia = date('Y-m-t', strtotime($primerDia));
$stmtAus=$pdo->prepare("SELECT a.id,a.usuario_id,a.tipo,a.fecha,a.dias,a.observaciones,u.nombre_completo FROM ral_ausencias a JOIN usuarios u ON u.id=a.usuario_id WHERE a.fecha BETWEEN ? AND ? ORDER BY a.fecha DESC, u.nombre_completo ASC");
$stmtAus->execute([$primerDia,$ultimoDia]);
$ausencias=$stmtAus->fetchAll();

// Resumen por usuario (para RAL)
$stmtResumen=$pdo->prepare("SELECT usuario_id, SUM(dias) as total_dias, SUM(CASE WHEN tipo='vacaciones' THEN dias ELSE 0 END) as vac, SUM(CASE WHEN tipo='permiso' THEN dias ELSE 0 END) as perm, SUM(CASE WHEN tipo='compensatorio' THEN dias ELSE 0 END) as comp FROM ral_ausencias WHERE fecha BETWEEN ? AND ? GROUP BY usuario_id");
$stmtResumen->execute([$primerDia,$ultimoDia]);
$resumenByUser=[];
foreach($stmtResumen->fetchAll() as $r) $resumenByUser[(int)$r['usuario_id']]=$r;

$title='Configuración RAL - Días hábiles y ausencias';
$adminNavCurrent='ral_config';
$adminSidebarFootInclude=null;
$qUsuario=''; $sedeIdFiltro=0;
require_once __DIR__ . '/../includes/header_admin_dashboard.php';
?>
<div class="admin-act-page">
  <div class="admin-act-page-head">
    <div>
      <h1 class="admin-act-page-title"><i class="bi bi-calendar3 me-2 text-primary"></i>RAL — Días hábiles y ausencias</h1>
      <p class="admin-act-page-lead">Configura los <strong>días hábiles por mes</strong> y registra <strong>vacaciones / permisos / compensatorios</strong>. El reporte RAL restará automáticamente estos días.</p>
    </div>
    <a class="btn admin-act-back-btn" href="<?php echo htmlspecialchars(BASE_URL.'admin/reportes_tiempo_actividad.php',ENT_QUOTES,'UTF-8'); ?>"><i class="bi bi-graph-up-arrow me-2"></i>Ver reporte RAL</a>
  </div>
  <?php if($msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($msg,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
  <?php if($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="admin-act-card p-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-calendar-check me-2"></i>Días hábiles del mes</h5>
        <form method="GET" class="row g-2 mb-3">
          <div class="col-6"><label class="form-label small fw-semibold">Año</label><input type="number" name="anio" class="form-control" value="<?php echo $anioSel; ?>" min="2020" max="2035"></div>
          <div class="col-6"><label class="form-label small fw-semibold">Mes</label><select name="mes" class="form-select"><?php for($m=1;$m<=12;$m++): $n=strftime('%B', mktime(0,0,0,$m,1)); ?><option value="<?php echo $m; ?>" <?php echo $m===$mesSel?'selected':''; ?>><?php echo $m.' - '.ucfirst($n); ?></option><?php endfor; ?></select></div>
          <div class="col-12"><button class="btn btn-outline-primary w-100 mt-1" type="submit"><i class="bi bi-search me-1"></i>Consultar</button></div>
        </form>
        <form method="POST" class="border-top pt-3">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(),ENT_QUOTES,'UTF-8'); ?>">
          <input type="hidden" name="accion" value="guardar_dias">
          <input type="hidden" name="anio" value="<?php echo $anioSel; ?>">
          <input type="hidden" name="mes" value="<?php echo $mesSel; ?>">
          <label class="form-label fw-semibold">Días hábiles <?php echo sprintf('%04d-%02d',$anioSel,$mesSel); ?></label>
          <div class="d-flex gap-2">
            <input type="number" name="dias_habiles" class="form-control" min="0" max="31" required value="<?php echo $diasActual!==null? (int)$diasActual : ''; ?>" placeholder="Ej: 22">
            <button class="btn btn-primary px-4" type="submit"><i class="bi bi-check-lg me-1"></i>Guardar</button>
          </div>
          <div class="form-text">Se usa para calcular cumplimiento: <code>días_efectivos = días_hábiles − (vac+perm+comp)</code>.</div>
          <?php if($diasActual===null): ?><div class="text-warning small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>Aún no configurado para este mes.</div><?php endif; ?>
        </form>
        <hr>
        <h6 class="fw-semibold">Histórico (últimos 12)</h6>
        <?php if(!$historico): ?><div class="text-muted small">Sin registros.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Mes</th><th>Días</th><th>Actualizado</th></tr></thead><tbody><?php foreach($historico as $h): ?><tr><td><?php echo sprintf('%04d-%02d',$h['anio'],$h['mes']); ?></td><td class="fw-bold"><?php echo (int)$h['dias_habiles']; ?></td><td class="text-muted"><?php echo htmlspecialchars($h['updated_at'],ENT_QUOTES,'UTF-8'); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="admin-act-card p-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-person-dash me-2"></i>Registrar ausencia (vacaciones / permiso / compensatorio)</h5>
        <form method="POST" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(),ENT_QUOTES,'UTF-8'); ?>">
          <input type="hidden" name="accion" value="guardar_ausencia">
          <div class="col-12 col-md-6"><label class="form-label fw-semibold">Compañera (usuario)</label><select name="usuario_id" class="form-select" required><option value="">— seleccionar —</option><?php foreach($usuarios as $u): ?><option value="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars($u['nombre_completo'],ENT_QUOTES,'UTF-8'); ?></option><?php endforeach; ?></select></div>
          <div class="col-6 col-md-3"><label class="form-label fw-semibold">Fecha</label><input type="date" name="fecha" class="form-control" required value="<?php echo htmlspecialchars($primerDia,ENT_QUOTES,'UTF-8'); ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label fw-semibold">Tipo</label><select name="tipo" class="form-select" required><option value="vacaciones">Vacaciones</option><option value="permiso">Permiso</option><option value="compensatorio">Compensatorio</option></select></div>
          <div class="col-4"><label class="form-label fw-semibold">Días</label><input type="number" name="dias" class="form-control" step="0.5" min="0.5" max="31" value="1" required></div>
          <div class="col-8"><label class="form-label fw-semibold">Motivo (opcional)</label><input type="text" name="observaciones" class="form-control" maxlength="255" placeholder="Ej: Vacaciones programadas"></div>
          <div class="col-12"><button class="btn btn-success w-100" type="submit"><i class="bi bi-plus-lg me-1"></i>Agregar ausencia</button></div>
        </form>
      </div>

      <div class="admin-act-card p-4 mt-4">
        <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fw-bold mb-0">Ausencias de <?php echo sprintf('%04d-%02d',$anioSel,$mesSel); ?> (<?php echo count($ausencias); ?>)</h6><span class="badge bg-primary"><?php echo htmlspecialchars($primerDia,ENT_QUOTES,'UTF-8').' a '.htmlspecialchars($ultimoDia,ENT_QUOTES,'UTF-8'); ?></span></div>
        <?php if(!$ausencias): ?><div class="text-muted small py-2">No hay ausencias registradas este mes.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm align-middle small mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Usuaria</th><th>Tipo</th><th>Días</th><th>Motivo</th><th></th></tr></thead><tbody><?php foreach($ausencias as $a): ?><tr><td class="text-nowrap"><?php echo htmlspecialchars($a['fecha'],ENT_QUOTES,'UTF-8'); ?></td><td><?php echo htmlspecialchars($a['nombre_completo'],ENT_QUOTES,'UTF-8'); ?></td><td><span class="badge <?php echo $a['tipo']==='vacaciones'?'bg-info':($a['tipo']==='permiso'?'bg-warning text-dark':'bg-success'); ?>"><?php echo htmlspecialchars($a['tipo'],ENT_QUOTES,'UTF-8'); ?></span></td><td class="fw-bold"><?php echo htmlspecialchars($a['dias'],ENT_QUOTES,'UTF-8'); ?></td><td class="small text-muted"><?php echo htmlspecialchars($a['observaciones']??'',ENT_QUOTES,'UTF-8'); ?></td><td><form method="POST" onsubmit="return confirm('¿Eliminar ausencia?')" class="d-inline"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(),ENT_QUOTES,'UTF-8'); ?>"><input type="hidden" name="accion" value="eliminar_ausencia"><input type="hidden" name="ausencia_id" value="<?php echo (int)$a['id']; ?>"><button class="btn btn-sm btn-outline-danger border-0" title="Eliminar"><i class="bi bi-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if($resumenByUser): ?>
        <hr><h6 class="fw-semibold">Resumen por usuaria (mes)</h6>
        <div class="table-responsive"><table class="table table-sm small mb-0"><thead><tr><th>Usuaria</th><th class="text-end">Vac</th><th class="text-end">Perm</th><th class="text-end">Comp</th><th class="text-end">Total</th></tr></thead><tbody><?php foreach($resumenByUser as $uid=>$r): $nom='?'; foreach($usuarios as $u) if((int)$u['id']===$uid) {$nom=$u['nombre_completo']; break;} ?><tr><td><?php echo htmlspecialchars($nom,ENT_QUOTES,'UTF-8'); ?></td><td class="text-end"><?php echo $r['vac']; ?></td><td class="text-end"><?php echo $r['perm']; ?></td><td class="text-end"><?php echo $r['comp']; ?></td><td class="text-end fw-bold"><?php echo $r['total_dias']; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer_admin_dashboard.php'; ?>
