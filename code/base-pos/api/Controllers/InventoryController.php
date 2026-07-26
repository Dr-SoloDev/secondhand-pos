<?php
class InventoryController extends Controller
{
    // Category methods
    public function getCategories()
    {
        $this->requireAuth();
        $branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : 0;
        $userBranch = $this->enforceBranchScope();
        if ($userBranch !== null) {
            $branchId = $userBranch;
        }

        $categoryModel = new Category();
        $categories = $categoryModel->findAll('name ASC');

        if ($branchId) {
            // Per-branch mode: aggregate stock from branch_stock (SSoT)
            $branchStocks = $this->db->fetchAll(
                "SELECT category_id,
                        ROUND(SUM(stock_kg), 3) AS stock_kg
                 FROM branch_stock
                 WHERE branch_id = ?
                 GROUP BY category_id",
                [$branchId]
            );

            // Build lookup: category_id => stock_kg
            $stockMap = [];
            foreach ($branchStocks as $row) {
                $catId = (int)$row['category_id'];
                if ($catId > 0) {
                    $stockMap[$catId] = (float)$row['stock_kg'];
                }
            }

            // Override stock_kg for each category
            foreach ($categories as &$cat) {
                $cat['stock_kg'] = $stockMap[(int)$cat['id']] ?? 0;
            }
            unset($cat);
        }

        Response::success('Categories retrieved', $categories);
    }

    public function getProducts()
    {
        $this->requireAuth();
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;

        $productModel = new Product();
        $products = $productModel->getAll($categoryId);

        Response::success('Products retrieved', $products);
    }

    public function createProduct()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name']);

        $data = $this->sanitizeInput($data);

        // M1: ตรวจค่าตัวเลขให้ไม่ติดลบ (negative price/cost/quantity ไม่ make sense)
        foreach (['price_tier1', 'price_tier2', 'price_tier3', 'quantity', 'low_stock_threshold'] as $f) {
            if (isset($data[$f]) && (!is_numeric($data[$f]) || (float)$data[$f] < 0)) {
                Response::error("Field '$f' must be a non-negative number", 400);
            }
        }

        // Validate: บิล1 ≤ บิล2 ≤ บิล3 (เท่ากันได้ แต่ห้ามกลับด้าน)
        if (isset($data['price_tier1']) && isset($data['price_tier2']) && isset($data['price_tier3'])) {
            $t1 = (float)$data['price_tier1'];
            $t2 = (float)$data['price_tier2'];
            $t3 = (float)$data['price_tier3'];
            if ($t1 > $t2 || $t2 > $t3) {
                Response::error('ราคาต้องเรียงจากน้อยไปมาก: บิล 1 ≤ บิล 2 ≤ บิล 3', 400);
            }
        }

        $name = trim((string)$data['name']);
        if ($name === '') {
            Response::error('Field name must not be empty', 400);
        }

        $productModel = new Product();

        try {
            $productId = $productModel->create([
                'name' => $name,
                'sku' => trim((string)($data['sku'] ?? '')),
                'barcode' => trim((string)($data['barcode'] ?? '')),
                'description' => $data['description'] ?? '',
                'category_id' => $data['category_id'] ?? null,
                'price' => $data['price_tier2'] ?? 0,  // backward compatibility
                'price_tier1' => $data['price_tier1'] ?? 0,
                'price_tier2' => $data['price_tier2'] ?? 0,
                'price_tier3' => $data['price_tier3'] ?? 0,
                'cost' => $data['cost'] ?? 0,
                'quantity' => $data['quantity'] ?? 0,
                'unit' => $data['unit'] ?? 'ชิ้น',
                'low_stock_threshold' => $data['low_stock_threshold'] ?? 5,
                'status' => 'active',
                'user_id' => $this->user['user_id'] ?? null,
            ]);

            try {
                Logger::logActivity(
                    $this->user['user_id'] ?? 0,
                    'create_product',
                    "Created product: {$name}"
                );
            } catch (Exception $logErr) {
                error_log('Product create log failed: ' . $logErr->getMessage());
            }

            Response::success('Product created', ['id' => $productId]);
        } catch (Exception $e) {
            error_log('Product create failed: ' . $e->getMessage());
            Response::error('Failed to create product', 400);
        }
    }

    public function createCategory()
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name']);

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Create category
        $categoryModel = new Category();
        try {
            $categoryId = $categoryModel->insert([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'status' => 'active'
            ]);

            // Log activity
            Logger::logActivity($this->user['user_id'], 'create_category', "Created category: {$data['name']}");

            Response::success('Category created', [
                'id' => $categoryId,
                'name' => $data['name']
            ]);
        } catch (Exception $e) {
            error_log('Category create failed: ' . $e->getMessage());
            Response::error('Failed to create category', 500);
        }
    }

    /**
     * @param $id
     */
    public function getCategory($id)
    {
        $this->requireAuth();
        if (!$id) {
            Response::error('Category ID is required', 400);
        }

        $categoryModel = new Category();
        $category = $categoryModel->findById($id);

        if (!$category) {
            Response::error('Category not found', 404);
        }

        Response::success('Category retrieved', $category);
    }

    /**
     * @param $id
     */
    public function updateCategory($id)
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        if (!$id) {
            Response::error('Category ID is required', 400);
        }

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name']);

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Update category
        $categoryModel = new Category();

        // Check if category exists
        $category = $categoryModel->findById($id);
        if (!$category) {
            Response::error('Category not found', 404);
        }

        try {
            $categoryModel->update($id, [
                'name' => $data['name'],
                'description' => $data['description'] ?? $category['description'],
                'status' => $data['status'] ?? $category['status']
            ]);

            // Log activity
            Logger::logActivity($this->user['user_id'], 'update_category', "Updated category ID: {$id}");

            Response::success('Category updated');
        } catch (Exception $e) {
            error_log('Category update failed: ' . $e->getMessage());
            Response::error('Failed to update category', 500);
        }
    }

    /**
     * @param $id
     */
    public function deleteCategory($id)
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('Category ID is required', 400);
        }

        // Check if category has products
        $productModel = new Product();
        $count = $productModel->countByCategoryId($id);

        if ($count > 0) {
            Response::error('Cannot delete category with associated products', 400);
        }

        // Delete category
        $categoryModel = new Category();

        try {
            $categoryModel->delete($id);

            // Log activity
            Logger::logActivity($this->user['user_id'], 'delete_category', "Deleted category ID: {$id}");

            Response::success('Category deleted');
        } catch (Exception $e) {
            error_log('Category delete failed: ' . $e->getMessage());
            Response::error('Failed to delete category', 500);
        }
    }

    /**
     * @param $id
     */
    public function getProduct($id)
    {
        $this->requireAuth();
        if (!$id) {
            Response::error('Product ID is required', 400);
        }

        $productModel = new Product();
        $product = $productModel->getById($id);

        if (!$product) {
            Response::error('Product not found', 404);
        }

        Response::success('Product retrieved', $product);
    }

    /**
     * @param $id
     */
    public function updateProduct($id)
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        if (!$id) {
            Response::error('Product ID is required', 400);
        }

        // Get and validate request data
        $data = $this->getRequestData();

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Validate: บิล1 ≤ บิล2 ≤ บิล3 (เท่ากันได้ แต่ห้ามกลับด้าน)
        if (isset($data['price_tier1']) && isset($data['price_tier2']) && isset($data['price_tier3'])) {
            $t1 = (float)$data['price_tier1'];
            $t2 = (float)$data['price_tier2'];
            $t3 = (float)$data['price_tier3'];
            if ($t1 > $t2 || $t2 > $t3) {
                Response::error('ราคาต้องเรียงจากน้อยไปมาก: บิล 1 ≤ บิล 2 ≤ บิล 3', 400);
            }
        }

        // Update product
        $productModel = new Product();

        try {
            $productModel->update($id, $data);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'update_product',
                "Updated product ID: {$id}"
            );

            Response::success('Product updated');
        } catch (Exception $e) {
            error_log('Product update failed: ' . $e->getMessage());
            Response::error('Failed to update product', 500);
        }
    }

    /**
     * @param $id
     */
    public function deleteProduct($id)
    {
        // Check permissions
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('Product ID is required', 400);
        }

        // Delete product
        $productModel = new Product();

        try {
            $result = $productModel->delete($id);

            // Log activity
            $action = $result === 'deleted' ? 'delete_product' : 'deactivate_product';
            $message = $result === 'deleted' ? "Deleted product ID: {$id}" : "Deactivated product ID: {$id}";

            Logger::logActivity($this->user['user_id'], $action, $message);

            if ($result === 'deleted') {
                Response::success('Product deleted');
            } else {
                Response::success('Product deactivated due to existing transactions');
            }
        } catch (Exception $e) {
            error_log('Product delete failed: ' . $e->getMessage());
            Response::error('Failed to delete product', 500);
        }
    }

    public function getLowStock()
    {
        $this->requireAuth();
        $productModel = new Product();
        $products = $productModel->getLowStock();

        Response::success('Low stock products retrieved', $products);
    }

    public function getTransactions()
    {
        $this->requireAuth();
        $productId = isset($_GET['product_id']) ? intval($_GET['product_id']) : null;
        $type = isset($_GET['type']) ? $this->sanitizeInput($_GET['type']) : null;

        $inventory = new Inventory();
        $transactions = $inventory->getTransactions($productId, $type);

        Response::success('Inventory transactions retrieved', $transactions);
    }

    public function createTransaction()
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['product_id', 'type', 'quantity']);

        // Sanitize input
        $data = $this->sanitizeInput($data);

        // Create transaction
        $productModel = new Product();
        $inventory = new Inventory();

        try {
            $this->db->beginTransaction();

            // Get product — try products table first, then catalog
            $product = $productModel->getById($data['product_id']);
            if (!$product) {
                $catalogModel = new \PurchaseItemCatalog();
                $catalogItem = $catalogModel->getById($data['product_id']);
                if ($catalogItem) {
                    $tierPrices = $catalogItem['tier_prices'] ?? [];
                    $newProductId = $productModel->create([
                        'sku' => 'CAT-' . $catalogItem['id'],
                        'name' => $catalogItem['name'],
                        'price' => $catalogItem['default_price'] ?? 0,
                        'cost' => 0,
                        'category_id' => $catalogItem['category_id'] ?? null,
                        'price_tier1' => $tierPrices[0]['price'] ?? 0,
                        'price_tier2' => $tierPrices[1]['price'] ?? 0,
                        'price_tier3' => $tierPrices[2]['price'] ?? 0,
                        'quantity' => 0,
                        'status' => 'active',
                    ]);
                    $product = $productModel->getById($newProductId);
                    $data['product_id'] = $newProductId;
                }
            }
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Process different transaction types
            $newQuantity = $product['quantity'];
            $type = $data['type'];
            $quantity = intval($data['quantity']);
            if ($quantity <= 0) {
                throw new Exception('Quantity must be greater than 0');
            }

            switch ($type) {
                case 'purchase':
                    $newQuantity += $quantity;
                    break;

                case 'sale':
                    if ($product['quantity'] < $quantity) {
                        throw new Exception('Insufficient stock');
                    }
                    $newQuantity -= $quantity;
                    break;

                case 'adjustment':
                    $newQuantity = $quantity;
                    break;

                case 'return':
                    $newQuantity += $quantity;
                    break;

                default:
                    throw new Exception('Invalid transaction type');
            }

            // Update product quantity
            $productModel->updateStock(
                $data['product_id'],
                $newQuantity,
                $data['notes'] ?? '',
                $this->user['user_id']
            );

            // Record transaction
            $transactionId = $inventory->recordTransaction(
                $data['product_id'],
                $type,
                $quantity,
                $data['reference_id'] ?? null,
                $data['notes'] ?? '',
                $this->user['user_id']
            );

            $this->db->commit();

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'inventory_transaction',
                "Created {$type} transaction for product ID: {$data['product_id']}"
            );

            Response::success('Inventory transaction created', ['id' => $transactionId]);
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log('Inventory transaction create failed: ' . $e->getMessage());
            Response::error('Failed to create transaction', 500);
        }
    }

    public function setThreshold()
    {
        $this->requireAuth(['admin']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id  = intval($body['category_id'] ?? 0);
        $val = isset($body['threshold']) && $body['threshold'] !== '' ? floatval($body['threshold']) : null;
        if (!$id) { Response::error('ไม่พบ category_id', 400); return; }
        $stmt = $this->db->prepare("UPDATE categories SET alert_threshold=? WHERE id=?");
        $this->db->execute($stmt, [$val, $id]);
        Response::success('บันทึกแล้ว');
    }

    /**
     * SECURITY: Non-admin บังคับ scope ที่ branch ของตัวเองเสมอ
     */
    private function enforceBranchScope()
    {
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
            return $userBranch;
        }
        return null; // admin: no restriction
    }

    public function getStockAlerts()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $branchId = isset($_GET['branch_id']) && is_numeric($_GET['branch_id'])
            ? intval($_GET['branch_id']) : null;
        if ($userBranch !== null) {
            $branchId = $userBranch;
        }

        // Query branch_stock for items below threshold
        // Joined with categories to get the alert_threshold
        $where = [];
        $params = [];

        if ($branchId) {
            $where[] = "bs.branch_id = ?";
            $params[] = $branchId;
        }

        // Alert: stock_kg <= 0 (out of stock) OR stock_kg <= alert_threshold
        // When categories.alert_threshold is NULL, treat as threshold = 0
        $where[] = "(c.alert_threshold IS NOT NULL AND bs.stock_kg <= c.alert_threshold)
                    OR (c.alert_threshold IS NULL AND bs.stock_kg <= 0)";

        $whereClause = " WHERE " . implode(' AND ', $where);

        $rows = $this->db->fetchAll(
            "SELECT
                bs.id,
                bs.branch_id,
                b.name AS branch_name,
                bs.category_id,
                c.name AS category_name,
                bs.item_name,
                bs.stock_kg,
                bs.unit_price,
                (bs.stock_kg * bs.unit_price) AS inventory_value,
                c.alert_threshold
            FROM branch_stock bs
            LEFT JOIN categories c ON bs.category_id = c.id
            LEFT JOIN branches b ON bs.branch_id = b.id
            $whereClause
            ORDER BY bs.stock_kg ASC, c.name ASC, bs.item_name ASC",
            $params
        );

        $totalAlerts = count($rows);
        $zeroStockCount = 0;
        $belowThresholdCount = 0;
        foreach ($rows as &$row) {
            if ($row['stock_kg'] <= 0) {
                $zeroStockCount++;
            } else {
                $belowThresholdCount++;
            }
        }
        unset($row);

        Response::success('สำเร็จ', [
            'items' => $rows ?: [],
            'summary' => [
                'total_alerts' => $totalAlerts,
                'zero_stock' => $zeroStockCount,
                'below_threshold' => $belowThresholdCount,
            ]
        ]);
    }

    /**
     * GET /inventory/category-items?category_id=X&branch_id=Y
     * คืนค่ารายการสินค้า (item_name) ในหมวดหมู่พร้อมสต็อกคงเหลือ (กก.)
     * ดึงจาก branch_stock (SSoT) แทนการคำนวณจาก PO items โดยตรง
     */
    public function getCategoryItems()
    {
        $this->requireAuth();
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
        $branchId   = isset($_GET['branch_id'])   ? intval($_GET['branch_id'])   : 0;
        $userBranch = $this->enforceBranchScope();
        if ($userBranch !== null) {
            $branchId = $userBranch;
        }

        if (!$categoryId) {
            Response::error('ต้องระบุ category_id', 400);
            return;
        }

        // ตรวจสอบหมวดหมู่
        $categoryModel = new Category();
        $category = $categoryModel->findById($categoryId);
        if (!$category) {
            Response::error('ไม่พบหมวดหมู่', 404);
            return;
        }

        // Query item-level stock จาก branch_stock (SSoT)
        $sql = "SELECT
                    bs.item_name,
                    bs.stock_kg,
                    bs.unit_price AS latest_unit_price
                FROM branch_stock bs
                WHERE bs.category_id = ?";
        $params = [$categoryId];

        if ($branchId) {
            $sql .= " AND bs.branch_id = ?";
            $params[] = $branchId;
        }

        $sql .= " ORDER BY bs.stock_kg DESC, bs.item_name ASC";

        $items = $this->db->fetchAll($sql, $params) ?: [];

        $totalStockKg = 0;
        $formattedItems = [];
        foreach ($items as $item) {
            $kg = (float)$item['stock_kg'];
            $totalStockKg += $kg;
            $formattedItems[] = [
                'item_name'         => $item['item_name'],
                'stock_kg'          => $kg,
                'latest_unit_price' => (float)($item['latest_unit_price'] ?? 0),
            ];
        }
        $totalItems = count($formattedItems);

        // Per-branch: แสดง stock_kg จริงที่ query ได้ (ไม่ใช่ global categories.stock_kg)
        $catStockKg = $branchId ? $totalStockKg : (float)$category['stock_kg'];

        Response::success('สำเร็จ', [
            'category' => [
                'id'       => (int)$category['id'],
                'name'     => $category['name'],
                'stock_kg' => $catStockKg,
            ],
            'items'          => $formattedItems,
            'total_items'    => $totalItems,
            'total_stock_kg' => $totalStockKg,
        ]);
    }
}
