<?php
class Auth
{
    /**
     * @var mixed
     */
    private static $user = null;

    public static function user()
    {
        return self::$user;
    }

    /**
     * @param $username
     * @param $password
     */
    public static function attempt($username, $password)
    {
        $db = Database::getInstance();

        $user = $db->fetch(
            "SELECT id, username, password, full_name, email, role, status, branch_id FROM users WHERE username = ?",
            [$username]
        );

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user['password'])) {
            return false;
        }

        if ($user['status'] !== 'active') {
            return false;
        }

        // Remove password from user array
        unset($user['password']);

        self::$user = $user;
        return true;
    }

    /**
     * @param array $roles
     */
    public static function checkPermission($roles = [])
    {
        if (!self::$user) {
            return false;
        }

        if (empty($roles)) {
            return true;
        }

        return in_array(self::$user['role'], $roles);
    }

    /**
     * @param $user
     */
    public static function login($user)
    {
        // Remove password from user array if exists
        if (isset($user['password'])) {
            unset($user['password']);
        }

        self::$user = $user;

        // Generate token
        return TokenService::generate($user['id'], $user['username'], $user['role']);
    }
}
