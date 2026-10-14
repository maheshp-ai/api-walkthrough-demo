import java.nio.charset.StandardCharsets;
import java.util.Arrays;
import java.util.Base64;
import javax.crypto.Cipher;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;
import javax.crypto.spec.SecretKeySpec;

public class Decrypt {

    // ==========================================================
    // CONFIG (32-byte key for AES-256 - Replace with your actual Base64 key)
    // ==========================================================
    private static final String KEY_B64 = "2POm3AlTZ4Ci7Cz8aWfacDPC4wIkDtVAQ2cti0l2S2A=";
    private static final int GCM_TAG_LENGTH_BITS = 128; // Standard 16-byte GCM tag
    private static final int NONCE_LENGTH_BYTES = 12;   // Standard 12-byte nonce

    public static String decryptAes256Gcm(String encryptedB64, byte[] aad) throws Exception {
        // Decode Base64 Key
        byte[] keyBytes = Base64.getDecoder().decode(KEY_B64);
        if (keyBytes.length != 32) {
            throw new IllegalArgumentException("Key must be 32 bytes for AES-256");
        }

        SecretKey secretKey = new SecretKeySpec(keyBytes, "AES");

        // Decode input Base64 string
        byte[] combined = Base64.getDecoder().decode(encryptedB64);

        if (combined.length < NONCE_LENGTH_BYTES + (GCM_TAG_LENGTH_BITS / 8)) {
            throw new IllegalArgumentException("Invalid payload length");
        }

        // Extract 12-byte Nonce and Ciphertext
        byte[] nonce = Arrays.copyOfRange(combined, 0, NONCE_LENGTH_BYTES);
        byte[] ciphertext = Arrays.copyOfRange(combined, NONCE_LENGTH_BYTES, combined.length);

        // Initialize AES-GCM Cipher for Decryption
        Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
        GCMParameterSpec gcmSpec = new GCMParameterSpec(GCM_TAG_LENGTH_BITS, nonce);
        cipher.init(Cipher.DECRYPT_MODE, secretKey, gcmSpec);

        // Add Associated Authenticated Data (AAD) if present
        if (aad != null && aad.length > 0) {
            cipher.updateAAD(aad);
        }

        // Decrypt (Authenticates the tag and decrypts)
        byte[] plaintextBytes = cipher.doFinal(ciphertext);

        return new String(plaintextBytes, StandardCharsets.UTF_8);
    }

    public static void main(String[] args) {
        if (args.length != 1) {
            System.out.println("Usage: java Decrypt '<encrypted_base64>'");
            System.exit(1);
        }

        String encryptedInput = args[0];

        try {
            String decrypted = decryptAes256Gcm(encryptedInput, null);
            System.out.println(decrypted);
        } catch (Exception e) {
            System.out.println("Decryption failed: " + e.getMessage());
        }
    }
}
