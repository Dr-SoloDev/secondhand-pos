<?php
class SellerIdCipher
{
    const KEY_VERSION = 1;
    private static $encryptionKey;
    private static $searchKey;

    public static function encrypt($idCard)
    {
        $idCard = self::normalize($idCard);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $idCard,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Unable to encrypt seller ID card');
        }
        return 'v1.' . self::base64UrlEncode($nonce . $tag . $ciphertext);
    }

    public static function decrypt($payload)
    {
        if (!is_string($payload) || strpos($payload, 'v1.') !== 0) {
            throw new RuntimeException('Unsupported seller ID encryption format');
        }
        $binary = self::base64UrlDecode(substr($payload, 3));
        if (strlen($binary) < 29) {
            throw new RuntimeException('Invalid seller ID ciphertext');
        }
        $nonce = substr($binary, 0, 12);
        $tag = substr($binary, 12, 16);
        $ciphertext = substr($binary, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt seller ID card; verify encryption key');
        }
        return self::normalize($plaintext);
    }

    public static function searchHash($idCard)
    {
        return hash_hmac('sha256', self::normalize($idCard), self::searchKey());
    }

    public static function normalize($idCard)
    {
        $digits = preg_replace('/\D/', '', (string)$idCard);
        if (strlen($digits) !== 13) {
            throw new InvalidArgumentException('Seller ID card must contain exactly 13 digits');
        }
        return $digits;
    }

    public static function resetForTests()
    {
        self::$encryptionKey = null;
        self::$searchKey = null;
    }

    private static function encryptionKey()
    {
        if (self::$encryptionKey !== null) return self::$encryptionKey;
        $material = trim((string)getenv('SELLER_ID_ENCRYPTION_KEY'));
        $appEnvironment = strtolower((string)(getenv('APP_ENV') ?: 'development'));
        $usingDevelopmentFallback = false;
        if ($material === '') {
            if ($appEnvironment === 'production') {
                throw new RuntimeException('SELLER_ID_ENCRYPTION_KEY is required in production');
            }
            $material = (string)getenv('JWT_SECRET');
            $usingDevelopmentFallback = true;
        }
        if ($material === '' || (!$usingDevelopmentFallback && strlen($material) < 32)) {
            throw new RuntimeException('SELLER_ID_ENCRYPTION_KEY must contain at least 32 characters');
        }
        self::$encryptionKey = hash('sha256', "seller-id-encryption-v1\0" . $material, true);
        return self::$encryptionKey;
    }

    private static function searchKey()
    {
        if (self::$searchKey === null) {
            self::$searchKey = hash_hmac('sha256', 'seller-id-search-v1', self::encryptionKey(), true);
        }
        return self::$searchKey;
    }

    private static function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($value)
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new RuntimeException('Invalid seller ID ciphertext encoding');
        }
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new RuntimeException('Invalid seller ID ciphertext encoding');
        return $decoded;
    }
}
