<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function crearUsuario($datos): array
{
    $camposRequeridos = ['nombre', 'email', 'password', 'rol_id'];
    foreach ($camposRequeridos as $campo) {
        if (!isset($datos[$campo]) || trim((string) $datos[$campo]) === '') {
            return ['ok' => false, 'mensaje' => 'Falta el campo requerido: ' . $campo];
        }
    }

    $pdo = getPdoConnection();

    $sql = '
        INSERT INTO usuarios (nombre, email, password_hash, rol_id, activo, created_at, updated_at)
        VALUES (:nombre, :email, :password_hash, :rol_id, 1, NOW(), NOW())
    ';

    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        'nombre' => trim((string) $datos['nombre']),
        'email' => trim((string) $datos['email']),
        'password_hash' => password_hash((string) $datos['password'], PASSWORD_DEFAULT),
        'rol_id' => (int) $datos['rol_id'],
    ]);

    if (!$ok) {
        return ['ok' => false, 'mensaje' => 'No se pudo crear el usuario'];
    }

    return [
        'ok' => true,
        'usuario_id' => (int) $pdo->lastInsertId(),
    ];
}

function desactivarUsuario($id): bool
{
    $pdo = getPdoConnection();

    $sql = '
        UPDATE usuarios
        SET activo = 0, updated_at = NOW()
        WHERE id = :id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => (int) $id]);

    return $stmt->rowCount() > 0;
}
