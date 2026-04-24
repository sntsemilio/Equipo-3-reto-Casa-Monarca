#!/usr/bin/env python3
"""Seeder de desarrollo para RBAC + PKI.

Limpia y repuebla la base con 4 usuarios demo:
- 1 Admin
- 1 Coordinador
- 1 Operativo
- 1 Voluntario

Admin y Coordinador incluyen simulacion de flujo criptografico:
- RSA 2048
- Certificado X.509
- ZIP temporal con .cer y .key
- Token one-time (hash en BD, token plano impreso en consola)
"""

from __future__ import annotations

import hashlib
import os
import secrets
import zipfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
from sqlalchemy import create_engine
from sqlalchemy.orm import Session

from orm_models import (
    Base,
    CertificateDownloadToken,
    Permission,
    Role,
    RolePermission,
    User,
)


DEMO_PASSWORD_HASH = "$2y$10$TFZaRc7yHpdwy.2XmNQHf.twjn08SmiSJHrGkV.VdC3T2CqyHxmpK"


def env(name: str, default: str) -> str:
    value = os.getenv(name)
    return value if value is not None and value.strip() != "" else default


def build_database_url() -> str:
    user = env("DB_USER", "casa_user")
    password = env("DB_PASS", "casa_pass")
    host = env("DB_HOST", "127.0.0.1")
    port = env("DB_PORT", "3307")
    db_name = env("DB_NAME", "casa_monarca")
    return f"mysql+pymysql://{user}:{password}@{host}:{port}/{db_name}?charset=utf8mb4"


def generate_certificate_bundle(user_email: str, role_name: str, user_id: int) -> dict[str, str]:
    private_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)

    subject = issuer = x509.Name(
        [
            x509.NameAttribute(NameOID.COUNTRY_NAME, "MX"),
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, "Casa Monarca"),
            x509.NameAttribute(NameOID.ORGANIZATIONAL_UNIT_NAME, role_name.capitalize()),
            x509.NameAttribute(NameOID.COMMON_NAME, user_email),
        ]
    )

    now = datetime.now(timezone.utc)
    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(issuer)
        .public_key(private_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + timedelta(days=365))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .sign(private_key=private_key, algorithm=hashes.SHA256())
    )

    cert_pem = cert.public_bytes(serialization.Encoding.PEM).decode("utf-8")
    key_pem = private_key.private_bytes(
        encoding=serialization.Encoding.PEM,
        format=serialization.PrivateFormat.PKCS8,
        encryption_algorithm=serialization.NoEncryption(),
    ).decode("utf-8")

    bundles_dir = Path(env("CM_CERT_BUNDLE_DIR", str(Path("src") / "keys" / "seed-bundles")))
    bundles_dir.mkdir(parents=True, exist_ok=True)

    bundle_name = f"seed_user_{user_id}_{secrets.token_hex(6)}.zip"
    zip_path = bundles_dir / bundle_name

    with zipfile.ZipFile(zip_path, mode="w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("certificado.cer", cert_pem)
        archive.writestr("llave_privada.key", key_pem)

    token_plain = secrets.token_hex(32)

    return {
        "cert_pem": cert_pem,
        "cert_sha256": hashlib.sha256(cert_pem.encode("utf-8")).hexdigest(),
        "serial": format(cert.serial_number, "x"),
        "zip_path": str(zip_path.resolve()),
        "token_plain": token_plain,
        "token_hash": hashlib.sha256(token_plain.encode("utf-8")).hexdigest(),
    }


def seed() -> None:
    engine = create_engine(build_database_url(), future=True)

    # Limpieza total para entorno de desarrollo.
    Base.metadata.drop_all(engine)
    Base.metadata.create_all(engine)

    role_names = ["admin", "coordinador", "operativo", "voluntario"]
    permission_actions = [
        "manage_users",
        "manage_role_permissions",
        "manage_user_permissions",
        "view_dashboard",
        "view_documents",
        "approve_documents",
        "sign_documents",
        "revoke_documents",
        "view_audit_log",
        "download_keys",
        "run_testing_matrix",
    ]

    role_permission_map = {
        "admin": set(permission_actions),
        "coordinador": {
            "view_dashboard",
            "view_documents",
            "approve_documents",
            "sign_documents",
            "revoke_documents",
            "view_audit_log",
            "download_keys",
            "run_testing_matrix",
        },
        "operativo": {
            "view_dashboard",
            "view_documents",
            "approve_documents",
            "sign_documents",
            "run_testing_matrix",
        },
        "voluntario": {
            "view_dashboard",
            "view_documents",
            "run_testing_matrix",
        },
    }

    demo_users = [
        ("Admin Demo", "admin@casamonarca.org", "admin"),
        ("Coordinador Demo", "coordinador@casamonarca.org", "coordinador"),
        ("Operativo Demo", "operativo@casamonarca.org", "operativo"),
        ("Voluntario Demo", "voluntario@casamonarca.org", "voluntario"),
    ]

    issued_tokens: list[tuple[str, str]] = []

    with Session(engine) as session:
        roles: dict[str, Role] = {}
        for name in role_names:
            role = Role(name=name, nombre=name, description=f"Rol {name}")
            session.add(role)
            roles[name] = role

        permissions: dict[str, Permission] = {}
        for action in permission_actions:
            perm = Permission(action=action, description=f"Permiso {action}")
            session.add(perm)
            permissions[action] = perm

        session.flush()

        for role_name, allowed_actions in role_permission_map.items():
            role = roles[role_name]
            for action in allowed_actions:
                session.add(RolePermission(role_id=role.id, permission_id=permissions[action].id))

        users: dict[str, User] = {}
        for full_name, email, role_name in demo_users:
            user = User(
                name=full_name,
                email=email,
                password_hash=DEMO_PASSWORD_HASH,
                role_id=roles[role_name].id,
                is_active=True,
                cert_status="none",
            )
            session.add(user)
            users[email] = user

        session.flush()

        for email in ["admin@casamonarca.org", "coordinador@casamonarca.org"]:
            user = users[email]
            material = generate_certificate_bundle(user.email, roles[[r for _, e, r in demo_users if e == email][0]].name, user.id)

            user.public_cert_pem = material["cert_pem"]
            user.public_cert_sha256 = material["cert_sha256"]
            user.public_cert_serial = material["serial"]
            user.cert_status = "active"
            user.cert_issued_at = datetime.now(timezone.utc)

            token = CertificateDownloadToken(
                user_id=user.id,
                token_hash=material["token_hash"],
                zip_path=material["zip_path"],
                expires_at=datetime.now(timezone.utc) + timedelta(hours=24),
            )
            session.add(token)

            issued_tokens.append((email, material["token_plain"]))

        session.commit()

    print("Seed completado.")
    print("Usuarios demo (password de desarrollo): admin123")
    for full_name, email, role_name in demo_users:
        print(f"- {full_name} | {email} | {role_name}")

    print("\nTokens one-time de descarga para llaves (compartir de forma segura):")
    for email, token in issued_tokens:
        print(f"- {email}: {token}")


if __name__ == "__main__":
    seed()
