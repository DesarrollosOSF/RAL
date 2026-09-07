<?php
require_once __DIR__ . '/../config/config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Método no permitido']);
        exit;
    }

    $csrfOk = verifyCsrfToken($_POST['csrf_token'] ?? '');
    if (!$csrfOk) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'CSRF inválido']);
        exit;
    }

    $actividadId = (int)($_POST['actividad_id'] ?? 0);
    $accion = sanitizar($_POST['accion'] ?? '');

    $accionesPermitidas = ['start', 'pause', 'resume', 'finish'];
    if ($actividadId <= 0 || !in_array($accion, $accionesPermitidas, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Datos inválidos']);
        exit;
    }

    $pdo = getDBConnection();
    $usuarioId = (int)$_SESSION['usuario_id'];
    ensureActividadesActivoColumn($pdo);

    $stmtActiva = $pdo->prepare('SELECT activo FROM actividades WHERE id = ? LIMIT 1');
    $stmtActiva->execute([$actividadId]);
    $actRow = $stmtActiva->fetch(PDO::FETCH_ASSOC);
    if (!$actRow) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'La actividad no existe']);
        exit;
    }
    if ((int)$actRow['activo'] !== 1 && in_array($accion, ['start', 'resume'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Esta actividad está inactiva y no se puede iniciar.']);
        exit;
    }

    $jornadaId = getOrCreateJornadaHoy($pdo);

    // IDs por slug (para evitar depender del nombre)
    $stmtEstados = $pdo->query("SELECT id, slug FROM actividad_estados");
    $estadoId = [];
    foreach ($stmtEstados->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $estadoId[$e['slug']] = (int)$e['id'];
    }

    foreach (['asignadas', 'iniciadas', 'finalizadas'] as $slug) {
        if (empty($estadoId[$slug])) {
            throw new Exception('Falta estado: ' . $slug);
        }
    }

    // Para `pause` intentamos ubicar la actividad por su intervalo abierto,
    // lo cual evita que falle si el usuario intenta pausar cerca del cambio de día.
    if ($accion === 'pause') {
        $stmtAU = $pdo->prepare('
            SELECT au.id, au.estado_id, au.tiempo_intervalo_inicio_at
            FROM actividades_usuario au
            INNER JOIN tiempos_intervalos_actividad ti
                ON ti.actividades_usuario_id = au.id AND ti.fin_at IS NULL
            WHERE au.actividad_id = ? AND au.usuario_id = ?
            ORDER BY ti.id DESC
            LIMIT 1
        ');
        $stmtAU->execute([$actividadId, $usuarioId]);
    } else {
        $stmtAU = $pdo->prepare('
            SELECT id, estado_id, tiempo_intervalo_inicio_at
            FROM actividades_usuario
            WHERE actividad_id = ? AND usuario_id = ? AND jornada_id = ?
            LIMIT 1
        ');
        $stmtAU->execute([$actividadId, $usuarioId, $jornadaId]);
    }
    $au = $stmtAU->fetch();
    if (!$au) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Actividad no asignada a este usuario']);
        exit;
    }

    $actividadesUsuarioId = (int)$au['id'];
    $estadoActualId = (int)$au['estado_id'];
    $inicioActual = $au['tiempo_intervalo_inicio_at'];

    // Intervalo abierto (si existe)
    $stmtOpen = $pdo->prepare('
        SELECT id, inicio_at 
        FROM tiempos_intervalos_actividad
        WHERE actividades_usuario_id = ? AND fin_at IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtOpen->execute([$actividadesUsuarioId]);
    $open = $stmtOpen->fetch();

    $pdo->beginTransaction();

    /**
     * Pausa automática: si hay otras actividades en Iniciadas corriendo,
     * se cierran sus intervalos y se acumula el tiempo, dejando todas pausadas
     * excepto la actividad seleccionada.
     *
     * @param int $excludeActividadesUsuarioId
     * @param string $evento
     */
    $autoPauseOthers = function(int $excludeActividadesUsuarioId, string $evento) use ($pdo, $usuarioId, $estadoId, $jornadaId) {
        $now = date('Y-m-d H:i:s');

        // 1) Acumular tiempo y limpiar tiempo_intervalo_inicio_at
        //    (si cerramos primero, el intervalo ya no cumple fin_at IS NULL y no podríamos acumular)
        $derived = '
            SELECT
                ti.id AS intervalo_id,
                ti.actividades_usuario_id,
                TIMESTAMPDIFF(SECOND, ti.inicio_at, ?) AS dur_seg
            FROM tiempos_intervalos_actividad ti
            INNER JOIN actividades_usuario au
                ON au.id = ti.actividades_usuario_id
            WHERE
                ti.fin_at IS NULL
                AND au.usuario_id = ?
                AND au.estado_id = ?
                AND au.jornada_id = ?
                AND au.id <> ?
        ';

        $stmtAcc = $pdo->prepare("
            UPDATE actividades_usuario au
            INNER JOIN ( $derived ) x
                ON x.actividades_usuario_id = au.id
            SET
                au.tiempo_acumulado_seg = au.tiempo_acumulado_seg + x.dur_seg,
                au.tiempo_intervalo_inicio_at = NULL
        ");
        $stmtAcc->execute([$now, $usuarioId, $estadoId['iniciadas'], $jornadaId, $excludeActividadesUsuarioId]);

        // 2) Cerrar intervalos abiertos de otras actividades "Iniciadas"
        $stmtClose = $pdo->prepare("
            UPDATE tiempos_intervalos_actividad ti
            INNER JOIN ( $derived ) x
                ON x.intervalo_id = ti.id
            SET
                ti.fin_at = ?,
                ti.duracion_seg = x.dur_seg,
                ti.evento = ?
        ");
        $stmtClose->execute([$now, $usuarioId, $estadoId['iniciadas'], $jornadaId, $excludeActividadesUsuarioId, $now, $evento]);
    };

    if ($accion === 'start') {
        if ($estadoActualId !== $estadoId['asignadas']) {
            throw new Exception('Solo se puede iniciar desde estado Asignadas.');
        }
        if ($open) {
            throw new Exception('La actividad ya tiene un intervalo activo.');
        }

        // Regla: al iniciar una actividad, todas las demás "Iniciadas" deben quedar pausadas.
        $autoPauseOthers($actividadesUsuarioId, 'AUTO_PAUSE_ON_START');

        $stmtUpd = $pdo->prepare('
            UPDATE actividades_usuario
            SET estado_id = ?, tiempo_intervalo_inicio_at = NOW()
            WHERE id = ?
        ');
        $stmtUpd->execute([$estadoId['iniciadas'], $actividadesUsuarioId]);

        $stmtIns = $pdo->prepare('
            INSERT INTO tiempos_intervalos_actividad (actividades_usuario_id, inicio_at, evento)
            VALUES (?, NOW(), ?)
        ');
        $stmtIns->execute([$actividadesUsuarioId, 'START']);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Actividad iniciada.']);
        exit;
    }

    if ($accion === 'pause') {
        if ($estadoActualId !== $estadoId['iniciadas']) {
            throw new Exception('Solo se puede pausar estando en Iniciadas.');
        }
        if (!$open) {
            throw new Exception('La actividad no está corriendo.');
        }

        $stmtUpdInt = $pdo->prepare('
            UPDATE tiempos_intervalos_actividad
            SET fin_at = NOW(),
                duracion_seg = TIMESTAMPDIFF(SECOND, inicio_at, NOW()),
                evento = ?
            WHERE id = ?
        ');
        $stmtUpdInt->execute(['PAUSE', (int)$open['id']]);

        $stmtSelDur = $pdo->prepare('SELECT duracion_seg FROM tiempos_intervalos_actividad WHERE id = ? LIMIT 1');
        $stmtSelDur->execute([(int)$open['id']]);
        $durSeg = (int)($stmtSelDur->fetch()['duracion_seg'] ?? 0);

        $stmtUpdAU = $pdo->prepare('
            UPDATE actividades_usuario
            SET tiempo_acumulado_seg = tiempo_acumulado_seg + ?,
                tiempo_intervalo_inicio_at = NULL
            WHERE id = ?
        ');
        $stmtUpdAU->execute([$durSeg, $actividadesUsuarioId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Actividad pausada.']);
        exit;
    }

    if ($accion === 'resume') {
        if ($estadoActualId !== $estadoId['iniciadas']) {
            throw new Exception('Solo se puede reanudar estando en Iniciadas.');
        }
        if ($open) {
            throw new Exception('La actividad ya está corriendo.');
        }

        // Regla: al reanudar una actividad, todas las demás "Iniciadas" deben quedar pausadas.
        $autoPauseOthers($actividadesUsuarioId, 'AUTO_PAUSE_ON_RESUME');

        $stmtUpdAU = $pdo->prepare('
            UPDATE actividades_usuario
            SET tiempo_intervalo_inicio_at = NOW()
            WHERE id = ?
        ');
        $stmtUpdAU->execute([$actividadesUsuarioId]);

        $stmtIns = $pdo->prepare('
            INSERT INTO tiempos_intervalos_actividad (actividades_usuario_id, inicio_at, evento)
            VALUES (?, NOW(), ?)
        ');
        $stmtIns->execute([$actividadesUsuarioId, 'RESUME']);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Actividad reanudada.']);
        exit;
    }

    // finish
    if ($accion === 'finish') {
        if ($estadoActualId !== $estadoId['iniciadas']) {
            throw new Exception('Solo se puede finalizar desde Iniciadas.');
        }

        if ($open) {
            $stmtUpdInt = $pdo->prepare('
                UPDATE tiempos_intervalos_actividad
                SET fin_at = NOW(),
                    duracion_seg = TIMESTAMPDIFF(SECOND, inicio_at, NOW()),
                    evento = ?
                WHERE id = ?
            ');
            $stmtUpdInt->execute(['FINISH', (int)$open['id']]);

            $stmtSelDur = $pdo->prepare('SELECT duracion_seg FROM tiempos_intervalos_actividad WHERE id = ? LIMIT 1');
            $stmtSelDur->execute([(int)$open['id']]);
            $durSeg = (int)($stmtSelDur->fetch()['duracion_seg'] ?? 0);

            $stmtUpdAU = $pdo->prepare('
                UPDATE actividades_usuario
                SET tiempo_acumulado_seg = tiempo_acumulado_seg + ?,
                    tiempo_intervalo_inicio_at = NULL
                WHERE id = ?
            ');
            $stmtUpdAU->execute([$durSeg, $actividadesUsuarioId]);
        }

        // Si estaba pausada o ya se cerró el intervalo, aseguramos el inicio nulo
        $pdo->prepare('UPDATE actividades_usuario SET tiempo_intervalo_inicio_at = NULL WHERE id = ?')
            ->execute([$actividadesUsuarioId]);

        $stmtUpdEstado = $pdo->prepare('
            UPDATE actividades_usuario
            SET estado_id = ?
            WHERE id = ?
        ');
        $stmtUpdEstado->execute([$estadoId['finalizadas'], $actividadesUsuarioId]);

        $pdo->commit();
        echo json_encode(['ok' => true, 'message' => 'Actividad finalizada.']);
        exit;
    }

    // Si llegamos aquí, accion no manejada
    throw new Exception('Acción no soportada.');
} catch (Exception $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}

