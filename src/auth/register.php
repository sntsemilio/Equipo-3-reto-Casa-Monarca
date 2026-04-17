<?php
declare(strict_types=1);

require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/../modules/usuarios.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

function sendRegisterResponse(int $httpCode, string $status, array $data = [], string $message = ''): void
{
    http_response_code($httpCode);

    $body = [
        'status' => $status,
        'data' => $data,
    ];

    if ($message !== '') {
        $body['message'] = $message;
        $body['mensaje'] = $message;
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit();
}

function readRegisterPayload(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    return $_POST;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendRegisterResponse(405, 'error', [], 'Metodo no permitido.');
}

$payload = readRegisterPayload();

try {
    $actorId = Rbac::userId();
    $esAdmin = Rbac::userHasAnyRole([Rbac::ROLE_ADMINISTRADOR]);

    $usuario = registrarUsuario($payload, $actorId, $esAdmin);

    sendRegisterResponse(201, 'success', [
        'user' => $usuario,
    ], 'Usuario registrado correctamente.');
} catch (InvalidArgumentException $e) {
    $code = $e->getCode();
    $http = ($code >= 400 && $code <= 499) ? $code : 400;
    sendRegisterResponse($http, 'error', [], $e->getMessage());
} catch (Throwable $e) {
    sendRegisterResponse(500, 'error', [], 'Error interno durante el registro.');
}
