<?php
class PurchaseItemCatalog extends Model
{
    protected $table = 'purchase_item_catalog';

    /**
     * ดึง catalog ทั้งหมด (active)
     */
    public function getAll($includeInactive = false)
    {
        $query = "SELECT c.id, c.code, c.name, c.category_id, c.default_unit,
                         c.default_price, c.is_active, c.notes,
                         cat.name AS category_name
                  FROM {$this->table} c
                  LEFT JOIN categories cat ON cat.id = c.category_id";
        if (!$includeInactive) {
            $query .= " WHERE c.is_active = 1";
        }
        $query .= " ORDER BY c.code ASC";
        return $this->db->fetchAll($query);
    }

    /**
     * ค้นหา catalog (code หรือ name) — ใช้สำหรับ autocomplete
     */
    public function search($keyword, $limit = 20)
    {
        $limit = max(1, min(50, (int)$limit));
        $query = "SELECT c.id, c.code, c.name, c.category_id, c.default_unit,
                         c.default_price,
                         cat.name AS category_name
                  FROM {$this->table} c
                  LEFT JOIN categories cat ON cat.id = c.category_id
                  WHERE c.is_active = 1
                    AND (c.code LIKE ? OR c.name LIKE ?)
                  ORDER BY
                    CASE WHEN c.code = ? THEN 0
                         WHEN c.code LIKE ? THEN 1
                         ELSE 2 END,
                    c.code ASC
                  LIMIT {$limit}";
        $term = "%{$keyword}%";
        $prefix = "{$keyword}%";
        return $this->db->fetchAll($query, [$term, $term, $keyword, $prefix]);
    }

    public function getById($id)
    {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
    }

    public function findByCode($code)
    {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE code = ?", [$code]);
    }

    public function create($data)
    {
        if (empty($data['code']) || empty($data['name'])) {
            throw new Exception('กรุณาระบุรหัสและชื่อสินค้า');
        }
        if ($this->findByCode($data['code'])) {
            throw new Exception('รหัสสินค้านี้มีในระบบแล้ว');
        }
        return $this->insert([
            'code' => $data['code'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'default_unit' => $data['default_unit'] ?? 'ชิ้น',
            'default_price' => $data['default_price'] ?? 0,
            'is_active' => $data['is_active'] ?? 1,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function update($id, $data)
    {
        $row = $this->getById($id);
        if (!$row) {
            throw new Exception('ไม่พบรายการ');
        }
        if (!empty($data['code']) && $data['code'] !== $row['code']) {
            if ($this->findByCode($data['code'])) {
                throw new Exception('รหัสสินค้านี้มีในระบบแล้ว');
            }
        }
        $updateData = [];
        foreach (['code','name','category_id','default_unit','default_price','is_active','notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $updateData[$f] = $data[$f];
            }
        }
        if (!empty($updateData)) {
            parent::update($id, $updateData);
        }
        return true;
    }
}
