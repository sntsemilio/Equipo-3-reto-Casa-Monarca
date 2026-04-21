<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

final class Rbac
{
    public const ROLE_ADMINISTRADOR = 'administrador';
    public const ROLE_SUPERVISOR    = 'supervisor';
    public const ROLE_EMISOR        = 'emisor';
    public const ROLE_VERIFICADOR   = 'verificador';
    public const ROLE_CONSULTOR     = 'consultor';

    private static function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));

        if ($normalized === 'admin') {
            return self::ROLE_ADMINISTRADOR;
        }

        if ($normalized === 'operador') {
            return self::ROLE_EMISOR;
        }

        return $normalized;
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
            if ($rolId === 1) {
                return self::ROLE_ADMINISTRADOR;
            }

            if ($rolId === 2) {
                return self::ROLE_EMISOR;
            }

            if ($rolId === 3) {
                return self::ROLE_CONSULTOR;
            }

            if ($rolId === 4) {
                return self::ROLE_SUPERVISOR;
            }

            if ($rolId === 5) {
                return self::ROLE_VERIFICADOR;
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
    $normalized = array_map(static function ($role): string {
        $value = strtolower(trim((string) $role));

        if ($value === '1') {
            return Rbac::ROLE_ADMINISTRADOR;
        }

        if ($value === '2') {
            return Rbac::ROLE_EMISOR;
        }

        if ($value === '3') {
            return Rbac::ROLE_CONSULTOR;
        }

        return $value;
    }, $roles);

    if (!Rbac::userHasAnyRole($normalized)) {
        header('HTTP/1.1 403 Forbidden');
        exit();
    }
}
