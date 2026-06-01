<?php
class Product extends Model
{
    /**
     * @var string
     */
    protected $table = 'products';

    /**
     * @param $categoryId
     */
    public function getAll($categoryId = null)
    {
        $query = "SELECT p.*, c.name as category_name
                 FROM {$this->table} p
                 LEFT JOIN categories c ON p.category_id = c.id";

        $params = [];
        if ($categoryId) {
            $query .= " WHERE p.category_id = ?";
            $params = [$categoryId];
        }

        $query .= " ORDER BY p.name ASC";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * @param $id
     */
    public function getById($id)
    {
        $query = "SELECT p.*, c.name as category_name
                 FROM {$this->table} p
                 LEFT JOIN categories c ON p.category_id = c.id
                 WHERE p.id = ?";

        return $this->db->fetch($query, [$id]);
    }

    /**
     * Get last product (for auto-generating SKU)
     */
    public function getLastProduct()
    {
        // หา SKU ที่เป็นตัวเลขล่าสุด (เรียงจากมากไปน้อย)
        $query = "SELECT * FROM {$this->table}
                  WHERE sku REGEXP '^[0-9]+$'
                  ORDER BY CAST(sku AS UNSIGNED) DESC
                  LIMIT 1";
        return $this->db->fetch($query);
    }

    /**
     * @param $data
     */
    public function create($data)
    {
        // Check if SKU already exists
        $exists = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE sku = ?",
            [$data['sku']]
        );

        if ($exists) {
            throw new Exception('SKU already exists');
        }

        // Prepare data for insert
        $insertData = [
            'sku' => $data['sku'],
            'name' => $data['name'],
            'price' => $data['price'],
            'cost' => $data['cost'],
            'status' => $data['status'] ?? 'active'
        ];

        // Optional fields
        if (isset($data['barcode'])) {
            $insertData['barcode'] = $data['barcode'];
        }

        if (isset($data['description'])) {
            $insertData['description'] = $data['description'];
        }

        if (isset($data['category_id'])) {
            $insertData['category_id'] = $data['category_id'];
        }

        if (isset($data['price_tier1'])) {
            $insertData['price_tier1'] = $data['price_tier1'];
        }

        if (isset($data['price_tier2'])) {
            $insertData['price_tier2'] = $data['price_tier2'];
        }

        if (isset($data['price_tier3'])) {
            $insertData['price_tier3'] = $data['price_tier3'];
        }

        if (isset($data['quantity'])) {
            $insertData['quantity'] = $data['quantity'];
        }

        if (isset($data['low_stock_threshold'])) {
            $insertData['low_stock_threshold'] = $data['low_stock_threshold'];
        }

        $productId = $this->insert($insertData);

        // If initial stock is added, record inventory transaction
        if (isset($data['quantity']) && $data['quantity'] > 0) {
            $inventory = new Inventory();
            $inventory->recordTransaction(
                $productId,
                'adjustment',
                $data['quantity'],
                null,
                'Initial stock',
                $data['user_id'] ?? null
            );
        }

        return $productId;
    }

    /**
     * @param $id
     * @param $data
     */
    public function update($id, $data)
    {
        // Check if product exists
        $product = $this->getById($id);
        if (!$product) {
            throw new Exception('Product not found');
        }

        // Check if SKU is unique
        if (isset($data['sku']) && $data['sku'] !== $product['sku']) {
            $exists = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {$this->table} WHERE sku = ? AND id != ?",
                [$data['sku'], $id]
            );

            if ($exists) {
                throw new Exception('SKU already exists');
            }
        }

        // Handle quantity updates separately
        $currentQuantity = $product['quantity'];
        $newQuantity = isset($data['quantity']) ? intval($data['quantity']) : $currentQuantity;
        $quantityChanged = $newQuantity !== $currentQuantity;

        // Remove quantity from update data (will be handled separately)
        unset($data['quantity']);

        // Update product data
        if (!empty($data)) {
            parent::update($id, $data);
        }

        // Handle quantity change if needed
        if ($quantityChanged) {
            $this->updateStock(
                $id,
                $newQuantity,
                'Quantity updated via product edit',
                $data['user_id'] ?? null
            );
        }

        return true;
    }

    /**
     * @param $id
     * @param $quantity
     * @param $notes
     * @param $userId
     */
    public function updateStock($id, $quantity, $notes = '', $userId = null)
    {
        // Start transaction
        $this->db->beginTransaction();

        try {
            // Get current quantity
            $currentQty = $this->db->fetchColumn(
                "SELECT quantity FROM {$this->table} WHERE id = ?",
                [$id]
            );

            if ($currentQty === false) {
                throw new Exception('Product not found');
            }

            // Update quantity
            $this->db->update(
                $this->table,
                ['quantity' => $quantity],
                ['id = ?'],
                [$id]
            );

            // Record transaction
            $adjustment = $quantity - $currentQty;

            // Only record if there's an actual change
            if ($adjustment != 0) {
                $inventory = new Inventory();
                $inventory->recordTransaction(
                    $id,
                    'adjustment',
                    abs($adjustment),
                    null,
                    $notes.($adjustment > 0 ? ' (Added)' : ' (Reduced)'),
                    $userId
                );
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param $id
     */
    public function delete($id)
    {
        // Check if product has transactions
        $txCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM inventory_transactions WHERE product_id = ?",
            [$id]
        );

        if ($txCount > 0) {
            // Soft delete - mark as inactive
            $this->update($id, ['status' => 'inactive']);
            return 'deactivated';
        } else {
            // Hard delete
            parent::delete($id);
            return 'deleted';
        }
    }

    /**
     * @return mixed
     */
    public function getLowStock()
    {
        return $this->db->fetchAll(
            "SELECT p.*, c.name as category_name
            FROM {$this->table} p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.quantity <= p.low_stock_threshold AND p.status = 'active'
            ORDER BY p.quantity ASC"
        );
    }

    /**
     * @param $categoryId
     * @return mixed
     */
    public function countByCategoryId($categoryId)
    {
        return $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE category_id = ?",
            [$categoryId]
        );
    }
}
