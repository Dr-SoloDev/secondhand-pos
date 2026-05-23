<?php
class TokenService
{
    /**
     * @param $userId
     * @param $username
     * @param $role
     */
    public static function generate($userId, $username, $role)
    {
        $issuedAt = time();
        $expiryTime = $issuedAt + JWT_EXPIRY;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expiryTime,
            'user_id' => $userId,
            'username' => $username,
            'role' => $role
        ];

        $header = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64_encode(json_encode($payload));
        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

        return "$header.$payload.$signature";
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
        $verifySignature = base64_encode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));

        if ($signature !== $verifySignature) {
            return false;
        }

        $decoded = json_decode(base64_decode($payload), true);
        if ($decoded['exp'] < time()) {
            return false;
        }

        return $decoded;
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

        return self::generate($decoded['user_id'], $decoded['username'], $decoded['role']);
    }
}
