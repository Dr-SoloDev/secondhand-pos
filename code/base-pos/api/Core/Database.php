<?php
class Database
{
    /**
     * @var mixed
     */
    private static $instance = null;
    /**
     * @var mixed
     */
    private $conn;

    private function __construct()
    {
        try {
            $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: ".$e->getMessage());
            throw new Exception('Database connection failed');
        }
    }

    public static function getInstance()
    {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * @return mixed
     */
    public function getConnection()
    {
        return $this->conn;
    }

    /**
     * @return mixed
     */
    public function beginTransaction()
    {
        return $this->conn->beginTransaction();
    }

    /**
     * @return mixed
     */
    public function commit()
    {
        return $this->conn->commit();
    }

    /**
     * @return mixed
     */
    public function rollBack()
    {
        return $this->conn->rollBack();
    }

    /**
     * @return mixed
     */
    public function lastInsertId()
    {
        return $this->conn->lastInsertId();
    }

    /**
     * @param $query
     * @return mixed
     */
    public function prepare($query)
    {
        return $this->conn->prepare($query);
    }

    /**
     * @param $stmt
     * @param array $params
     * @return mixed
     */
    public function execute($stmt, $params = [])
    {
        try {
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database Query Error: ".$e->getMessage());
            throw new Exception("Database query failed: ".$e->getMessage());
        }
    }

    /**
     * @param $query
     * @param array $params
     * @return mixed
     */
    public function query($query, $params = [])
    {
        $stmt = $this->prepare($query);
        $this->execute($stmt, $params);
        return $stmt;
    }

    /**
     * @param $query
     * @param array $params
     * @return mixed
     */
    public function fetchAll($query, $params = [])
    {
        $stmt = $this->query($query, $params);
        return $stmt->fetchAll();
    }

    /**
     * @param $query
     * @param array $params
     * @return mixed
     */
    public function fetch($query, $params = [])
    {
        $stmt = $this->query($query, $params);
        return $stmt->fetch();
    }

    /**
     * @param $query
     * @param array $params
     * @param $column
     * @return mixed
     */
    public function fetchColumn($query, $params = [], $column = 0)
    {
        $stmt = $this->query($query, $params);
        return $stmt->fetchColumn($column);
    }

    /**
     * @param $table
     * @param $data
     */
    public function insert($table, $data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $query = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $this->prepare($query);
        $this->execute($stmt, array_values($data));

        return $this->lastInsertId();
    }

    /**
     * @param $table
     * @param $data
     * @param $conditions
     * @param array $conditionParams
     */
    public function update($table, $data, $conditions, $conditionParams = [])
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = "{$column} = ?";
        }

        $query = "UPDATE {$table} SET ".implode(', ', $sets);

        if (!empty($conditions)) {
            $query .= " WHERE ".implode(' AND ', $conditions);
        }

        $params = array_merge(array_values($data), $conditionParams);
        $stmt = $this->prepare($query);
        $this->execute($stmt, $params);

        return $stmt->rowCount();
    }

    /**
     * @param $table
     * @param $conditions
     * @param array $params
     */
    public function delete($table, $conditions, $params = [])
    {
        $query = "DELETE FROM {$table}";

        if (!empty($conditions)) {
            $query .= " WHERE ".implode(' AND ', $conditions);
        }

        $stmt = $this->prepare($query);
        $this->execute($stmt, $params);

        return $stmt->rowCount();
    }
}
