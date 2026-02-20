import sys
import base64
from typing import Optional
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

# ==========================================================
# CONFIG (Same key as encrypt.py)
# ==========================================================

KEY_B64 = "pE5nm9GhrRFNRjCanEitQtMV4lyHhNrgD/6GvlZloHE="
SECRET_KEY = base64.b64decode(KEY_B64)

if len(SECRET_KEY) != 32:
    raise ValueError("Key must be 32 bytes for AES-256")

# ==========================================================
# DECRYPT FUNCTION
# ==========================================================

def decrypt_aes256_gcm(encrypted_b64: str, aad: Optional[bytes] = None) -> str:
    aesgcm = AESGCM(SECRET_KEY)

    combined = base64.b64decode(encrypted_b64)

    nonce = combined[:12]
    ciphertext = combined[12:]

    plaintext = aesgcm.decrypt(nonce, ciphertext, aad)
    return plaintext.decode("utf-8")


# ==========================================================
# MAIN
# ==========================================================

if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 decrypt.py '<encrypted_base64>'")
        sys.exit(1)

    encrypted_input = sys.argv[1]

    try:
        decrypted = decrypt_aes256_gcm(encrypted_input)
        print(decrypted)
    except Exception as e:
        print("Decryption failed:", str(e))
