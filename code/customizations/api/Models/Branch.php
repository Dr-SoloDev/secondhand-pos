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
        // The business policy is FIFO for every branch.  Keep this invariant
        // in the model as a second line of defence in addition to the DB enum.
        if (isset($data['cost_method']) && $data['cost_method'] !== 'fifo') {
            throw new Exception('ระบบกำหนดให้ทุกสาขาใช้ต้นทุนแบบ FIFO เท่านั้น');
        }
        return $this->insert([
            'code' => $data['code'],
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'manager_name' => $data['manager_name'] ?? null,
            'status' => $data['status'] ?? 'active',
            'cost_method' => 'fifo',
        ]);
    }

    public function getSummary($branchId = null)
    {
        $sql = "SELECT
                b.id, b.code, b.name, b.status, b.address, b.phone, b.manager_name,
                (SELECT COALESCE(SUM(po.total_amount), 0) FROM purchase_orders po WHERE po.branch_id = b.id AND DATE(po.created_at) = CURDATE() AND po.status = 'completed' AND po.source_type = 'manual') AS today_purchase_amount,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.branch_id = b.id AND DATE(po.created_at) = CURDATE() AND po.status = 'completed' AND po.source_type = 'manual') AS today_purchase_count,
                (SELECT COALESCE(SUM(po.total_amount), 0) FROM purchase_orders po WHERE po.branch_id = b.id AND MONTH(po.created_at) = MONTH(CURDATE()) AND YEAR(po.created_at) = YEAR(CURDATE()) AND po.status = 'completed' AND po.source_type = 'manual') AS month_purchase_amount,
                (SELECT COUNT(*) FROM purchase_orders po WHERE po.branch_id = b.id AND po.status = 'draft' AND po.source_type = 'manual') AS pending_po_count,
                (SELECT COUNT(*) FROM sale_lots sl WHERE sl.branch_id = b.id AND sl.status = 'draft') AS pending_salelot_count,
                (SELECT COALESCE(SUM(sl.total_amount), 0) FROM sale_lots sl WHERE sl.branch_id = b.id AND DATE(sl.sale_date) = CURDATE() AND sl.status = 'confirmed') AS today_salelot_amount,
                (SELECT COALESCE(SUM(sl.total_amount), 0) FROM sale_lots sl WHERE sl.branch_id = b.id AND MONTH(sl.sale_date) = MONTH(CURDATE()) AND YEAR(sl.sale_date) = YEAR(CURDATE()) AND sl.status = 'confirmed') AS month_salelot_amount,
                (SELECT COALESCE(SUM(bs.stock_kg), 0) FROM branch_stock bs WHERE bs.branch_id = b.id) AS total_stock_kg
            FROM {$this->table} b
            WHERE b.status = 'active'";
        $params = [];
        if ($branchId) {
            $sql .= " AND b.id = ?";
            $params[] = (int)$branchId;
        }
        $sql .= "
            ORDER BY b.code ASC";
        return $this->db->fetchAll($sql, $params);
    }
}
