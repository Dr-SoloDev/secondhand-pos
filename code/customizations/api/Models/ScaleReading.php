<?php
class ScaleReading extends Model
{
    protected $table = 'scale_readings';

    public function log(array $data): int
    {
        return (int)$this->db->insert($this->table, [
            'device_id' => !empty($data['device_id']) ? (int)$data['device_id'] : null,
            'branch_id' => (int)$data['branch_id'],
            'purchase_order_id' => !empty($data['purchase_order_id']) ? (int)$data['purchase_order_id'] : null,
            'purchase_order_item_id' => !empty($data['purchase_order_item_id']) ? (int)$data['purchase_order_item_id'] : null,
            'weight_kg' => (float)$data['weight_kg'],
            'raw_value' => isset($data['raw_value']) ? (string)$data['raw_value'] : null,
            'stable' => !empty($data['stable']) ? 1 : 0,
            'weight_source' => $data['weight_source'] ?? 'scale',
            'captured_at' => $data['captured_at'] ?? date('Y-m-d H:i:s'),
            'created_by' => !empty($data['created_by']) ? (int)$data['created_by'] : null,
        ]);
    }

    public function getByPO(int $poId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE purchase_order_id = ? ORDER BY id ASC",
            [$poId]
        ) ?: [];
    }

    public function getByBranch(int $branchId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE branch_id = ? ORDER BY captured_at DESC LIMIT {$limit}",
            [$branchId]
        ) ?: [];
    }

    /** สัดส่วน scale vs manual ต่อสาขา — ใช้ดู audit */
    public function getStatsByBranch(int $branchId, string $dateFrom = null, string $dateTo = null): array
    {
        $where = "branch_id = ?";
        $params = [$branchId];
        if ($dateFrom) { $where .= " AND DATE(captured_at) >= ?"; $params[] = $dateFrom; }
        if ($dateTo)   { $where .= " AND DATE(captured_at) <= ?"; $params[] = $dateTo; }
        $rows = $this->db->fetchAll(
            "SELECT weight_source, COUNT(*) AS cnt, SUM(weight_kg) AS total_kg
             FROM {$this->table} WHERE {$where} GROUP BY weight_source",
            $params
        ) ?: [];
        $out = ['scale'=>0,'manual'=>0,'manual_override'=>0,'total_kg'=>0];
        foreach ($rows as $r) {
            $k = $r['weight_source'];
            if (isset($out[$k])) $out[$k] = (int)$r['cnt'];
            $out['total_kg'] += (float)$r['total_kg'];
        }
        return $out;
    }
}
