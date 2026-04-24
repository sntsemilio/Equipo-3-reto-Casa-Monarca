<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';
require_once __DIR__ . '/documentos.php';

function normalizePemContent(string $pem): string
{
    return preg_replace('/\s+/', '', trim($pem)) ?? '';
}

function validatePrivateKeyMatchesCertificate(string $certPem, string $privateKeyPem, string $keyPassword = ''): bool
{
    $certPublicKey = openssl_pkey_get_public($certPem);
    if ($certPublicKey === false) {
        return false;
    }

    $privateKey = $keyPassword === ''
        ? openssl_pkey_get_private($privateKeyPem)
        : openssl_pkey_get_private($privateKeyPem, $keyPassword);

    if ($privateKey === false) {
        return false;
    }

    $certDetails = openssl_pkey_get_details($certPublicKey);
    $privateDetails = openssl_pkey_get_details($privateKey);

    $certPublicPem = (string) ($certDetails['key'] ?? '');
    $privatePublicPem = (string) ($privateDetails['key'] ?? '');

    if ($certPublicPem === '' || $privatePublicPem === '') {
        return false;
    }

    return hash_equals(normalizePemContent($certPublicPem), normalizePemContent($privatePublicPem));
}

function loadUserCertificateSnapshot(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('
        SELECT
            public_cert_pem,
            public_cert_sha256,
            cert_status
        FROM users
        WHERE id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'public_cert_pem' => (string) ($row['public_cert_pem'] ?? ''),
        'public_cert_sha256' => (string) ($row['public_cert_sha256'] ?? ''),
        'cert_status' => strtolower((string) ($row['cert_status'] ?? 'none')),
    ];
}

function authorizeDocumentActionWithCertificates(
    int $userId,
    int $documentId,
    string $action,
    string $certPem,
    string $privateKeyPem,
    string $keyPassword = '',
    string $revokeReason = ''
): array {
    if ($userId <= 0 || $documentId <= 0) {
        throw new InvalidArgumentException('Parametros de autorizacion invalidos.', 400);
    }

    $snapshot = loadUserCertificateSnapshot($userId);
    if (!$snapshot) {
        throw new InvalidArgumentException('Usuario no encontrado.', 404);
    }

    if (($snapshot['cert_status'] ?? 'none') !== 'active') {
        throw new RuntimeException('El certificado del usuario no esta activo.', 403);
    }

    $uploadedCertNormalized = normalizePemContent($certPem);
    if ($uploadedCertNormalized === '') {
        throw new InvalidArgumentException('El certificado proporcionado es invalido.', 400);
    }

    $storedCertPem = (string) ($snapshot['public_cert_pem'] ?? '');
    $storedCertHash = (string) ($snapshot['public_cert_sha256'] ?? '');
    if ($storedCertPem === '' || $storedCertHash === '') {
        throw new RuntimeException('No existe certificado publico vigente para este usuario.', 403);
    }

    $uploadedCertHash = hash('sha256', $certPem);
    if (!hash_equals($storedCertHash, $uploadedCertHash)) {
        throw new RuntimeException('El certificado cargado no coincide con el registrado para el usuario.', 403);
    }

    if (!validatePrivateKeyMatchesCertificate($certPem, $privateKeyPem, $keyPassword)) {
        throw new RuntimeException('La llave privada no corresponde al certificado proporcionado.', 403);
    }

    $normalizedAction = strtolower(trim($action));
    $evidenceHash = hash('sha256', $uploadedCertHash . '|' . $documentId . '|' . $normalizedAction . '|' . microtime(true));

    if ($normalizedAction === 'approve_document' || $normalizedAction === 'approve') {
        $documento = aprobarDocumento($documentId, $userId, $evidenceHash);
    } elseif ($normalizedAction === 'emit_document' || $normalizedAction === 'emit') {
        $documento = emitirDocumento($documentId, $userId);
    } elseif ($normalizedAction === 'revoke_document' || $normalizedAction === 'revoke') {
        $documento = revocarDocumento($documentId, $userId, $revokeReason);
    } else {
        throw new InvalidArgumentException('Accion restringida no soportada.', 400);
    }

    try {
        registrarBitacora(
            'DOCUMENT_ACTION_CERT_AUTHORIZED',
            'DOCUMENTOS',
            'Accion restringida autorizada con .cer/.key.',
            $userId,
            null,
            null,
            $documentId,
            [
                'action' => $normalizedAction,
                'cert_hash' => $uploadedCertHash,
                'evidence_hash' => $evidenceHash,
            ]
        );
    } catch (Throwable $e) {
        // No romper el flujo por bitacora.
    }

    // Limpieza explicita de variables sensibles en memoria de proceso.
    $certPem = '';
    $privateKeyPem = '';
    $keyPassword = '';
    unset($certPem, $privateKeyPem, $keyPassword);

    return [
        'documento' => $documento,
        'evidence_hash' => $evidenceHash,
    ];
}
