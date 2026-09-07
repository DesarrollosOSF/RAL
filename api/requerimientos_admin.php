<?php
require_once __DIR__ . '/../config/config.php';
requireRole(['admin']);

header('Content-Type: application/json; charset=utf-8');

function jsonError(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonError(405, 'Método no permitido');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    jsonError(400, 'CSRF inválido');
}

$idRequerimiento = (int)($_POST['id_requerimiento'] ?? 0);
$estado = strtolower(trim((string)($_POST['estado'] ?? '')));
$estadosValidos = ['pendiente', 'en_tramite', 'cerrado'];

if ($idRequerimiento <= 0) {
    jsonError(400, 'Requerimiento inválido.');
}

if (!in_array($estado, $estadosValidos, true)) {
    jsonError(400, 'Estado no válido.');
}

try {
    $pdo = getDBConnection();

    $stmtCheck = $pdo->prepare('SELECT id_requerimiento, estado FROM requerimientos WHERE id_requerimiento = ? LIMIT 1');
    $stmtCheck->execute([$idRequerimiento]);
    $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonError(404, 'Requerimiento no encontrado.');
    }

    $stmtUpd = $pdo->prepare('UPDATE requerimientos SET estado = ? WHERE id_requerimiento = ?');
    $stmtUpd->execute([$estado, $idRequerimiento]);

    echo json_encode([
        'ok' => true,
        'message' => 'Estado actualizado correctamente.',
        'id_requerimiento' => $idRequerimiento,
        'estado' => $estado,
    ]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'requerimientos') !== false || (int)$e->getCode() === 1146) {
        jsonError(500, 'La tabla requerimientos no existe.');
    }
    jsonError(400, $e->getMessage());
} catch (Throwable $e) {
    jsonError(400, $e->getMessage());
}
