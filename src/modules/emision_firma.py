#!/usr/bin/env python3
"""Boilerplate para emision de hash SHA-256 y firma RSA."""

from __future__ import annotations

import argparse
import base64
import hashlib
from pathlib import Path


def _load_crypto_modules():
    try:
        primitives = __import__(
            "cryptography.hazmat.primitives", fromlist=["hashes", "serialization"]
        )
        asymmetric = __import__(
            "cryptography.hazmat.primitives.asymmetric", fromlist=["padding", "rsa"]
        )
    except ImportError as exc:
        raise SystemExit(
            "Falta la dependencia 'cryptography'. Instala con: pip install cryptography"
        ) from exc

    return primitives.hashes, primitives.serialization, asymmetric.padding, asymmetric.rsa


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def generate_rsa_keys(private_key_path: Path, public_key_path: Path) -> None:
    _, serialization, _, rsa = _load_crypto_modules()

    private_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
    public_key = private_key.public_key()

    private_key_path.write_bytes(
        private_key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption(),
        )
    )

    public_key_path.write_bytes(
        public_key.public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo,
        )
    )


def load_private_key(private_key_path: Path):
    _, serialization, _, _ = _load_crypto_modules()
    return serialization.load_pem_private_key(private_key_path.read_bytes(), password=None)


def sign_data(data: bytes, private_key_path: Path) -> bytes:
    hashes, _, padding, _ = _load_crypto_modules()
    private_key = load_private_key(private_key_path)
    return private_key.sign(
        data,
        padding.PSS(mgf=padding.MGF1(hashes.SHA256()), salt_length=padding.PSS.MAX_LENGTH),
        hashes.SHA256(),
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Genera hash SHA-256 y firma RSA para texto o archivo."
    )
    parser.add_argument("--input", help="Ruta al archivo de entrada")
    parser.add_argument("--text", help="Texto directo para procesar")
    parser.add_argument("--private-key", default="private_key.pem", help="Ruta de llave privada")
    parser.add_argument("--public-key", default="public_key.pem", help="Ruta de llave publica")
    parser.add_argument("--signature-out", default="firma.bin", help="Archivo de salida para la firma")
    args = parser.parse_args()

    if not args.input and not args.text:
        raise SystemExit("Debes enviar --input o --text")

    private_key_path = Path(args.private_key)
    public_key_path = Path(args.public_key)

    if not private_key_path.exists() or not public_key_path.exists():
        generate_rsa_keys(private_key_path, public_key_path)

    if args.input:
        data = Path(args.input).read_bytes()
    else:
        data = args.text.encode("utf-8")

    digest = sha256_bytes(data)
    signature = sign_data(data, private_key_path)

    Path(args.signature_out).write_bytes(signature)

    print(f"SHA-256: {digest}")
    print("Firma (Base64):")
    print(base64.b64encode(signature).decode("ascii"))
    print(f"Firma guardada en: {args.signature_out}")


if __name__ == "__main__":
    main()
