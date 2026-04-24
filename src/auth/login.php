<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../modules/bitacora.php';
require_once __DIR__ . '/../modules/permissions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sendAuthResponse(int $httpCode, string $status, array $data = [], string $message = ''): void
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

function readLoginPayload(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    return $_POST;
}

function authenticate(string $email, string $password, $pdo = null): array
{
    $pdo = $pdo ?? getPdoConnection();

    $sql = '
        SELECT
            u.id,
            u.name AS nombre,
            u.email,
            u.password_hash,
            u.role_id AS rol_id,
            u.is_active AS activo,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.email = :email
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => trim(strtolower($email))]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        return [
            'success' => false,
            'message' => 'Usuario no encontrado.',
        ];
    }

    if ((int) ($usuario['activo'] ?? 0) !== 1) {
        return [
            'success' => false,
            'message' => 'Usuario inactivo.',
        ];
    }

    if (!password_verify($password, (string) ($usuario['password_hash'] ?? ''))) {
        return [
            'success' => false,
            'message' => 'Credenciales invalidas.',
        ];
    }

    $roleName = strtolower((string) ($usuario['role_name'] ?? ''));
    $rolId = isset($usuario['rol_id']) ? (int) $usuario['rol_id'] : 0;

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $usuario['id'];
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['user_role'] = $roleName;
    $_SESSION['rol_id'] = $rolId;
    $_SESSION['user_email'] = (string) ($usuario['email'] ?? '');
    $_SESSION['user_name'] = (string) ($usuario['nombre'] ?? '');
    $_SESSION['permissions'] = array_keys(array_filter(getEffectivePermissionsForUser((int) $usuario['id'])));

    if ($pdo instanceof PDO) {
        $up = $pdo->prepare('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id');
        $up->execute(['id' => (int) $usuario['id']]);
    }

    return [
        'success' => true,
        'message' => 'Autenticacion exitosa.',
        'user' => [
            'id' => (int) $usuario['id'],
            'nombre' => (string) ($usuario['nombre'] ?? ''),
            'email' => (string) ($usuario['email'] ?? ''),
            'rol_id' => $rolId,
            'role_name' => $roleName,
        ],
    ];
}

function autenticarUsuario(string $email, string $password): array
{
    $result = authenticate($email, $password);

    if ($result['success'] ?? false) {
        return ['ok' => true, 'mensaje' => 'Autenticacion exitosa.'];
    }

    return [
        'ok' => false,
        'mensaje' => (string) ($result['message'] ?? 'Credenciales invalidas.'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $payload = readLoginPayload();
    $email = trim((string) ($payload['email'] ?? ''));
    $password = (string) ($payload['password'] ?? '');

    if ($email === '' || $password === '') {
        sendAuthResponse(400, 'error', [], 'Email y password son obligatorios.');
    }

    try {
        $pdo = getPdoConnection();
        $resultado = authenticate($email, $password, $pdo);

        if (!($resultado['success'] ?? false)) {
            try {
                registrarBitacora(
                    'LOGIN_FALLIDO',
                    'AUTH',
                    'Intento de autenticacion fallido.',
                    null,
                    null,
                    $pdo,
                    null,
                    ['email_intento' => strtolower($email)]
                );
            } catch (Throwable $e) {
                // No bloquear login por bitacora.
            }

            usleep(250000);
            sendAuthResponse(401, 'error', [], (string) ($resultado['message'] ?? 'Credenciales invalidas.'));
        }

        try {
            registrarBitacora(
                'LOGIN_EXITOSO',
                'AUTH',
                'Inicio de sesion exitoso.',
                (int) ($resultado['user']['id'] ?? 0),
                null,
                $pdo,
                null,
                ['rol' => (string) ($resultado['user']['role_name'] ?? '')]
            );
        } catch (Throwable $e) {
            // No bloquear login por bitacora.
        }

        sendAuthResponse(200, 'success', [
            'user' => $resultado['user'],
        ], (string) ($resultado['message'] ?? 'Autenticacion exitosa.'));
    } catch (Throwable $e) {
        sendAuthResponse(500, 'error', [], 'Error interno durante autenticacion.');
    }
}
