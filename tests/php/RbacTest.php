<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../src/auth/rbac.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function runRbacTests(): array
{
    $results = [];

    $results[] = runTest('rbac_rol_permitido', function (): void {
        $_SESSION['user_role'] = 'admin';
        $ok = Rbac::userHasAnyRole(['admin', 'supervisor']);
        assertSame(true, $ok, 'El rol admin debe estar permitido.');
    });

    $results[] = runTest('rbac_rol_no_permitido', function (): void {
        $_SESSION['user_role'] = 'visitante';
        $ok = Rbac::userHasAnyRole(['admin', 'supervisor']);
        assertSame(false, $ok, 'El rol visitante no debe estar permitido.');
    });

    $results[] = runTest('rbac_sin_rol_en_sesion', function (): void {
        unset($_SESSION['user_role']);
        $ok = Rbac::userHasAnyRole(['admin']);
        assertSame(false, $ok, 'Sin rol en sesion no debe conceder acceso.');
    });

    return $results;
}
