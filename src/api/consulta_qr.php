<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$folio = isset($_GET['folio']) ? trim((string) $_GET['folio']) : '';
if ($folio === '') {
    echo json_encode(['estatus' => 'no_encontrado']);
    exit();
}

$pdo = getPdoConnection();

$sql = '
    SELECT estado AS estatus
    FROM documentos
    WHERE id = :folio
    LIMIT 1
';
$stmt = $pdo->prepare($sql);
$stmt->execute(['folio' => (int) $folio]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !isset($row['estatus'])) {
    echo json_encode(['estatus' => 'no_encontrado']);
    exit();
}

$estatus = strtolower((string) $row['estatus']);
if ($estatus === 'revocado') {
    echo json_encode(['estatus' => 'revocado']);
    exit();
}

if ($estatus === 'emitido' || $estatus === 'valido') {
    echo json_encode(['estatus' => 'valido']);
    exit();
}

echo json_encode(['estatus' => 'no_encontrado']);
exit();
