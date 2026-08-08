<?php
class AuditLog extends Model
{
    protected $table = 'activity_log';

    public function search(array $filters = [], $page = 1, $limit = 20, $forExport = false)
    {
        $conditions = [];
        $params = [];

        $this->addExactFilter($conditions, $params, 'a.actor_role', $filters['actor_role'] ?? null);
        $this->addIntegerFilter($conditions, $params, 'a.actor_branch_id', $filters['actor_branch_id'] ?? null);
        $this->addIntegerFilter($conditions, $params, 'a.actor_id', $filters['actor_id'] ?? null);
        $this->addExactFilter($conditions, $params, 'a.module', $filters['module'] ?? null);
        $this->addExactFilter($conditions, $params, 'a.action', $filters['action'] ?? null);
        $this->addExactFilter($conditions, $params, 'a.outcome', $filters['outcome'] ?? null);

        if (!empty($filters['entity'])) {
            $conditions[] = '(a.entity_id = ? OR a.entity_type = ?)';
            $params[] = (string)$filters['entity'];
            $params[] = (string)$filters['entity'];
        }
        if (!empty($filters['start_datetime'])) {
            $conditions[] = 'a.created_at >= ?';
            $params[] = $filters['start_datetime'];
        }
        if (!empty($filters['end_datetime'])) {
            $conditions[] = 'a.created_at <= ?';
            $params[] = $filters['end_datetime'];
        }
        if (!empty($filters['keyword'])) {
            $like = '%' . $filters['keyword'] . '%';
            $conditions[] = '(a.description LIKE ? OR a.reason LIKE ? OR a.request_id LIKE ? OR a.entity_id LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$this->table} a{$where}", $params);
        $limit = $forExport ? min(max((int)$limit, 1), 10000) : min(max((int)$limit, 1), 100);
        $page = max((int)$page, 1);
        $offset = $forExport ? 0 : ($page - 1) * $limit;

        $sql = "SELECT a.id, a.actor_id, a.actor_role, a.actor_branch_id,
                       a.action, a.module, a.entity_type, a.entity_id, a.entity_branch_id,
                       a.outcome, a.reason, a.description, a.before_json, a.after_json,
                       a.self_approved, a.ip_address, a.user_agent, a.request_id, a.created_at,
                       u.username
                FROM {$this->table} a
                LEFT JOIN users u ON u.id = a.actor_id
                {$where}
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT ? OFFSET ?";
        $rows = $this->db->fetchAll($sql, array_merge($params, [$limit, $offset]));
        foreach ($rows as &$row) {
            $row['before_json'] = $this->decodeJson($row['before_json'] ?? null);
            $row['after_json'] = $this->decodeJson($row['after_json'] ?? null);
            $row['self_approved'] = (bool)($row['self_approved'] ?? false);
        }
        unset($row);

        return [
            'logs' => $rows,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $total > 0 ? (int)ceil($total / $limit) : 0,
            ],
        ];
    }

    private function addExactFilter(&$conditions, &$params, $column, $value)
    {
        if ($value === null || $value === '') return;
        $conditions[] = $column . ' = ?';
        $params[] = (string)$value;
    }

    private function addIntegerFilter(&$conditions, &$params, $column, $value)
    {
        if ($value === null || $value === '') return;
        $conditions[] = $column . ' = ?';
        $params[] = (int)$value;
    }

    private function decodeJson($value)
    {
        if ($value === null || $value === '') return null;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
