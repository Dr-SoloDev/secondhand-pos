<?php
class AuthController extends Controller
{
    private const DEFAULT_LOGIN_MAX_ATTEMPTS = 5;
    private const DEFAULT_LOGIN_LOCKOUT_SECONDS = 120;

    private function getLoginMaxAttempts()
    {
        $configured = getenv('LOGIN_MAX_ATTEMPTS');
        if ($configured === false || $configured === '') {
            return self::DEFAULT_LOGIN_MAX_ATTEMPTS;
        }

        $value = intval($configured);
        if ($value <= 0) {
            return 0;
        }

        return max(3, min(100, $value));
    }

    private function getLoginLockoutSeconds()
    {
        $value = intval(getenv('LOGIN_LOCKOUT_SECONDS') ?: self::DEFAULT_LOGIN_LOCKOUT_SECONDS);
        return max(30, min(3600, $value));
    }

    private function formatRetryAfter($seconds)
    {
        if ($seconds < 60) {
            return "{$seconds} วินาที";
        }

        return ceil($seconds / 60) . ' นาที';
    }

    private function clientIp()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (getenv('TRUST_PROXY') !== 'true') {
            return $remote;
        }
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        $candidate = trim($forwarded[0] ?? '');
        return filter_var($candidate, FILTER_VALIDATE_IP) ? $candidate : $remote;
    }

    private function normalizeRateLimitUsername($username)
    {
        return mb_strtolower(trim($username), 'UTF-8');
    }

    private function checkRateLimit($username)
    {
        $ip = $this->clientIp();
        $rateLimitUsername = $this->normalizeRateLimitUsername($username);
        $db = Database::getInstance();
        $row = $db->fetch(
            "SELECT attempts, window_start FROM login_attempts WHERE ip = ? AND username = ?",
            [$ip, $rateLimitUsername]
        );
        $maxAttempts = $this->getLoginMaxAttempts();
        if ($maxAttempts <= 0) {
            return;
        }

        if ($row) {
            $windowAge = time() - strtotime($row['window_start']);
            $lockoutSeconds = $this->getLoginLockoutSeconds();
            if ($windowAge < $lockoutSeconds && $row['attempts'] >= $maxAttempts) {
                $retryAfter = $this->formatRetryAfter($lockoutSeconds - $windowAge);
                Response::error("ลองเข้าสู่ระบบผิดหลายครั้ง กรุณารอ {$retryAfter}", 429);
                exit;
            }
        }
    }

    private function recordFailedAttempt($username)
    {
        if ($this->getLoginMaxAttempts() <= 0) {
            return;
        }

        $ip = $this->clientIp();
        $rateLimitUsername = $this->normalizeRateLimitUsername($username);
        $db = Database::getInstance();
        $lockoutSeconds = $this->getLoginLockoutSeconds();
        $db->query(
            "INSERT INTO login_attempts (ip, username, attempts, window_start) VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               attempts = IF(window_start < NOW() - INTERVAL {$lockoutSeconds} SECOND, 1, attempts + 1),
               window_start = IF(window_start < NOW() - INTERVAL {$lockoutSeconds} SECOND, NOW(), window_start)",
            [$ip, $rateLimitUsername]
        );
    }

    private function clearRateLimit($username)
    {
        $ip = $this->clientIp();
        $rateLimitUsername = $this->normalizeRateLimitUsername($username);
        Database::getInstance()->query(
            "DELETE FROM login_attempts WHERE ip = ? AND username = ?",
            [$ip, $rateLimitUsername]
        );
    }

    public function login()
    {
        // Get POST data
        $data = $this->getRequestData();

        // Validate input
        $this->validateRequiredFields($data, ['username', 'password']);
        if (!is_string($data['username']) || !is_string($data['password'])) {
            Response::error('Username and password must be strings', 400);
            exit;
        }

        $username = $this->sanitizeInput($data['username'], 50);
        $password = $data['password'];

        // Check rate limit
        $this->checkRateLimit($username);

        // Check user
        $userModel = new User();
        $user = $userModel->findByUsername($username);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->recordFailedAttempt($username);
            error_log("Failed login attempt for username: {$username} from IP: " . $this->clientIp());
            Response::error('Invalid username or password', 401);
            exit;
        }

        if ($user['status'] !== 'active') {
            $this->recordFailedAttempt($username);
            error_log("Inactive account login attempt for username: {$username} from IP: " . $this->clientIp());
            Response::error('Account is inactive', 403);
            exit;
        }

        // Clear rate limit on success
        $this->clearRateLimit($username);
        TokenService::pruneExpired();

        // Generate token
        $token = TokenService::generate(
            $user['id'],
            $user['username'],
            $user['role'],
            $user['branch_id'] ?? null,
            $user['auth_version'] ?? 1
        );

        // Log activity
        Logger::logActivity($user['id'], 'login', 'User logged in successfully');

        // Remove password before sending response
        unset($user['password']);

        $cookieExpiry = time() + JWT_EXPIRY;
        $secure = getenv('APP_ENV') === 'production';
        setcookie('posToken', $token, [
            'expires' => $cookieExpiry,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => $secure,
        ]);
        setcookie('posUser', json_encode($user), [
            'expires' => $cookieExpiry,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => $secure,
        ]);

        Response::success('Login successful', ['user' => $user]);
    }

    public function verify()
    {
        // Get data (backward compat: token in body)
        $data = $this->getRequestData();
        $token = isset($data['token']) ? $data['token'] : '';

        // Fallback to cookie
        if (empty($token)) {
            $token = $_COOKIE['posToken'] ?? '';
        }

        if (empty($token)) {
            Response::error('Token is required', 401);
            exit;
        }

        $decoded = TokenService::validate($token);

        if (!$decoded) {
            Response::error('Invalid or expired token', 401);
            exit;
        }

        // Get user details
        $userModel = new User();
        $user = $userModel->findById($decoded['user_id']);

        if (!$user || $user['status'] !== 'active') {
            Response::error('User not found or inactive', 401);
            exit;
        }
        if ((int)($decoded['auth_version'] ?? 1) !== (int)($user['auth_version'] ?? 1)) {
            Response::error('Token has been invalidated', 401);
            exit;
        }

        // Remove password before sending response
        unset($user['password']);

        Response::success('Token is valid', [
            'user' => $user
        ]);
    }

    public function logout()
    {
        // F2: Try cookie first, then Authorization header (backward compat)
        $token = $_COOKIE['posToken'] ?? '';
        if (empty($token)) {
            $headers = getallheaders();
            $token = substr($headers['Authorization'] ?? '', 7);
        }

        $decoded = TokenService::validate($token);

        if ($decoded && isset($decoded['jti'])) {
            TokenService::revokeToken($decoded['jti'], $decoded['exp']);
        }

        // F2: Clear cookies
        $secure = getenv('APP_ENV') === 'production';
        setcookie('posToken', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure' => $secure,
        ]);
        setcookie('posUser', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'strict',
            'secure' => $secure,
        ]);

        Response::success('Logged out successfully');
    }
}
