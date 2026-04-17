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

try {
    $documento = crearDocumentoBorrador($payload, (int) Rbac::userId());

    echo json_encode([
        'status' => 'success',
        'data' => ['documento' => $documento],
        'message' => 'Documento creado en estado borrador.',
        'mensaje' => 'Documento creado en estado borrador.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
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
        'message' => 'No fue posible crear el documento.',
        'mensaje' => 'No fue posible crear el documento.',
    ], JSON_UNESCAPED_UNICODE);
}
