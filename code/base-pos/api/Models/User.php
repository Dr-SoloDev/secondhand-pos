<?php
class User extends Model
{
    /**
     * @var string
     */
    protected $table = 'users';

    /**
     * @param $username
     * @return mixed
     */
    public function findByUsername($username)
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->table} WHERE username = ?",
            [$username]
        );
    }

    /**
     * @param $phone
     * @return mixed
     */
    public function findByPhone($phone)
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->table} WHERE phone = ?",
            [$phone]
        );
    }

    /**
     * @param $data
     * @return mixed
     */
    public function create($data)
    {
        // Validate unique username and phone
        if ($this->findByUsername($data['username'])) {
            throw new Exception('Username already exists');
        }

        if (isset($data['phone']) && $this->findByPhone($data['phone'])) {
            throw new Exception('Phone already exists');
        }

        // Hash password
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        return $this->insert($data);
    }

    /**
     * @param $userId
     * @param $newPassword
     * @return mixed
     */
    public function updatePassword($userId, $newPassword)
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        return $this->db->query(
            "UPDATE {$this->table}
             SET password = ?, auth_version = auth_version + 1
             WHERE id = ?",
            [$hashedPassword, $userId]
        );
    }

    /**
     * @return mixed
     */
    public function getAllWithLastLogin()
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.username, u.full_name, u.phone, u.role, u.status, u.branch_id,
                    b.name AS branch_name,
             (SELECT MAX(created_at) FROM activity_log WHERE user_id = u.id) as last_login
             FROM {$this->table} u
             LEFT JOIN branches b ON b.id = u.branch_id
             ORDER BY u.username ASC"
        );
    }
}
