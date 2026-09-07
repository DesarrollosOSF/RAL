<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

/**
 * Convierte fecha enviada por el cliente (ISO o datetime MySQL) a formato SQL.
 */
function parseFechaNotaActividad(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return date('Y-m-d H:i:s');
    }

    $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', DateTime::ATOM, 'Y-m-d\TH:i:s.v\Z'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw, new DateTimeZone('America/Bogota'));
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    try {
        $dt = new DateTime($raw, new DateTimeZone('America/Bogota'));
    } catch (Exception $e) {
        return date('Y-m-d H:i:s');
    }

    return $dt->format('Y-m-d H:i:s');
}

try {
    $pdo = getDBConnection();
    $usuarioId = (int)$_SESSION['usuario_id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $actividadId = (int)($_GET['actividad_id'] ?? 0);
        if ($actividadId <= 0) {
            throw new Exception('actividad_id inválido');
        }

        $stmt = $pdo->prepare('
            SELECT id, actividad_id, usuario_id, fallecido, fecha, observaciones, created_at, updated_at
            FROM actividad_notas
            WHERE usuario_id = ? AND actividad_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');
        $stmt->execute([$usuarioId, $actividadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode([
                'ok' => true,
                'nota' => null,
            ]);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'nota' => [
                'id' => (int)$row['id'],
                'actividad_id' => (int)$row['actividad_id'],
                'usuario_id' => (int)$row['usuario_id'],
                'fallecido' => (string)$row['fallecido'],
                'fecha' => (string)$row['fecha'],
                'observaciones' => (string)$row['observaciones'],
            ],
        ]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
        exit;
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'CSRF inválido']);
        exit;
    }

    $actividadId = (int)($_POST['actividad_id'] ?? 0);
    if ($actividadId <= 0) {
        throw new Exception('actividad_id inválido');
    }

    $stmtAct = $pdo->prepare('SELECT id FROM actividades WHERE id = ? LIMIT 1');
    $stmtAct->execute([$actividadId]);
    if (!$stmtAct->fetch()) {
        throw new Exception('La actividad no existe');
    }

    $fallecido = mb_substr(trim((string)($_POST['fallecido'] ?? '')), 0, 200);
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));
    if (mb_strlen($observaciones) > 2000) {
        $observaciones = mb_substr($observaciones, 0, 2000);
    }

    $fechaSql = parseFechaNotaActividad($_POST['fecha'] ?? '');

    $stmtIns = $pdo->prepare('
        INSERT INTO actividad_notas (actividad_id, usuario_id, fallecido, fecha, observaciones)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmtIns->execute([$actividadId, $usuarioId, $fallecido, $fechaSql, $observaciones]);
    $notaId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok' => true,
        'message' => 'Nota guardada correctamente',
        'nota' => [
            'id' => $notaId,
            'actividad_id' => $actividadId,
            'usuario_id' => $usuarioId,
            'fallecido' => $fallecido,
            'fecha' => $fechaSql,
            'observaciones' => $observaciones,
        ],
    ]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'actividad_notas') !== false || (int)$e->getCode() === 1146) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'message' => 'La tabla actividad_notas no existe. Ejecute install/migrate_actividad_notas.sql en la base de datos.',
        ]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
