<?php
/**
 * Configuración general (Control-Sedes).
 */

// Sesión
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Cambiar a 1 con HTTPS
session_start();

// Zona horaria
date_default_timezone_set('America/Bogota');

// BASE_URL calculado automáticamente
if (!defined('BASE_URL')) {
    $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $basePath = trim(str_replace($docRoot, '', $projectRoot), '/');
    $basePath = $basePath === '' ? '/' : '/' . $basePath . '/';
    define('BASE_URL', $basePath);
}

// Rutas
define('BASE_PATH', __DIR__ . '/../');
define('ASSETS_PATH', BASE_PATH . 'assets/');
define('ASSETS_URL', BASE_URL . 'assets/');

/** Carpeta física de adjuntos de requerimientos (la BD solo guarda la ruta relativa). */
define('UPLOAD_REQUERIMIENTOS_DIR', BASE_PATH . 'uploads/requerimientos/');
define('UPLOAD_REQUERIMIENTOS_URL', BASE_URL . 'uploads/requerimientos/');
/** Tamaño máximo de adjunto: 5 MB. */
define('REQUERIMIENTO_ARCHIVO_MAX_BYTES', 5 * 1024 * 1024);

/**
 * Meta fija de jornada laboral diaria: 8 horas y 30 minutos (no configurable).
 * Usada p. ej. en la barra de progreso del rol auxiliar administrativo.
 */
define('META_JORNADA_SEG_FIJA', 8 * 3600 + 30 * 60);
/**
 * Tope diario para contadores (sidebar, dashboard, reportes): máximo 8 h por usuario y día
 * al sumar al contador general (semanal, quincenal, mensual, total del reporte).
 */
define('TOPE_HORAS_DIARIAS_CONTADOR_SEG', 8 * 3600);
/**
 * Actividad de almuerzo: no cuenta en totales, KPIs, barra de jornada ni CSV/tabla del reporte;
 * sí aparece en los gráficos del reporte de tiempo (dona / barras).
 */
define('ACTIVIDAD_ALMUERZO_ID', 16);
/** Prestación de servicio funerario: abre modal de notas al clic en el título. */
define('ACTIVIDAD_NOTAS_FUNERARIO_ID', 10);
/**
 * Actividad "Otros": mismo modal de notas.
 * Si es 0, se resuelve por título en BD (coincide con `titulo` = "Otros").
 */
define('ACTIVIDAD_NOTAS_OTROS_ID', 0);
/**
 * Actividad "Facturación": mismo modal de notas.
 * Si es 0, se resuelve por título en BD (Facturación / facturacion).
 */
define('ACTIVIDAD_NOTAS_FACTURACION_ID', 0);
/** Slug del rol en BD (coincide con `usuarios.rol`: auxiliar_administrativo). */
define('ROL_AUXILIAR_ADMINISTRATIVO', 'auxiliar_administrativo');

function getActividadNotasOtrosId(?PDO $pdo = null): int
{
    $otrosId = (int)ACTIVIDAD_NOTAS_OTROS_ID;
    if ($otrosId <= 0 && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("SELECT id FROM actividades WHERE LOWER(TRIM(titulo)) = 'otros' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $otrosId = (int)$row['id'];
        }
    }

    return $otrosId > 0 ? $otrosId : 0;
}

function getActividadNotasFacturacionId(?PDO $pdo = null): int
{
    $facturacionId = (int)ACTIVIDAD_NOTAS_FACTURACION_ID;
    if ($facturacionId <= 0 && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("
            SELECT id FROM actividades
            WHERE LOWER(TRIM(titulo)) IN ('facturación', 'facturacion')
            LIMIT 1
        ");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $facturacionId = (int)$row['id'];
        }
    }

    return $facturacionId > 0 ? $facturacionId : 0;
}

/**
 * Etiqueta y placeholder del primer campo del modal de notas según la actividad.
 *
 * @return array{label:string,placeholder:string}
 */
function getEtiquetasCampoPrincipalNotas(int $actividadId, ?PDO $pdo = null): array
{
    if ($actividadId > 0 && $actividadId === getActividadNotasOtrosId($pdo)) {
        return [
            'label' => 'Nombre actividad',
            'placeholder' => 'Nombre actividad',
        ];
    }

    if ($actividadId > 0 && $actividadId === getActividadNotasFacturacionId($pdo)) {
        return [
            'label' => 'Número de Factura',
            'placeholder' => 'Número de factura',
        ];
    }

    return [
        'label' => 'Fallecido',
        'placeholder' => 'Nombre del fallecido',
    ];
}

/**
 * IDs de actividades cuyo título es enlace al modal de notas.
 *
 * @return array<int>
 */
function getActividadesConModalNotasIds(?PDO $pdo = null): array
{
    $ids = [];
    if ((int)ACTIVIDAD_NOTAS_FUNERARIO_ID > 0) {
        $ids[] = (int)ACTIVIDAD_NOTAS_FUNERARIO_ID;
    }

    $otrosId = getActividadNotasOtrosId($pdo);
    if ($otrosId > 0) {
        $ids[] = $otrosId;
    }

    $facturacionId = getActividadNotasFacturacionId($pdo);
    if ($facturacionId > 0) {
        $ids[] = $facturacionId;
    }

    return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
}

function actividadTieneModalNotas(int $actividadId, ?PDO $pdo = null): bool
{
    return in_array($actividadId, getActividadesConModalNotasIds($pdo), true);
}

// Seguridad / sesión
define('SESSION_TIMEOUT', 3600); // 1 hora

// Incluir BD
require_once __DIR__ . '/database.php';

// -------- Utilidades --------
function sanitizar($data): string {
    return htmlspecialchars(strip_tags(trim((string)$data)), ENT_QUOTES, 'UTF-8');
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken($token): bool {
    $token = (string)$token;
    return isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireAuth(): void {
    if (empty($_SESSION['usuario_id'])) {
        $isApi = isset($_SERVER['SCRIPT_NAME']) && strpos((string)$_SERVER['SCRIPT_NAME'], '/api/') !== false;
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'No autenticado']);
            exit;
        }
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function requireRole($allowedRoles): void {
    requireAuth();
    $rol = $_SESSION['rol'] ?? 'usuario';
    if (!in_array($rol, $allowedRoles, true)) {
        http_response_code(403);
        echo 'Acceso denegado.';
        exit;
    }
}

/**
 * Garantiza la columna actividades.activo (1=visible, 0=oculta; historial se conserva).
 */
function ensureActividadesActivoColumn(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM actividades LIKE 'activo'");
        if (!$stmt->fetch()) {
            $pdo->exec("
                ALTER TABLE actividades
                ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1
                COMMENT '1=visible en tablero, 0=oculta (conserva registros)'
                AFTER descripcion
            ");
        }
        $done = true;
    } catch (Throwable $e) {
        // Si falla, las consultas con a.activo pueden errorar; conviene correr migrate_actividades_activo.sql
        $done = true;
    }
}

/**
 * Usuario con rol Auxiliar Administrativo (barra de jornada en el tablero).
 * Incluye el valor truncado a 20 caracteres si la columna `usuarios.rol` era VARCHAR(20).
 */
function usuarioEsAuxiliarAdministrativo(): bool {
    $r = (string)($_SESSION['rol'] ?? '');
    if ($r === ROL_AUXILIAR_ADMINISTRATIVO) {
        return true;
    }
    // Código previo / pruebas con plural
    if ($r === 'auxiliar_administrativos') {
        return true;
    }
    // Legado: VARCHAR(20) cortaba a "auxiliar_administr"
    return $r === 'auxiliar_administr';
}

/**
 * Pantalla de inicio según rol: administrador → dashboard; resto → tablero de actividades.
 */
function urlInicioApp(): string {
    if (($_SESSION['rol'] ?? 'usuario') === 'admin') {
        return BASE_URL . 'admin/dashboard.php';
    }
    return BASE_URL . 'index.php';
}

function msOrSegToHMSFromSeconds(int $totalSeg): string {
    $totalSeg = max(0, $totalSeg);
    $h = intdiv($totalSeg, 3600);
    $m = intdiv($totalSeg % 3600, 60);
    $s = $totalSeg % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/** Segundos de una fila actividades_usuario, incluyendo intervalo en curso solo si la jornada es hoy. */
function sqlSegActividadUsuarioConEnCurso(): string {
    return '(au.tiempo_acumulado_seg + CASE WHEN au.tiempo_intervalo_inicio_at IS NOT NULL AND j.fecha = CURDATE() THEN TIMESTAMPDIFF(SECOND, au.tiempo_intervalo_inicio_at, NOW()) ELSE 0 END)';
}

function capSegundosContadorDiario(int $segundos): int {
    return min(max(0, $segundos), (int)TOPE_HORAS_DIARIAS_CONTADOR_SEG);
}

/**
 * Suma tiempos diarios topeados (sin almuerzo) por usuario-día en un rango de fechas.
 *
 * @param array<int>|null $usuarioIds null o vacío = todos los usuarios
 */
function fetchSumaTiemposContadorCapped(PDO $pdo, string $fechaDesde, string $fechaHasta, ?array $usuarioIds = null): int {
    $segExpr = sqlSegActividadUsuarioConEnCurso();
    $tope = (int)TOPE_HORAS_DIARIAS_CONTADOR_SEG;
    $almuerzo = (int)ACTIVIDAD_ALMUERZO_ID;

    $userFilter = '';
    $params = [$fechaDesde, $fechaHasta];
    if ($usuarioIds !== null && $usuarioIds !== []) {
        $ids = array_values(array_filter(array_map('intval', $usuarioIds), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $userFilter = " AND au.usuario_id IN ($placeholders) ";
        $params = array_merge($params, $ids);
    }

    $sql = "
        SELECT COALESCE(SUM(seg_dia), 0) AS total_seg
        FROM (
            SELECT LEAST($tope, SUM($segExpr)) AS seg_dia
            FROM actividades_usuario au
            INNER JOIN jornadas j ON j.id = au.jornada_id
            WHERE j.fecha BETWEEN ? AND ?
              AND au.actividad_id <> $almuerzo
              $userFilter
            GROUP BY au.usuario_id, j.fecha
        ) daily
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

/**
 * Tiempos diario / semanal / quincenal / mensual por usuario (sin almuerzo, tope 8 h por día).
 *
 * @param array<int> $usuarioIds
 * @return array<int, array{diario:int,semanal:int,quincenal:int,mensual:int}>
 */
function fetchTiemposContadorPorUsuario(PDO $pdo, array $usuarioIds): array {
    $usuarioIds = array_values(array_filter(array_map('intval', $usuarioIds), static fn(int $id): bool => $id > 0));
    if ($usuarioIds === []) {
        return [];
    }

    $segExpr = sqlSegActividadUsuarioConEnCurso();
    $tope = (int)TOPE_HORAS_DIARIAS_CONTADOR_SEG;
    $almuerzo = (int)ACTIVIDAD_ALMUERZO_ID;
    $placeholders = implode(',', array_fill(0, count($usuarioIds), '?'));

    $sql = "
        SELECT usuario_id,
            SUM(CASE WHEN fecha = CURDATE() THEN seg_dia ELSE 0 END) AS tiempo_diario_seg,
            SUM(CASE WHEN fecha >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN seg_dia ELSE 0 END) AS tiempo_semanal_seg,
            SUM(CASE WHEN fecha >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN seg_dia ELSE 0 END) AS tiempo_quincenal_seg,
            SUM(CASE WHEN fecha >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN seg_dia ELSE 0 END) AS tiempo_mensual_seg
        FROM (
            SELECT au.usuario_id, j.fecha, LEAST($tope, SUM($segExpr)) AS seg_dia
            FROM actividades_usuario au
            INNER JOIN jornadas j ON j.id = au.jornada_id
            WHERE au.usuario_id IN ($placeholders)
              AND au.actividad_id <> $almuerzo
              AND j.fecha >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY au.usuario_id, j.fecha
        ) daily
        GROUP BY usuario_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($usuarioIds);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['usuario_id'];
        $out[$uid] = [
            'diario' => (int)($r['tiempo_diario_seg'] ?? 0),
            'semanal' => (int)($r['tiempo_semanal_seg'] ?? 0),
            'quincenal' => (int)($r['tiempo_quincenal_seg'] ?? 0),
            'mensual' => (int)($r['tiempo_mensual_seg'] ?? 0),
        ];
    }

    return $out;
}

/**
 * Devuelve la jornada activa/actual para "hoy" (por fecha) y garantiza que exista
 * la asignación base de actividades en estado "Asignadas" para todos los usuarios activos.
 *
 * - No resetea el tiempo de filas existentes.
 * - Solo crea filas faltantes para la jornada actual.
 */
function getOrCreateJornadaHoy(PDO $pdo): int {
    $fecha = date('Y-m-d');
    ensureActividadesActivoColumn($pdo);

    $stmt = $pdo->prepare('SELECT id FROM jornadas WHERE fecha = ? LIMIT 1');
    $stmt->execute([$fecha]);
    $row = $stmt->fetch();
    if ($row) {
        $jornadaId = (int)$row['id'];
    } else {
        $pdo->beginTransaction();
        $stmtIns = $pdo->prepare('INSERT INTO jornadas (fecha, activa) VALUES (?, 1)');
        $stmtIns->execute([$fecha]);
        $jornadaId = (int)$pdo->lastInsertId();
        $pdo->commit();
    }

    // Garantizar que existe "Asignadas"
    $stmtEstado = $pdo->prepare('SELECT id FROM actividad_estados WHERE slug = ? LIMIT 1');
    $stmtEstado->execute(['asignadas']);
    $estadoAsignadas = $stmtEstado->fetch();
    if (!$estadoAsignadas) {
        throw new Exception('No existe estado "Asignadas".');
    }
    $estadoAsignadasId = (int)$estadoAsignadas['id'];

    // Solo actividades activas: no crear filas nuevas de inactivas en la jornada actual
    $stmtEnsure = $pdo->prepare('
        INSERT INTO actividades_usuario
            (jornada_id, actividad_id, usuario_id, estado_id, tiempo_acumulado_seg, tiempo_intervalo_inicio_at)
        SELECT
            ?, a.id AS actividad_id,
            u.id AS usuario_id,
            ? AS estado_id,
            0 AS tiempo_acumulado_seg,
            NULL AS tiempo_intervalo_inicio_at
        FROM actividades a
        CROSS JOIN usuarios u
        WHERE u.activo = 1
          AND a.activo = 1
        AND NOT EXISTS (
            SELECT 1
            FROM actividades_usuario au
            WHERE au.jornada_id = ?
              AND au.actividad_id = a.id
              AND au.usuario_id = u.id
        )
    ');
    $stmtEnsure->execute([$jornadaId, $estadoAsignadasId, $jornadaId]);

    return $jornadaId;
}

