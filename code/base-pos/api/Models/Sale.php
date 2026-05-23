<?php
class Sale extends Model
{
    /**
     * @var string
     */
    protected $table = 'sales';

    /**
     * @param $saleData
     * @param $items
     */
    public function create($saleData, $items)
    {
        // Start transaction
        $this->db->beginTransaction();

        try {
            // Calculate totals
            $subTotal = 0;
            foreach ($items as $item) {
                $quantity = intval($item['quantity']);
                $unitPrice = floatval($item['unit_price']);
                $subTotal += $quantity * $unitPrice;
            }

            // Add calculated totals
            $discountAmount = isset($saleData['discount_amount']) ? floatval($saleData['discount_amount']) : 0;
            $taxAmount = isset($saleData['tax_amount']) ? floatval($saleData['tax_amount']) : 0;
            $grandTotal = $subTotal - $discountAmount + $taxAmount;

            // Generate reference number (format: SALE-YYYYMMDD-XXXX)
            $date = date('Ymd');
            $count = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {$this->table} WHERE reference_no LIKE ?",
                ["SALE-$date-%"]
            );
            $refNumber = sprintf("SALE-%s-%04d", $date, $count + 1);

            // Create sale header
            $saleId = $this->insert([
                'reference_no' => $refNumber,
                'customer_id' => $saleData['customer_id'],
                'user_id' => $saleData['user_id'],
                'total_amount' => $subTotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'payment_method' => $saleData['payment_method'],
                'payment_status' => 'paid', // Default to paid
                'notes' => $saleData['notes']
            ]);

            // Create sale items and update inventory
            $productModel = new Product();
            $inventory = new Inventory();
            $saleItemModel = new SaleItem();

            foreach ($items as $item) {
                $productId = intval($item['product_id']);
                $quantity = intval($item['quantity']);
                $unitPrice = floatval($item['unit_price']);
                $discount = isset($item['discount']) ? floatval($item['discount']) : 0;
                $total = ($unitPrice * $quantity) - $discount;

                // Get product
                $product = $productModel->getById($productId);
                if (!$product) {
                    throw new Exception("Product ID {$productId} not found");
                }

                // Check stock
                if ($product['quantity'] < $quantity) {
                    throw new Exception("Insufficient stock for product: {$product['name']}");
                }

                // Create sale item
                $saleItemModel->insert([
                    'sale_id' => $saleId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'total' => $total
                ]);

                // Update product stock
                $newQuantity = $product['quantity'] - $quantity;
                $productModel->updateStock(
                    $productId,
                    $newQuantity,
                    "Sale: {$refNumber}",
                    $saleData['user_id']
                );

                // Record inventory transaction
                $inventory->recordTransaction(
                    $productId,
                    'sale',
                    $quantity,
                    $saleId,
                    "Sale: {$refNumber}",
                    $saleData['user_id']
                );
            }

            $this->db->commit();
            return $saleId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param $saleId
     */
    public function getDetailsForReceipt($saleId)
    {
        // Get sale header
        $sale = $this->db->fetch(
            "SELECT s.*, c.name as customer_name, u.username as cashier_name, u.full_name as cashier_full_name
           FROM {$this->table} s
           LEFT JOIN customers c ON s.customer_id = c.id
           LEFT JOIN users u ON s.user_id = u.id
           WHERE s.id = ?",
            [$saleId]
        );

        if (!$sale) {
            return null;
        }

        // Get sale items
        $items = $this->db->fetchAll(
            "SELECT si.*, p.name as product_name, p.sku
           FROM sale_items si
           JOIN products p ON si.product_id = p.id
           WHERE si.sale_id = ?",
            [$saleId]
        );

        // Get store settings
        $settingModel = new Setting();
        $settings = $settingModel->getSettingsByKeys([
            'store_name',
            'store_address',
            'store_phone',
            'receipt_footer'
        ]);

        // Combine data
        $sale['items'] = $items;
        $sale['store_name'] = $settings['store_name'] ?? 'My POS Store';
        $sale['store_address'] = $settings['store_address'] ?? '';
        $sale['store_phone'] = $settings['store_phone'] ?? '';
        $sale['receipt_footer'] = $settings['receipt_footer'] ?? 'Thank you for shopping with us!';

        // Use full name if available, otherwise use username
        $sale['cashier_name'] = $sale['cashier_full_name'] ?: $sale['cashier_name'];
        unset($sale['cashier_full_name']);

        return $sale;
    }

    /**
     * @param $page
     * @param $limit
     * @param $dateFrom
     * @param null $dateTo
     * @param null $customerId
     * @param null $paymentStatus
     * @param null $paymentMethod
     * @param null $search
     */
    public function getSalesWithPagination($page = 1, $limit = 20, $dateFrom = null, $dateTo = null, $customerId = null, $paymentStatus = null, $paymentMethod = null, $search = null)
    {
        $conditions = [];
        $params = [];

        if ($dateFrom) {
            $conditions[] = "DATE(s.created_at) >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $conditions[] = "DATE(s.created_at) <= ?";
            $params[] = $dateTo;
        }

        if ($customerId) {
            $conditions[] = "s.customer_id = ?";
            $params[] = $customerId;
        }

        if ($paymentStatus) {
            $conditions[] = "s.payment_status = ?";
            $params[] = $paymentStatus;
        }

        if ($paymentMethod) {
            $conditions[] = "s.payment_method = ?";
            $params[] = $paymentMethod;
        }

        if ($search) {
            $conditions[] = "(s.reference_no LIKE ? OR c.name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $whereClause = empty($conditions) ? "" : " WHERE ".implode(' AND ', $conditions);

        // Count total records
        $countQuery = "
           SELECT COUNT(*)
           FROM {$this->table} s
           LEFT JOIN customers c ON s.customer_id = c.id
           $whereClause
       ";

        $totalCount = $this->db->fetchColumn($countQuery, $params);
        $offset = ($page - 1) * $limit;

        // Get data with pagination
        $query = "
           SELECT s.*, c.name as customer_name, u.username as user_name,
               (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
           FROM {$this->table} s
           LEFT JOIN customers c ON s.customer_id = c.id
           LEFT JOIN users u ON s.user_id = u.id
           $whereClause
           ORDER BY s.created_at DESC
           LIMIT ? OFFSET ?
       ";

        $queryParams = array_merge($params, [$limit, $offset]);
        $sales = $this->db->fetchAll($query, $queryParams);

        return [
            'sales' => $sales,
            'pagination' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalCount / $limit)
            ]
        ];
    }

    /**
     * @param $saleId
     */
    public function getDetailedSale($saleId)
    {
        // Get sale header with related info
        $sale = $this->db->fetch(
            "SELECT s.*, c.name as customer_name, c.phone as customer_phone, c.email as customer_email,
           u.username as user_name, u.full_name as user_full_name
           FROM {$this->table} s
           LEFT JOIN customers c ON s.customer_id = c.id
           LEFT JOIN users u ON s.user_id = u.id
           WHERE s.id = ?",
            [$saleId]
        );

        if (!$sale) {
            return null;
        }

        // Get sale items with product details
        $items = $this->db->fetchAll(
            "SELECT si.*, p.name as product_name, p.sku
           FROM sale_items si
           JOIN products p ON si.product_id = p.id
           WHERE si.sale_id = ?",
            [$saleId]
        );

        $sale['items'] = $items;

        return $sale;
    }

    /**
     * @param $saleId
     * @param $reason
     * @param $userId
     */
    public function voidSale($saleId, $reason, $userId)
    {
        // Start transaction
        $this->db->beginTransaction();

        try {
            // Get sale and items
            $sale = $this->getDetailedSale($saleId);

            if (!$sale) {
                throw new Exception('Sale not found');
            }

            if ($sale['payment_status'] === 'voided') {
                throw new Exception('Sale is already voided');
            }

            // Update sale status
            $notes = $sale['notes'].' | Voided: '.$reason;
            $this->update($saleId, [
                'payment_status' => 'voided',
                'notes' => $notes
            ]);

            // Return items to inventory
            $productModel = new Product();
            $inventory = new Inventory();

            foreach ($sale['items'] as $item) {
                // Get current product quantity
                $product = $productModel->getById($item['product_id']);

                if ($product) {
                    // Update product stock
                    $newQuantity = $product['quantity'] + $item['quantity'];
                    $productModel->updateStock(
                        $item['product_id'],
                        $newQuantity,
                        "Sale voided: {$sale['reference_no']} - {$reason}",
                        $userId
                    );

                    // Record inventory transaction
                    $inventory->recordTransaction(
                        $item['product_id'],
                        'return',
                        $item['quantity'],
                        $saleId,
                        "Sale voided: {$reason}",
                        $userId
                    );
                }
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param $dateFrom
     * @param null $dateTo
     * @param null $paymentStatus
     * @param null $paymentMethod
     * @param null $search
     */
    public function getSalesForExport($dateFrom = null, $dateTo = null, $paymentStatus = null, $paymentMethod = null, $search = null)
    {
        $conditions = [];
        $params = [];

        if ($dateFrom) {
            $conditions[] = "DATE(s.created_at) >= ?";
            $params[] = $dateFrom;
        }

        if ($dateTo) {
            $conditions[] = "DATE(s.created_at) <= ?";
            $params[] = $dateTo;
        }

        if ($paymentStatus) {
            $conditions[] = "s.payment_status = ?";
            $params[] = $paymentStatus;
        }

        if ($paymentMethod) {
            $conditions[] = "s.payment_method = ?";
            $params[] = $paymentMethod;
        }

        if ($search) {
            $conditions[] = "(s.reference_no LIKE ? OR c.name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $whereClause = empty($conditions) ? "" : " WHERE ".implode(' AND ', $conditions);

        $query = "
           SELECT s.*, c.name as customer_name, u.username as user_name,
               (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
           FROM {$this->table} s
           LEFT JOIN customers c ON s.customer_id = c.id
           LEFT JOIN users u ON s.user_id = u.id
           $whereClause
           ORDER BY s.created_at DESC
       ";

        return $this->db->fetchAll($query, $params);
    }

    /**
     * @param $customerId
     * @return mixed
     */
    public function countByCustomerId($customerId)
    {
        return $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE customer_id = ?",
            [$customerId]
        );
    }
}
