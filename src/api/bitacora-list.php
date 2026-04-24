<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/bitacora.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Metodo no permitido.',
        'mensaje' => 'Metodo no permitido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

Rbac::requireAuthJson();
Rbac::requirePermissionJson('view_audit_log');

try {
    $limite = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
    $documentoId = isset($_GET['documento_id']) ? (int) $_GET['documento_id'] : null;

    if ($limite < 1) {
        $limite = 1;
    }
    if ($limite > 500) {
        $limite = 500;
    }

    $eventos = obtenerBitacora($limite, $documentoId);

    echo json_encode([
        'status' => 'success',
        'data' => ['eventos' => $eventos],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No fue posible obtener la bitacora.',
        'mensaje' => 'No fue posible obtener la bitacora.',
    ], JSON_UNESCAPED_UNICODE);
}
