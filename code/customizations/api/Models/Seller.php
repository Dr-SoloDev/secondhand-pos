<?php
class Seller extends Model
{
    protected $table = 'sellers';

    public function getAll($search = null, $includeBlacklisted = true)
    {
        $query = "SELECT * FROM {$this->table} WHERE 1=1";
        $params = [];
        if ($search) {
            $query .= " AND (full_name LIKE ? OR id_card LIKE ? OR phone LIKE ?)";
            $like = "%{$search}%";
            $params = [$like, $like, $like];
        }
        if (!$includeBlacklisted) {
            $query .= " AND is_blacklisted = 0";
        }
        $query .= " ORDER BY last_transaction_at DESC, full_name ASC";
        return $this->db->fetchAll($query, $params);
    }

    public function getPaginated($page = 1, $limit = 20, $search = null)
    {
        $offset = ($page - 1) * $limit;
        $where = "1=1";
        $params = [];
        if ($search) {
            $where .= " AND (full_name LIKE ? OR id_card LIKE ? OR phone LIKE ?)";
            $like = "%{$search}%";
            $params = [$like, $like, $like];
        }
        $total = $this->db->fetchColumn("SELECT COUNT(*) FROM {$this->table} WHERE {$where}", $params);
        $items = $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE {$where} ORDER BY full_name ASC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return [
            'items' => $items,
            'pagination' => [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    public function findByIdCard($idCard)
    {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE id_card = ?", [$idCard]);
    }

    public function create($data)
    {
        if (!empty($data['id_card'])) {
            $existing = $this->findByIdCard($data['id_card']);
            if ($existing) {
                throw new Exception('เลขบัตรประชาชนนี้มีอยู่แล้วในระบบ (ID: ' . $existing['id'] . ')');
            }
        }
        return $this->insert([
            'id_card' => $data['id_card'] ?? null,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'id_card_photo' => $data['id_card_photo'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_blacklisted' => $data['is_blacklisted'] ?? 0,
        ]);
    }

    public function recordTransaction($sellerId, $amount)
    {
        return $this->db->update(
            $this->table,
            [
                'total_transactions' => $this->db->fetchColumn("SELECT total_transactions FROM {$this->table} WHERE id = ?", [$sellerId]) + 1,
                'total_amount' => $this->db->fetchColumn("SELECT total_amount FROM {$this->table} WHERE id = ?", [$sellerId]) + $amount,
                'last_transaction_at' => date('Y-m-d H:i:s'),
            ],
            ['id = ?'],
            [$sellerId]
        );
    }
}
