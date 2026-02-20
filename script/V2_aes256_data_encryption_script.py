import base64
import sys
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives import padding
from cryptography.hazmat.backends import default_backend

def encrypt_with_string_key_iv(key_string, iv_string, plaintext):
    # Convert strings to UTF-8 bytes (matching Java's getBytes(StandardCharsets.UTF_8))
    key_bytes = key_string.encode('utf-8')  # Will be exactly 32 bytes
    iv_bytes = iv_string.encode('utf-8')    # Will be exactly 16 bytes
    
    print(f"Key length: {len(key_bytes)} bytes")
    print(f"IV length: {len(iv_bytes)} bytes")
    
    # Convert plaintext to bytes using default encoding
    plaintext_bytes = plaintext.encode()
    
    # Apply PKCS7 padding
    padder = padding.PKCS7(128).padder()
    padded_data = padder.update(plaintext_bytes) + padder.finalize()

    # Create AES cipher in CBC mode
    cipher = Cipher(algorithms.AES(key_bytes), modes.CBC(iv_bytes), backend=default_backend())
    encryptor = cipher.encryptor()
    
    # Encrypt the padded data
    encrypted_bytes = encryptor.update(padded_data) + encryptor.finalize()

    # Return Base64-encoded encrypted string
    return base64.b64encode(encrypted_bytes).decode('utf-8')

def decrypt_with_string_key_iv(key_string, iv_string, encrypted_base64):
    # Convert strings to UTF-8 bytes (matching Java's behavior)
    key_bytes = key_string.encode('utf-8')
    iv_bytes = iv_string.encode('utf-8')
    
    # Decode the encrypted data from Base64
    encrypted_bytes = base64.b64decode(encrypted_base64)

    # Create AES cipher in CBC mode
    cipher = Cipher(algorithms.AES(key_bytes), modes.CBC(iv_bytes), backend=default_backend())
    decryptor = cipher.decryptor()
    
    # Decrypt the data
    decrypted_padded = decryptor.update(encrypted_bytes) + decryptor.finalize()
    
    # Remove PKCS7 padding
    unpadder = padding.PKCS7(128).unpadder()
    decrypted_bytes = unpadder.update(decrypted_padded) + unpadder.finalize()

    # Convert back to string using default encoding
    return decrypted_bytes.decode()

# Example usage
if __name__ == "__main__":
    # Use exactly these strings that are 32 and 16 bytes respectively
    key_string = "JjqJxxJJxDsYyJiVwWavaP0AejZb5nW4"
    iv_string = "5TOMPCQvDu6uHuMw"    
    
    if len(sys.argv) != 2:
        print("Usage: python3 encrypt_aes.py <plaintext>")
        print("Example: python3 encrypt_aes.py 'Hello World'")
        sys.exit(1)
    
    plain_input = sys.argv[1]
    
    try:
        print(f"Using Key: {key_string}")
        print(f"Using IV:  {iv_string}")
        
        # Encrypt with Python
        encrypted = encrypt_with_string_key_iv(key_string, iv_string, plain_input)
        print(f"\nEncrypted String (Base64): {encrypted}")
        
        # Test decryption with Python
        decrypted = decrypt_with_string_key_iv(key_string, iv_string, encrypted)
        print(f"Decrypted String: {decrypted}")
        print(f"Match: {plain_input == decrypted}")
        
    except Exception as e:
        print(f"Error: {e}")
        import traceback
        traceback.print_exc()
