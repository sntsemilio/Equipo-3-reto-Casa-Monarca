<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function registrarBitacora(
    string $accion,
    string $modulo,
    ?string $detalle = null,
    ?int $usuarioId = null,
    ?string $ipAddress = null,
    $pdo = null,
    ?int $documentoId = null,
    array $contexto = []
): bool
{
    $pdo = $pdo ?? getPdoConnection();

    $ip = $ipAddress ?: (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-agent'), 0, 255);
    $contextoJson = empty($contexto) ? null : json_encode($contexto, JSON_UNESCAPED_UNICODE);
    $timestamp = date('Y-m-d H:i:s');

    $sql = '
        INSERT INTO bitacora
            (usuario_id, accion, modulo, documento_id, detalle, ip_address, user_agent, contexto_json, created_at)
        VALUES
            (:usuario_id, :accion, :modulo, :documento_id, :detalle, :ip_address, :user_agent, :contexto_json, :created_at)
    ';

    $stmt = $pdo->prepare($sql);

    return $stmt->execute([
        'usuario_id' => $usuarioId,
        'accion' => (string) $accion,
        'modulo' => (string) $modulo,
        'documento_id' => $documentoId,
        'detalle' => $detalle,
        'ip_address' => $ip,
        'user_agent' => $agent,
        'contexto_json' => $contextoJson,
        'created_at' => $timestamp,
    ]);
}

function registrarAccion($conexion_pdo, $usuario_id, $accion, $entidad_afectada): bool
{
    return registrarBitacora(
        (string) $accion,
        (string) $entidad_afectada,
        null,
        (int) $usuario_id,
        null,
        $conexion_pdo
    );
}

function obtenerBitacora(int $limit = 100, ?int $documentoId = null): array
{
    $pdo = getPdoConnection();
    $safeLimit = max(1, min($limit, 300));

    $sql = '
        SELECT
            b.id,
            b.accion,
            b.modulo,
            b.documento_id,
            b.detalle,
            b.ip_address,
            b.user_agent,
            b.contexto_json,
            b.created_at,
            u.email AS usuario_email,
            u.nombre AS usuario_nombre
        FROM bitacora b
        LEFT JOIN usuarios u ON u.id = b.usuario_id
    ';

    if ($documentoId !== null && $documentoId > 0) {
        $sql .= ' WHERE b.documento_id = :documento_id';
    }

    $sql .= ' ORDER BY b.id DESC LIMIT :lim';

    $stmt = $pdo->prepare($sql);
    if ($documentoId !== null && $documentoId > 0) {
        $stmt->bindValue(':documento_id', $documentoId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', $safeLimit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['contexto_json'] = $row['contexto_json'] ? json_decode((string) $row['contexto_json'], true) : null;
    }

    return $rows;
}
