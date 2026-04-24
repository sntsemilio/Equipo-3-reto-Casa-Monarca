SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    public_cert_pem LONGTEXT NULL,
    public_cert_sha256 CHAR(64) NULL,
    public_cert_serial VARCHAR(128) NULL,
    cert_status ENUM('none', 'active', 'revoked') NOT NULL DEFAULT 'none',
    cert_issued_at DATETIME NULL,
    cert_revoked_at DATETIME NULL,
    cert_revoked_reason VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_id (role_id),
    INDEX idx_users_is_active (is_active),
    CONSTRAINT fk_users_roles
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS user_permissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    is_allowed TINYINT(1) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_permissions (user_id, permission_id),
    CONSTRAINT fk_user_permissions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_user_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS certificate_download_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    zip_path VARCHAR(500) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    consumed_ip VARCHAR(45) NULL,
    key_destroyed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cert_tokens_user_id (user_id),
    INDEX idx_cert_tokens_expires_at (expires_at),
    CONSTRAINT fk_cert_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS certificate_revocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    serial_number VARCHAR(128) NULL,
    reason VARCHAR(255) NULL,
    revoked_by INT UNSIGNED NULL,
    revoked_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cert_revocations_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_cert_revocations_revoked_by
        FOREIGN KEY (revoked_by) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
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
    estado ENUM('borrador', 'aprobado', 'emitido', 'revocado') NOT NULL DEFAULT 'borrador',
    firmado TINYINT(1) NOT NULL DEFAULT 0,
    autorizado_por INT UNSIGNED NULL,
    autorizado_at DATETIME NULL,
    autorizacion_evidencia_sha256 CHAR(64) NULL,
    creado_por INT UNSIGNED NULL,
    emitido_por INT UNSIGNED NULL,
    revocado_por INT UNSIGNED NULL,
    emitido_at DATETIME NULL,
    revocado_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_documentos_creado_por
        FOREIGN KEY (creado_por) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_documentos_autorizado_por
        FOREIGN KEY (autorizado_por) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_documentos_emitido_por
        FOREIGN KEY (emitido_por) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_documentos_revocado_por
        FOREIGN KEY (revocado_por) REFERENCES users(id)
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
        FOREIGN KEY (usuario_id) REFERENCES users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT fk_bitacora_documentos
        FOREIGN KEY (documento_id) REFERENCES documentos(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
);

CREATE OR REPLACE VIEW usuarios AS
SELECT
    id,
    name AS nombre,
    email,
    password_hash,
    role_id AS rol_id,
    is_active AS activo,
    last_login_at AS ultimo_login,
    created_at,
    updated_at,
    public_cert_pem,
    public_cert_sha256,
    public_cert_serial,
    cert_status,
    cert_issued_at,
    cert_revoked_at,
    cert_revoked_reason
FROM users;

INSERT INTO roles (id, name, nombre, description)
VALUES
    (1, 'admin', 'admin', 'Control total de usuarios, permisos, llaves y revocaciones'),
    (2, 'coordinador', 'coordinador', 'Autoriza y firma documentos restringidos'),
    (3, 'operativo', 'operativo', 'Opera flujos documentales y firma segun permisos'),
    (4, 'voluntario', 'voluntario', 'Acceso de consulta limitado al tablero')
ON DUPLICATE KEY UPDATE
    nombre = VALUES(nombre),
    description = VALUES(description);

INSERT INTO permissions (action, description)
VALUES
    ('manage_users', 'CRUD de usuarios y cambios de rol'),
    ('manage_role_permissions', 'Configurar permisos por rol'),
    ('manage_user_permissions', 'Configurar permisos directos por usuario'),
    ('view_dashboard', 'Visualizar panel principal'),
    ('view_documents', 'Consultar documentos'),
    ('approve_documents', 'Aprobar documento restringido con .cer/.key'),
    ('sign_documents', 'Emitir y firmar documentos'),
    ('revoke_documents', 'Revocar documentos emitidos'),
    ('view_audit_log', 'Consultar bitacora de eventos'),
    ('download_keys', 'Descargar paquete de llaves de un solo uso'),
    ('run_testing_matrix', 'Acceder a matriz de comprobacion interna')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p
    ON r.name = 'admin'
ON DUPLICATE KEY UPDATE
    created_at = created_at;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.action IN (
    'view_dashboard',
    'view_documents',
    'approve_documents',
    'sign_documents',
    'revoke_documents',
    'view_audit_log',
    'download_keys',
    'run_testing_matrix'
)
WHERE r.name = 'coordinador'
ON DUPLICATE KEY UPDATE
    created_at = created_at;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.action IN (
    'view_dashboard',
    'view_documents',
    'approve_documents',
    'sign_documents',
    'run_testing_matrix'
)
WHERE r.name = 'operativo'
ON DUPLICATE KEY UPDATE
    created_at = created_at;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
INNER JOIN permissions p ON p.action IN (
    'view_dashboard',
    'view_documents',
    'run_testing_matrix'
)
WHERE r.name = 'voluntario'
ON DUPLICATE KEY UPDATE
    created_at = created_at;

SET @seed_hash = '$2y$10$TFZaRc7yHpdwy.2XmNQHf.twjn08SmiSJHrGkV.VdC3T2CqyHxmpK';

INSERT INTO users (name, email, password_hash, role_id, is_active)
SELECT
    'Admin Demo',
    'admin@casamonarca.org',
    @seed_hash,
    r.id,
    1
FROM roles r
WHERE r.name = 'admin'
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    role_id = VALUES(role_id),
    is_active = VALUES(is_active);

INSERT INTO users (name, email, password_hash, role_id, is_active)
SELECT
    'Coordinador Demo',
    'coordinador@casamonarca.org',
    @seed_hash,
    r.id,
    1
FROM roles r
WHERE r.name = 'coordinador'
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    role_id = VALUES(role_id),
    is_active = VALUES(is_active);

INSERT INTO users (name, email, password_hash, role_id, is_active)
SELECT
    'Operativo Demo',
    'operativo@casamonarca.org',
    @seed_hash,
    r.id,
    1
FROM roles r
WHERE r.name = 'operativo'
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    role_id = VALUES(role_id),
    is_active = VALUES(is_active);

INSERT INTO users (name, email, password_hash, role_id, is_active)
SELECT
    'Voluntario Demo',
    'voluntario@casamonarca.org',
    @seed_hash,
    r.id,
    1
FROM roles r
WHERE r.name = 'voluntario'
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    role_id = VALUES(role_id),
    is_active = VALUES(is_active);
