<?php
class Inventory extends Model
{
    /**
     * @var string
     */
    protected $table = 'inventory_transactions';

    /**
     * @param $productId
     * @param null $type
     */
    public function getTransactions($productId = null, $type = null)
    {
        $query = "SELECT t.*, p.name as product_name, p.sku, u.username
                FROM {$this->table} t
                JOIN products p ON t.product_id = p.id
                LEFT JOIN users u ON t.user_id = u.id";

        $conditions = [];
        $params = [];

        if ($productId) {
            $conditions[] = "t.product_id = ?";
            $params[] = $productId;
        }

        if ($type) {
            $conditions[] = "t.type = ?";
            $params[] = $type;
        }

        if (!empty($conditions)) {
            $query .= " WHERE ".implode(' AND ', $conditions);
        }

        $query .= " ORDER BY t.created_at DESC";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * @param $productId
     * @param $type
     * @param $quantity
     * @param $referenceId
     * @param null $notes
     * @param $userId
     * @return mixed
     */
    public function recordTransaction($productId, $type, $quantity, $referenceId = null, $notes = '', $userId = null)
    {
        return $this->insert([
            'product_id' => $productId,
            'type' => $type,
            'quantity' => $quantity,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'user_id' => $userId
        ]);
    }
}
