<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/bitacora.php';

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

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : (isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null);
if ($userId !== null && $userId > 0) {
    try {
        registrarBitacora(
            'LOGOUT',
            'AUTH',
            'Sesion finalizada por el usuario.',
            $userId
        );
    } catch (Throwable $e) {
        // No bloquear el cierre de sesion por una falla de bitacora.
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();

echo json_encode([
    'status' => 'success',
    'data' => [],
    'message' => 'Sesion cerrada correctamente.',
    'mensaje' => 'Sesion cerrada correctamente.',
], JSON_UNESCAPED_UNICODE);
