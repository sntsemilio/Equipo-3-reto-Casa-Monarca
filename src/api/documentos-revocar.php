<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/documentos.php';

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
Rbac::requireRoleJson([
    Rbac::ROLE_ADMINISTRADOR,
    Rbac::ROLE_EMISOR,
]);

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$id = isset($payload['id']) ? (int) $payload['id'] : 0;
$motivo = isset($payload['motivo']) ? trim((string) $payload['motivo']) : '';

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'ID de documento invalido.',
        'mensaje' => 'ID de documento invalido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $documento = revocarDocumento($id, (int) Rbac::userId(), $motivo);

    echo json_encode([
        'status' => 'success',
        'data' => ['documento' => $documento],
        'message' => 'Documento revocado correctamente.',
        'mensaje' => 'Documento revocado correctamente.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code < 500) ? $code : 400);
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
        'message' => 'No fue posible revocar el documento.',
        'mensaje' => 'No fue posible revocar el documento.',
    ], JSON_UNESCAPED_UNICODE);
}
