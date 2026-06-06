<?php
class PurchaseItemCatalog extends Model
{
    protected $table = 'purchase_item_catalog';

    private function decodeTierPrices($row)
    {
        if ($row && isset($row['tier_prices'])) {
            $row['tier_prices'] = json_decode($row['tier_prices'], true) ?: [];
        }
        return $row;
    }

    private function decodeTierPricesList($rows)
    {
        foreach ($rows as &$row) {
            $row = $this->decodeTierPrices($row);
        }
        return $rows;
    }

    public function getAll($includeInactive = false)
    {
        $query = "SELECT c.id, c.code, c.name, c.category_id, c.default_unit,
                         c.default_price, c.tier_prices, c.is_active, c.notes,
                         cat.name AS category_name
                  FROM {$this->table} c
                  LEFT JOIN categories cat ON cat.id = c.category_id";
        if (!$includeInactive) {
            $query .= " WHERE c.is_active = 1";
        }
        $query .= " ORDER BY c.code ASC";
        $rows = $this->db->fetchAll($query);
        return $this->decodeTierPricesList($rows);
    }

    public function search($keyword, $limit = 20)
    {
        $limit = max(1, min(50, (int)$limit));
        $query = "SELECT c.id, c.code, c.name, c.category_id, c.default_unit,
                         c.default_price, c.tier_prices,
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
        $rows = $this->db->fetchAll($query, [$term, $term, $keyword, $prefix]);
        return $this->decodeTierPricesList($rows);
    }

    public function getById($id)
    {
        $row = $this->db->fetch("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
        return $this->decodeTierPrices($row);
    }

    public function findByCode($code)
    {
        $row = $this->db->fetch("SELECT * FROM {$this->table} WHERE code = ?", [$code]);
        return $this->decodeTierPrices($row);
    }

    public function create($data)
    {
        if (empty($data['code']) || empty($data['name'])) {
            throw new Exception('กรุณาระบุรหัสและชื่อสินค้า');
        }
        if ($this->findByCode($data['code'])) {
            throw new Exception('รหัสสินค้านี้มีในระบบแล้ว');
        }
        $tiers = $data['tiers'] ?? $data['tier_prices'] ?? null;
        return $this->insert([
            'code' => $data['code'],
            'name' => $data['name'],
            'category_id' => $data['category_id'] ?? null,
            'default_unit' => $data['default_unit'] ?? 'ชิ้น',
            'default_price' => $data['default_price'] ?? 0,
            'tier_prices' => $tiers ? json_encode(self::sanitizeTiers($tiers), JSON_UNESCAPED_UNICODE) : '[]',
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
        if (isset($data['tiers']) || isset($data['tier_prices'])) {
            $tiers = $data['tiers'] ?? $data['tier_prices'];
            $updateData['tier_prices'] = json_encode(self::sanitizeTiers($tiers), JSON_UNESCAPED_UNICODE);
        }
        if (!empty($updateData)) {
            parent::update($id, $updateData);
        }
        return true;
    }

    // M5: whitelist key เฉพาะ label/price + จำกัดความยาว — กัน client ส่ง field แปลกๆ ลง DB
    private static function sanitizeTiers($tiers)
    {
        if (!is_array($tiers)) {
            return [];
        }
        $clean = [];
        foreach ($tiers as $t) {
            if (!is_array($t) || !isset($t['label'])) {
                continue;
            }
            $label = trim((string)$t['label']);
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 50) {
                $label = mb_substr($label, 0, 50);
            }
            $clean[] = [
                'label' => $label,
                'price' => isset($t['price']) && is_numeric($t['price']) ? max(0, (float)$t['price']) : 0,
            ];
        }
        return $clean;
    }

    /**
     * อัปเดต category_id ของ catalog item
     */
    public function updateCategory($catalogId, $categoryId)
    {
        $sql = "UPDATE purchase_item_catalog SET category_id = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $categoryId, $catalogId);
        if (!$stmt->execute()) {
            throw new Exception('Update category failed: ' . $stmt->error);
        }
        $stmt->close();
    }
}
