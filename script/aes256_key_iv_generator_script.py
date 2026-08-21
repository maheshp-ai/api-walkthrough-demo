import secrets
import base64

def generate_aes_b64_credentials():
    # 1. Generate cryptographically secure random bytes
    # AES-256 requires a 32-byte key
    # AES typically uses a 16-byte IV
    raw_key = secrets.token_bytes(32)
    raw_iv = secrets.token_bytes(16)

    # 2. Encode those bytes into Base64 strings
    key_b64 = base64.b64encode(raw_key).decode('utf-8')
    iv_b64 = base64.b64encode(raw_iv).decode('utf-8')

    return key_b64, iv_b64

# --- Execution ---
key_b64, iv_b64 = generate_aes_b64_credentials()

print(f"=== AES Base64 Credentials ===\n")
print(f'KEY_B64 = "{key_b64}"')
print(f'IV_B64  = "{iv_b64}"')

print("\n# How to use in your main script:")
print("import base64")
print(f"SECRET_KEY = base64.b64decode('{key_b64}')")
print(f"IV = base64.b64decode('{iv_b64}')")
