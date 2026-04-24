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
        'status'  => 'error',
        'data'    => [],
        'message' => 'Metodo no permitido.',
        'mensaje' => 'Metodo no permitido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

Rbac::requireAuthJson();
Rbac::requirePermissionJson('manage_users');

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$targetId = isset($payload['id']) ? (int) $payload['id'] : 0;
$nuevoRol = strtolower(trim((string) ($payload['rol'] ?? '')));

if ($targetId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'data'    => [],
        'message' => 'ID de usuario invalido.',
        'mensaje' => 'ID de usuario invalido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($targetId === (int) Rbac::userId()) {
    http_response_code(403);
    echo json_encode([
        'status'  => 'error',
        'data'    => [],
        'message' => 'No puedes cambiar tu propio rol.',
        'mensaje' => 'No puedes cambiar tu propio rol.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $resultado = cambiarRolUsuario($targetId, $nuevoRol, (int) Rbac::userId());

    echo json_encode([
        'status'  => 'success',
        'data'    => $resultado,
        'message' => 'Rol actualizado correctamente.',
        'mensaje' => 'Rol actualizado correctamente.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code <= 499) ? $code : 400);
    echo json_encode([
        'status'  => 'error',
        'data'    => [],
        'message' => $e->getMessage(),
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'data'    => [],
        'message' => 'No fue posible cambiar el rol.',
        'mensaje' => 'No fue posible cambiar el rol.',
    ], JSON_UNESCAPED_UNICODE);
}
