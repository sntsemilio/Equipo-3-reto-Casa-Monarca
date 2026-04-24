<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/usuarios.php';
require_once __DIR__ . '/../modules/user_certificates.php';

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
Rbac::requirePermissionJson('manage_users');

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$userId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
$reason = trim((string) ($payload['reason'] ?? 'Regeneracion administrativa por reposicion de llave.'));

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'user_id invalido.',
        'mensaje' => 'user_id invalido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $user = obtenerUsuarioPorId($userId);
    if (!$user) {
        throw new InvalidArgumentException('Usuario no encontrado.', 404);
    }

    $roleName = normalizeRoleName((string) ($user['rol_nombre'] ?? ''));
    if (!roleRequiresCertificate($roleName)) {
        throw new InvalidArgumentException('El rol actual del usuario no requiere certificado.', 409);
    }

    revokeUserCertificate($userId, $reason, (int) Rbac::userId());
    $keyDelivery = generateAndStoreUserCertificateBundle($userId, (int) Rbac::userId());
    $updated = obtenerUsuarioPorId($userId);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'user' => $updated,
            'key_delivery' => $keyDelivery,
        ],
        'message' => 'Certificado regenerado correctamente.',
        'mensaje' => 'Certificado regenerado correctamente.',
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
        'message' => 'No fue posible regenerar el certificado del usuario.',
        'mensaje' => 'No fue posible regenerar el certificado del usuario.',
    ], JSON_UNESCAPED_UNICODE);
}
