<?php
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit;
}

class Branch
{
    private $db;
    private $table = 'branches';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll($status = null)
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];

        if ($status) {
            $sql .= " WHERE status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY code ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (code, name, address, phone, manager_name, status)
            VALUES (:code, :name, :address, :phone, :manager_name, :status)
        ");
        $stmt->execute([
            ':code' => $data['code'],
            ':name' => $data['name'],
            ':address' => $data['address'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':manager_name' => $data['manager_name'] ?? null,
            ':status' => $data['status'] ?? 'active'
        ]);
        return $this->db->lastInsertId();
    }

    public function update($id, $data)
    {
        $fields = [];
        $params = [':id' => $id];

        foreach (['code', 'name', 'address', 'phone', 'manager_name', 'status'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) return false;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete($id)
    {
        $stmt = $this->db->prepare("UPDATE {$this->table} SET status = 'inactive' WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function getSummary($branch_id = null)
    {
        $sql = "
            SELECT
                b.id,
                b.code,
                b.name,
                (SELECT COUNT(*) FROM products p WHERE p.branch_id = b.id AND p.status = 'active') as total_products,
                (SELECT COALESCE(SUM(s.grand_total), 0) FROM sales s WHERE s.branch_id = b.id AND DATE(s.created_at) = CURDATE()) as today_sales,
                (SELECT COALESCE(SUM(po.total_amount), 0) FROM purchase_orders po WHERE po.branch_id = b.id AND DATE(po.created_at) = CURDATE()) as today_purchases
            FROM branches b
            WHERE b.status = 'active'
        ";

        if ($branch_id) {
            $sql .= " AND b.id = :branch_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':branch_id' => $branch_id]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
