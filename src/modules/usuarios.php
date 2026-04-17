<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';

function obtenerRolIdPorNombre(string $rolNombre): ?int
{
    $rol = strtolower(trim($rolNombre));
    if ($rol === '') {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('SELECT id FROM roles WHERE nombre = :nombre LIMIT 1');
    $stmt->execute(['nombre' => $rol]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return (int) $row['id'];
}

function obtenerRolNombrePorId(int $rolId): ?string
{
    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('SELECT nombre FROM roles WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $rolId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return strtolower((string) $row['nombre']);
}

function validarPasswordSegura(string $password): void
{
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('La contrasena debe tener al menos 8 caracteres.', 400);
    }

    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        throw new InvalidArgumentException('La contrasena debe incluir mayusculas, minusculas y numeros.', 400);
    }
}

function mapearUsuario(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'nombre' => (string) ($row['nombre'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'rol_id' => (int) ($row['rol_id'] ?? 0),
        'rol_nombre' => strtolower((string) ($row['rol_nombre'] ?? '')),
        'activo' => ((int) ($row['activo'] ?? 0) === 1),
        'ultimo_login' => $row['ultimo_login'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function registrarUsuario(
    array $datos,
    ?int $registradoPor = null,
    bool $permitirAdministrador = false
): array {
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $email = strtolower(trim((string) ($datos['email'] ?? '')));
    $password = (string) ($datos['password'] ?? '');
    $rolNombre = strtolower(trim((string) ($datos['rol'] ?? $datos['rol_nombre'] ?? 'consultor')));

    if ($nombre === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Nombre, email y contrasena son obligatorios.', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El email no tiene formato valido.', 400);
    }

    if (!$permitirAdministrador && $rolNombre === 'administrador') {
        throw new InvalidArgumentException('No se permite registro publico con rol administrador.', 403);
    }

    if (!in_array($rolNombre, ['emisor', 'consultor', 'administrador'], true)) {
        throw new InvalidArgumentException('El rol proporcionado no es valido.', 400);
    }

    validarPasswordSegura($password);

    $rolId = obtenerRolIdPorNombre($rolNombre);
    if ($rolId === null) {
        throw new InvalidArgumentException('El rol seleccionado no existe.', 400);
    }

    $pdo = getPdoConnection();

    $existsStmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
    $existsStmt->execute(['email' => $email]);
    if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new InvalidArgumentException('Ya existe una cuenta registrada con ese email.', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = '
        INSERT INTO usuarios (nombre, email, password_hash, rol_id, activo, created_at, updated_at)
        VALUES (:nombre, :email, :password_hash, :rol_id, 1, NOW(), NOW())
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'nombre' => $nombre,
        'email' => $email,
        'password_hash' => $hash,
        'rol_id' => $rolId,
    ]);

    $usuarioId = (int) $pdo->lastInsertId();

    try {
        registrarBitacora(
            'ALTA_USUARIO',
            'AUTH',
            'Nuevo usuario registrado',
            $registradoPor,
            null,
            $pdo,
            null,
            [
                'usuario_id_nuevo' => $usuarioId,
                'email_nuevo' => $email,
                'rol_nuevo' => $rolNombre,
            ]
        );
    } catch (Throwable $e) {
        // Ignorar errores de bitacora para no interrumpir registro.
    }

    return [
        'id' => $usuarioId,
        'nombre' => $nombre,
        'email' => $email,
        'rol_id' => $rolId,
        'rol_nombre' => $rolNombre,
        'activo' => true,
    ];
}

function listarUsuarios(): array
{
    $pdo = getPdoConnection();

    $sql = '
        SELECT
            u.id,
            u.nombre,
            u.email,
            u.rol_id,
            r.nombre AS rol_nombre,
            u.activo,
            u.ultimo_login,
            u.created_at,
            u.updated_at
        FROM usuarios u
        INNER JOIN roles r ON r.id = u.rol_id
        ORDER BY u.id ASC
    ';

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map('mapearUsuario', $rows);
}

function desactivarUsuario(int $id, ?int $actorId = null): bool
{
    if ($actorId !== null && $actorId > 0 && $actorId === $id) {
        throw new InvalidArgumentException('No puedes desactivar tu propia cuenta.', 409);
    }

    $pdo = getPdoConnection();

    $sql = '
        UPDATE usuarios
        SET activo = 0, updated_at = NOW()
        WHERE id = :id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $ok = $stmt->rowCount() > 0;

    if ($ok) {
        try {
            registrarBitacora(
                'DESACTIVAR_USUARIO',
                'AUTH',
                'Usuario desactivado por politica de acceso.',
                $actorId,
                null,
                $pdo,
                null,
                ['usuario_id_afectado' => $id]
            );
        } catch (Throwable $e) {
            // Ignorar fallas de bitacora.
        }
    }

    return $ok;
}
