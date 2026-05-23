<?php
class Customer extends Model
{
    /**
     * @var string
     */
    protected $table = 'customers';

    /**
     * @param $page
     * @param $limit
     * @param $search
     */
    public function getCustomersWithPagination($page = 1, $limit = 20, $search = null)
    {
        $conditions = [];
        $params = [];

        if ($search) {
            $conditions[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $whereClause = empty($conditions) ? "" : " WHERE ".implode(' AND ', $conditions);

        // Count total records
        $countQuery = "SELECT COUNT(*) FROM {$this->table}$whereClause";
        $totalCount = $this->db->fetchColumn($countQuery, $params);

        $offset = ($page - 1) * $limit;

        // Get data with pagination
        $query = "
            SELECT * FROM {$this->table}
            $whereClause
            ORDER BY name ASC
            LIMIT ? OFFSET ?
        ";

        $queryParams = array_merge($params, [$limit, $offset]);
        $customers = $this->db->fetchAll($query, $queryParams);

        return [
            'customers' => $customers,
            'pagination' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalCount / $limit)
            ]
        ];
    }
}
