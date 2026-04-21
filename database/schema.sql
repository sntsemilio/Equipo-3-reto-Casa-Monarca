CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol_id INT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_login TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_roles
        FOREIGN KEY (rol_id) REFERENCES roles(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folio VARCHAR(40) NOT NULL UNIQUE,
    titulo VARCHAR(255) NOT NULL,
    descripcion TEXT NULL,
    contenido LONGTEXT NULL,
    ruta_archivo VARCHAR(500) NULL,
    hash_sha256 CHAR(64) NULL,
    firma_base64 LONGTEXT NULL,
    algoritmo_firma VARCHAR(50) NULL,
    qr_token CHAR(64) NOT NULL UNIQUE,
    estado ENUM('borrador', 'emitido', 'revocado') NOT NULL DEFAULT 'borrador',
    firmado TINYINT(1) NOT NULL DEFAULT 0,
    creado_por INT UNSIGNED NULL,
    emitido_por INT UNSIGNED NULL,
    revocado_por INT UNSIGNED NULL,
    emitido_at DATETIME NULL,
    revocado_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_documentos_creado_por
        FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_documentos_emitido_por
        FOREIGN KEY (emitido_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_documentos_revocado_por
        FOREIGN KEY (revocado_por) REFERENCES usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS bitacora (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    accion VARCHAR(150) NOT NULL,
    modulo VARCHAR(100) NOT NULL,
    documento_id INT UNSIGNED NULL,
    detalle TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    contexto_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bitacora_usuario_id (usuario_id),
    INDEX idx_bitacora_documento_id (documento_id),
    INDEX idx_bitacora_modulo (modulo),
    INDEX idx_bitacora_created_at (created_at),
    CONSTRAINT fk_bitacora_usuarios
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_bitacora_documentos
        FOREIGN KEY (documento_id) REFERENCES documentos(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);

INSERT INTO roles (id, nombre, descripcion)
VALUES
    (1, 'administrador', 'Control total del gestor'),
    (2, 'emisor', 'Puede crear, emitir y revocar documentos'),
    (3, 'consultor', 'Acceso de lectura y consulta de trazabilidad'),
    (4, 'supervisor', 'Puede emitir, revocar y revisar documentos, sin gestionar usuarios'),
    (5, 'verificador', 'Solo puede verificar la autenticidad de documentos emitidos')
ON DUPLICATE KEY UPDATE
    descripcion = VALUES(descripcion);

INSERT INTO usuarios (nombre, email, password_hash, rol_id, activo)
VALUES
    (
        'Administrador Principal',
        'admin@casamonarca.org',
        '$2y$10$TFZaRc7yHpdwy.2XmNQHf.twjn08SmiSJHrGkV.VdC3T2CqyHxmpK',
        1,
        1
    ),
    (
        'Emisor Institucional',
        'emisor@casamonarca.org',
        '$2y$10$i//ZJGrg2hfO0aeuIWeca.oq7FdPd9doDZ11Ilq8RlskOA4I/iI2m',
        2,
        1
    ),
    (
        'Consultor Externo',
        'consultor@casamonarca.org',
        '$2y$10$E8HlaCROGx3ROGZdDgOIjOG9QGSHaPcb.4pnRYMoG/9YkyxSG9A9q',
        3,
        1
    )
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    rol_id = VALUES(rol_id),
    activo = VALUES(activo);
