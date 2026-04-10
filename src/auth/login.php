<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function autenticarUsuario(string $email, string $password): array
{
    $pdo = getPdoConnection();

    $sql = '
        SELECT id, rol_id, password_hash, activo
        FROM usuarios
        WHERE email = :email
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['email' => trim($email)]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        return ['ok' => false, 'mensaje' => 'Credenciales invalidas'];
    }

    if ((int) $usuario['activo'] !== 1) {
        return ['ok' => false, 'mensaje' => 'Usuario desactivado'];
    }

    if (!password_verify($password, (string) $usuario['password_hash'])) {
        return ['ok' => false, 'mensaje' => 'Credenciales invalidas'];
    }

    $_SESSION['usuario_id'] = (int) $usuario['id'];
    $_SESSION['rol_id'] = (int) $usuario['rol_id'];

    return ['ok' => true];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'Email y password son obligatorios']);
        exit();
    }

    $resultado = autenticarUsuario($email, $password);
    if (!$resultado['ok']) {
        http_response_code(401);
    }

    echo json_encode($resultado);
    exit();
}
