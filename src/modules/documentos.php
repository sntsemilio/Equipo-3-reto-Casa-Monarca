<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function revocarDocumento($folio): bool
{
    $pdo = getPdoConnection();

    $sql = "
        UPDATE documentos
        SET estado = 'revocado', updated_at = NOW()
        WHERE id = :folio
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['folio' => (int) $folio]);

    return $stmt->rowCount() > 0;
}
