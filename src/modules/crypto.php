<?php
declare(strict_types=1);

function getInstitutionalKeysDir(): string
{
    $envDir = getenv('CM_KEYS_DIR');
    if (is_string($envDir) && trim($envDir) !== '') {
        return rtrim($envDir, DIRECTORY_SEPARATOR);
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'casa_monarca_keys';
}

function getInstitutionalPrivateKeyPath(): string
{
    $envPath = getenv('CM_PRIVATE_KEY_PATH');
    if (is_string($envPath) && trim($envPath) !== '') {
        return $envPath;
    }

    return getInstitutionalKeysDir() . DIRECTORY_SEPARATOR . 'institutional_private.pem';
}

function getInstitutionalPublicKeyPath(): string
{
    $envPath = getenv('CM_PUBLIC_KEY_PATH');
    if (is_string($envPath) && trim($envPath) !== '') {
        return $envPath;
    }

    return getInstitutionalKeysDir() . DIRECTORY_SEPARATOR . 'institutional_public.pem';
}

function ensureInstitutionalKeyPair(): void
{
    $privatePath = getInstitutionalPrivateKeyPath();
    $publicPath = getInstitutionalPublicKeyPath();

    if (is_file($privatePath) && is_file($publicPath)) {
        return;
    }

    $dir = dirname($privatePath);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('No fue posible crear el directorio de llaves institucionales.', 500);
    }

    $config = [
        'private_key_bits' => 3072,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $resource = openssl_pkey_new($config);
    if ($resource === false) {
        throw new RuntimeException('No fue posible generar la llave privada institucional.', 500);
    }

    $privatePem = '';
    $privateExported = openssl_pkey_export($resource, $privatePem);
    if ($privateExported === false || $privatePem === '') {
        throw new RuntimeException('No fue posible exportar la llave privada institucional.', 500);
    }

    $details = openssl_pkey_get_details($resource);
    $publicPem = (string) ($details['key'] ?? '');
    if ($publicPem === '') {
        throw new RuntimeException('No fue posible obtener la llave publica institucional.', 500);
    }

    file_put_contents($privatePath, $privatePem);
    file_put_contents($publicPath, $publicPem);

    @chmod($privatePath, 0600);
    @chmod($publicPath, 0644);
}

function getInstitutionalPrivateKeyResource()
{
    ensureInstitutionalKeyPair();

    $privatePath = getInstitutionalPrivateKeyPath();
    $privatePem = file_get_contents($privatePath);
    if ($privatePem === false || trim($privatePem) === '') {
        throw new RuntimeException('No se pudo cargar la llave privada institucional.', 500);
    }

    $resource = openssl_pkey_get_private($privatePem);
    if ($resource === false) {
        throw new RuntimeException('La llave privada institucional no es valida.', 500);
    }

    return $resource;
}

function getInstitutionalPublicKeyPem(): string
{
    ensureInstitutionalKeyPair();

    $publicPath = getInstitutionalPublicKeyPath();
    $publicPem = file_get_contents($publicPath);
    if ($publicPem === false || trim($publicPem) === '') {
        throw new RuntimeException('No se pudo cargar la llave publica institucional.', 500);
    }

    return $publicPem;
}

function signDocumentHash(string $hashSha256): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', strtolower($hashSha256))) {
        throw new InvalidArgumentException('El hash para firmar no es valido.', 400);
    }

    $privateKey = getInstitutionalPrivateKeyResource();
    $signature = '';
    $ok = openssl_sign($hashSha256, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if ($ok !== true || $signature === '') {
        throw new RuntimeException('No se pudo firmar el documento.', 500);
    }

    $publicPem = getInstitutionalPublicKeyPem();
    $fingerprint = hash('sha256', $publicPem);

    return [
        'firma_base64' => base64_encode($signature),
        'algoritmo_firma' => 'RSA-SHA256',
        'llave_publica_sha256' => $fingerprint,
    ];
}

function verifyDocumentHashSignature(string $hashSha256, string $signatureBase64): bool
{
    if ($hashSha256 === '' || $signatureBase64 === '') {
        return false;
    }

    $signatureRaw = base64_decode($signatureBase64, true);
    if ($signatureRaw === false) {
        return false;
    }

    $publicPem = getInstitutionalPublicKeyPem();
    $publicKey = openssl_pkey_get_public($publicPem);
    if ($publicKey === false) {
        return false;
    }

    $result = openssl_verify($hashSha256, $signatureRaw, $publicKey, OPENSSL_ALGO_SHA256);
    return $result === 1;
}

function generarQrToken(): string
{
    return bin2hex(random_bytes(32));
}
