<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDBConnection();
    $usuarioId = (int)$_SESSION['usuario_id'];
    $jornadaId = getOrCreateJornadaHoy($pdo);

    $stmtEstados = $pdo->query("SELECT id, slug FROM actividad_estados ORDER BY orden ASC");
    $estados = $stmtEstados->fetchAll(PDO::FETCH_ASSOC);
    $estadoId = [];
    foreach ($estados as $e) {
        $estadoId[$e['slug']] = (int)$e['id'];
    }

    $required = ['asignadas', 'iniciadas', 'finalizadas'];
    foreach ($required as $slug) {
        if (empty($estadoId[$slug])) {
            throw new Exception('Falta estado: ' . $slug);
        }
    }

    ensureActividadesActivoColumn($pdo);

    // 1) Solo actividades activas (las inactivas no se muestran; su historial permanece en BD)
    $stmt = $pdo->prepare('
        SELECT 
            a.id AS actividad_id,
            a.titulo,
            a.descripcion,
            au.id AS actividades_usuario_id,
            e.slug AS estado_slug,
            e.nombre AS estado_nombre,
            COALESCE(au.tiempo_acumulado_seg, 0) AS tiempo_acumulado_seg,
            au.tiempo_intervalo_inicio_at
        FROM actividades a
        LEFT JOIN actividades_usuario au 
            ON au.actividad_id = a.id AND au.usuario_id = ? AND au.jornada_id = ?
        LEFT JOIN actividad_estados e 
            ON e.id = au.estado_id
        WHERE a.activo = 1
        ORDER BY a.titulo ASC
    ');
    $stmt->execute([$usuarioId, $jornadaId]);
    $rows = $stmt->fetchAll();

    // 2) Inicialización perezosa: si el usuario no tiene fila para la actividad, se crea en “Asignadas”
    $missingActividadIds = [];
    foreach ($rows as $r) {
        if (empty($r['actividades_usuario_id'])) {
            $missingActividadIds[] = (int)$r['actividad_id'];
        }
    }

    if (!empty($missingActividadIds)) {
        $stmtIns = $pdo->prepare('
            INSERT INTO actividades_usuario (jornada_id, actividad_id, usuario_id, estado_id, tiempo_acumulado_seg, tiempo_intervalo_inicio_at)
            VALUES (?, ?, ?, ?, 0, NULL)
        ');
        foreach ($missingActividadIds as $actividadId) {
            $stmtIns->execute([$jornadaId, $actividadId, $usuarioId, $estadoId['asignadas']]);
        }
        // Recargar para retornar estados correctos
        $stmt->execute([$usuarioId, $jornadaId]);
        $rows = $stmt->fetchAll();
    }

    // 3) Construir tablero
    $board = [
        'asignadas' => [],
        'iniciadas' => [],
        'finalizadas' => [],
    ];

    foreach ($rows as $r) {
        $slug = $r['estado_slug'] ?? 'asignadas';

        $inicioAt = $r['tiempo_intervalo_inicio_at'];
        $inicioUnixMs = null;
        $estaCorriendo = false;
        if ($inicioAt !== null) {
            $inicioUnixMs = (int)(strtotime($inicioAt) * 1000);
            $estaCorriendo = true;
        }

        $card = [
            'actividad_id' => (int)$r['actividad_id'],
            'titulo' => $r['titulo'],
            'descripcion' => $r['descripcion'],
            'estado_slug' => $slug,
            'tiempo_acumulado_seg' => (int)$r['tiempo_acumulado_seg'],
            'esta_corriendo' => $estaCorriendo,
            'inicio_unix_ms' => $inicioUnixMs, // null si está pausada o asignada/finalizada
        ];

        if (!isset($board[$slug])) {
            // En caso de datos inconsistentes
            $board['asignadas'][] = $card;
        } else {
            $board[$slug][] = $card;
        }
    }

    // En la columna "iniciadas", priorizar las que están corriendo (cronómetro activo)
    if (!empty($board['iniciadas'])) {
        usort($board['iniciadas'], function (array $a, array $b): int {
            // Primero: las que están corriendo
            if ($a['esta_corriendo'] === $b['esta_corriendo']) {
                // Segundo: ordenar por título alfabético para mantener consistencia
                return strcasecmp((string)$a['titulo'], (string)$b['titulo']);
            }
            return $a['esta_corriendo'] ? -1 : 1;
        });
    }

    $serverNowMs = (int)round(microtime(true) * 1000);

    echo json_encode([
        'ok' => true,
        'server_now_unix_ms' => $serverNowMs,
        'board' => $board
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

