<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function verificarPermiso($rol_requerido): void
{
    $rolSesion = $_SESSION['rol_id'] ?? null;

    $permitido = false;
    if (is_array($rol_requerido)) {
        $permitido = in_array($rolSesion, $rol_requerido, true);
    } else {
        $permitido = ((string) $rolSesion === (string) $rol_requerido);
    }

    if (!$permitido) {
        header('HTTP/1.1 403 Forbidden');
        exit();
    }
}
