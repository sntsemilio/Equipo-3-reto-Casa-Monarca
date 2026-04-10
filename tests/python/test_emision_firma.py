import hashlib
import tempfile
import unittest
from pathlib import Path

from src.modules import emision_firma


class TestEmisionFirma(unittest.TestCase):
    def test_sha256_bytes(self):
        data = b"Casa Monarca"
        expected = hashlib.sha256(data).hexdigest()
        self.assertEqual(expected, emision_firma.sha256_bytes(data))

    def test_generate_keys_requires_or_uses_crypto(self):
        with tempfile.TemporaryDirectory() as tmp:
            private_key = Path(tmp) / "private_key.pem"
            public_key = Path(tmp) / "public_key.pem"

            try:
                emision_firma.generate_rsa_keys(private_key, public_key)
            except SystemExit:
                self.skipTest("cryptography no esta disponible en este entorno")

            self.assertTrue(private_key.exists())
            self.assertTrue(public_key.exists())

    def test_generate_keys_and_sign(self):
        try:
            with tempfile.TemporaryDirectory() as tmp:
                private_key = Path(tmp) / "private_key.pem"
                public_key = Path(tmp) / "public_key.pem"
                emision_firma.generate_rsa_keys(private_key, public_key)
        except SystemExit:
            self.skipTest("cryptography no esta disponible en este entorno")

        with tempfile.TemporaryDirectory() as tmp:
            private_key = Path(tmp) / "private_key.pem"
            public_key = Path(tmp) / "public_key.pem"

            emision_firma.generate_rsa_keys(private_key, public_key)

            self.assertTrue(private_key.exists())
            self.assertTrue(public_key.exists())

            signature = emision_firma.sign_data(b"documento", private_key)
            self.assertIsInstance(signature, bytes)
            self.assertGreater(len(signature), 0)


if __name__ == "__main__":
    unittest.main()
