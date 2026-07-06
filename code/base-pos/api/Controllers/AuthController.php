<?php
class AuthController extends Controller
{
    private function checkRateLimit()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db = Database::getInstance();
        $row = $db->fetch("SELECT attempts, window_start FROM login_attempts WHERE ip = ?", [$ip]);

        if ($row) {
            $windowAge = time() - strtotime($row['window_start']);
            if ($windowAge < 900 && $row['attempts'] >= 5) {
                $retryAfter = ceil((900 - $windowAge) / 60);
                Response::error("Too many login attempts. Try again in {$retryAfter} minutes.", 429);
                exit;
            }
        }
    }

    private function recordFailedAttempt()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db = Database::getInstance();
        $db->query(
            "INSERT INTO login_attempts (ip, attempts, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               attempts = IF(window_start < NOW() - INTERVAL 15 MINUTE, 1, attempts + 1),
               window_start = IF(window_start < NOW() - INTERVAL 15 MINUTE, NOW(), window_start)",
            [$ip]
        );
    }

    private function clearRateLimit()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        Database::getInstance()->query("DELETE FROM login_attempts WHERE ip = ?", [$ip]);
    }

    public function login()
    {
        // Get POST data
        $data = $this->getRequestData();

        // Validate input
        $this->validateRequiredFields($data, ['username', 'password']);

        $username = $this->sanitizeInput($data['username']);
        $password = $data['password'];

        // Check rate limit
        $this->checkRateLimit();

        // Check user
        $userModel = new User();
        $user = $userModel->findByUsername($username);

        if (!$user || !password_verify($password, $user['password'])) {
            $this->recordFailedAttempt();
            error_log("Failed login attempt for username: {$username} from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            Response::error('Invalid username or password', 401);
            exit;
        }

        if ($user['status'] !== 'active') {
            $this->recordFailedAttempt();
            error_log("Inactive account login attempt for username: {$username} from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            Response::error('Account is inactive', 403);
            exit;
        }

        // Clear rate limit on success
        $this->clearRateLimit();
        TokenService::pruneExpired();

        // Generate token
        $token = TokenService::generate($user['id'], $user['username'], $user['role'], $user['branch_id'] ?? null);

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
