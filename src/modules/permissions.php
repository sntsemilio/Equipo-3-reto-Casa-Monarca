<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';

function normalizeRoleName(string $roleName): string
{
    $role = strtolower(trim($roleName));

    $map = [
        'administrador' => 'admin',
        'admin' => 'admin',
        'coordinador' => 'coordinador',
        'coordinator' => 'coordinador',
        'supervisor' => 'coordinador',
        'operativo' => 'operativo',
        'operador' => 'operativo',
        'emisor' => 'operativo',
        'voluntario' => 'voluntario',
        'consultor' => 'voluntario',
        'verificador' => 'voluntario',
    ];

    return $map[$role] ?? $role;
}

function roleRequiresCertificate(string $roleName): bool
{
    $normalized = normalizeRoleName($roleName);
    return in_array($normalized, ['admin', 'coordinador'], true);
}

function fetchRoleById(int $roleId): ?array
{
    if ($roleId <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('SELECT id, name, description FROM roles WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $roleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => normalizeRoleName((string) $row['name']),
        'description' => $row['description'] ?? null,
    ];
}

function fetchRoleByName(string $roleName): ?array
{
    $normalized = normalizeRoleName($roleName);
    if ($normalized === '') {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('SELECT id, name, description FROM roles WHERE name = :name LIMIT 1');
    $stmt->execute(['name' => $normalized]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => normalizeRoleName((string) $row['name']),
        'description' => $row['description'] ?? null,
    ];
}

function obtenerRolIdPorNombre(string $rolNombre): ?int
{
    $role = fetchRoleByName($rolNombre);
    if (!$role) {
        return null;
    }

    return (int) $role['id'];
}

function obtenerRolNombrePorId(int $rolId): ?string
{
    $role = fetchRoleById($rolId);
    if (!$role) {
        return null;
    }

    return (string) $role['name'];
}

function listRolesCatalog(): array
{
    $pdo = getPdoConnection();
    $stmt = $pdo->query('SELECT id, name, description FROM roles ORDER BY id ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => normalizeRoleName((string) $row['name']),
            'description' => $row['description'] ?? null,
        ];
    }, $rows);
}

function listPermissionsCatalog(): array
{
    $pdo = getPdoConnection();
    $stmt = $pdo->query('SELECT id, action, description FROM permissions ORDER BY action ASC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'action' => strtolower((string) $row['action']),
            'description' => $row['description'] ?? null,
        ];
    }, $rows);
}

function resolvePermissionId(string $action): ?int
{
    $permission = strtolower(trim($action));
    if ($permission === '') {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('SELECT id FROM permissions WHERE action = :action LIMIT 1');
    $stmt->execute(['action' => $permission]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return (int) $row['id'];
}

function fetchUserRoleById(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $sql = '
        SELECT
            u.id,
            u.role_id,
            r.name AS role_name
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'user_id' => (int) $row['id'],
        'role_id' => (int) $row['role_id'],
        'role_name' => normalizeRoleName((string) $row['role_name']),
    ];
}

function getRolePermissionsMap(int $roleId): array
{
    if ($roleId <= 0) {
        return [];
    }

    $pdo = getPdoConnection();
    $sql = '
        SELECT p.action
        FROM role_permissions rp
        INNER JOIN permissions p ON p.id = rp.permission_id
        WHERE rp.role_id = :role_id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['role_id' => $roleId]);

    $permissions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $action = strtolower((string) ($row['action'] ?? ''));
        if ($action !== '') {
            $permissions[$action] = true;
        }
    }

    return $permissions;
}

function getUserOverridesMap(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $pdo = getPdoConnection();
    $sql = '
        SELECT p.action, up.is_allowed
        FROM user_permissions up
        INNER JOIN permissions p ON p.id = up.permission_id
        WHERE up.user_id = :user_id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);

    $overrides = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $action = strtolower((string) ($row['action'] ?? ''));
        if ($action === '') {
            continue;
        }

        $overrides[$action] = (int) ($row['is_allowed'] ?? 0) === 1;
    }

    return $overrides;
}

function getEffectivePermissionsForUser(int $userId): array
{
    $role = fetchUserRoleById($userId);
    if (!$role) {
        return [];
    }

    $effective = getRolePermissionsMap((int) $role['role_id']);
    $overrides = getUserOverridesMap($userId);

    foreach ($overrides as $action => $isAllowed) {
        $effective[$action] = $isAllowed;
    }

    return $effective;
}

function userHasPermission(int $userId, string $action): bool
{
    if ($userId <= 0) {
        return false;
    }

    $permission = strtolower(trim($action));
    if ($permission === '') {
        return false;
    }

    $effective = getEffectivePermissionsForUser($userId);
    return (bool) ($effective[$permission] ?? false);
}

function userHasAnyPermission(int $userId, array $actions): bool
{
    if ($userId <= 0) {
        return false;
    }

    $effective = getEffectivePermissionsForUser($userId);
    foreach ($actions as $action) {
        $normalized = strtolower(trim((string) $action));
        if ($normalized !== '' && (bool) ($effective[$normalized] ?? false)) {
            return true;
        }
    }

    return false;
}

function updateRolePermissionAssignment(int $actorId, int $roleId, string $action, bool $enabled): void
{
    if ($roleId <= 0) {
        throw new InvalidArgumentException('Rol invalido.', 400);
    }

    $permissionId = resolvePermissionId($action);
    if ($permissionId === null) {
        throw new InvalidArgumentException('Permiso no encontrado.', 404);
    }

    $pdo = getPdoConnection();

    if ($enabled) {
        $stmt = $pdo->prepare('
            INSERT INTO role_permissions (role_id, permission_id, created_at)
            VALUES (:role_id, :permission_id, NOW())
            ON DUPLICATE KEY UPDATE created_at = created_at
        ');
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    } else {
        $stmt = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id');
        $stmt->execute([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    try {
        registrarBitacora(
            'RBAC_ROLE_PERMISSION_UPDATE',
            'RBAC',
            'Actualizacion de permiso por rol.',
            $actorId > 0 ? $actorId : null,
            null,
            $pdo,
            null,
            [
                'role_id' => $roleId,
                'permission_action' => strtolower(trim($action)),
                'enabled' => $enabled,
            ]
        );
    } catch (Throwable $e) {
        // No interrumpir el flujo por bitacora.
    }
}

function updateUserPermissionAssignment(int $actorId, int $userId, string $action, bool $enabled): void
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Usuario invalido.', 400);
    }

    $permissionId = resolvePermissionId($action);
    if ($permissionId === null) {
        throw new InvalidArgumentException('Permiso no encontrado.', 404);
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('
        INSERT INTO user_permissions (user_id, permission_id, is_allowed, created_at, updated_at)
        VALUES (:user_id, :permission_id, :is_allowed, NOW(), NOW())
        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), updated_at = NOW()
    ');
    $stmt->execute([
        'user_id' => $userId,
        'permission_id' => $permissionId,
        'is_allowed' => $enabled ? 1 : 0,
    ]);

    try {
        registrarBitacora(
            'RBAC_USER_PERMISSION_UPDATE',
            'RBAC',
            'Actualizacion de permiso por usuario.',
            $actorId > 0 ? $actorId : null,
            null,
            $pdo,
            null,
            [
                'target_user_id' => $userId,
                'permission_action' => strtolower(trim($action)),
                'enabled' => $enabled,
            ]
        );
    } catch (Throwable $e) {
        // No interrumpir el flujo por bitacora.
    }
}

function buildPermissionsMatrix(): array
{
    $pdo = getPdoConnection();

    $permissions = listPermissionsCatalog();
    $roles = listRolesCatalog();

    $usersStmt = $pdo->query('
        SELECT
            u.id,
            u.name,
            u.email,
            u.role_id,
            r.name AS role_name,
            u.is_active
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        ORDER BY u.id ASC
    ');

    $users = [];
    foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $users[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role_id' => (int) $row['role_id'],
            'role_name' => normalizeRoleName((string) $row['role_name']),
            'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        ];
    }

    $roleMatrix = [];
    $roleStmt = $pdo->query('
        SELECT
            rp.role_id,
            p.action
        FROM role_permissions rp
        INNER JOIN permissions p ON p.id = rp.permission_id
    ');
    foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $roleId = (int) $row['role_id'];
        $action = strtolower((string) $row['action']);
        if (!isset($roleMatrix[$roleId])) {
            $roleMatrix[$roleId] = [];
        }
        $roleMatrix[$roleId][$action] = true;
    }

    $userOverrides = [];
    $userOverrideStmt = $pdo->query('
        SELECT
            up.user_id,
            p.action,
            up.is_allowed
        FROM user_permissions up
        INNER JOIN permissions p ON p.id = up.permission_id
    ');
    foreach ($userOverrideStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userId = (int) $row['user_id'];
        $action = strtolower((string) $row['action']);

        if (!isset($userOverrides[$userId])) {
            $userOverrides[$userId] = [];
        }

        $userOverrides[$userId][$action] = (int) ($row['is_allowed'] ?? 0) === 1;
    }

    $effective = [];
    foreach ($users as $user) {
        $userId = (int) $user['id'];
        $effective[$userId] = getEffectivePermissionsForUser($userId);
    }

    return [
        'roles' => $roles,
        'permissions' => $permissions,
        'users' => $users,
        'role_matrix' => $roleMatrix,
        'user_overrides' => $userOverrides,
        'effective_matrix' => $effective,
    ];
}
