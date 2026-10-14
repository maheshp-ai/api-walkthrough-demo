import java.security.SecureRandom;
import java.util.Base64;

public class GenerateCredentials {

    public static void main(String[] args) {
        // 1. Generate cryptographically secure random bytes
        // AES-256 requires 32 bytes (256 bits)
        // AES typically uses a 16-byte (128-bit) IV / Nonce
        byte[] rawKey = new byte[32];
        byte[] rawIv = new byte[16];

        SecureRandom secureRandom = new SecureRandom();
        secureRandom.nextBytes(rawKey);
        secureRandom.nextBytes(rawIv);

        // 2. Encode bytes into Base64 strings
        String keyB64 = Base64.getEncoder().encodeToString(rawKey);
        String ivB64 = Base64.getEncoder().encodeToString(rawIv);

        // --- Output ---
        System.out.println("=== AES Base64 Credentials ===\n");
        System.out.println("KEY_B64 = \"" + keyB64 + "\"");
        System.out.println("IV_B64  = \"" + ivB64 + "\"");

        System.out.println("\n// How to use in your main Java script:");
        System.out.println("import java.util.Base64;");
        System.out.println("byte[] secretKey = Base64.getDecoder().decode(\"" + keyB64 + "\");");
        System.out.println("byte[] iv = Base64.getDecoder().decode(\"" + ivB64 + "\");");
    }
}
