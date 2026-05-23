<?php
class AuthController extends Controller
{
    public function login()
    {
        // Get POST data
        $data = $this->getRequestData();

        // Validate input
        $this->validateRequiredFields($data, ['username', 'password']);

        $username = $this->sanitizeInput($data['username']);
        $password = $data['password'];

        // Check user
        $userModel = new User();
        $user = $userModel->findByUsername($username);

        if (!$user || !password_verify($password, $user['password'])) {
            Response::error('Invalid username or password', 401);
            exit;
        }

        if ($user['status'] !== 'active') {
            Response::error('Account is inactive', 403);
            exit;
        }

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
