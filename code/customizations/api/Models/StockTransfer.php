<?php
class StockTransfer extends Model
{
    protected $table = 'stock_transfers';

    public function getAll($filters = [])
    {
        $where = [];
        $params = [];
        if (!empty($filters['from_branch_id'])) {
            $where[] = "st.from_branch_id = ?";
            $params[] = $filters['from_branch_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = "st.status = ?";
            $params[] = $filters['status'];
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        return $this->db->fetchAll(
            "SELECT st.*,
                    fb.name AS from_branch_name, tb.name AS to_branch_name,
                    c.name AS category_name,
                    u1.full_name AS created_by_name, u2.full_name AS confirmed_by_name
             FROM stock_transfers st
             LEFT JOIN branches fb ON fb.id = st.from_branch_id
             LEFT JOIN branches tb ON tb.id = st.to_branch_id
             LEFT JOIN categories c ON c.id = st.category_id
             LEFT JOIN users u1 ON u1.id = st.created_by
             LEFT JOIN users u2 ON u2.id = st.confirmed_by
             $whereSql ORDER BY st.created_at DESC LIMIT 50",
            $params
        ) ?: [];
    }

    public function create($data, $userId)
    {
        // Sequential reference_no (ST-YYYYMMDD-NNN) — ป้องกัน collision จาก mt_rand
        $today = date('Ymd');
        $lastRef = $this->db->fetchColumn(
            "SELECT reference_no FROM stock_transfers
             WHERE reference_no LIKE ? ORDER BY id DESC LIMIT 1",
            ["ST-{$today}-%"]
        );
        $seq = $lastRef ? (intval(substr($lastRef, -3)) + 1) : 1;
        $ref = 'ST-' . $today . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);

        $stmt = $this->db->prepare(
            "INSERT INTO stock_transfers (reference_no,from_branch_id,to_branch_id,category_id,weight_kg,note,created_by)
             VALUES (?,?,?,?,?,?,?)"
        );
        $this->db->execute($stmt, [
            $ref, $data['from_branch_id'], $data['to_branch_id'],
            $data['category_id'], $data['weight_kg'], $data['note'] ?? null, $userId
        ]);
        return ['id' => intval($this->db->lastInsertId()), 'reference_no' => $ref];
    }

    public function confirm($id, $userId)
    {
        $st = $this->db->fetch("SELECT * FROM stock_transfers WHERE id = ? AND status = 'pending'", [$id]);
        if (!$st) throw new Exception('ไม่พบใบโอนหรือดำเนินการแล้ว');

        // ตรวจสอบว่าสต็อกต้นทางเพียงพอก่อน confirm
        $stockKg = $this->db->fetchColumn(
            "SELECT stock_kg FROM categories WHERE id = ?",
            [$st['category_id']]
        );
        if ($stockKg === false || floatval($stockKg) < floatval($st['weight_kg'])) {
            throw new Exception(
                "สต็อกไม่เพียงพอ (มี " . number_format(floatval($stockKg), 2) .
                " กก. ต้องการ " . number_format(floatval($st['weight_kg']), 2) . " กก.)"
            );
        }

        $this->db->beginTransaction();
        try {
            $this->db->execute(
                $this->db->prepare("UPDATE stock_transfers SET status='confirmed', confirmed_by=?, confirmed_at=NOW() WHERE id=?"),
                [$userId, $id]
            );
            // หัก/เพิ่ม stock_kg (global per category) ตาม weight_kg ที่โอน
            $this->db->execute(
                $this->db->prepare(
                    "UPDATE categories SET stock_kg = GREATEST(0, stock_kg - ?) WHERE id = ?"
                ),
                [$st['weight_kg'], $st['category_id']]
            );
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancel($id)
    {
        $stmt = $this->db->prepare("UPDATE stock_transfers SET status='cancelled' WHERE id=? AND status='pending'");
        $this->db->execute($stmt, [$id]);
    }
}
