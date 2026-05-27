<?php
class Seller extends Model
{
    protected $table = 'sellers';

    /**
     * ดึงผู้ขายทั้งหมด
     */
    public function getAll($includeBlacklisted = false)
    {
        $query = "SELECT
                    id, id_card, full_name, phone, address, vehicle_plate,
                    is_blacklisted, total_transactions, total_amount,
                    last_transaction_at, created_at, updated_at
                  FROM {$this->table}";
        
        if (!$includeBlacklisted) {
            $query .= " WHERE is_blacklisted = 0";
        }
        
        $query .= " ORDER BY created_at DESC";
        
        return $this->db->fetchAll($query);
    }

    /**
     * ดึงผู้ขายตาม ID
     */
    public function getById($id)
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return $this->db->fetch($query, [$id]);
    }

    /**
     * ค้นหาผู้ขายด้วยเบอร์โทร
     */
    public function findByPhone($phone)
    {
        $query = "SELECT * FROM {$this->table} WHERE phone = ?";
        return $this->db->fetch($query, [$phone]);
    }

    /**
     * ค้นหาผู้ขายด้วยบัตรประชาชน
     */
    public function findByIdCard($idCard)
    {
        $query = "SELECT * FROM {$this->table} WHERE id_card = ?";
        return $this->db->fetch($query, [$idCard]);
    }

    /**
     * ค้นหาผู้ขาย (เบอร์โทร หรือ ชื่อ)
     */
    public function search($keyword)
    {
        $query = "SELECT * FROM {$this->table} 
                  WHERE phone LIKE ? 
                     OR full_name LIKE ?
                     OR id_card LIKE ?
                  ORDER BY created_at DESC
                  LIMIT 20";
        
        $searchTerm = "%{$keyword}%";
        return $this->db->fetchAll($query, [$searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * เพิ่มผู้ขายใหม่
     */
    public function create($data)
    {
        // ตรวจสอบบัตรประชาชนซ้ำ
        if (!empty($data['id_card'])) {
            $exists = $this->findByIdCard($data['id_card']);
            if ($exists) {
                throw new Exception('เลขบัตรประชาชนนี้มีในระบบแล้ว');
            }
        }

        // ตรวจสอบเบอร์โทรซ้ำ
        if (!empty($data['phone'])) {
            $exists = $this->findByPhone($data['phone']);
            if ($exists) {
                throw new Exception('เบอร์โทรศัพท์นี้มีในระบบแล้ว');
            }
        }

        return $this->insert([
            'id_card' => $data['id_card'] ?? null,
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_blacklisted' => $data['is_blacklisted'] ?? 0,
        ]);
    }

    /**
     * แก้ไขข้อมูลผู้ขาย
     */
    public function update($id, $data)
    {
        $seller = $this->getById($id);
        if (!$seller) {
            throw new Exception('ไม่พบผู้ขายนี้');
        }

        // ตรวจสอบบัตรประชาชนซ้ำ (ถ้ามีการเปลี่ยน)
        if (!empty($data['id_card']) && $data['id_card'] !== $seller['id_card']) {
            $exists = $this->findByIdCard($data['id_card']);
            if ($exists) {
                throw new Exception('เลขบัตรประชาชนนี้มีในระบบแล้ว');
            }
        }

        // ตรวจสอบเบอร์โทรซ้ำ (ถ้ามีการเปลี่ยน)
        if (!empty($data['phone']) && $data['phone'] !== $seller['phone']) {
            $exists = $this->findByPhone($data['phone']);
            if ($exists) {
                throw new Exception('เบอร์โทรศัพท์นี้มีในระบบแล้ว');
            }
        }

        $updateData = [];
        if (isset($data['id_card'])) $updateData['id_card'] = $data['id_card'];
        if (isset($data['full_name'])) $updateData['full_name'] = $data['full_name'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['address'])) $updateData['address'] = $data['address'];
        if (isset($data['vehicle_plate'])) $updateData['vehicle_plate'] = $data['vehicle_plate'];
        if (isset($data['notes'])) $updateData['notes'] = $data['notes'];
        if (isset($data['is_blacklisted'])) $updateData['is_blacklisted'] = $data['is_blacklisted'];

        if (!empty($updateData)) {
            parent::update($id, $updateData);
        }

        return true;
    }

    /**
     * อัปเดตสถิติหลังรับซื้อ
     */
    public function updateStats($sellerId, $amount)
    {
        $query = "UPDATE {$this->table} 
                  SET total_transactions = total_transactions + 1,
                      total_amount = total_amount + ?,
                      last_transaction_at = NOW()
                  WHERE id = ?";
        
        return $this->db->execute($query, [$amount, $sellerId]);
    }

    /**
     * Blacklist ผู้ขาย
     */
    public function blacklist($id, $reason = null)
    {
        $updateData = ['is_blacklisted' => 1];
        if ($reason) {
            $updateData['notes'] = $reason;
        }
        return parent::update($id, $updateData);
    }

    /**
     * ยกเลิก Blacklist
     */
    public function unblacklist($id)
    {
        return parent::update($id, ['is_blacklisted' => 0]);
    }
}
