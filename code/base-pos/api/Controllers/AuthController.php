<?php
class AuthController extends Controller
{
    private function checkRateLimit($username)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'login_attempts_' . md5($ip . '_' . $username);
        $filePath = sys_get_temp_dir() . '/' . $key;

        $attempts = [];
        if (file_exists($filePath)) {
            $data = file_get_contents($filePath);
            $attempts = json_decode($data, true) ?: [];
        }

        // Remove entries older than 15 minutes
        $window = time() - 900;
        $attempts = array_filter($attempts, fn($t) => $t > $window);

        if (count($attempts) >= 5) {
            $retryAfter = 900 - (time() - min($attempts));
            Response::error('Too many login attempts. Try again in ' . ceil($retryAfter / 60) . ' minutes.', 429);
            exit;
        }

        $attempts[] = time();
        file_put_contents($filePath, json_encode($attempts), LOCK_EX);
    }

    private function clearRateLimit($username)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'login_attempts_' . md5($ip . '_' . $username);
        $filePath = sys_get_temp_dir() . '/' . $key;

        if (file_exists($filePath)) {
            unlink($filePath);
        }
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
        $this->checkRateLimit($username);

        // Check user
        $userModel = new User();
        $user = $userModel->findByUsername($username);

        if (!$user || !password_verify($password, $user['password'])) {
            // Log failed attempt
            error_log("Failed login attempt for username: {$username} from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            Response::error('Invalid username or password', 401);
            exit;
        }

        if ($user['status'] !== 'active') {
            error_log("Inactive account login attempt for username: {$username} from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            Response::error('Account is inactive', 403);
            exit;
        }

        // Clear rate limit on success
        $this->clearRateLimit($username);

        // Generate token
        $token = TokenService::generate($user['id'], $user['username'], $user['role']);

        // Log activity
        Logger::logActivity($user['id'], 'login', 'User logged in successfully');

        // Remove password before sending response
        unset($user['password']);

        Response::success('Login successful', [
            'token' => $token,
            'user' => $user
        ]);
    }

    public function verify()
    {
        // Get data
        $data = $this->getRequestData();

        // Validate input
        $this->validateRequiredFields($data, ['token']);

        $token = $data['token'];
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
}
