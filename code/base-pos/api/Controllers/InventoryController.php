<?php
class InventoryController extends Controller
{
    // Category methods
    public function getCategories()
    {
        $categoryModel = new Category();
        $categories = $categoryModel->findAll('name ASC');

        Response::success('Categories retrieved', $categories);
    }

    public function getProducts()
    {
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
        foreach (['price', 'cost', 'quantity', 'low_stock_threshold'] as $f) {
            if (isset($data[$f]) && (!is_numeric($data[$f]) || (float)$data[$f] < 0)) {
                Response::error("Field '$f' must be a non-negative number", 400);
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
                'price' => $data['price'] ?? 0,
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
        $productModel = new Product();
        $products = $productModel->getLowStock();

        Response::success('Low stock products retrieved', $products);
    }

    public function getTransactions()
    {
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

            // Get product
            $product = $productModel->getById($data['product_id']);
            if (!$product) {
                throw new Exception('Product not found');
            }

            // Process different transaction types
            $newQuantity = $product['quantity'];
            $type = $data['type'];
            $quantity = intval($data['quantity']);

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
}
