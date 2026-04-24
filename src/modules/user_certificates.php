<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';
require_once __DIR__ . '/permissions.php';

function getCertificateBundlesDir(): string
{
    $configured = getenv('CM_CERT_BUNDLE_DIR');
    if (is_string($configured) && trim($configured) !== '') {
        return rtrim($configured, DIRECTORY_SEPARATOR);
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'casa_monarca_cert_bundles';
}

function ensureCertificateBundlesDir(): string
{
    $dir = getCertificateBundlesDir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo preparar el directorio temporal de certificados.', 500);
    }

    return $dir;
}

function generateUserCertificateMaterial(string $commonName, string $roleName): array
{
    $keyConfig = [
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'private_key_bits' => 2048,
        'digest_alg' => 'sha256',
    ];

    $privateKey = openssl_pkey_new($keyConfig);
    if ($privateKey === false) {
        throw new RuntimeException('No fue posible generar la llave privada RSA.', 500);
    }

    $privatePem = '';
    if (!openssl_pkey_export($privateKey, $privatePem)) {
        throw new RuntimeException('No fue posible exportar la llave privada.', 500);
    }

    $dn = [
        'countryName' => 'MX',
        'organizationName' => 'Casa Monarca',
        'organizationalUnitName' => ucfirst($roleName),
        'commonName' => $commonName,
    ];

    $csr = openssl_csr_new($dn, $privateKey, ['digest_alg' => 'sha256']);
    if ($csr === false) {
        throw new RuntimeException('No fue posible generar la solicitud de certificado (CSR).', 500);
    }

    $x509 = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256']);
    if ($x509 === false) {
        throw new RuntimeException('No fue posible firmar el certificado X.509.', 500);
    }

    $certPem = '';
    if (!openssl_x509_export($x509, $certPem)) {
        throw new RuntimeException('No fue posible exportar el certificado X.509.', 500);
    }

    $parsed = openssl_x509_parse($x509, false);
    $serial = '';
    if (is_array($parsed)) {
        if (isset($parsed['serialNumberHex'])) {
            $serial = (string) $parsed['serialNumberHex'];
        } elseif (isset($parsed['serialNumber'])) {
            $serial = (string) $parsed['serialNumber'];
        }
    }

    return [
        'private_key_pem' => $privatePem,
        'cert_pem' => $certPem,
        'serial' => $serial,
        'cert_sha256' => hash('sha256', $certPem),
    ];
}

function createCertificateZipBundle(int $userId, string $certPem, string $privateKeyPem): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('La extension ZipArchive no esta disponible en el servidor.', 500);
    }

    $dir = ensureCertificateBundlesDir();
    $bundleId = sprintf('user_%d_%s', $userId, bin2hex(random_bytes(8)));
    $zipPath = $dir . DIRECTORY_SEPARATOR . $bundleId . '.zip';

    $zip = new ZipArchive();
    $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) {
        throw new RuntimeException('No fue posible construir el paquete ZIP de llaves.', 500);
    }

    $zip->addFromString('certificado.cer', $certPem);
    $zip->addFromString('llave_privada.key', $privateKeyPem);
    $zip->close();

    @chmod($zipPath, 0600);

    return [
        'zip_path' => $zipPath,
        'download_name' => 'certificados_usuario_' . $userId . '.zip',
    ];
}

function invalidateUserPendingTokens(int $userId, ?PDO $pdo = null): void
{
    if ($userId <= 0) {
        return;
    }

    $pdo = $pdo ?? getPdoConnection();
    $stmt = $pdo->prepare('
        SELECT id, zip_path
        FROM certificate_download_tokens
        WHERE user_id = :user_id
          AND used_at IS NULL
    ');
    $stmt->execute(['user_id' => $userId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $path = (string) ($row['zip_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    $upd = $pdo->prepare('
        UPDATE certificate_download_tokens
        SET used_at = NOW(), key_destroyed_at = NOW()
        WHERE user_id = :user_id
          AND used_at IS NULL
    ');
    $upd->execute(['user_id' => $userId]);
}

function loadUserIdentityForCertificates(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('
        SELECT
            u.id,
            u.name,
            u.email,
            u.role_id,
            r.name AS role_name,
            u.public_cert_serial,
            u.cert_status
        FROM users u
        INNER JOIN roles r ON r.id = u.role_id
        WHERE u.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $userId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'email' => (string) $row['email'],
        'role_id' => (int) $row['role_id'],
        'role_name' => normalizeRoleName((string) $row['role_name']),
        'public_cert_serial' => $row['public_cert_serial'] ?? null,
        'cert_status' => strtolower((string) ($row['cert_status'] ?? 'none')),
    ];
}

function revokeUserCertificate(int $userId, string $reason, ?int $actorId = null, ?PDO $pdo = null): void
{
    $user = loadUserIdentityForCertificates($userId);
    if (!$user) {
        throw new InvalidArgumentException('Usuario no encontrado.', 404);
    }

    $pdo = $pdo ?? getPdoConnection();

    $serial = (string) ($user['public_cert_serial'] ?? '');
    if ($serial !== '') {
        $insert = $pdo->prepare('
            INSERT INTO certificate_revocations (user_id, serial_number, reason, revoked_by, revoked_at, created_at)
            VALUES (:user_id, :serial, :reason, :revoked_by, NOW(), NOW())
        ');
        $insert->execute([
            'user_id' => $userId,
            'serial' => $serial,
            'reason' => $reason,
            'revoked_by' => $actorId,
        ]);
    }

    $update = $pdo->prepare('
        UPDATE users
        SET
            public_cert_pem = NULL,
            public_cert_sha256 = NULL,
            public_cert_serial = NULL,
            cert_status = "revoked",
            cert_revoked_at = NOW(),
            cert_revoked_reason = :reason,
            updated_at = NOW()
        WHERE id = :id
    ');
    $update->execute([
        'id' => $userId,
        'reason' => trim($reason) !== '' ? trim($reason) : 'Revocacion administrativa.',
    ]);

    invalidateUserPendingTokens($userId, $pdo);

    try {
        registrarBitacora(
            'CERT_REVOKED',
            'PKI',
            'Certificado revocado por seguridad.',
            $actorId,
            null,
            $pdo,
            null,
            [
                'target_user_id' => $userId,
                'serial' => $serial !== '' ? $serial : null,
                'reason' => $reason,
            ]
        );
    } catch (Throwable $e) {
        // No bloquear flujo principal.
    }
}

function generateAndStoreUserCertificateBundle(int $userId, ?int $actorId = null): array
{
    $user = loadUserIdentityForCertificates($userId);
    if (!$user) {
        throw new InvalidArgumentException('Usuario no encontrado.', 404);
    }

    if (!roleRequiresCertificate((string) $user['role_name'])) {
        throw new InvalidArgumentException('El rol del usuario no requiere certificado.', 409);
    }

    $pdo = getPdoConnection();
    invalidateUserPendingTokens($userId, $pdo);

    $material = generateUserCertificateMaterial((string) $user['email'], (string) $user['role_name']);
    $bundle = createCertificateZipBundle($userId, (string) $material['cert_pem'], (string) $material['private_key_pem']);

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $updateUser = $pdo->prepare('
            UPDATE users
            SET
                public_cert_pem = :cert_pem,
                public_cert_sha256 = :cert_sha256,
                public_cert_serial = :cert_serial,
                cert_status = "active",
                cert_issued_at = NOW(),
                cert_revoked_at = NULL,
                cert_revoked_reason = NULL,
                updated_at = NOW()
            WHERE id = :id
        ');
        $updateUser->execute([
            'id' => $userId,
            'cert_pem' => $material['cert_pem'],
            'cert_sha256' => $material['cert_sha256'],
            'cert_serial' => $material['serial'],
        ]);

        $insertToken = $pdo->prepare('
            INSERT INTO certificate_download_tokens
                (user_id, token_hash, zip_path, expires_at, created_at)
            VALUES
                (:user_id, :token_hash, :zip_path, :expires_at, NOW())
        ');
        $insertToken->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'zip_path' => $bundle['zip_path'],
            'expires_at' => $expiresAt,
        ]);

        if ($actorId !== null && $actorId > 0) {
            registrarBitacora(
                'CERT_GENERATED',
                'PKI',
                'Certificado emitido y paquete de descarga temporal generado.',
                $actorId,
                null,
                $pdo,
                null,
                [
                    'target_user_id' => $userId,
                    'expires_at' => $expiresAt,
                    'serial' => $material['serial'],
                ]
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (is_file($bundle['zip_path'])) {
            @unlink($bundle['zip_path']);
        }

        throw $e;
    }

    return [
        'download_token' => $token,
        'expires_at' => $expiresAt,
        'download_name' => $bundle['download_name'],
    ];
}

function getLatestUserPendingToken(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('
        SELECT id, expires_at
        FROM certificate_download_tokens
        WHERE user_id = :user_id
          AND used_at IS NULL
          AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute(['user_id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'expires_at' => $row['expires_at'] ?? null,
    ];
}

function peekCertificateDownloadTokenOwner(string $token): ?int
{
    $rawToken = trim($token);
    if ($rawToken === '') {
        return null;
    }

    $hash = hash('sha256', $rawToken);
    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('
        SELECT user_id, used_at, expires_at
        FROM certificate_download_tokens
        WHERE token_hash = :token_hash
        LIMIT 1
    ');
    $stmt->execute(['token_hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    if (!empty($row['used_at'])) {
        return null;
    }

    $expiresAt = (string) ($row['expires_at'] ?? '');
    if ($expiresAt === '') {
        return null;
    }

    if (new DateTimeImmutable('now') >= new DateTimeImmutable($expiresAt)) {
        return null;
    }

    return (int) $row['user_id'];
}

function consumeCertificateDownloadToken(string $token, ?string $ipAddress = null): array
{
    $rawToken = trim($token);
    if ($rawToken === '') {
        throw new InvalidArgumentException('Token de descarga requerido.', 400);
    }

    $tokenHash = hash('sha256', $rawToken);
    $pdo = getPdoConnection();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            SELECT
                id,
                user_id,
                zip_path,
                expires_at,
                used_at
            FROM certificate_download_tokens
            WHERE token_hash = :token_hash
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute(['token_hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Token invalido.', 404);
        }

        if (!empty($row['used_at'])) {
            throw new InvalidArgumentException('El token ya fue utilizado.', 410);
        }

        $expiresAt = (string) ($row['expires_at'] ?? '');
        $now = new DateTimeImmutable('now');
        if ($expiresAt === '' || $now >= new DateTimeImmutable($expiresAt)) {
            $expireStmt = $pdo->prepare('
                UPDATE certificate_download_tokens
                SET used_at = NOW(), key_destroyed_at = NOW(), consumed_ip = :ip
                WHERE id = :id
            ');
            $expireStmt->execute([
                'id' => (int) $row['id'],
                'ip' => $ipAddress,
            ]);

            $path = (string) ($row['zip_path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }

            throw new InvalidArgumentException('El token expiro.', 410);
        }

        $zipPath = (string) ($row['zip_path'] ?? '');
        if ($zipPath === '' || !is_file($zipPath)) {
            throw new RuntimeException('El paquete ZIP ya no esta disponible.', 410);
        }

        $zipBytes = file_get_contents($zipPath);
        if ($zipBytes === false) {
            throw new RuntimeException('No se pudo leer el paquete ZIP.', 500);
        }

        if (!@unlink($zipPath)) {
            throw new RuntimeException('No se pudo destruir el paquete de llaves despues de descargar.', 500);
        }

        $upd = $pdo->prepare('
            UPDATE certificate_download_tokens
            SET used_at = NOW(),
                consumed_ip = :ip,
                key_destroyed_at = NOW()
            WHERE id = :id
        ');
        $upd->execute([
            'id' => (int) $row['id'],
            'ip' => $ipAddress,
        ]);

        try {
            registrarBitacora(
                'CERT_ONE_TIME_DOWNLOAD',
                'PKI',
                'Descarga de certificado consumida y destruccion de artefacto ejecutada.',
                (int) $row['user_id'],
                $ipAddress,
                $pdo,
                null,
                [
                    'token_id' => (int) $row['id'],
                ]
            );
        } catch (Throwable $e) {
            // No bloquear descarga por bitacora.
        }

        $pdo->commit();

        return [
            'download_name' => 'certificados_usuario_' . ((int) $row['user_id']) . '.zip',
            'zip_bytes' => $zipBytes,
            'user_id' => (int) $row['user_id'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function cleanupExpiredCertificateBundles(): int
{
    $pdo = getPdoConnection();
    $stmt = $pdo->query('
        SELECT id, zip_path
        FROM certificate_download_tokens
        WHERE used_at IS NULL
          AND expires_at <= NOW()
    ');

    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($tokens as $token) {
        $path = (string) ($token['zip_path'] ?? '');
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    if (empty($tokens)) {
        return 0;
    }

    $upd = $pdo->prepare('
        UPDATE certificate_download_tokens
        SET used_at = NOW(), key_destroyed_at = NOW()
        WHERE used_at IS NULL
          AND expires_at <= NOW()
    ');
    $upd->execute();

    return count($tokens);
}
