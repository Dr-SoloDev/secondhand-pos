<?php
class Model
{
    /**
     * @var mixed
     */
    protected $db;
    /**
     * @var mixed
     */
    protected $table;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param $orderBy
     */
    public function findAll($orderBy = null)
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        $query = "SELECT * FROM {$this->table}";

        if ($orderBy) {
            $orderBy = preg_replace('/[^a-zA-Z0-9_\s,.]/', '', $orderBy);
            $query .= " ORDER BY {$orderBy}";
        }

        return $this->db->fetchAll($query);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function findById($id)
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        return $this->db->fetch("SELECT * FROM {$this->table} WHERE id = ?", [$id]);
    }

    /**
     * @param array $conditions
     * @param array $params
     */
    public function count($conditions = [], $params = [])
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        $query = "SELECT COUNT(*) FROM {$this->table}";

        if (!empty($conditions)) {
            $query .= " WHERE ".implode(' AND ', $conditions);
        }

        return $this->db->fetchColumn($query, $params);
    }

    /**
     * @param array $conditions
     * @param array $params
     * @param $orderBy
     * @param null $limit
     * @param null $offset
     */
    public function findByCondition($conditions = [], $params = [], $orderBy = null, $limit = null, $offset = null)
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        $query = "SELECT * FROM {$this->table}";

        if (!empty($conditions)) {
            $query .= " WHERE ".implode(' AND ', $conditions);
        }

        if ($orderBy) {
            $orderBy = preg_replace('/[^a-zA-Z0-9_\s,.]/', '', $orderBy);
            $query .= " ORDER BY ".$orderBy;
        }

        if ($limit) {
            $query .= " LIMIT ".intval($limit);

            if ($offset) {
                $query .= " OFFSET ".intval($offset);
            }
        }

        return $this->db->fetchAll($query, $params);
    }

    /**
     * @param $data
     * @return mixed
     */
    public function insert($data)
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        return $this->db->insert($this->table, $data);
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function update($id, $data)
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        return $this->db->update($this->table, $data, ['id = ?'], [$id]);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function delete($id)
    {
        if (!$this->table) {
            throw new Exception('Table name is not specified');
        }

        return $this->db->delete($this->table, ['id = ?'], [$id]);
    }

    /**
     * @param $page
     * @param $limit
     * @param array $conditions
     * @param array $params
     * @param $orderBy
     */
    public function paginate($page = 1, $limit = 20, $conditions = [], $params = [], $orderBy = null)
    {
        $offset = ($page - 1) * $limit;

        $totalItems = $this->count($conditions, $params);
        $items = $this->findByCondition($conditions, $params, $orderBy, $limit, $offset);

        return [
            'items' => $items,
            'pagination' => [
                'total' => $totalItems,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalItems / $limit)
            ]
        ];
    }
}
