<?php
class BranchSetting extends Model
{
    protected $table = 'branch_settings';

    public function getByBranch(int $branchId, array $keys = []): array
    {
        $params = [$branchId];
        $where = 'branch_id = ?';
        if ($keys) {
            $where .= ' AND setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')';
            $params = array_merge($params, $keys);
        }
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM {$this->table} WHERE {$where}",
            $params
        ) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $result[(string)$row['setting_key']] = $row['setting_value'];
        }
        return $result;
    }

    public function updateForBranch(int $branchId, array $settings): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($settings as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $exists = $this->db->fetchColumn(
                    "SELECT COUNT(*) FROM {$this->table} WHERE branch_id = ? AND setting_key = ?",
                    [$branchId, $key]
                );
                if ($exists) {
                    $this->db->update(
                        $this->table,
                        ['setting_value' => (string)$value],
                        ['branch_id = ? AND setting_key = ?'],
                        [$branchId, $key]
                    );
                } else {
                    $this->db->insert($this->table, [
                        'branch_id' => $branchId,
                        'setting_key' => $key,
                        'setting_value' => (string)$value,
                    ]);
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
