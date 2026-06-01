<?php
class Branch extends Model
{
    protected $table = 'branches';

    public function getAll($status = null)
    {
        $query = "SELECT * FROM {$this->table}";
        $params = [];
        if ($status) {
            $query .= " WHERE status = ?";
            $params[] = $status;
        }
        $query .= " ORDER BY code ASC";
        return $this->db->fetchAll($query, $params);
    }

    public function getActive()
    {
        return $this->getAll('active');
    }

    public function create($data)
    {
        $exists = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE code = ?",
            [$data['code']]
        );
        if ($exists) {
            throw new Exception('รหัสสาขานี้ถูกใช้แล้ว');
        }
        $costMethod = isset($data['cost_method']) && in_array($data['cost_method'], ['fifo', 'weighted'])
            ? $data['cost_method'] : 'fifo';
        return $this->insert([
            'code' => $data['code'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'manager_name' => $data['manager_name'] ?? null,
            'status' => $data['status'] ?? 'active',
            'cost_method' => $costMethod,
        ]);
    }

    public function getSummary()
    {
        $sql = "SELECT
                b.id, b.code, b.name, b.status,
                (SELECT COUNT(*) FROM products p WHERE p.branch_id = b.id AND p.status = 'active') AS total_products,
                (SELECT COALESCE(SUM(po.total_amount), 0) FROM purchase_orders po WHERE po.branch_id = b.id AND DATE(po.created_at) = CURDATE() AND po.status = 'completed') AS today_purchase_amount,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.branch_id = b.id AND DATE(po.created_at) = CURDATE() AND po.status = 'completed') AS today_purchase_count
            FROM {$this->table} b
            WHERE b.status = 'active'
            ORDER BY b.code ASC";
        return $this->db->fetchAll($sql);
    }
}
