<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

const MIME_IMAGEN = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const EXT_IMAGEN = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

function jsonError(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

function columnasRequerimientos(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $stmt = $pdo->query('SHOW COLUMNS FROM requerimientos');
    $cache = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
    return $cache;
}

function tieneColumna(PDO $pdo, string $col): bool
{
    return in_array($col, columnasRequerimientos($pdo), true);
}

function sanitizeNombreArchivo(string $name): string
{
    $name = preg_replace('/[^\w.\-áéíóúñÁÉÍÓÚÑ ]+/u', '_', trim($name)) ?? 'archivo';
    $name = preg_replace('/\s+/', '_', $name) ?? 'archivo';
    $name = mb_substr($name, 0, 120);
    return $name !== '' ? $name : 'archivo';
}

function extensionArchivo(string $name): string
{
    $pos = mb_strrpos($name, '.');
    if ($pos === false) {
        return '';
    }
    return strtolower(mb_substr($name, $pos + 1));
}

function validarAdjuntoRequerimiento(array $file, string $tipo): ?string
{
    if ($tipo === 'ninguno') {
        return null;
    }

    $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode === UPLOAD_ERR_NO_FILE) {
        return 'Debes seleccionar un archivo o elegir "Sin adjunto".';
    }
    if ($errCode !== UPLOAD_ERR_OK) {
        return 'No se pudo subir el archivo. Intenta de nuevo.';
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > REQUERIMIENTO_ARCHIVO_MAX_BYTES) {
        $maxMb = round(REQUERIMIENTO_ARCHIVO_MAX_BYTES / (1024 * 1024), 1);
        return "El archivo supera el tamaño máximo ({$maxMb} MB).";
    }

    $mime = strtolower((string)($file['type'] ?? ''));
    $ext = extensionArchivo((string)($file['name'] ?? ''));

    if ($tipo === 'pdf') {
        if ($mime !== 'application/pdf' && $ext !== 'pdf') {
            return 'Solo se permiten documentos PDF.';
        }
        return null;
    }

    if ($tipo === 'imagen') {
        $mimeOk = in_array($mime, MIME_IMAGEN, true);
        $extOk = in_array($ext, EXT_IMAGEN, true);
        if (!$mimeOk && !$extOk) {
            return 'Solo se permiten imágenes JPG, PNG, WebP o GIF.';
        }
        return null;
    }

    return 'Tipo de adjunto no válido.';
}

function guardarAdjuntoRequerimiento(int $reqId, int $usuarioId, array $file): string
{
    $nombreOriginal = sanitizeNombreArchivo((string)($file['name'] ?? 'archivo'));
    $year = date('Y');
    $month = date('m');
    $dirRel = "uploads/requerimientos/{$year}/{$month}/{$usuarioId}";
    $dirAbs = BASE_PATH . str_replace('/', DIRECTORY_SEPARATOR, $dirRel);

    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0755, true) && !is_dir($dirAbs)) {
        throw new RuntimeException('No se pudo crear la carpeta de adjuntos.');
    }

    $destName = $reqId . '_' . $nombreOriginal;
    $rutaRel = $dirRel . '/' . $destName;
    $rutaAbs = BASE_PATH . str_replace('/', DIRECTORY_SEPARATOR, $rutaRel);

    if (!move_uploaded_file((string)$file['tmp_name'], $rutaAbs)) {
        throw new RuntimeException('No se pudo guardar el archivo adjunto.');
    }

    return $rutaRel;
}

function mapRequerimientoRow(array $row): array
{
    $adjunto = trim((string)($row['adjunto'] ?? ''));
    $adjuntoUrl = $adjunto !== '' ? BASE_URL . str_replace('\\', '/', $adjunto) : null;
    $adjuntoNombre = '';
    if ($adjunto !== '') {
        $adjuntoNombre = basename($adjunto);
        if (preg_match('/^\d+_(.+)$/', $adjuntoNombre, $m)) {
            $adjuntoNombre = $m[1];
        }
    }

    return [
        'id_requerimiento' => (int)$row['id_requerimiento'],
        'id_usuario' => (int)$row['id_usuario'],
        'id_sede' => (int)$row['id_sede'],
        'sede_nombre' => (string)($row['sede_nombre'] ?? ''),
        'asunto' => (string)$row['asunto'],
        'id_dependencia' => isset($row['id_dependencia']) && $row['id_dependencia'] !== null
            ? (int)$row['id_dependencia'] : null,
        'dependencia_nombre' => (string)($row['dependencia_nombre'] ?? ''),
        'prioridad' => (string)($row['prioridad'] ?? 'media'),
        'descripcion' => (string)$row['descripcion'],
        'adjunto' => $adjunto !== '' ? $adjunto : null,
        'adjunto_url' => $adjuntoUrl,
        'adjunto_nombre' => $adjuntoNombre !== '' ? $adjuntoNombre : null,
        'estado' => (string)($row['estado'] ?? 'pendiente'),
        'creado_at' => (string)($row['creado_at'] ?? ''),
    ];
}

function sqlSelectRequerimientos(PDO $pdo): string
{
    $cols = ['r.id_requerimiento', 'r.id_usuario', 'r.id_sede', 's.nombre AS sede_nombre'];
    $cols[] = 'r.asunto';
    $cols[] = 'r.id_dependencia';
    $cols[] = 'd.nombre AS dependencia_nombre';
    $cols[] = 'r.prioridad';
    $cols[] = 'r.descripcion';
    $cols[] = 'r.adjunto';
    if (tieneColumna($pdo, 'estado')) {
        $cols[] = 'r.estado';
    }
    if (tieneColumna($pdo, 'creado_at')) {
        $cols[] = 'r.creado_at';
    }

    return '
        SELECT ' . implode(', ', $cols) . '
        FROM requerimientos r
        LEFT JOIN sedes s ON s.id = r.id_sede
        LEFT JOIN dependencias d ON d.id = r.id_dependencia
    ';
}

$pdo = null;

try {
    $pdo = getDBConnection();
    $usuarioId = (int)$_SESSION['usuario_id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $sql = sqlSelectRequerimientos($pdo) . '
            WHERE r.id_usuario = ?
            ORDER BY r.id_requerimiento DESC
        ';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$usuarioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'ok' => true,
            'requerimientos' => array_map('mapRequerimientoRow', $rows),
        ]);
        exit;
    }

    if ($method !== 'POST') {
        jsonError(405, 'Método no permitido');
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        jsonError(400, 'CSRF inválido');
    }

    $stmtUser = $pdo->prepare('SELECT sede_id FROM usuarios WHERE id = ? LIMIT 1');
    $stmtUser->execute([$usuarioId]);
    $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
    $sedeId = (int)($userRow['sede_id'] ?? 0);
    if ($sedeId <= 0) {
        jsonError(400, 'Tu usuario no tiene una sede asignada. Contacta al administrador.');
    }

    $asunto = mb_substr(trim((string)($_POST['asunto'] ?? '')), 0, 120);
    $descripcion = trim((string)($_POST['descripcion'] ?? ''));
    if ($asunto === '' || $descripcion === '') {
        jsonError(400, 'Completa los campos obligatorios: asunto y descripción.');
    }
    if (mb_strlen($descripcion) > 2000) {
        $descripcion = mb_substr($descripcion, 0, 2000);
    }

    $prioridad = strtolower(trim((string)($_POST['prioridad'] ?? 'media')));
    $prioridadesValidas = ['baja', 'media', 'alta', 'urgente'];
    if (!in_array($prioridad, $prioridadesValidas, true)) {
        $prioridad = 'media';
    }

    $idDependencia = (int)($_POST['id_dependencia'] ?? 0);
    $idDependenciaDb = null;
    if ($idDependencia > 0) {
        $stmtDep = $pdo->prepare('SELECT id FROM dependencias WHERE id = ? AND activo = 1 LIMIT 1');
        $stmtDep->execute([$idDependencia]);
        if (!$stmtDep->fetch()) {
            jsonError(400, 'La dependencia seleccionada no es válida.');
        }
        $idDependenciaDb = $idDependencia;
    }

    $adjuntoTipo = strtolower(trim((string)($_POST['adjunto_tipo'] ?? 'ninguno')));
    $tiposAdjunto = ['ninguno', 'imagen', 'pdf'];
    if (!in_array($adjuntoTipo, $tiposAdjunto, true)) {
        $adjuntoTipo = 'ninguno';
    }

    $file = $_FILES['adjunto'] ?? null;
    $tieneArchivo = is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($adjuntoTipo !== 'ninguno') {
        if (!is_array($file)) {
            jsonError(400, 'Debes seleccionar un archivo válido o indicar que no llevará adjunto.');
        }
        $errAdj = validarAdjuntoRequerimiento($file, $adjuntoTipo);
        if ($errAdj !== null) {
            jsonError(400, $errAdj);
        }
    } elseif ($tieneArchivo) {
        jsonError(400, 'Selecciona el tipo de adjunto (Imagen o PDF) antes de subir un archivo.');
    }

    $pdo->beginTransaction();

    $stmtIns = $pdo->prepare('
        INSERT INTO requerimientos (id_usuario, id_sede, asunto, id_dependencia, prioridad, descripcion, adjunto)
        VALUES (?, ?, ?, ?, ?, ?, NULL)
    ');
    $stmtIns->execute([$usuarioId, $sedeId, $asunto, $idDependenciaDb, $prioridad, $descripcion]);
    $reqId = (int)$pdo->lastInsertId();

    if ($adjuntoTipo !== 'ninguno' && is_array($file)) {
        $rutaAdjunto = guardarAdjuntoRequerimiento($reqId, $usuarioId, $file);
        $stmtUpd = $pdo->prepare('
            UPDATE requerimientos SET adjunto = ?
            WHERE id_requerimiento = ? AND id_usuario = ?
        ');
        $stmtUpd->execute([$rutaAdjunto, $reqId, $usuarioId]);
    }

    $pdo->commit();

    $stmtOne = $pdo->prepare(sqlSelectRequerimientos($pdo) . '
        WHERE r.id_requerimiento = ? AND r.id_usuario = ?
        LIMIT 1
    ');
    $stmtOne->execute([$reqId, $usuarioId]);
    $row = $stmtOne->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError(500, 'Requerimiento creado pero no se pudo recuperar.');
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Requerimiento registrado correctamente.',
        'requerimiento' => mapRequerimientoRow($row),
    ]);
} catch (PDOException $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (strpos($e->getMessage(), 'requerimientos') !== false || (int)$e->getCode() === 1146) {
        jsonError(500, 'La tabla requerimientos no existe. Ejecute install/migrate_requerimientos.sql.');
    }
    jsonError(400, 'Error al guardar: ' . $e->getMessage());
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonError(400, $e->getMessage());
}
