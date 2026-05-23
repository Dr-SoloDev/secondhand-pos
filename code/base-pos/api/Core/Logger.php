<?php
class Logger
{
    /**
     * @param $userId
     * @param $action
     * @param $description
     */
    public static function logActivity($userId, $action, $description = '')
    {
        $db = Database::getInstance();

        $data = [
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ];

        $db->insert('activity_log', $data);
        return true;
    }

    /**
     * @param $userId
     * @param null $page
     * @param $limit
     */
    public static function getActivityLog($userId = null, $page = 1, $limit = 10)
    {
        $db = Database::getInstance();
        $offset = ($page - 1) * $limit;

        $conditions = [];
        $params = [];

        if ($userId) {
            $conditions[] = "a.user_id = ?";
            $params[] = $userId;
        }

        $whereClause = empty($conditions) ? "" : " WHERE ".implode(' AND ', $conditions);

        // Get total count
        $countQuery = "SELECT COUNT(*) FROM activity_log a".$whereClause;
        $totalCount = $db->fetchColumn($countQuery, $params);

        // Get data with pagination
        $query = "
            SELECT a.*, u.username
            FROM activity_log a
            LEFT JOIN users u ON a.user_id = u.id
            $whereClause
            ORDER BY a.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $queryParams = array_merge($params, [$limit, $offset]);
        $logs = $db->fetchAll($query, $queryParams);

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
