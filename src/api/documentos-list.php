<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/documentos.php';

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
Rbac::requireRoleJson([
    Rbac::ROLE_ADMINISTRADOR,
    Rbac::ROLE_EMISOR,
    Rbac::ROLE_CONSULTOR,
]);

try {
    $filtros = [
        'estado' => isset($_GET['estado']) ? trim((string) $_GET['estado']) : null,
        'q' => isset($_GET['q']) ? trim((string) $_GET['q']) : null,
        'limit' => isset($_GET['limit']) ? (int) $_GET['limit'] : 200,
        'offset' => isset($_GET['offset']) ? (int) $_GET['offset'] : 0,
    ];

    $resultado = listarDocumentos($filtros);

    echo json_encode([
        'status' => 'success',
        'data' => [
            'documentos' => $resultado['items'],
            'resumen' => $resultado['resumen'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No fue posible obtener los documentos.',
        'mensaje' => 'No fue posible obtener los documentos.',
    ], JSON_UNESCAPED_UNICODE);
}
