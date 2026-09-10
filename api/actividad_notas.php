<?php

require_once __DIR__ . '/../config/config.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

/**
 * Convierte una fecha enviada por el cliente
 * (ISO o datetime MySQL) a formato SQL.
 */
function parseFechaNotaActividad(?string $raw): string
{
    $raw = trim((string)$raw);

    if ($raw === '') {
        return date('Y-m-d H:i:s');
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d\TH:i:s',
        DateTime::ATOM,
        'Y-m-d\TH:i:s.v\Z'
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat(
            $fmt,
            $raw,
            new DateTimeZone('America/Bogota')
        );

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    try {
        $dt = new DateTime(
            $raw,
            new DateTimeZone('America/Bogota')
        );
    } catch (Exception $e) {
        return date('Y-m-d H:i:s');
    }

    return $dt->format('Y-m-d H:i:s');
}

/**
 * Normaliza valores enviados desde JavaScript.
 *
 * Ejemplo:
 * "Empresarial" -> "empresarial"
 * "Servicios no prestados" -> "servicios_no_prestados"
 */
function normalizarServicio(string $valor): string
{
    $valor = trim(mb_strtolower($valor, 'UTF-8'));

    $valor = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
        ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
        $valor
    );

    $valor = preg_replace('/\s+/', '_', $valor);

    return $valor;
}

/**
 * Valida y normaliza el tipo y subtipo de servicio.
 *
 * Retorna:
 * [
 *   'tipo' => string|null,
 *   'subtipo' => string|null
 * ]
 */
function validarServicio(string $tipoRaw, string $subtipoRaw): array
{
    $tipo = normalizarServicio($tipoRaw);
    $subtipo = normalizarServicio($subtipoRaw);

    $tiposPermitidos = [
        'empresarial',
        'particular',
        'osf',
        'terceros',
        'mascotas',
        'servicios_no_prestados',
    ];

    if ($tipo === '') {
        return [
            'tipo' => null,
            'subtipo' => null,
        ];
    }

    if (!in_array($tipo, $tiposPermitidos, true)) {
        throw new Exception('Tipo de servicio no válido.');
    }

    $subtiposPermitidos = [];

    switch ($tipo) {

        case 'empresarial':
        case 'particular':
        case 'osf':
        case 'terceros':

            $subtiposPermitidos = [
                'completo',
                'inicial',
                'final',
            ];

            break;

        case 'mascotas':

            $subtiposPermitidos = [
                'prevision',
                'particular',
            ];

            break;

        case 'servicios_no_prestados':

            $subtiposPermitidos = [
                'negados',
                'no_prestados',
            ];

            break;
    }

    if ($subtipo === '') {
        throw new Exception(
            'Debe seleccionar un subtipo de servicio para el tipo seleccionado.'
        );
    }

    if (!in_array($subtipo, $subtiposPermitidos, true)) {
        throw new Exception(
            'El subtipo seleccionado no corresponde al tipo de servicio.'
        );
    }

    return [
        'tipo' => $tipo,
        'subtipo' => $subtipo,
    ];
}

try {

    $pdo = getDBConnection();

    /*
     * Mantiene la compatibilidad con la función existente.
     */
    if (function_exists('ensureActividadNotasServicioColumns')) {
        ensureActividadNotasServicioColumns($pdo);
    }

    /*
     * Verificamos que servicio_subtipo exista.
     * Si no existe, se crea automáticamente.
     */
    $stmtColumn = $pdo->query("
        SHOW COLUMNS FROM actividad_notas LIKE 'servicio_subtipo'
    ");

    $columnaSubtipo = $stmtColumn->fetch(PDO::FETCH_ASSOC);

    if (!$columnaSubtipo) {
        $pdo->exec("
            ALTER TABLE actividad_notas
            ADD COLUMN servicio_subtipo VARCHAR(30) NULL
            AFTER servicio_tipo
        ");
    }

    $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

    if ($usuarioId <= 0) {
        throw new Exception('Usuario no válido.');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    /*
     * =========================================================
     * GET
     * =========================================================
     */
    if ($method === 'GET') {

        $actividadId = (int)($_GET['actividad_id'] ?? 0);

        if ($actividadId <= 0) {
            throw new Exception('actividad_id inválido');
        }

        $stmt = $pdo->prepare('
            SELECT
                id,
                actividad_id,
                usuario_id,
                fallecido,
                fecha,
                observaciones,
                servicio_tipo,
                servicio_subtipo,
                es_terceros,
                es_mascota,
                created_at,
                updated_at
            FROM actividad_notas
            WHERE usuario_id = ?
              AND actividad_id = ?
            ORDER BY id DESC
            LIMIT 1
        ');

        $stmt->execute([
            $usuarioId,
            $actividadId
        ]);

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
                'fallecido' => (string)($row['fallecido'] ?? ''),
                'fecha' => (string)($row['fecha'] ?? ''),
                'observaciones' => (string)($row['observaciones'] ?? ''),

                'servicio_tipo' => (string)($row['servicio_tipo'] ?? ''),

                'servicio_subtipo' => (string)(
                    $row['servicio_subtipo'] ?? ''
                ),

                'es_terceros' =>
                    (int)($row['es_terceros'] ?? 0) === 1,

                'es_mascota' =>
                    (int)($row['es_mascota'] ?? 0) === 1,
            ],
        ]);

        exit;
    }

    /*
     * =========================================================
     * MÉTODO
     * =========================================================
     */
    if ($method !== 'POST') {

        http_response_code(405);

        echo json_encode([
            'ok' => false,
            'message' => 'Método no permitido',
        ]);

        exit;
    }

    /*
     * =========================================================
     * CSRF
     * =========================================================
     */
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {

        http_response_code(400);

        echo json_encode([
            'ok' => false,
            'message' => 'CSRF inválido',
        ]);

        exit;
    }

    /*
     * =========================================================
     * ACTIVIDAD
     * =========================================================
     */
    $actividadId = (int)($_POST['actividad_id'] ?? 0);

    if ($actividadId <= 0) {
        throw new Exception('actividad_id inválido');
    }

    $stmtAct = $pdo->prepare(
        'SELECT id FROM actividades WHERE id = ? LIMIT 1'
    );

    $stmtAct->execute([$actividadId]);

    if (!$stmtAct->fetch()) {
        throw new Exception('La actividad no existe');
    }

    /*
     * =========================================================
     * DATOS GENERALES
     * =========================================================
     */
    $fallecido = mb_substr(
        trim((string)($_POST['fallecido'] ?? '')),
        0,
        200
    );

    $observaciones = trim(
        (string)($_POST['observaciones'] ?? '')
    );

    if (mb_strlen($observaciones) > 2000) {
        $observaciones = mb_substr(
            $observaciones,
            0,
            2000
        );
    }

    $fechaSql = parseFechaNotaActividad(
        $_POST['fecha'] ?? ''
    );

    /*
     * =========================================================
     * TIPO Y SUBTIPO DE SERVICIO
     * =========================================================
     */
    $servicioTipoRaw = trim(
        (string)($_POST['servicio_tipo'] ?? '')
    );

    $servicioSubtipoRaw = trim(
        (string)($_POST['servicio_subtipo'] ?? '')
    );

    $servicio = validarServicio(
        $servicioTipoRaw,
        $servicioSubtipoRaw
    );

    $servicioTipo = $servicio['tipo'];
    $servicioSubtipo = $servicio['subtipo'];

    /*
     * =========================================================
     * COMPATIBILIDAD CON ES_TERCEROS Y ES_MASCOTA
     * =========================================================
     *
     * No dependemos de que el navegador envíe correctamente
     * estos checkboxes.
     *
     * Los calculamos directamente desde servicio_tipo.
     */
    $esTerceros = (
        $servicioTipo === 'terceros'
    ) ? 1 : 0;

    $esMascota = (
        $servicioTipo === 'mascotas'
    ) ? 1 : 0;

    /*
     * =========================================================
     * INSERT
     * =========================================================
     */
    $stmtIns = $pdo->prepare('
        INSERT INTO actividad_notas
        (
            actividad_id,
            usuario_id,
            fallecido,
            fecha,
            observaciones,
            servicio_tipo,
            servicio_subtipo,
            es_terceros,
            es_mascota
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ');

    $stmtIns->execute([
        $actividadId,
        $usuarioId,
        $fallecido,
        $fechaSql,
        $observaciones,
        $servicioTipo,
        $servicioSubtipo,
        $esTerceros,
        $esMascota,
    ]);

    $notaId = (int)$pdo->lastInsertId();

    /*
     * =========================================================
     * RESPUESTA
     * =========================================================
     */
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

            'servicio_tipo' => $servicioTipo,
            'servicio_subtipo' => $servicioSubtipo,

            'es_terceros' => $esTerceros === 1,
            'es_mascota' => $esMascota === 1,
        ],
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'message' => 'Error de base de datos: ' . $e->getMessage(),
    ]);

} catch (Throwable $e) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}