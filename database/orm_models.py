"""Modelos ORM (SQLAlchemy) para RBAC + PKI de Casa Monarca."""

from __future__ import annotations

from datetime import datetime
from typing import Optional

from sqlalchemy import (
    BigInteger,
    Boolean,
    DateTime,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
    func,
)
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship


class Base(DeclarativeBase):
    """Clase base de SQLAlchemy para los modelos del sistema."""


class Role(Base):
    __tablename__ = "roles"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(50), unique=True, nullable=False)
    nombre: Mapped[str] = mapped_column(String(50), unique=True, nullable=False)
    description: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())

    users: Mapped[list["User"]] = relationship(back_populates="role", cascade="all,delete")
    role_permissions: Mapped[list["RolePermission"]] = relationship(back_populates="role", cascade="all,delete")


class Permission(Base):
    __tablename__ = "permissions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    action: Mapped[str] = mapped_column(String(100), unique=True, nullable=False)
    description: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())

    role_permissions: Mapped[list["RolePermission"]] = relationship(back_populates="permission", cascade="all,delete")
    user_permissions: Mapped[list["UserPermission"]] = relationship(back_populates="permission", cascade="all,delete")


class RolePermission(Base):
    __tablename__ = "role_permissions"

    role_id: Mapped[int] = mapped_column(ForeignKey("roles.id", ondelete="CASCADE"), primary_key=True)
    permission_id: Mapped[int] = mapped_column(ForeignKey("permissions.id", ondelete="CASCADE"), primary_key=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())

    role: Mapped[Role] = relationship(back_populates="role_permissions")
    permission: Mapped[Permission] = relationship(back_populates="role_permissions")


class User(Base):
    __tablename__ = "users"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    name: Mapped[str] = mapped_column(String(150), nullable=False)
    email: Mapped[str] = mapped_column(String(190), unique=True, nullable=False)
    password_hash: Mapped[str] = mapped_column(String(255), nullable=False)
    role_id: Mapped[int] = mapped_column(ForeignKey("roles.id", ondelete="RESTRICT"), nullable=False)

    public_cert_pem: Mapped[Optional[str]] = mapped_column(Text, nullable=True)
    public_cert_sha256: Mapped[Optional[str]] = mapped_column(String(64), nullable=True)
    public_cert_serial: Mapped[Optional[str]] = mapped_column(String(128), nullable=True)
    cert_status: Mapped[str] = mapped_column(String(20), nullable=False, default="none")
    cert_issued_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    cert_revoked_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    cert_revoked_reason: Mapped[Optional[str]] = mapped_column(String(255), nullable=True)

    is_active: Mapped[bool] = mapped_column(Boolean, nullable=False, default=True)
    last_login_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now(), onupdate=func.now())

    role: Mapped[Role] = relationship(back_populates="users")
    user_permissions: Mapped[list["UserPermission"]] = relationship(back_populates="user", cascade="all,delete")
    download_tokens: Mapped[list["CertificateDownloadToken"]] = relationship(back_populates="user", cascade="all,delete")


class UserPermission(Base):
    __tablename__ = "user_permissions"
    __table_args__ = (UniqueConstraint("user_id", "permission_id", name="uq_user_permissions"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    permission_id: Mapped[int] = mapped_column(ForeignKey("permissions.id", ondelete="CASCADE"), nullable=False)
    is_allowed: Mapped[bool] = mapped_column(Boolean, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now(), onupdate=func.now())

    user: Mapped[User] = relationship(back_populates="user_permissions")
    permission: Mapped[Permission] = relationship(back_populates="user_permissions")


class CertificateDownloadToken(Base):
    __tablename__ = "certificate_download_tokens"

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id", ondelete="CASCADE"), nullable=False)
    token_hash: Mapped[str] = mapped_column(String(64), unique=True, nullable=False)
    zip_path: Mapped[str] = mapped_column(String(500), nullable=False)
    expires_at: Mapped[datetime] = mapped_column(DateTime, nullable=False)
    used_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    consumed_ip: Mapped[Optional[str]] = mapped_column(String(45), nullable=True)
    key_destroyed_at: Mapped[Optional[datetime]] = mapped_column(DateTime, nullable=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, nullable=False, server_default=func.now())

    user: Mapped[User] = relationship(back_populates="download_tokens")
