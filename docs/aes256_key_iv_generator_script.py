import secrets
import string
import base64
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes

def generate_random_string_key_iv():
    """
    Generate random 32-byte key and 16-byte IV as strings that can be 
    directly used with the first script's UTF-8 encoding approach.
    """
    # Generate cryptographically secure random strings
    # Using printable ASCII characters to ensure they encode to expected byte lengths
    
    # For 32-byte key: use letters and numbers
    key_chars = string.ascii_letters + string.digits
    key_string = ''.join(secrets.choice(key_chars) for _ in range(32))
    
    # For 16-byte IV: use letters and numbers  
    iv_string = ''.join(secrets.choice(key_chars) for _ in range(16))
    
    return key_string, iv_string

def validate_key_iv_lengths(key_string, iv_string):
    """
    Validate that the key and IV strings will produce the correct byte lengths
    when encoded as UTF-8.
    """
    key_bytes = key_string.encode('utf-8')
    iv_bytes = iv_string.encode('utf-8')
    
    print(f"Key string: '{key_string}'")
    print(f"Key length: {len(key_string)} characters, {len(key_bytes)} bytes")
    print(f"IV string: '{iv_string}'")
    print(f"IV length: {len(iv_string)} characters, {len(iv_bytes)} bytes")
    
    if len(key_bytes) != 32:
        print(f"⚠️  WARNING: Key is {len(key_bytes)} bytes, should be 32 bytes")
        return False
    if len(iv_bytes) != 16:
        print(f"⚠️  WARNING: IV is {len(iv_bytes)} bytes, should be 16 bytes")
        return False
    
    print("✅ Key and IV lengths are correct!")
    return True

if __name__ == "__main__":
    print("=== AES Key and IV Generator ===\n")
    
    print("Method 1: Random strings (for use with UTF-8 encoding)")
    print("-" * 55)
    key_string, iv_string = generate_random_string_key_iv()
    validate_key_iv_lengths(key_string, iv_string)
    
    print(f"\n# Copy these values for your first script:")
    print(f'key_string = "{key_string}"')
    print(f'iv_string = "{iv_string}"')
    
    print("\nSecurity Notes:")
    print("• Generated strings use alphanumeric characters (62 possible per position)")
    print("• This provides cryptographically secure randomness for AES encryption")
    print("• Store these values securely - anyone with key+IV can decrypt your data")
    print("• Generate new key+IV pairs for different applications/users")
    print("• Never reuse the same key+IV combination")
