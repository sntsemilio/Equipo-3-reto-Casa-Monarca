<?php
declare(strict_types=1);

function registrarAccion($conexion_pdo, $usuario_id, $accion, $entidad_afectada): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $timestamp = date('Y-m-d H:i:s');

    $sql = '
        INSERT INTO bitacora (usuario_id, accion, modulo, detalle, ip_address, created_at)
        VALUES (:usuario_id, :accion, :modulo, :detalle, :ip_address, :created_at)
    ';

    $stmt = $conexion_pdo->prepare($sql);

    return $stmt->execute([
        'usuario_id' => (int) $usuario_id,
        'accion' => (string) $accion,
        'modulo' => (string) $entidad_afectada,
        'detalle' => null,
        'ip_address' => $ip,
        'created_at' => $timestamp,
    ]);
}
