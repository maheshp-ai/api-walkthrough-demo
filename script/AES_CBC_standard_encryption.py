import os
import sys
import base64
from typing import Optional
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

# ==========================================================
# CONFIG (Same key must be used in decrypt.py)
# ==========================================================

KEY_B64 = "pE5nm9GhrRFNRjCanEitQtMV4lyHhNrgD/6GvlZloHE="
SECRET_KEY = base64.b64decode(KEY_B64)

if len(SECRET_KEY) != 32:
    raise ValueError("Key must be 32 bytes for AES-256")

# ==========================================================
# ENCRYPT FUNCTION
# ==========================================================

def encrypt_aes256_gcm(plaintext: str, aad: Optional[bytes] = None) -> str:
    aesgcm = AESGCM(SECRET_KEY)

    nonce = os.urandom(12)  # 12-byte nonce
    ciphertext = aesgcm.encrypt(nonce, plaintext.encode("utf-8"), aad)

    combined = nonce + ciphertext
    return base64.b64encode(combined).decode("ascii")


# ==========================================================
# MAIN
# ==========================================================

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 encrypt.py '<payload>'")
        sys.exit(1)

    payload = sys.argv[1]
    encrypted = encrypt_aes256_gcm(payload)

    print(encrypted)
