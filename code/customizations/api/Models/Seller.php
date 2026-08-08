<?php
class Seller extends Model
{
    protected $table = 'sellers';

    private function decryptRow($row)
    {
        if (!$row) return $row;
        if (!empty($row['id_card_encrypted'])) {
            $row['id_card'] = SellerIdCipher::decrypt($row['id_card_encrypted']);
        } else {
            $row['id_card'] = $row['id_card'] ?? null;
        }
        unset($row['id_card_encrypted'], $row['id_card_search_hash'], $row['id_card_key_version']);
        return $row;
    }

    private function decryptRows($rows)
    {
        foreach ($rows as &$row) $row = $this->decryptRow($row);
        unset($row);
        return $rows;
    }

    /**
     * ดึงผู้ขายทั้งหมด
     */
    public function getAll($includeBlacklisted = false)
    {
        $query = "SELECT
                    id, id_card, id_card_encrypted, id_card_search_hash, id_card_key_version,
                    full_name, phone, address, vehicle_plate, vehicle_type,
                    id_card_photo, is_blacklisted, blacklist_reason, blacklisted_at,
                    notes, tier_level,
                    total_transactions, total_amount,
                    last_transaction_at, created_at, updated_at
                  FROM {$this->table}";
        
        if (!$includeBlacklisted) {
            $query .= " WHERE is_blacklisted = 0";
        }
        
        $query .= " ORDER BY created_at DESC";
        
        return $this->decryptRows($this->db->fetchAll($query));
    }

    /**
     * ดึงผู้ขายตาม ID
     */
    public function getById($id)
    {
        $query = "SELECT * FROM {$this->table} WHERE id = ?";
        return $this->decryptRow($this->db->fetch($query, [$id]));
    }

    /**
     * ค้นหาผู้ขายด้วยเบอร์โทร
     */
    public function findByPhone($phone)
    {
        $query = "SELECT * FROM {$this->table} WHERE phone = ?";
        return $this->decryptRow($this->db->fetch($query, [$phone]));
    }

    /**
     * ค้นหาผู้ขายด้วยบัตรประชาชน
     */
    public function findByIdCard($idCard)
    {
        $idCard = SellerIdCipher::normalize($idCard);
        $query = "SELECT * FROM {$this->table}
                  WHERE id_card_search_hash = ?
                     OR (id_card_search_hash IS NULL AND id_card = ?)
                  LIMIT 1";
        return $this->decryptRow($this->db->fetch($query, [SellerIdCipher::searchHash($idCard), $idCard]));
    }

    /**
     * ค้นหาผู้ขาย — autocomplete by full_name, id_card, or phone
     */
    public function search($keyword, $includeBlacklisted = false)
    {
        $query = "SELECT id,
                         full_name            AS name,
                         id_card,
                         id_card_encrypted,
                         id_card_search_hash,
                         id_card_key_version,
                         phone,
                         id_card_photo,
                          vehicle_plate, vehicle_type,
                         is_blacklisted,
                         blacklist_reason,
                         blacklisted_at,
                         tier_level,
                         total_transactions,
                         total_amount,
                         last_transaction_at
                  FROM {$this->table}
                  WHERE (full_name LIKE ? OR phone LIKE ?";
        $params = ["%{$keyword}%", "%{$keyword}%"];
        $digits = preg_replace('/\D/', '', $keyword);
        if (strlen($digits) === 13) {
            $query .= " OR id_card_search_hash = ? OR (id_card_search_hash IS NULL AND id_card = ?)";
            $params[] = SellerIdCipher::searchHash($digits);
            $params[] = $digits;
        }
        $query .= ')';
        if (!$includeBlacklisted) {
            $query .= " AND is_blacklisted = 0";
        }
        $query .= " ORDER BY full_name ASC LIMIT 20";
        $rows = $this->decryptRows($this->db->fetchAll($query, $params));
        foreach ($rows as &$row) {
            $row['national_id'] = $row['id_card'] ?? null;
            unset($row['id_card']);
        }
        unset($row);
        return $rows;
    }

    /**
     * ตรวจสอบ Checksum เลขบัตรประชาชนไทย (13 หลัก)
     */
    public function validateIdCard($idCard)
    {
        $idCard = preg_replace('/[^0-9]/', '', $idCard);
        if (strlen($idCard) !== 13) {
            throw new Exception('เลขบัตรประชาชนต้องมี 13 หลัก');
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$idCard[$i] * (13 - $i);
        }
        $checkDigit = (11 - ($sum % 11)) % 10;
        if ((int)$idCard[12] !== $checkDigit) {
            throw new Exception('เลขบัตรประชาชนไม่ถูกต้อง (ตรวจสอบเลขหลักสุดท้าย)');
        }
        return true;
    }

    /**
     * เพิ่มผู้ขายใหม่
     */
    public function create($data)
    {
        // ตรวจสอบ Checksum เลขบัตรประชาชน
        if (!empty($data['id_card'])) {
            $this->validateIdCard($data['id_card']);
        }

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

        $idCard = !empty($data['id_card']) ? SellerIdCipher::normalize($data['id_card']) : null;
        return $this->insert([
            'id_card' => null,
            'id_card_encrypted' => $idCard ? SellerIdCipher::encrypt($idCard) : null,
            'id_card_search_hash' => $idCard ? SellerIdCipher::searchHash($idCard) : null,
            'id_card_key_version' => $idCard ? SellerIdCipher::KEY_VERSION : null,
            'full_name' => $data['full_name'],
            'phone' => !empty($data['phone']) ? $data['phone'] : null,
            'address' => !empty($data['address']) ? $data['address'] : null,
            'vehicle_plate' => !empty($data['vehicle_plate']) ? $data['vehicle_plate'] : null,
            'vehicle_type' => !empty($data['vehicle_type']) ? $data['vehicle_type'] : null,
            'notes' => !empty($data['notes']) ? $data['notes'] : null,
            'tier_level' => !empty($data['tier_level']) ? (int)$data['tier_level'] : 1,
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

        // ตรวจสอบ Checksum เลขบัตรประชาชน (ถ้ามีการเปลี่ยน)
        $requestedIdCard = array_key_exists('id_card', $data)
            ? preg_replace('/[^0-9]/', '', (string)$data['id_card'])
            : null;
        if ($requestedIdCard !== null && $requestedIdCard !== '' && $requestedIdCard !== ($seller['id_card'] ?? null)) {
            $this->validateIdCard($requestedIdCard);
        }

        // ตรวจสอบบัตรประชาชนซ้ำ (ถ้ามีการเปลี่ยน)
        if ($requestedIdCard !== null && $requestedIdCard !== '' && $requestedIdCard !== ($seller['id_card'] ?? null)) {
            $exists = $this->findByIdCard($requestedIdCard);
            if ($exists && (int)$exists['id'] !== (int)$id) {
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
        if ($requestedIdCard !== null) {
            $updateData['id_card'] = null;
            $updateData['id_card_encrypted'] = $requestedIdCard !== '' ? SellerIdCipher::encrypt($requestedIdCard) : null;
            $updateData['id_card_search_hash'] = $requestedIdCard !== '' ? SellerIdCipher::searchHash($requestedIdCard) : null;
            $updateData['id_card_key_version'] = $requestedIdCard !== '' ? SellerIdCipher::KEY_VERSION : null;
        }
        if (isset($data['full_name'])) $updateData['full_name'] = $data['full_name'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['address'])) $updateData['address'] = $data['address'];
        if (isset($data['vehicle_plate'])) $updateData['vehicle_plate'] = $data['vehicle_plate'];
        if (isset($data['vehicle_type'])) $updateData['vehicle_type'] = $data['vehicle_type'];
        if (isset($data['notes'])) $updateData['notes'] = $data['notes'];
        if (isset($data['tier_level'])) $updateData['tier_level'] = (int)$data['tier_level'];
        // is_blacklisted/blacklist_reason ต้องใช้ผ่าน endpoint blacklist/unblacklist เท่านั้น

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
        return parent::update($id, [
            'is_blacklisted'   => 1,
            'blacklist_reason' => $reason ?: null,
            'blacklisted_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * ยกเลิก Blacklist
     */
    public function unblacklist($id)
    {
        return parent::update($id, ['is_blacklisted' => 0]);
    }
}
