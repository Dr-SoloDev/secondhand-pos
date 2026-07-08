<?php
class UsersController extends Controller
{
    public function getAllUsers()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        $userModel = new User();
        $users = $userModel->getAllWithLastLogin();

        Response::success('Users retrieved', $users);
    }

    public function createUser()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['username', 'password', 'email', 'full_name', 'role']);

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Validate role
        $validRoles = ['admin', 'manager', 'cashier'];
        if (!in_array($data['role'], $validRoles)) {
            Response::error('Invalid role', 400);
        }

        // Create user
        $userModel = new User();

        try {
            $userId = $userModel->create([
                'username' => $data['username'],
                'password' => $data['password'],
                'email' => $data['email'],
                'full_name' => $data['full_name'],
                'role' => $data['role'],
                'status' => isset($data['status']) ? $data['status'] : 'active'
            ]);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'create_user',
                "Created user: {$data['username']}"
            );

            Response::success('User created', ['id' => $userId]);
        } catch (Exception $e) {
            error_log('User create failed: ' . $e->getMessage());
            Response::error('Failed to create user', 500);
        }
    }

    /**
     * @param $id
     */
    public function getUser($id)
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('User ID is required', 400);
        }

        $userModel = new User();
        $user = $userModel->findById($id);

        if (!$user) {
            Response::error('User not found', 404);
        }

        // Remove sensitive data
        unset($user['password']);

        Response::success('User retrieved', $user);
    }

    /**
     * @param $id
     */
    public function updateUser($id)
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('User ID is required', 400);
        }

        // Get and validate request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Validate role if provided
        if (isset($data['role'])) {
            $validRoles = ['admin', 'manager', 'cashier'];
            if (!in_array($data['role'], $validRoles)) {
                Response::error('Invalid role', 400);
            }
        }

        // Get existing user
        $userModel = new User();
        $user = $userModel->findById($id);

        if (!$user) {
            Response::error('User not found', 404);
        }

        // Prevent updating own role or status (admin cannot demote themselves)
        if ((int)$id === (int)$this->user['user_id']) {
            unset($data['role']);
            unset($data['status']);
        }

        // Check email uniqueness
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            $emailExists = $userModel->findByEmail($data['email']);
            if ($emailExists) {
                Response::error('Email already in use by another user', 400);
            }
        }

        // Remove username from update data (cannot be changed)
        unset($data['username']);

        // Remove password from update data (use separate endpoint for this)
        unset($data['password']);

        try {
            $userModel->update($id, $data);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_user',
                "Updated user: {$user['username']}"
            );

            Response::success('User updated');
        } catch (Exception $e) {
            error_log('User update failed: ' . $e->getMessage());
            Response::error('Failed to update user', 500);
        }
    }

    /**
     * @param $id
     */
    public function deleteUser($id)
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('User ID is required', 400);
        }

        // Cannot delete self
        if ($id == $this->user['user_id']) {
            Response::error('You cannot delete your own account', 400);
        }

        // Get user for logging
        $userModel = new User();
        $user = $userModel->findById($id);

        if (!$user) {
            Response::error('User not found', 404);
        }

        try {
            $userModel->delete($id);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'delete_user',
                "Deleted user: {$user['username']}"
            );

            Response::success('User deleted');
        } catch (Exception $e) {
            error_log('User delete failed: ' . $e->getMessage());
            Response::error('Failed to delete user', 500);
        }
    }

    public function changePassword()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['user_id', 'new_password']);

        $userId = intval($data['user_id']);
        $newPassword = $data['new_password'];

        // Validate password length
        if (strlen($newPassword) < 12) {
            Response::error('รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร', 400);
        }

        // Update password
        $userModel = new User();

        // Check if user exists
        $user = $userModel->findById($userId);
        if (!$user) {
            Response::error('User not found', 404);
        }

        try {
            $userModel->updatePassword($userId, $newPassword);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'change_password',
                "Changed password for user: {$user['username']}"
            );

            Response::success('Password changed successfully');
        } catch (Exception $e) {
            error_log('Password change failed: ' . $e->getMessage());
            Response::error('Failed to change password', 500);
        }
    }

    public function getActivityLog()
    {
        // Check permissions
        $this->requireAuth(['admin']);

        // Get pagination params
        $pagination = $this->getPaginationParams();

        // Get filter params
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

        // Get activity log
        $activityLog = Logger::getActivityLog($userId, $pagination['page'], $pagination['limit']);

        Response::success('Activity logs retrieved', $activityLog);
    }

    public function getProfile()
    {
        $this->requireAuth();
        $userId = $this->user['user_id'];

        $userModel = new User();
        $user = $userModel->findById($userId);

        if (!$user) {
            Response::error('User not found', 404);
        }

        // Remove sensitive data
        unset($user['password']);

        Response::success('Profile retrieved', $user);
    }

    public function updateProfile()
    {
        $this->requireAuth();
        $userId = $this->user['user_id'];

        // Get and validate request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Only allow updating full_name and email
        $updateData = [];

        if (isset($data['full_name'])) {
            $updateData['full_name'] = $data['full_name'];
        }

        if (isset($data['email'])) {
            // Check email uniqueness
            $userModel = new User();
            $emailExists = $userModel->findByEmail($data['email']);

            if ($emailExists && $emailExists['id'] != $userId) {
                Response::error('Email already in use by another user', 400);
            }

            $updateData['email'] = $data['email'];
        }

        if (empty($updateData)) {
            Response::success('No changes to update');
        }

        try {
            $userModel = new User();
            $userModel->update($userId, $updateData);

            // Log activity
            Logger::logActivity(
                $userId,
                'update_profile',
                "Updated own profile"
            );

            Response::success('Profile updated');
        } catch (Exception $e) {
            error_log('Profile update failed: ' . $e->getMessage());
            Response::error('Failed to update profile', 500);
        }
    }

    public function changeOwnPassword()
    {
        $this->requireAuth();
        $userId = $this->user['user_id'];

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['current_password', 'new_password']);

        $currentPassword = $data['current_password'];
        $newPassword = $data['new_password'];

        // Validate password length
        if (strlen($newPassword) < 12) {
            Response::error('รหัสผ่านต้องมีอย่างน้อย 12 ตัวอักษร', 400);
        }

        // Check current password
        $userModel = new User();
        $user = $userModel->findById($userId);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            Response::error('Current password is incorrect', 400);
        }

        try {
            $userModel->updatePassword($userId, $newPassword);

            // Log activity
            Logger::logActivity(
                $userId,
                'change_own_password',
                "Changed own password"
            );

            Response::success('Password changed successfully');
        } catch (Exception $e) {
            error_log('Own password change failed: ' . $e->getMessage());
            Response::error('Failed to change password', 500);
        }
}
}
