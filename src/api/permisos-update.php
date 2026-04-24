<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/permissions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$scope = strtolower(trim((string) ($payload['scope'] ?? '')));
$action = strtolower(trim((string) ($payload['action'] ?? '')));
$enabled = filter_var($payload['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($enabled === null) {
    $enabled = false;
}

if ($scope === '' || $action === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'scope y action son obligatorios.',
        'mensaje' => 'scope y action son obligatorios.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $actorId = (int) (Rbac::userId() ?? 0);

    if ($scope === 'role') {
        Rbac::requirePermissionJson('manage_role_permissions');
        $roleId = isset($payload['role_id']) ? (int) $payload['role_id'] : 0;
        updateRolePermissionAssignment($actorId, $roleId, $action, (bool) $enabled);
    } elseif ($scope === 'user') {
        Rbac::requirePermissionJson('manage_user_permissions');
        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        updateUserPermissionAssignment($actorId, $userId, $action, (bool) $enabled);

        if ($userId === $actorId) {
            Rbac::refreshSessionPermissions();
        }
    } else {
        throw new InvalidArgumentException('Scope invalido. Use role o user.', 400);
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'scope' => $scope,
            'action' => $action,
            'enabled' => (bool) $enabled,
        ],
        'message' => 'Permiso actualizado correctamente.',
        'mensaje' => 'Permiso actualizado correctamente.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code <= 499) ? $code : 400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => $e->getMessage(),
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No fue posible actualizar permisos.',
        'mensaje' => 'No fue posible actualizar permisos.',
    ], JSON_UNESCAPED_UNICODE);
}
