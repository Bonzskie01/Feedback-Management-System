<?php
/**
 * TOTP Helper for Two-Factor Authentication
 * Implements RFC 6238 (Time-based One-Time Password)
 */
class TOTP
{
    private static $base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a random Base32 secret
     * @param int $length Desired length (default 16)
     * @return string
     */
    public static function generateSecret($length = 16)
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::$base32Chars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Generate TOTP code for a given secret and time slice
     * @param string $secret Base32 secret
     * @param int|null $timeSlice Unix timestamp / 30 (optional)
     * @return string 6-digit code
     */
    public static function getCode($secret, $timeSlice = null)
    {
        if ($timeSlice === null) {
            $timeSlice = floor(time() / 30);
        }
        $decodedSecret = self::base32Decode($secret);
        $time = pack('N', $timeSlice);
        $time = str_pad($time, 8, chr(0), STR_PAD_LEFT);
        $hash = hash_hmac('sha1', $time, $decodedSecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-provided TOTP code
     * @param string $secret Base32 secret
     * @param string $code User's 6-digit code
     * @param int $drift Allowed time slices (default 1 = ±30 sec)
     * @return bool
     */
    public static function verifyCode($secret, $code, $drift = 1)
    {
        return self::verifyCodeAndGetCounter($secret, $code, $drift) !== false;
    }

    /**
     * Verify a TOTP code and return the matching 30-second counter.
     * The counter can be persisted to prevent a valid code from being replayed.
     *
     * @param string $secret Base32 secret
     * @param string $code User's 6-digit code
     * @param int $drift Allowed time slices
     * @return int|false Matching counter, or false if invalid
     */
    public static function verifyCodeAndGetCounter($secret, $code, $drift = 1)
    {
        $code = (string) $code;
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $currentCounter = (int) floor(time() / 30);
        for ($i = -$drift; $i <= $drift; $i++) {
            $candidateCounter = $currentCounter + $i;
            if (hash_equals(self::getCode($secret, $candidateCounter), $code)) {
                return $candidateCounter;
            }
        }
        return false;
    }
    /**
     * Generate a QR code URL for Google Authenticator or similar apps
     * @param string $issuer System name (e.g., "VotingSystem")
     * @param string $account User identifier (email or username)
     * @param string $secret Base32 secret
     * @return string otpauth:// URL
     */
    public static function getQRCodeUrl($issuer, $account, $secret)
    {
        return "otpauth://totp/" . rawurlencode($issuer) . ":" . rawurlencode($account) .
               "?secret=" . $secret . "&issuer=" . rawurlencode($issuer);
    }

    /**
     * Decode a Base32 string to binary
     * @param string $input
     * @return string
     */
    private static function base32Decode($input)
    {
        $input = strtoupper($input);
        $output = '';
        $buffer = 0;
        $bits = 0;
        for ($i = 0; $i < strlen($input); $i++) {
            $char = $input[$i];
            if ($char === '=') break;
            $val = strpos(self::$base32Chars, $char);
            if ($val === false) continue;
            $buffer = ($buffer << 5) | $val;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $output;
    }

    /**
     * Generate backup codes (plain text)
     * @param int $count Number of codes (default 5)
     * @param int $length Length of each code (default 8)
     * @return array
     */
    public static function generateBackupCodes($count = 5, $length = 8)
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes($length / 2));
        }
        return $codes;
    }

    /**
     * Hash an array of backup codes for storage
     * @param array $codes Plain backup codes
     * @return string JSON-encoded array of hashes
     */
    public static function hashBackupCodes($codes)
    {
        $hashed = [];
        foreach ($codes as $code) {
            $hashed[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return json_encode($hashed);
    }

    /**
     * Verify a backup code and return the updated hash list (or false)
     * @param string $inputCode User-submitted backup code
     * @param string|null $storedHashesJson JSON array of hashed backup codes
     * @return string|false New JSON of remaining hashes, or false if invalid
     */
    public static function verifyBackupCode($inputCode, $storedHashesJson)
    {
        if (!$storedHashesJson) return false;
        $hashes = json_decode($storedHashesJson, true);
        if (!is_array($hashes)) return false;

        foreach ($hashes as $index => $hash) {
            if (password_verify($inputCode, $hash)) {
                // Remove used code
                unset($hashes[$index]);
                // Re-index and return new JSON
                return json_encode(array_values($hashes));
            }
        }
        return false;
    }
}
?>