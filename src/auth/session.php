<?php
declare(strict_types=1);

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/../modules/user_certificates.php';

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

if (!Rbac::isAuthenticated()) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Sesion no autorizada.',
        'mensaje' => 'Sesion no autorizada.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$user = [
    'id' => Rbac::userId(),
    'nombre' => (string) ($_SESSION['user_name'] ?? ''),
    'email' => (string) ($_SESSION['user_email'] ?? ''),
    'rol_id' => isset($_SESSION['rol_id']) ? (int) $_SESSION['rol_id'] : null,
    'role_name' => Rbac::userRole(),
    'permissions' => Rbac::userPermissions(),
];

$pendingKeyDownload = null;
$uid = Rbac::userId();
if ($uid !== null && $uid > 0) {
    $pendingKeyDownload = getLatestUserPendingToken($uid);
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'user' => $user,
        'pending_key_download' => $pendingKeyDownload,
    ],
], JSON_UNESCAPED_UNICODE);
