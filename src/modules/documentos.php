<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/bitacora.php';
require_once __DIR__ . '/crypto.php';

function normalizarIdDocumento(string $folio): ?int
{
    $folio = trim($folio);
    if ($folio === '') {
        return null;
    }

    if (ctype_digit($folio)) {
        $id = (int) $folio;
        return $id > 0 ? $id : null;
    }

    if (preg_match('/(\d+)$/', $folio, $matches) !== 1) {
        return null;
    }

    $id = (int) ltrim($matches[1], '0');
    return $id > 0 ? $id : null;
}

function formatearFolioDocumento(int $id): string
{
    return 'CM-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function validarHashOpcional(?string $hash): ?string
{
    $hash = strtolower(trim((string) $hash));
    if ($hash === '') {
        return null;
    }

    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
        throw new InvalidArgumentException('hash_sha256 debe contener 64 caracteres hexadecimales.', 400);
    }

    return $hash;
}

function registrarBitacoraDocumento(
    string $accion,
    ?int $usuarioId,
    ?int $documentoId,
    ?string $detalle = null,
    array $contexto = []
): void {
    try {
        registrarBitacora(
            $accion,
            'DOCUMENTOS',
            $detalle,
            $usuarioId,
            null,
            null,
            $documentoId,
            $contexto
        );
    } catch (Throwable $e) {
        // La bitacora no debe interrumpir el flujo principal.
    }
}

function mapearFilaDocumento(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'folio' => (string) ($row['folio'] ?? ''),
        'titulo' => (string) ($row['titulo'] ?? ''),
        'descripcion' => $row['descripcion'] ?? null,
        'contenido' => $row['contenido'] ?? null,
        'ruta_archivo' => $row['ruta_archivo'] ?? null,
        'hash_sha256' => $row['hash_sha256'] ?? null,
        'firma_base64' => $row['firma_base64'] ?? null,
        'algoritmo_firma' => $row['algoritmo_firma'] ?? null,
        'qr_token' => $row['qr_token'] ?? null,
        'estado' => (string) ($row['estado'] ?? 'borrador'),
        'firmado' => ((int) ($row['firmado'] ?? 0) === 1),
        'creado_por' => isset($row['creado_por']) ? (int) $row['creado_por'] : null,
        'emitido_por' => isset($row['emitido_por']) ? (int) $row['emitido_por'] : null,
        'revocado_por' => isset($row['revocado_por']) ? (int) $row['revocado_por'] : null,
        'emitido_at' => $row['emitido_at'] ?? null,
        'revocado_at' => $row['revocado_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function construirPayloadParaHash(array $documento): string
{
    $contenido = trim((string) ($documento['contenido'] ?? ''));
    if ($contenido !== '') {
        return $contenido;
    }

    $parts = [
        (string) ($documento['folio'] ?? ''),
        (string) ($documento['titulo'] ?? ''),
        (string) ($documento['descripcion'] ?? ''),
        (string) ($documento['ruta_archivo'] ?? ''),
        (string) ($documento['created_at'] ?? ''),
    ];

    return implode('|', $parts);
}

function construirSelectDocumentosBase(): string
{
    return '
        SELECT
            id,
            folio,
            titulo,
            descripcion,
            contenido,
            ruta_archivo,
            hash_sha256,
            firma_base64,
            algoritmo_firma,
            qr_token,
            estado,
            firmado,
            creado_por,
            emitido_por,
            revocado_por,
            emitido_at,
            revocado_at,
            created_at,
            updated_at
        FROM documentos
    ';
}

function obtenerDocumentoPorId(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $pdo = getPdoConnection();
    $sql = construirSelectDocumentosBase() . ' WHERE id = :id LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? mapearFilaDocumento($row) : null;
}

function obtenerDocumentoPorIdentificadorPublico(string $identificador): ?array
{
    $identificador = trim($identificador);
    if ($identificador === '') {
        return null;
    }

    $pdo = getPdoConnection();

    $sql = construirSelectDocumentosBase() . '
        WHERE qr_token = :token
           OR folio = :folio
        LIMIT 1
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'token' => $identificador,
        'folio' => strtoupper($identificador),
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return mapearFilaDocumento($row);
    }

    $id = normalizarIdDocumento($identificador);
    if ($id === null) {
        return null;
    }

    return obtenerDocumentoPorId($id);
}

function listarDocumentosInterno(array $filtros): array
{
    $pdo = getPdoConnection();

    $estado = isset($filtros['estado']) ? strtolower(trim((string) $filtros['estado'])) : '';
    $q = isset($filtros['q']) ? trim((string) $filtros['q']) : '';
    $limit = isset($filtros['limit']) ? (int) $filtros['limit'] : 200;
    $offset = isset($filtros['offset']) ? (int) $filtros['offset'] : 0;

    if (!in_array($estado, ['', 'borrador', 'aprobado', 'emitido', 'revocado'], true)) {
        throw new InvalidArgumentException('Filtro de estado invalido.', 400);
    }

    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 500) {
        $limit = 500;
    }
    if ($offset < 0) {
        $offset = 0;
    }

    $sql = construirSelectDocumentosBase() . ' WHERE 1 = 1';
    $params = [];

    if ($estado !== '') {
        $sql .= ' AND estado = :estado';
        $params['estado'] = $estado;
    }

    if ($q !== '') {
        $sql .= ' AND (
            titulo LIKE :q
            OR folio LIKE :q
            OR CAST(id AS CHAR) LIKE :q
        )';
        $params['q'] = '%' . $q . '%';
    }

    $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_map('mapearFilaDocumento', $rows);

    $summary = obtenerResumenDocumentos($estado, $q);

    return [
        'items' => $items,
        'resumen' => $summary,
    ];
}

function listarDocumentos($filtros = null, ?string $queryLegacy = null): array
{
    if (is_array($filtros)) {
        return listarDocumentosInterno($filtros);
    }

    // Compatibilidad con llamada legacy listarDocumentos($estado, $query): array simple.
    $estadoLegacy = is_string($filtros) ? $filtros : '';
    $resultado = listarDocumentosInterno([
        'estado' => $estadoLegacy,
        'q' => $queryLegacy ?? '',
        'limit' => 200,
        'offset' => 0,
    ]);

    return $resultado['items'];
}

function obtenerResumenDocumentos(?string $estado = null, ?string $query = null): array
{
    $pdo = getPdoConnection();

    $sql = '
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN estado = "emitido" THEN 1 ELSE 0 END) AS emitidos,
            SUM(CASE WHEN estado = "aprobado" THEN 1 ELSE 0 END) AS aprobados,
            SUM(CASE WHEN estado = "borrador" THEN 1 ELSE 0 END) AS borradores,
            SUM(CASE WHEN estado = "revocado" THEN 1 ELSE 0 END) AS revocados
        FROM documentos
        WHERE 1 = 1
    ';

    $params = [];

    $estado = strtolower(trim((string) $estado));
    if ($estado !== '') {
        $sql .= ' AND estado = :estado';
        $params['estado'] = $estado;
    }

    $query = trim((string) $query);
    if ($query !== '') {
        $sql .= ' AND (
            titulo LIKE :q
            OR folio LIKE :q
            OR CAST(id AS CHAR) LIKE :q
        )';
        $params['q'] = '%' . $query . '%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'emitidos' => (int) ($row['emitidos'] ?? 0),
        'aprobados' => (int) ($row['aprobados'] ?? 0),
        'borradores' => (int) ($row['borradores'] ?? 0),
        'revocados' => (int) ($row['revocados'] ?? 0),
    ];
}

function aprobarDocumento(int $id, ?int $usuarioId = null, string $evidenceHash = ''): array
{
    $actorId = (int) ($usuarioId ?? 0);
    $actual = obtenerDocumentoPorId($id);
    if (!$actual) {
        throw new InvalidArgumentException('Documento no encontrado.', 404);
    }

    if ($actual['estado'] === 'revocado') {
        throw new InvalidArgumentException('No es posible aprobar un documento revocado.', 409);
    }

    if ($actual['estado'] === 'emitido') {
        return $actual;
    }

    if ($actual['estado'] === 'aprobado') {
        return $actual;
    }

    $pdo = getPdoConnection();
    $sql = '
        UPDATE documentos
        SET estado = "aprobado",
            autorizado_por = :autorizado_por,
            autorizado_at = NOW(),
            autorizacion_evidencia_sha256 = :evidence_hash,
            updated_at = NOW()
        WHERE id = :id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'autorizado_por' => $actorId > 0 ? $actorId : null,
        'evidence_hash' => trim($evidenceHash) !== '' ? trim($evidenceHash) : null,
        'id' => $id,
    ]);

    $actualizado = obtenerDocumentoPorId($id);
    if (!$actualizado) {
        throw new RuntimeException('No fue posible recuperar el documento aprobado.', 500);
    }

    registrarBitacoraDocumento(
        'APROBAR_DOCUMENTO',
        $actorId > 0 ? $actorId : null,
        $id,
        'Documento aprobado para firma/restriccion.',
        [
            'folio' => $actualizado['folio'],
            'evidence_hash' => trim($evidenceHash) !== '' ? trim($evidenceHash) : null,
        ]
    );

    return $actualizado;
}

function crearDocumentoBorrador(array $datos, ?int $usuarioId = null): array
{
    $actorId = ($usuarioId !== null && $usuarioId > 0) ? $usuarioId : null;

    $titulo = trim((string) ($datos['titulo'] ?? ''));
    $descripcion = trim((string) ($datos['descripcion'] ?? ''));
    $contenido = trim((string) ($datos['contenido'] ?? ''));
    $rutaArchivo = trim((string) ($datos['ruta_archivo'] ?? ''));
    $hashOpcional = validarHashOpcional($datos['hash_sha256'] ?? null);

    if ($titulo === '') {
        throw new InvalidArgumentException('El titulo es obligatorio.', 400);
    }

    if (strlen($titulo) > 255) {
        throw new InvalidArgumentException('El titulo supera 255 caracteres.', 400);
    }

    if (strlen($rutaArchivo) > 500) {
        throw new InvalidArgumentException('La ruta_archivo supera 500 caracteres.', 400);
    }

    $pdo = getPdoConnection();

    $folioTemporal = 'TMP-' . bin2hex(random_bytes(8));
    $qrToken = generarQrToken();

    $sql = '
        INSERT INTO documentos
            (folio, titulo, descripcion, contenido, ruta_archivo, hash_sha256, qr_token, estado, firmado, creado_por, created_at, updated_at)
        VALUES
            (:folio, :titulo, :descripcion, :contenido, :ruta_archivo, :hash_sha256, :qr_token, "borrador", 0, :creado_por, NOW(), NOW())
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'folio' => $folioTemporal,
        'titulo' => $titulo,
        'descripcion' => $descripcion !== '' ? $descripcion : null,
        'contenido' => $contenido !== '' ? $contenido : null,
        'ruta_archivo' => $rutaArchivo !== '' ? $rutaArchivo : null,
        'hash_sha256' => $hashOpcional,
        'qr_token' => $qrToken,
        'creado_por' => $actorId,
    ]);

    $id = (int) $pdo->lastInsertId();
    $folio = formatearFolioDocumento($id);

    $up = $pdo->prepare('UPDATE documentos SET folio = :folio WHERE id = :id');
    $up->execute([
        'folio' => $folio,
        'id' => $id,
    ]);

    $documento = obtenerDocumentoPorId($id);
    if (!$documento) {
        throw new RuntimeException('No fue posible recuperar el documento creado.', 500);
    }

    registrarBitacoraDocumento(
        'CREAR_DOCUMENTO',
        $actorId,
        $id,
        'Documento registrado en estado borrador.',
        ['folio' => $folio]
    );

    return $documento;
}

function crearDocumento(array $datos, ?int $usuarioId = null): array
{
    return crearDocumentoBorrador($datos, $usuarioId);
}

function actualizarDocumentoBorrador(int $id, array $datos, ?int $usuarioId = null): array
{
    $actorId = ($usuarioId !== null && $usuarioId > 0) ? $usuarioId : null;

    $actual = obtenerDocumentoPorId($id);
    if (!$actual) {
        throw new InvalidArgumentException('Documento no encontrado.', 404);
    }

    if ($actual['estado'] !== 'borrador') {
        throw new InvalidArgumentException('Solo se pueden editar documentos en estado borrador.', 409);
    }

    $titulo = trim((string) ($datos['titulo'] ?? $actual['titulo']));
    $descripcion = trim((string) ($datos['descripcion'] ?? ($actual['descripcion'] ?? '')));
    $contenido = trim((string) ($datos['contenido'] ?? ($actual['contenido'] ?? '')));
    $rutaArchivo = trim((string) ($datos['ruta_archivo'] ?? ($actual['ruta_archivo'] ?? '')));

    if ($titulo === '') {
        throw new InvalidArgumentException('El titulo es obligatorio.', 400);
    }

    $pdo = getPdoConnection();
    $sql = '
        UPDATE documentos
        SET titulo = :titulo,
            descripcion = :descripcion,
            contenido = :contenido,
            ruta_archivo = :ruta_archivo,
            updated_at = NOW()
        WHERE id = :id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'titulo' => $titulo,
        'descripcion' => $descripcion !== '' ? $descripcion : null,
        'contenido' => $contenido !== '' ? $contenido : null,
        'ruta_archivo' => $rutaArchivo !== '' ? $rutaArchivo : null,
        'id' => $id,
    ]);

    $actualizado = obtenerDocumentoPorId($id);
    if (!$actualizado) {
        throw new RuntimeException('No fue posible recuperar el documento actualizado.', 500);
    }

    registrarBitacoraDocumento(
        'ACTUALIZAR_DOCUMENTO',
        $actorId,
        $id,
        'Documento borrador actualizado.',
        ['folio' => $actualizado['folio']]
    );

    return $actualizado;
}

function actualizarDocumento(int $id, array $datos, ?int $usuarioId = null): array
{
    return actualizarDocumentoBorrador($id, $datos, $usuarioId);
}

function emitirDocumento(int $id, ?int $usuarioId = null): array
{
    $actorId = (int) ($usuarioId ?? 0);
    $actual = obtenerDocumentoPorId($id);
    if (!$actual) {
        throw new InvalidArgumentException('Documento no encontrado.', 404);
    }

    if ($actual['estado'] === 'revocado') {
        throw new InvalidArgumentException('No es posible emitir un documento revocado.', 409);
    }

    if ($actual['estado'] === 'emitido') {
        return $actual;
    }

    $payload = construirPayloadParaHash($actual);
    if (trim($payload) === '') {
        throw new InvalidArgumentException('El documento no tiene informacion para calcular el hash.', 409);
    }

    $hash = hash('sha256', $payload);
    $firma = signDocumentHash($hash);

    $pdo = getPdoConnection();
    $sql = '
        UPDATE documentos
        SET hash_sha256 = :hash_sha256,
            firma_base64 = :firma_base64,
            algoritmo_firma = :algoritmo_firma,
            estado = "emitido",
            firmado = 1,
            emitido_por = :emitido_por,
            emitido_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'hash_sha256' => $hash,
        'firma_base64' => $firma['firma_base64'],
        'algoritmo_firma' => $firma['algoritmo_firma'],
        'emitido_por' => $actorId,
        'id' => $id,
    ]);

    $actualizado = obtenerDocumentoPorId($id);
    if (!$actualizado) {
        throw new RuntimeException('No fue posible recuperar el documento emitido.', 500);
    }

    registrarBitacoraDocumento(
        'EMITIR_DOCUMENTO',
        $actorId,
        $id,
        'Documento emitido y firmado digitalmente.',
        [
            'folio' => $actualizado['folio'],
            'hash_sha256' => $hash,
            'algoritmo_firma' => $firma['algoritmo_firma'],
        ]
    );

    return $actualizado;
}

function revocarDocumento(int $id, ?int $usuarioId = null, string $motivo = ''): array
{
    $actorId = (int) ($usuarioId ?? 0);
    $actual = obtenerDocumentoPorId($id);
    if (!$actual) {
        throw new InvalidArgumentException('Documento no encontrado.', 404);
    }

    if ($actual['estado'] === 'revocado') {
        return $actual;
    }

    if ($actual['estado'] !== 'emitido') {
        throw new InvalidArgumentException('Solo se pueden revocar documentos emitidos.', 409);
    }

    $pdo = getPdoConnection();
    $sql = '
        UPDATE documentos
        SET estado = "revocado",
            revocado_por = :revocado_por,
            revocado_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'revocado_por' => $actorId,
        'id' => $id,
    ]);

    $actualizado = obtenerDocumentoPorId($id);
    if (!$actualizado) {
        throw new RuntimeException('No fue posible recuperar el documento revocado.', 500);
    }

    registrarBitacoraDocumento(
        'REVOCAR_DOCUMENTO',
        $actorId,
        $id,
        'Documento revocado.',
        [
            'folio' => $actualizado['folio'],
            'motivo' => trim($motivo) !== '' ? trim($motivo) : null,
        ]
    );

    return $actualizado;
}

function eliminarDocumentoBorrador(int $id, ?int $usuarioId = null): bool
{
    $actorId = ($usuarioId !== null && $usuarioId > 0) ? $usuarioId : null;

    $actual = obtenerDocumentoPorId($id);
    if (!$actual) {
        throw new InvalidArgumentException('Documento no encontrado.', 404);
    }

    if ($actual['estado'] !== 'borrador') {
        throw new InvalidArgumentException('Solo se pueden eliminar documentos en estado borrador.', 409);
    }

    $pdo = getPdoConnection();
    $stmt = $pdo->prepare('DELETE FROM documentos WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $ok = $stmt->rowCount() > 0;

    if ($ok) {
        registrarBitacoraDocumento(
            'ELIMINAR_DOCUMENTO',
            $actorId,
            $id,
            'Documento borrador eliminado.',
            ['folio' => $actual['folio']]
        );
    }

    return $ok;
}

function eliminarDocumento(int $id, ?int $usuarioId = null): bool
{
    return eliminarDocumentoBorrador($id, $usuarioId);
}

function evaluarEstatusAutenticidadPublica(array $documento): array
{
    $estado = strtolower((string) ($documento['estado'] ?? 'no_encontrado'));
    $hash = (string) ($documento['hash_sha256'] ?? '');
    $firma = (string) ($documento['firma_base64'] ?? '');

    $firmaValida = false;
    if ($hash !== '' && $firma !== '') {
        $firmaValida = verifyDocumentHashSignature($hash, $firma);
    }

    if ($estado === 'emitido' && $firmaValida) {
        return ['estatus' => 'valido', 'firma_valida' => true];
    }

    if ($estado === 'revocado') {
        return ['estatus' => 'revocado', 'firma_valida' => $firmaValida];
    }

    return ['estatus' => 'no_encontrado', 'firma_valida' => false];
}

function evaluarAutenticidadPublica(string $identificador): array
{
    $documento = obtenerDocumentoPorIdentificadorPublico($identificador);

    if (!$documento) {
        registrarBitacoraDocumento(
            'CONSULTA_PUBLICA_QR',
            null,
            null,
            'Consulta publica sin coincidencias.',
            ['identificador' => $identificador]
        );

        return [
            'encontrado' => false,
            'estado' => 'no_encontrado',
            'firma_valida' => false,
            'es_autentico' => false,
            'folio' => null,
            'tipo_documento' => null,
            'fecha_emision' => null,
            'fecha_revocacion' => null,
            'motivo_revocacion' => null,
        ];
    }

    $estado = strtolower((string) ($documento['estado'] ?? ''));
    $hash = (string) ($documento['hash_sha256'] ?? '');
    $firma = (string) ($documento['firma_base64'] ?? '');
    $firmaValida = ($hash !== '' && $firma !== '') ? verifyDocumentHashSignature($hash, $firma) : false;

    $esAutentico = ($estado === 'emitido' && $firmaValida) || $estado === 'revocado';

    registrarBitacoraDocumento(
        'CONSULTA_PUBLICA_QR',
        null,
        (int) $documento['id'],
        'Consulta publica de autenticidad.',
        [
            'identificador' => $identificador,
            'folio' => $documento['folio'],
            'estado' => $estado,
            'firma_valida' => $firmaValida,
        ]
    );

    return [
        'encontrado' => true,
        'estado' => $estado,
        'firma_valida' => $firmaValida,
        'es_autentico' => $esAutentico,
        'folio' => $documento['folio'],
        'tipo_documento' => null,
        'fecha_emision' => $documento['emitido_at'] ?? null,
        'fecha_revocacion' => $documento['revocado_at'] ?? null,
        'motivo_revocacion' => null,
    ];
}
