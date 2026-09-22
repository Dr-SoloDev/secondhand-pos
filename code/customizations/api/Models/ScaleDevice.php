<?php
class ScaleDevice extends Model
{
    protected $table = 'scale_devices';

    public function getByBranch(int $branchId, string $status = 'active'): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE branch_id = ?";
        $params = [$branchId];
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY id ASC";
        return $this->db->fetchAll($sql, $params) ?: [];
    }

    public function getById(int $id)
    {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
    }

    public function getByCode(string $code)
    {
        return $this->db->fetch("SELECT * FROM {$this->table} WHERE code = ?", [$code]);
    }

    public function createDevice(array $data): int
    {
        if (empty($data['branch_id']) || empty($data['code'])) {
            throw new Exception('ต้องระบุสาขาและรหัสเครื่อง');
        }
        $exists = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE code = ?",
            [$data['code']]
        );
        if ($exists) {
            throw new Exception('รหัสเครื่องชั่งนี้ถูกใช้แล้ว');
        }
        return (int)$this->db->insert($this->table, [
            'branch_id' => (int)$data['branch_id'],
            'code' => trim((string)$data['code']),
            'name' => trim((string)($data['name'] ?? $data['code'])),
            'model' => trim((string)($data['model'] ?? 'Tiger TI-01')),
            'serial_no' => !empty($data['serial_no']) ? trim((string)$data['serial_no']) : null,
            'port' => !empty($data['port']) ? trim((string)$data['port']) : null,
            'baud_rate' => (int)($data['baud_rate'] ?? 9600),
            'status' => $data['status'] ?? 'active',
        ]);
    }

    public function updateDevice(int $id, array $data): void
    {
        $allowed = array_intersect_key($data, array_flip(['name','model','serial_no','port','baud_rate','status']));
        if (isset($allowed['status']) && !in_array($allowed['status'], ['active','inactive'], true)) {
            throw new Exception('สถานะเครื่องชั่งไม่ถูกต้อง');
        }
        if (empty($allowed)) return;
        $this->db->update($this->table, $allowed, ['id = ?'], [$id]);
    }

    public function deleteDevice(int $id): void
    {
        $this->db->delete($this->table, ['id = ?'], [$id]);
    }
}
