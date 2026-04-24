<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/user_certificates.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Metodo no permitido.',
        'mensaje' => 'Metodo no permitido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

Rbac::requireAuthJson();

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Token de descarga requerido.',
        'mensaje' => 'Token de descarga requerido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $ownerId = peekCertificateDownloadTokenOwner($token);
    if ($ownerId === null || $ownerId <= 0) {
        throw new InvalidArgumentException('Token invalido o expirado.', 404);
    }

    $sessionUserId = (int) (Rbac::userId() ?? 0);
    if ($sessionUserId <= 0 || $sessionUserId !== $ownerId) {
        throw new RuntimeException('No autorizado para descargar este paquete de llaves.', 403);
    }

    $consumed = consumeCertificateDownloadToken(
        $token,
        (string) ($_SERVER['REMOTE_ADDR'] ?? null)
    );

    $bytes = (string) ($consumed['zip_bytes'] ?? '');
    if ($bytes === '') {
        throw new RuntimeException('No fue posible obtener el ZIP solicitado.', 500);
    }

    $downloadName = (string) ($consumed['download_name'] ?? 'certificados.zip');

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) strlen($bytes));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    echo $bytes;
    exit();
} catch (InvalidArgumentException $e) {
    header('Content-Type: application/json');
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code <= 499) ? $code : 400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => $e->getMessage(),
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    header('Content-Type: application/json');
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code <= 599) ? $code : 500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => $e->getMessage(),
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No fue posible completar la descarga segura.',
        'mensaje' => 'No fue posible completar la descarga segura.',
    ], JSON_UNESCAPED_UNICODE);
}
