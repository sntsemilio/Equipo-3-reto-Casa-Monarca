<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/usuarios.php';

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
Rbac::requireRoleJson([Rbac::ROLE_ADMINISTRADOR]);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$targetId = isset($payload['id']) ? (int) $payload['id'] : 0;
if ($targetId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'ID de usuario invalido.',
        'mensaje' => 'ID de usuario invalido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $ok = desactivarUsuario($targetId, (int) Rbac::userId());
    if (!$ok) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'data' => [],
            'message' => 'Usuario no encontrado o sin cambios.',
            'mensaje' => 'Usuario no encontrado o sin cambios.',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    echo json_encode([
        'status' => 'success',
        'data' => ['id' => $targetId],
        'message' => 'Usuario desactivado correctamente.',
        'mensaje' => 'Usuario desactivado correctamente.',
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
        'message' => 'No fue posible desactivar el usuario.',
        'mensaje' => 'No fue posible desactivar el usuario.',
    ], JSON_UNESCAPED_UNICODE);
}
