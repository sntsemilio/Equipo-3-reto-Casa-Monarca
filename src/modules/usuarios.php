<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/user_certificates.php';

function validarPasswordSegura(string $password): void
{
    if (strlen($password) < 10) {
        throw new InvalidArgumentException('La contrasena debe tener al menos 10 caracteres.', 400);
    }

    if (
        !preg_match('/[A-Z]/', $password)
        || !preg_match('/[a-z]/', $password)
        || !preg_match('/\d/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)
    ) {
        throw new InvalidArgumentException('La contrasena debe incluir mayusculas, minusculas, numeros y simbolos.', 400);
    }
}

function mapearUsuario(array $row): array
{
    $rol = strtolower((string) ($row['rol_nombre'] ?? $row['role_name'] ?? 'voluntario'));

    return [
        'id' => (int) ($row['id'] ?? 0),
        'nombre' => (string) ($row['nombre'] ?? $row['name'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'rol_id' => (int) ($row['rol_id'] ?? $row['role_id'] ?? 0),
        'rol_nombre' => normalizeRoleName($rol),
        'activo' => ((int) ($row['activo'] ?? $row['is_active'] ?? 0) === 1),
        'ultimo_login' => $row['ultimo_login'] ?? $row['last_login_at'] ?? null,
        'public_cert_sha256' => $row['public_cert_sha256'] ?? null,
        'cert_status' => strtolower((string) ($row['cert_status'] ?? 'none')),
        'cert_issued_at' => $row['cert_issued_at'] ?? null,
        'cert_revoked_at' => $row['cert_revoked_at'] ?? null,
        'cert_revoked_reason' => $row['cert_revoked_reason'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function obtenerUsuarioPorId(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $sql = '
        SELECT
            u.id,
            u.name AS nombre,
            u.email,
            u.role_id AS rol_id,
            r.name AS rol_nombre,
            u.is_active AS activo,
            u.last_login_at AS ultimo_login,
            u.public_cert_sha256,
            u.cert_status,
            u.cert_issued_at,
            u.cert_revoked_at,
            u.cert_revoked_reason,
            u.created_at,
            u.updated_at
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? mapearUsuario($row) : null;
}

function registrarUsuario(
    array $datos,
    ?int $registradoPor = null,
    bool $permitirAdministrador = false
): array {
    $nombre = trim((string) ($datos['nombre'] ?? $datos['name'] ?? ''));
    $email = strtolower(trim((string) ($datos['email'] ?? '')));
    $password = (string) ($datos['password'] ?? '');
    $rolNombre = normalizeRoleName((string) ($datos['rol'] ?? $datos['rol_nombre'] ?? 'voluntario'));

    if ($nombre === '' || $email === '' || $password === '') {
        throw new InvalidArgumentException('Nombre, email y contrasena son obligatorios.', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('El email no tiene formato valido.', 400);
    }

    if (!$permitirAdministrador && in_array($rolNombre, ['admin', 'coordinador'], true)) {
        throw new InvalidArgumentException('No se permite registro publico con rol privilegiado.', 403);
    }

    if (!in_array($rolNombre, ['admin', 'coordinador', 'operativo', 'voluntario'], true)) {
        throw new InvalidArgumentException('El rol proporcionado no es valido.', 400);
    }

    validarPasswordSegura($password);

    $rolId = obtenerRolIdPorNombre($rolNombre);
    if ($rolId === null) {
        throw new InvalidArgumentException('El rol seleccionado no existe.', 400);
    }

    $pdo = getPdoConnection();

    $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existsStmt->execute(['email' => $email]);
    if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
        throw new InvalidArgumentException('Ya existe una cuenta registrada con ese email.', 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = '
        INSERT INTO users (name, email, password_hash, role_id, is_active, created_at, updated_at)
        VALUES (:name, :email, :password_hash, :role_id, 1, NOW(), NOW())
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'name' => $nombre,
        'email' => $email,
        'password_hash' => $hash,
        'role_id' => $rolId,
    ]);

    $usuarioId = (int) $pdo->lastInsertId();
    $keyDelivery = null;

    if (roleRequiresCertificate($rolNombre)) {
        $keyDelivery = generateAndStoreUserCertificateBundle($usuarioId, $registradoPor);
    }

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
                'certificado_emitido' => $keyDelivery !== null,
            ]
        );
    } catch (Throwable $e) {
        // Ignorar errores de bitacora para no interrumpir registro.
    }

    $creado = obtenerUsuarioPorId($usuarioId);
    if (!$creado) {
        throw new RuntimeException('No fue posible recuperar el usuario creado.', 500);
    }

    $creado['key_delivery'] = $keyDelivery;
    return $creado;
}

function listarUsuarios(): array
{
    $pdo = getPdoConnection();

    $sql = '
        SELECT
            u.id,
            u.name AS nombre,
            u.email,
            u.role_id AS rol_id,
            r.name AS rol_nombre,
            u.is_active AS activo,
            u.last_login_at AS ultimo_login,
            u.public_cert_sha256,
            u.cert_status,
            u.cert_issued_at,
            u.cert_revoked_at,
            u.cert_revoked_reason,
            u.created_at,
            u.updated_at
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
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
        UPDATE users
        SET is_active = 0, updated_at = NOW()
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

function cambiarRolUsuario(int $targetId, string $nuevoRol, ?int $actorId = null): array
{
    if ($targetId <= 0) {
        throw new InvalidArgumentException('ID de usuario invalido.', 400);
    }

    $normalizedRole = normalizeRoleName($nuevoRol);
    if (!in_array($normalizedRole, ['admin', 'coordinador', 'operativo', 'voluntario'], true)) {
        throw new InvalidArgumentException('Rol no valido.', 400);
    }

    $actual = obtenerUsuarioPorId($targetId);
    if (!$actual) {
        throw new InvalidArgumentException('Usuario no encontrado.', 404);
    }

    if ((int) $actual['id'] === (int) ($actorId ?? 0) && normalizeRoleName((string) ($actual['rol_nombre'] ?? '')) !== $normalizedRole) {
        throw new InvalidArgumentException('No puedes cambiar tu propio rol.', 403);
    }

    $rolId = obtenerRolIdPorNombre($normalizedRole);
    if ($rolId === null) {
        throw new InvalidArgumentException('El rol seleccionado no existe.', 400);
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('UPDATE users SET role_id = :role_id, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        'role_id' => $rolId,
        'id' => $targetId,
    ]);

    if ($stmt->rowCount() === 0 && normalizeRoleName((string) ($actual['rol_nombre'] ?? '')) === $normalizedRole) {
        return [
            'user' => $actual,
            'key_delivery' => null,
        ];
    }

    $keyDelivery = null;
    $oldRole = normalizeRoleName((string) ($actual['rol_nombre'] ?? ''));
    $wasPrivileged = roleRequiresCertificate($oldRole);
    $nowPrivileged = roleRequiresCertificate($normalizedRole);

    if ($wasPrivileged && !$nowPrivileged) {
        revokeUserCertificate($targetId, 'Revocado por descenso de privilegios de rol.', $actorId, $pdo);
    }

    if ($nowPrivileged) {
        if ($wasPrivileged) {
            revokeUserCertificate($targetId, 'Rotacion de certificado por cambio/confirmacion de rol.', $actorId, $pdo);
        }

        $keyDelivery = generateAndStoreUserCertificateBundle($targetId, $actorId);
    }

    try {
        registrarBitacora(
            'CAMBIO_ROL_USUARIO',
            'AUTH',
            'Cambio de rol aplicado.',
            $actorId,
            null,
            $pdo,
            null,
            [
                'target_user_id' => $targetId,
                'old_role' => $oldRole,
                'new_role' => $normalizedRole,
                'certificado_emitido' => $keyDelivery !== null,
            ]
        );
    } catch (Throwable $e) {
        // No bloquear flujo principal.
    }

    $updated = obtenerUsuarioPorId($targetId);
    if (!$updated) {
        throw new RuntimeException('No fue posible recuperar el usuario actualizado.', 500);
    }

    return [
        'user' => $updated,
        'key_delivery' => $keyDelivery,
    ];
}
