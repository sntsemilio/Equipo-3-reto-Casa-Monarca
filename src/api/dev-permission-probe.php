<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/permissions.php';

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
Rbac::requirePermissionJson('run_testing_matrix');

$targetUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($targetUserId <= 0 || $action === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Parmetros user_id y action son obligatorios.',
        'mensaje' => 'Parmetros user_id y action son obligatorios.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$allowed = userHasPermission($targetUserId, $action);
if (!$allowed) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'data' => [
            'allowed' => false,
            'user_id' => $targetUserId,
            'action' => $action,
        ],
        'message' => 'RBAC rechazo el acceso para esa accion.',
        'mensaje' => 'RBAC rechazo el acceso para esa accion.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'allowed' => true,
        'user_id' => $targetUserId,
        'action' => $action,
    ],
], JSON_UNESCAPED_UNICODE);
