import java.nio.charset.StandardCharsets;
import java.security.SecureRandom;
import java.util.Base64;
import javax.crypto.Cipher;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.SecretKeySpec;

public class Encrypt {

    // ==========================================================
    // CONFIG (32-byte key for AES-256)
    // ==========================================================
    private static final String KEY_B64 = "Vk0xak1MQ2JBKzZzV3crYU1aTmpseERjVkZ1RzFCSno=";
    private static final int GCM_TAG_LENGTH_BITS = 128; // Standard 16-byte GCM tag
    private static final int NONCE_LENGTH_BYTES = 12;   // Standard 12-byte nonce

    public static String encryptAes256Gcm(String plaintext, byte[] aad) throws Exception {
        // Decode Base64 Key
        byte[] keyBytes = Base64.getDecoder().decode(KEY_B64);
        if (keyBytes.length != 32) {
            throw new IllegalArgumentException("Key must be 32 bytes for AES-256");
        }

        SecretKey secretKey = new SecretKeySpec(keyBytes, "AES");

        // Generate 12-byte random nonce
        byte[] nonce = new byte[NONCE_LENGTH_BYTES];
        SecureRandom random = new SecureRandom();
        random.nextBytes(nonce);

        // Initialize AES-GCM Cipher
        Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
        GCMParameterSpec gcmSpec = new GCMParameterSpec(GCM_TAG_LENGTH_BITS, nonce);
        cipher.init(Cipher.ENCRYPT_MODE, secretKey, gcmSpec);

        // Add Associated Authenticated Data (AAD) if present
        if (aad != null && aad.length > 0) {
            cipher.updateAAD(aad);
        }

        // Encrypt (Ciphertext + 16-byte Auth Tag are generated automatically)
        byte[] ciphertext = cipher.doFinal(plaintext.getBytes(StandardCharsets.UTF_8));

        // Combine Nonce (12 bytes) + Ciphertext
        byte[] combined = new byte[nonce.length + ciphertext.length];
        System.arraycopy(nonce, 0, combined, 0, nonce.length);
        System.arraycopy(ciphertext, 0, combined, nonce.length, ciphertext.length);

        // Return Base64 encoded string
        return Base64.getEncoder().encodeToString(combined);
    }

    public static void main(String[] args) {
        if (args.length != 1) {
            System.out.println("Usage: java Encrypt '<payload>'");
            System.exit(1);
        }

        try {
            String payload = args[0];
            String encrypted = encryptAes256Gcm(payload, null);
            System.out.println(encrypted);
        } catch (Exception e) {
            System.err.println("Encryption failed: " + e.getMessage());
            e.printStackTrace();
            System.exit(1);
        }
    }
}
