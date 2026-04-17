<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/documentos.php';

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

$identificador = '';
if (isset($_GET['token'])) {
    $identificador = trim((string) $_GET['token']);
} elseif (isset($_GET['folio'])) {
    $identificador = trim((string) $_GET['folio']);
} elseif (isset($_GET['id'])) {
    $identificador = trim((string) $_GET['id']);
}

if ($identificador === '') {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Debe proporcionar un token, folio o id.',
        'mensaje' => 'Debe proporcionar un token, folio o id.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $resultado = evaluarAutenticidadPublica($identificador);

    if (!$resultado['encontrado']) {
        http_response_code(404);
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'encontrado' => (bool) $resultado['encontrado'],
            'estado' => $resultado['estado'],
            'firma_valida' => (bool) $resultado['firma_valida'],
            'es_autentico' => (bool) $resultado['es_autentico'],
            'folio' => $resultado['folio'],
            'tipo_documento' => $resultado['tipo_documento'],
            'fecha_emision' => $resultado['fecha_emision'],
            'fecha_revocacion' => $resultado['fecha_revocacion'],
            'motivo_revocacion' => $resultado['motivo_revocacion'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No fue posible validar el documento.',
        'mensaje' => 'No fue posible validar el documento.',
    ], JSON_UNESCAPED_UNICODE);
}
