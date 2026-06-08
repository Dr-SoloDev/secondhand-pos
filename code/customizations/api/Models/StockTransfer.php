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
        $ref = 'ST-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
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

        $this->db->beginTransaction();
        try {
            // หัก stock จากสาขาต้นทาง — categories เป็น global แต่ stock_kg คือ global ด้วย
            // ระบบนี้ stock_kg เป็น global per category → โอนแค่บันทึก audit trail
            $this->db->execute(
                $this->db->prepare("UPDATE stock_transfers SET status='confirmed', confirmed_by=?, confirmed_at=NOW() WHERE id=?"),
                [$userId, $id]
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
