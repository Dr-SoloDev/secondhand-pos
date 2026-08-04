<?php
class TokenService
{
    private static function base64urlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * @param $userId
     * @param $username
     * @param $role
     */
    public static function generate($userId, $username, $role, $branchId = null, $authVersion = 1)
    {
        $issuedAt = time();
        $expiryTime = $issuedAt + JWT_EXPIRY;

        $header = self::base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));

        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $expiryTime,
            'user_id' => $userId,
            'username' => $username,
            'role' => $role,
            'branch_id' => $branchId !== null ? (int)$branchId : null,
            'auth_version' => (int)$authVersion,
        ];
        $payloadEncoded = self::base64urlEncode(json_encode($payload));

        $signature = self::base64urlEncode(
            hash_hmac('sha256', "$header.$payloadEncoded", JWT_SECRET, true)
        );

        return "$header.$payloadEncoded.$signature";
    }

    /**
     * @param $token
     * @return mixed
     */
    public static function validate($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($header, $payload, $signature) = $parts;

        // Verify signature
        $expectedSig = self::base64urlEncode(
            hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
        );

        if (!hash_equals($expectedSig, $signature)) {
            return false;
        }

        // Verify algorithm to prevent alg confusion
        $headerDecoded = json_decode(self::base64urlDecode($header), true);
        if (!$headerDecoded || ($headerDecoded['alg'] ?? '') !== 'HS256') {
            return false;
        }

        $decoded = json_decode(self::base64urlDecode($payload), true);
        if (!$decoded) {
            return false;
        }

        // Check expiry
        if ($decoded['exp'] < time()) {
            return false;
        }

        // Check "not before"
        if (isset($decoded['nbf']) && $decoded['nbf'] > time()) {
            return false;
        }

        // Check blocklist
        $db = Database::getInstance();
        $blocked = $db->fetchColumn(
            "SELECT 1 FROM token_blocklist WHERE jti = ? AND expires_at > NOW()",
            [$decoded['jti'] ?? '']
        );
        if ($blocked) {
            return false;
        }

        return $decoded;
    }

    public static function revokeToken(string $jti, int $exp): void
    {
        $db = Database::getInstance();
        $db->query(
            "INSERT IGNORE INTO token_blocklist (jti, expires_at) VALUES (?, FROM_UNIXTIME(?))",
            [$jti, $exp]
        );
    }

    public static function pruneExpired(): void
    {
        Database::getInstance()->query("DELETE FROM token_blocklist WHERE expires_at < NOW()");
    }

    /**
     * @param $token
     */
    public static function refreshToken($token)
    {
        $decoded = self::validate($token);
        if (!$decoded) {
            return false;
        }

        return self::generate($decoded['user_id'], $decoded['username'], $decoded['role'], $decoded['branch_id'] ?? null);
    }
}
