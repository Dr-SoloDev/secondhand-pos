<?php
class ActivityLog extends Model
{
    /**
     * @var string
     */
    protected $table = 'activity_log';

    /**
     * @param $page
     * @param $limit
     * @param $userId
     */
    public function getLogsWithPagination($page = 1, $limit = 10, $userId = null)
    {
        $conditions = [];
        $params = [];

        if ($userId) {
            $conditions[] = "user_id = ?";
            $params[] = $userId;
        }

        $whereClause = empty($conditions) ? "" : " WHERE ".implode(' AND ', $conditions);

        // Count total records
        $countQuery = "SELECT COUNT(*) FROM {$this->table}$whereClause";
        $totalCount = $this->db->fetchColumn($countQuery, $params);

        $offset = ($page - 1) * $limit;

        // Get data with pagination
        $query = "
            SELECT a.*, u.username
            FROM {$this->table} a
            LEFT JOIN users u ON a.user_id = u.id
            $whereClause
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $queryParams = array_merge($params, [$limit, $offset]);
        $logs = $this->db->fetchAll($query, $queryParams);

        return [
            'logs' => $logs,
            'pagination' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalCount / $limit)
            ]
        ];
    }
}
