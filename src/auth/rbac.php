<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/permissions.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

final class Rbac
{
    public const ROLE_ADMIN       = 'admin';
    public const ROLE_COORDINADOR = 'coordinador';
    public const ROLE_OPERATIVO   = 'operativo';
    public const ROLE_VOLUNTARIO  = 'voluntario';

    private static function normalizeRole(string $role): string
    {
        return normalizeRoleName($role);
    }

    public static function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']) || isset($_SESSION['usuario_id']);
    }

    public static function userId(): ?int
    {
        if (isset($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        if (isset($_SESSION['usuario_id'])) {
            return (int) $_SESSION['usuario_id'];
        }

        return null;
    }

    public static function userRole(): ?string
    {
        if (isset($_SESSION['user_role'])) {
            return self::normalizeRole((string) $_SESSION['user_role']);
        }

        if (isset($_SESSION['rol_id'])) {
            $rolId = (int) $_SESSION['rol_id'];
            $roleName = obtenerRolNombrePorId($rolId);
            if (is_string($roleName) && $roleName !== '') {
                return self::normalizeRole($roleName);
            }
        }

        return null;
    }

    public static function userHasAnyRole(array $roles): bool
    {
        $sessionRole = self::userRole();
        if ($sessionRole === null) {
            return false;
        }

        $normalized = array_map(static fn($role): string => self::normalizeRole((string) $role), $roles);
        return in_array($sessionRole, $normalized, true);
    }

    public static function refreshSessionPermissions(): void
    {
        $userId = self::userId();
        if ($userId === null || $userId <= 0) {
            $_SESSION['permissions'] = [];
            return;
        }

        $effective = getEffectivePermissionsForUser($userId);
        $actions = [];
        foreach ($effective as $action => $allowed) {
            if ($allowed === true) {
                $actions[] = (string) $action;
            }
        }

        sort($actions);
        $_SESSION['permissions'] = $actions;
    }

    public static function userPermissions(): array
    {
        if (!self::isAuthenticated()) {
            return [];
        }

        $cached = $_SESSION['permissions'] ?? null;
        if (is_array($cached)) {
            return array_values(array_map(static fn($v): string => strtolower((string) $v), $cached));
        }

        self::refreshSessionPermissions();
        $reloaded = $_SESSION['permissions'] ?? [];
        return is_array($reloaded)
            ? array_values(array_map(static fn($v): string => strtolower((string) $v), $reloaded))
            : [];
    }

    public static function userHasPermission(string $permission): bool
    {
        $normalized = strtolower(trim($permission));
        if ($normalized === '') {
            return false;
        }

        $permissions = self::userPermissions();
        return in_array($normalized, $permissions, true);
    }

    public static function userHasAnyPermission(array $permissions): bool
    {
        $available = self::userPermissions();
        if (empty($available)) {
            return false;
        }

        foreach ($permissions as $permission) {
            $normalized = strtolower(trim((string) $permission));
            if ($normalized !== '' && in_array($normalized, $available, true)) {
                return true;
            }
        }

        return false;
    }

    public static function requireAuthJson(): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        self::sendJsonError(401, 'Sesion no autorizada.');
    }

    public static function requireRoleJson(array $roles): void
    {
        self::requireAuthJson();

        if (self::userHasAnyRole($roles)) {
            return;
        }

        self::sendJsonError(403, 'No cuentas con permisos para esta operacion.');
    }

    public static function requirePermissionJson($permission): void
    {
        self::requireAuthJson();

        if (is_array($permission)) {
            if (self::userHasAnyPermission($permission)) {
                return;
            }
        } else {
            if (self::userHasPermission((string) $permission)) {
                return;
            }
        }

        self::sendJsonError(403, 'No cuentas con permisos para esta operacion.');
    }

    public static function sendJsonError(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'data' => [],
            'message' => $message,
            'mensaje' => $message,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}

function verificarPermiso($rol_requerido): void
{
    $roles = is_array($rol_requerido) ? $rol_requerido : [$rol_requerido];
    $normalized = array_map(static fn($role): string => normalizeRoleName((string) $role), $roles);

    if (!Rbac::userHasAnyRole($normalized)) {
        header('HTTP/1.1 403 Forbidden');
        exit();
    }
}
