<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

try {
    $actividadId = (int)($_GET['actividad_id'] ?? $_POST['actividad_id'] ?? 0);
    if ($actividadId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'actividad_id inválido']);
        exit;
    }

    $pdo = getDBConnection();
    $usuarioId = (int)$_SESSION['usuario_id'];
    $jornadaId = getOrCreateJornadaHoy($pdo);

    // Datos base (actividad + estado actual del usuario)
    $stmt = $pdo->prepare('
        SELECT 
            a.id AS actividad_id,
            a.titulo,
            a.descripcion,
            au.id AS actividades_usuario_id,
            e.slug AS estado_slug,
            COALESCE(au.tiempo_acumulado_seg, 0) AS tiempo_acumulado_seg
        FROM actividades a
        INNER JOIN actividades_usuario au 
            ON au.actividad_id = a.id AND au.usuario_id = ? AND au.jornada_id = ?
        INNER JOIN actividad_estados e 
            ON e.id = au.estado_id
        WHERE a.id = ?
        LIMIT 1
    ');
    $stmt->execute([$usuarioId, $jornadaId, $actividadId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Actividad no asignada a este usuario']);
        exit;
    }

    $actividadesUsuarioId = (int)$row['actividades_usuario_id'];
    $tiempoAcumuladoSeg = (int)$row['tiempo_acumulado_seg'];

    // Intervalo abierto (si existe) para calcular "en curso" y total mostrado
    $stmtOpen = $pdo->prepare('
        SELECT inicio_at 
        FROM tiempos_intervalos_actividad
        WHERE actividades_usuario_id = ? AND fin_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtOpen->execute([$actividadesUsuarioId]);
    $open = $stmtOpen->fetch();
    $extraSeg = 0;
    if ($open && !empty($open['inicio_at'])) {
        $stmtExtra = $pdo->prepare('SELECT TIMESTAMPDIFF(SECOND, ?, NOW()) AS extra_seg');
        $stmtExtra->execute([(string)$open['inicio_at']]);
        $extraSeg = (int)($stmtExtra->fetch()['extra_seg'] ?? 0);
    }

    $tiempoTotalSeg = $tiempoAcumuladoSeg + $extraSeg;

    // Historial de intervalos (incluye cálculo de duración para el tramo abierto)
    $stmtHist = $pdo->prepare('
        SELECT 
            inicio_at,
            fin_at,
            evento,
            CASE 
              WHEN fin_at IS NULL THEN TIMESTAMPDIFF(SECOND, inicio_at, NOW())
              ELSE duracion_seg
            END AS duracion_seg_mostrable
        FROM tiempos_intervalos_actividad
        WHERE actividades_usuario_id = ?
        ORDER BY inicio_at ASC
    ');
    $stmtHist->execute([$actividadesUsuarioId]);
    $intervals = [];
    foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $intervals[] = [
            'inicio_at' => $i['inicio_at'],
            'fin_at' => $i['fin_at'],
            'evento' => $i['evento'],
            'duracion_seg' => (int)($i['duracion_seg_mostrable'] ?? 0),
        ];
    }

    echo json_encode([
        'ok' => true,
        'actividad' => [
            'actividad_id' => (int)$row['actividad_id'],
            'titulo' => $row['titulo'],
            'descripcion' => $row['descripcion'],
            'estado_slug' => $row['estado_slug'],
        ],
        'tiempo' => [
            'tiempo_acumulado_seg' => $tiempoAcumuladoSeg,
            'tiempo_total_seg' => $tiempoTotalSeg,
        ],
        'intervalos' => $intervals
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

