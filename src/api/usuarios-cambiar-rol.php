<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/usuarios.php';
require_once __DIR__ . '/../modules/bitacora.php';
require_once __DIR__ . '/../config/db.php';

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
Rbac::requireRoleJson([Rbac::ROLE_ADMINISTRADOR]);

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

$rolesPermitidos = ['administrador', 'supervisor', 'emisor', 'verificador', 'consultor'];
if (!in_array($nuevoRol, $rolesPermitidos, true)) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'data'    => [],
        'message' => 'Rol no valido. Opciones: ' . implode(', ', $rolesPermitidos),
        'mensaje' => 'Rol no valido.',
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
    $rolId = obtenerRolIdPorNombre($nuevoRol);
    if ($rolId === null) {
        http_response_code(400);
        echo json_encode([
            'status'  => 'error',
            'data'    => [],
            'message' => 'Rol no encontrado en la base de datos.',
            'mensaje' => 'Rol no encontrado en la base de datos.',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('UPDATE usuarios SET rol_id = :rol_id, updated_at = NOW() WHERE id = :id');
    $stmt->execute(['rol_id' => $rolId, 'id' => $targetId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            'status'  => 'error',
            'data'    => [],
            'message' => 'Usuario no encontrado o sin cambios.',
            'mensaje' => 'Usuario no encontrado o sin cambios.',
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    registrarBitacora(
        (int) Rbac::userId(),
        'CAMBIO_ROL',
        'USUARIOS',
        null,
        "Cambio de rol a '{$nuevoRol}' para usuario ID {$targetId}"
    );

    echo json_encode([
        'status'  => 'success',
        'data'    => ['id' => $targetId, 'rol' => $nuevoRol],
        'message' => "Rol actualizado a {$nuevoRol} correctamente.",
        'mensaje' => "Rol actualizado a {$nuevoRol} correctamente.",
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
