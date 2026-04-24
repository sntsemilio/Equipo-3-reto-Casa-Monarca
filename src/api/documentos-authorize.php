<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth/rbac.php';
require_once __DIR__ . '/../modules/document_authorization.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$action = strtolower(trim((string) ($_POST['action'] ?? 'approve_document')));
$documentId = (int) ($_POST['document_id'] ?? 0);
$keyPassword = (string) ($_POST['key_password'] ?? '');
$revokeReason = trim((string) ($_POST['revoke_reason'] ?? ''));

if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'document_id invalido.',
        'mensaje' => 'document_id invalido.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ($action === 'approve_document' || $action === 'approve') {
    Rbac::requirePermissionJson('approve_documents');
} elseif ($action === 'emit_document' || $action === 'emit') {
    Rbac::requirePermissionJson('sign_documents');
} elseif ($action === 'revoke_document' || $action === 'revoke') {
    Rbac::requirePermissionJson('revoke_documents');
} else {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Accion restringida invalida.',
        'mensaje' => 'Accion restringida invalida.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$certFile = $_FILES['cer_file'] ?? null;
$keyFile = $_FILES['key_file'] ?? null;

if (!is_array($certFile) || !is_array($keyFile)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Debe enviar cer_file y key_file en multipart/form-data.',
        'mensaje' => 'Debe enviar cer_file y key_file en multipart/form-data.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

if ((int) ($certFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($keyFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'Error al cargar archivos de certificado/llave.',
        'mensaje' => 'Error al cargar archivos de certificado/llave.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

$certPem = file_get_contents((string) ($certFile['tmp_name'] ?? ''));
$keyPem = file_get_contents((string) ($keyFile['tmp_name'] ?? ''));
if ($certPem === false || $keyPem === false) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No se pudieron leer los archivos cargados.',
        'mensaje' => 'No se pudieron leer los archivos cargados.',
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

try {
    $result = authorizeDocumentActionWithCertificates(
        (int) (Rbac::userId() ?? 0),
        $documentId,
        $action,
        (string) $certPem,
        (string) $keyPem,
        $keyPassword,
        $revokeReason
    );

    echo json_encode([
        'status' => 'success',
        'data' => $result,
        'message' => 'Autorizacion criptografica valida. Accion ejecutada.',
        'mensaje' => 'Autorizacion criptografica valida. Accion ejecutada.',
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code <= 499) ? $code : 400);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => $e->getMessage(),
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    $code = $e->getCode();
    http_response_code(($code >= 400 && $code <= 599) ? $code : 403);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => $e->getMessage(),
        'mensaje' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'data' => [],
        'message' => 'No fue posible completar la autorizacion segura del documento.',
        'mensaje' => 'No fue posible completar la autorizacion segura del documento.',
    ], JSON_UNESCAPED_UNICODE);
}
