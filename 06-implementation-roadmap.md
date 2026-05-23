# Implementation Roadmap - Secondhand Shop POS System
## Detailed Step-by-Step Customization Guide

**Document Version:** 1.0  
**Created:** 2026-05-16  
**Target:** goragodwiriya/pos-system customization for secondhand retail

---

## PHASE 1: FOUNDATION & SETUP (Week 1-2)

### Sprint 1.1: Database Schema Modifications (Days 1-2)

#### Task 1.1.1: Create Migration Script
**File:** `/database/migrations/001_add_branch_support.sql`

```sql
-- Create branches table
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    manager_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Add branch_id to existing tables
ALTER TABLE products ADD COLUMN branch_id INT AFTER category_id;
ALTER TABLE products ADD FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;

ALTER TABLE sales ADD COLUMN branch_id INT AFTER user_id;
ALTER TABLE sales ADD FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;

ALTER TABLE inventory_transactions ADD COLUMN branch_id INT AFTER user_id;
ALTER TABLE inventory_transactions ADD FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;

ALTER TABLE users ADD COLUMN branch_id INT AFTER role;
ALTER TABLE users ADD FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

ALTER TABLE activity_log ADD COLUMN branch_id INT AFTER user_id;
ALTER TABLE activity_log ADD FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

-- Create item_conditions table
CREATE TABLE item_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    color_code VARCHAR(7),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default conditions
INSERT INTO item_conditions (name, description, color_code) VALUES
('ดี', 'สภาพดี - ใช้งานได้ปกติ', '#28a745'),
('พอใช้', 'สภาพพอใช้ - มีรอยใช้งาน', '#ffc107'),
('ชำรุด', 'สภาพชำรุด - ต้องซ่อม', '#dc3545');

-- Create product_conditions table
CREATE TABLE product_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    condition_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    branch_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (condition_id) REFERENCES item_conditions(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    UNIQUE KEY unique_product_condition_branch (product_id, condition_id, branch_id)
);

-- Add condition tracking to sale_items
ALTER TABLE sale_items ADD COLUMN condition_id INT AFTER product_id;
ALTER TABLE sale_items ADD FOREIGN KEY (condition_id) REFERENCES item_conditions(id) ON DELETE SET NULL;

-- Create sellers table
CREATE TABLE sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    id_card_type ENUM('national_id', 'passport', 'other') NOT NULL,
    id_card_number VARCHAR(50) NOT NULL UNIQUE,
    phone VARCHAR(20),
    address TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create purchase_orders table (for buying from sellers)
CREATE TABLE purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(20) NOT NULL UNIQUE,
    seller_id INT NOT NULL,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'card', 'bank_transfer') DEFAULT 'cash',
    payment_status ENUM('paid', 'pending') DEFAULT 'paid',
    status ENUM('draft', 'submitted', 'accepted', 'rejected') DEFAULT 'draft',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create purchase_order_items table
CREATE TABLE purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    condition_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (condition_id) REFERENCES item_conditions(id) ON DELETE CASCADE
);

-- Create seller_payouts table
CREATE TABLE seller_payouts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    purchase_order_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'check') DEFAULT 'cash',
    payment_date DATETIME,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
);

-- Add database indexes for performance
CREATE INDEX idx_products_branch ON products(branch_id);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_sales_branch ON sales(branch_id);
CREATE INDEX idx_sales_created ON sales(created_at);
CREATE INDEX idx_sales_customer ON sales(customer_id);
CREATE INDEX idx_inventory_product ON inventory_transactions(product_id);
CREATE INDEX idx_inventory_branch ON inventory_transactions(branch_id);
CREATE INDEX idx_users_branch ON users(branch_id);
CREATE INDEX idx_activity_branch ON activity_log(branch_id);
CREATE INDEX idx_product_conditions_branch ON product_conditions(branch_id);
CREATE INDEX idx_purchase_orders_seller ON purchase_orders(seller_id);
CREATE INDEX idx_purchase_orders_branch ON purchase_orders(branch_id);
```

**Execution Steps:**
1. Backup existing database
2. Run migration script
3. Verify all tables and indexes created
4. Update existing data (set default branch_id = 1 for all records)

---

#### Task 1.1.2: Insert Default Branch
```sql
-- Insert default branch for existing data
INSERT INTO branches (id, name, address, phone, status) VALUES
(1, 'Main Branch', '123 Main Street', '0800000000', 'active');

-- Update all existing products to have branch_id = 1
UPDATE products SET branch_id = 1 WHERE branch_id IS NULL;

-- Update all existing sales to have branch_id = 1
UPDATE sales SET branch_id = 1 WHERE branch_id IS NULL;

-- Update all existing inventory_transactions to have branch_id = 1
UPDATE inventory_transactions SET branch_id = 1 WHERE branch_id IS NULL;

-- Update all existing users to have branch_id = 1
UPDATE users SET branch_id = 1 WHERE branch_id IS NULL;
```

**Verification:**
```sql
SELECT COUNT(*) FROM branches;  -- Should be 1
SELECT COUNT(*) FROM item_conditions;  -- Should be 3
SELECT COUNT(*) FROM products WHERE branch_id IS NOT NULL;  -- Should match total products
```

---

### Sprint 1.2: Create Branch Model & Controller (Days 2-3)

#### Task 1.2.1: Create Branch Model
**File:** `/api/Models/Branch.php`

```php
<?php
class Branch extends Model
{
    protected $table = 'branches';

    public function getAll()
    {
        $query = "SELECT b.*, u.full_name as manager_name
                 FROM {$this->table} b
                 LEFT JOIN users u ON b.manager_id = u.id
                 WHERE b.status = 'active'
                 ORDER BY b.name ASC";
        return $this->db->fetchAll($query);
    }

    public function getById($id)
    {
        $query = "SELECT b.*, u.full_name as manager_name
                 FROM {$this->table} b
                 LEFT JOIN users u ON b.manager_id = u.id
                 WHERE b.id = ?";
        return $this->db->fetch($query, [$id]);
    }

    public function create($data)
    {
        return $this->insert([
            'name' => $data['name'],
            'address' => $data['address'] ?? '',
            'phone' => $data['phone'] ?? '',
            'manager_id' => $data['manager_id'] ?? null,
            'status' => 'active'
        ]);
    }

    public function update($id, $data)
    {
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['address'])) $updateData['address'] = $data['address'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['manager_id'])) $updateData['manager_id'] = $data['manager_id'];
        if (isset($data['status'])) $updateData['status'] = $data['status'];

        if (!empty($updateData)) {
            parent::update($id, $updateData);
        }
        return true;
    }

    public function getBranchStats($branchId)
    {
        $stats = [];

        // Total products in branch
        $stats['total_products'] = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM products WHERE branch_id = ?",
            [$branchId]
        );

        // Total inventory value
        $stats['inventory_value'] = $this->db->fetchColumn(
            "SELECT SUM(quantity * cost) FROM products WHERE branch_id = ?",
            [$branchId]
        ) ?? 0;

        // Today's sales
        $stats['today_sales'] = $this->db->fetchColumn(
            "SELECT SUM(grand_total) FROM sales WHERE branch_id = ? AND DATE(created_at) = CURDATE()",
            [$branchId]
        ) ?? 0;

        // Total users in branch
        $stats['total_users'] = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE branch_id = ? AND status = 'active'",
            [$branchId]
        );

        return $stats;
    }
}
```

---

#### Task 1.2.2: Create Branches Controller
**File:** `/api/Controllers/BranchesController.php`

```php
<?php
class BranchesController extends Controller
{
    public function getBranches()
    {
        $branchModel = new Branch();
        $branches = $branchModel->getAll();
        Response::success('Branches retrieved', $branches);
    }

    public function createBranch()
    {
        $this->requireAuth(['admin']);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name']);

        $branchModel = new Branch();
        try {
            $branchId = $branchModel->create($data);
            Logger::logActivity(
                $this->user['user_id'],
                'create_branch',
                "Created branch: {$data['name']}"
            );
            Response::success('Branch created', ['id' => $branchId]);
        } catch (Exception $e) {
            Response::error('Failed to create branch: ' . $e->getMessage());
        }
    }

    public function getBranch($id)
    {
        if (!$id) {
            Response::error('Branch ID is required', 400);
        }

        $branchModel = new Branch();
        $branch = $branchModel->getById($id);

        if (!$branch) {
            Response::error('Branch not found', 404);
        }

        $branch['stats'] = $branchModel->getBranchStats($id);
        Response::success('Branch retrieved', $branch);
    }

    public function updateBranch($id)
    {
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('Branch ID is required', 400);
        }

        $data = $this->getRequestData();
        $branchModel = new Branch();

        try {
            $branchModel->update($id, $data);
            Logger::logActivity(
                $this->user['user_id'],
                'update_branch',
                "Updated branch ID: {$id}"
            );
            Response::success('Branch updated');
        } catch (Exception $e) {
            Response::error('Failed to update branch: ' . $e->getMessage());
        }
    }

    public function deleteBranch($id)
    {
        $this->requireAuth(['admin']);

        if (!$id) {
            Response::error('Branch ID is required', 400);
        }

        $branchModel = new Branch();
        try {
            // Check if branch has products
            $productCount = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM products WHERE branch_id = ?",
                [$id]
            );

            if ($productCount > 0) {
                Response::error('Cannot delete branch with products', 400);
            }

            // Check if branch has users
            $userCount = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM users WHERE branch_id = ?",
                [$id]
            );

            if ($userCount > 0) {
                Response::error('Cannot delete branch with assigned users', 400);
            }

            $branchModel->update($id, ['status' => 'inactive']);
            Logger::logActivity(
                $this->user['user_id'],
                'delete_branch',
                "Deactivated branch ID: {$id}"
            );
            Response::success('Branch deactivated');
        } catch (Exception $e) {
            Response::error('Failed to delete branch: ' . $e->getMessage());
        }
    }
}
```

---

#### Task 1.2.3: Update Router with Branch Routes
**File:** `/api/Router.php` (Add to registerRoutes method around line 50)

```php
// Branch routes
$this->routes[] = ['route' => 'branches', 'controller' => 'BranchesController', 'method' => 'getBranches', 'verb' => 'GET'];
$this->routes[] = ['route' => 'branches', 'controller' => 'BranchesController', 'method' => 'createBranch', 'verb' => 'POST'];
$this->routes[] = ['route' => 'branches/branch', 'controller' => 'BranchesController', 'method' => 'getBranch', 'verb' => 'GET'];
$this->routes[] = ['route' => 'branches/branch', 'controller' => 'BranchesController', 'method' => 'updateBranch', 'verb' => 'PUT'];
$this->routes[] = ['route' => 'branches/branch', 'controller' => 'BranchesController', 'method' => 'deleteBranch', 'verb' => 'DELETE'];
```

---

### Sprint 1.3: Create Condition Model (Days 3-4)

#### Task 1.3.1: Create Condition Model
**File:** `/api/Models/Condition.php`

```php
<?php
class Condition extends Model
{
    protected $table = 'item_conditions';

    public function getAll()
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} ORDER BY id ASC"
        );
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->table} WHERE id = ?",
            [$id]
        );
    }

    public function getByName($name)
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->table} WHERE name = ?",
            [$name]
        );
    }

    public function create($data)
    {
        return $this->insert([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'color_code' => $data['color_code'] ?? '#808080'
        ]);
    }

    public function update($id, $data)
    {
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['color_code'])) $updateData['color_code'] = $data['color_code'];

        if (!empty($updateData)) {
            parent::update($id, $updateData);
        }
        return true;
    }
}
```

---

### Sprint 1.4: Modify Product Model for Branch & Condition Support (Days 4-5)

#### Task 1.4.1: Update Product Model
**File:** `/api/Models/Product.php` (Modifications)

**Add these methods to Product class:**

```php
public function getByBranch($branchId, $categoryId = null)
{
    $query = "SELECT p.*, c.name as category_name
             FROM {$this->table} p
             LEFT JOIN categories c ON p.category_id = c.id
             WHERE p.branch_id = ?";

    $params = [$branchId];
    
    if ($categoryId) {
        $query .= " AND p.category_id = ?";
        $params[] = $categoryId;
    }

    $query .= " ORDER BY p.name ASC";
    return $this->db->fetchAll($query, $params);
}

public function getConditionStock($productId, $branchId)
{
    return $this->db->fetchAll(
        "SELECT pc.*, ic.name as condition_name, ic.color_code
         FROM product_conditions pc
         JOIN item_conditions ic ON pc.condition_id = ic.id
         WHERE pc.product_id = ? AND pc.branch_id = ?
         ORDER BY ic.id ASC",
        [$productId, $branchId]
    );
}

public function updateConditionStock($productId, $conditionId, $branchId, $quantity)
{
    $existing = $this->db->fetch(
        "SELECT id FROM product_conditions 
         WHERE product_id = ? AND condition_id = ? AND branch_id = ?",
        [$productId, $conditionId, $branchId]
    );

    if ($existing) {
        $this->db->update(
            'product_conditions',
            ['quantity' => $quantity],
            ['id = ?'],
            [$existing['id']]
        );
    } else {
        $this->db->insert('product_conditions', [
            'product_id' => $productId,
            'condition_id' => $conditionId,
            'branch_id' => $branchId,
            'quantity' => $quantity
        ]);
    }
}

public function getTotalQuantityByBranch($productId, $branchId)
{
    return $this->db->fetchColumn(
        "SELECT SUM(quantity) FROM product_conditions 
         WHERE product_id = ? AND branch_id = ?",
        [$productId, $branchId]
    ) ?? 0;
}
```

---

## PHASE 2: SELLER SYSTEM (Week 2-3)

### Sprint 2.1: Create Seller Model & Controller (Days 6-8)

#### Task 2.1.1: Create Seller Model
**File:** `/api/Models/Seller.php`

```php
<?php
class Seller extends Model
{
    protected $table = 'sellers';

    public function getAll()
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY name ASC"
        );
    }

    public function getById($id)
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->table} WHERE id = ?",
            [$id]
        );
    }

    public function create($data)
    {
        // Check if ID card already exists
        $exists = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} WHERE id_card_number = ?",
            [$data['id_card_number']]
        );

        if ($exists) {
            throw new Exception('ID card number already registered');
        }

        return $this->insert([
            'name' => $data['name'],
            'id_card_type' => $data['id_card_type'],
            'id_card_number' => $data['id_card_number'],
            'phone' => $data['phone'] ?? '',
            'address' => $data['address'] ?? '',
            'status' => 'active'
        ]);
    }

    public function update($id, $data)
    {
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['address'])) $updateData['address'] = $data['address'];
        if (isset($data['status'])) $updateData['status'] = $data['status'];

        if (!empty($updateData)) {
            parent::update($id, $updateData);
        }
        return true;
    }

    public function getSellerStats($sellerId)
    {
        $stats = [];

        // Total POs
        $stats['total_pos'] = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM purchase_orders WHERE seller_id = ?",
            [$sellerId]
        );

        // Total amount paid
        $stats['total_paid'] = $this->db->fetchColumn(
            "SELECT SUM(amount) FROM seller_payouts WHERE seller_id = ?",
            [$sellerId]
        ) ?? 0;

        // Pending payment
        $stats['pending_payment'] = $this->db->fetchColumn(
            "SELECT SUM(total_amount) FROM purchase_orders 
             WHERE seller_id = ? AND payment_status = 'pending'",
            [$sellerId]
        ) ?? 0;

        // Last transaction
        $lastPO = $this->db->fetch(
            "SELECT created_at FROM purchase_orders 
             WHERE seller_id = ? ORDER BY created_at DESC LIMIT 1",
            [$sellerId]
        );
        $stats['last_transaction'] = $lastPO['created_at'] ?? null;

        return $stats;
    }

    public function searchByIdCard($idCardNumber)
    {
        return $this->db->fetch(
            "SELECT * FROM {$this->table} WHERE id_card_number = ? AND status = 'active'",
            [$idCardNumber]
        );
    }
}
```

---

#### Task 2.1.2: Create Sellers Controller
**File:** `/api/Controllers/SellersController.php`

```php
<?php
class SellersController extends Controller
{
    public function getSellers()
    {
        $sellerModel = new Seller();
        $sellers = $sellerModel->getAll();
        Response::success('Sellers retrieved', $sellers);
    }

    public function createSeller()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['name', 'id_card_type', 'id_card_number']);

        $sellerModel = new Seller();
        try {
            $sellerId = $sellerModel->create($data);
            Logger::logActivity(
                $this->user['user_id'],
                'create_seller',
                "Registered seller: {$data['name']}"
            );
            Response::success('Seller registered', ['id' => $sellerId]);
        } catch (Exception $e) {
            Response::error('Failed to register seller: ' . $e->getMessage());
        }
    }

    public function getSeller($id)
    {
        if (!$id) {
            Response::error('Seller ID is required', 400);
        }

        $sellerModel = new Seller();
        $seller = $sellerModel->getById($id);

        if (!$seller) {
            Response::error('Seller not found', 404);
        }

        $seller['stats'] = $sellerModel->getSellerStats($id);
        Response::success('Seller retrieved', $seller);
    }

    public function updateSeller($id)
    {
        $this->requireAuth(['admin', 'manager']);

        if (!$id) {
            Response::error('Seller ID is required', 400);
        }

        $data = $this->getRequestData();
        $sellerModel = new Seller();

        try {
            $sellerModel->update($id, $data);
            Logger::logActivity(
                $this->user['user_id'],
                'update_seller',
                "Updated seller ID: {$id}"
            );
            Response::success('Seller updated');
        } catch (Exception $e) {
            Response::error('Failed to update seller: ' . $e->getMessage());
        }
    }

    public function searchByIdCard()
    {
        $idCard = isset($_GET['id_card']) ? $this->sanitizeInput($_GET['id_card']) : null;

        if (!$idCard) {
            Response::error('ID card number is required', 400);
        }

        $sellerModel = new Seller();
        $seller = $sellerModel->searchByIdCard($idCard);

        if (!$seller) {
            Response::success('Seller not found', null);
        } else {
            $seller['stats'] = $sellerModel->getSellerStats($seller['id']);
            Response::success('Seller found', $seller);
        }
    }
}
```

---

### Sprint 2.2: Create Purchase Order Model & Controller (Days 8-10)

#### Task 2.2.1: Create Purchase Order Model
**File:** `/api/Models/PurchaseOrder.php`

```php
<?php
class PurchaseOrder extends Model
{
    protected $table = 'purchase_orders';

    public function create($poData, $items)
    {
        $this->db->beginTransaction();

        try {
            // Calculate total
            $totalAmount = 0;
            foreach ($items as $item) {
                $totalAmount += floatval($item['total']);
            }

            // Generate reference number
            $date = date('Ymd');
            $count = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM {$this->table} WHERE reference_no LIKE ?",
                ["PO-$date-%"]
            );
            $refNumber = sprintf("PO-%s-%04d", $date, $count + 1);

            // Create PO header
            $poId = $this->insert([
                'reference_no' => $refNumber,
                'seller_id' => $poData['seller_id'],
                'branch_id' => $poData['branch_id'],
                'user_id' => $poData['user_id'],
                'total_amount' => $totalAmount,
                'payment_method' => $poData['payment_method'] ?? 'cash',
                'payment_status' => $poData['payment_status'] ?? 'pending',
                'status' => 'draft',
                'notes' => $poData['notes'] ?? ''
            ]);

            // Create PO items
            $poItemModel = new PurchaseOrderItem();
            foreach ($items as $item) {
                $poItemModel->insert([
                    'purchase_order_id' => $poId,
                    'product_id' => $item['product_id'],
                    'condition_id' => $item['condition_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'total' => $item['total']
                ]);
            }

            $this->db->commit();
            return $poId;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function acceptPO($poId, $userId)
    {
        $this->db->beginTransaction();

        try {
            // Get PO and items
            $po = $this->getById($poId);
            if (!$po) {
                throw new Exception('Purchase order not found');
            }

            $items = $this->db->fetchAll(
                "SELECT * FROM purchase_order_items WHERE purchase_order_id = ?",
                [$poId]
            );

            // Update inventory for each item
            $productModel = new Product();
            $inventory = new Inventory();

            foreach ($items as $item) {
                // Update product condition stock
                $currentQty = $productModel->db->fetchColumn(
                    "SELECT quantity FROM product_conditions 
                     WHERE product_id = ? AND condition_id = ? AND branch_id = ?",
                    [$item['product_id'], $item['condition_id'], $po['branch_id']]
                ) ?? 0;

                $newQty = $currentQty + $item['quantity'];
                $productModel->updateConditionStock(
                    $item['product_id'],
                    $item['condition_id'],
                    $po['branch_id'],
                    $newQty
                );

                // Record inventory transaction
                $inventory->recordTransaction(
                    $item['product_id'],
                    'purchase',
                    $item['quantity'],
                    $poId,
                    "Purchase from seller: {$po['reference_no']}",
                    $userId
                );
            }

            // Update PO status
            $this->update($poId, ['status' => 'accepted']);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function rejectPO($poId, $reason, $userId)
    {
        $this->update($poId, [
            'status' => 'rejected',
            'notes' => $reason
        ]);

        Logger::logActivity(
            $userId,
            'reject_purchase_order',
            "Rejected PO ID: {$poId}"
        );

        return true;
    }

    public function getPOsWithPagination($page = 1, $limit = 20, $branchId = null, $sellerId = null, $status = null)
    {
        $conditions = [];
        $params = [];

        if ($branchId) {
            $conditions[] = "po.branch_id = ?";
            $params[] = $branchId;
        }

        if ($sellerId) {
            $conditions[] = "po.seller_id = ?";
            $params[] = $sellerId;
        }

        if ($status) {
            $conditions[] = "po.status = ?";
            $params[] = $status;
        }

        $whereClause = empty($conditions) ? "" : " WHERE " . implode(' AND ', $conditions);

        // Count total
        $totalCount = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$this->table} po $whereClause",
            $params
        );

        $offset = ($page - 1) * $limit;

        // Get data
        $query = "
            SELECT po.*, s.name as seller_name, b.name as branch_name, u.full_name as user_name
            FROM {$this->table} po
            LEFT JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN branches b ON po.branch_id = b.id
            LEFT JOIN users u ON po.user_id = u.id
            $whereClause
            ORDER BY po.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $queryParams = array_merge($params, [$limit, $offset]);
        $pos = $this->db->fetchAll($query, $queryParams);

        return [
            'pos' => $pos,
            'pagination' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($totalCount / $limit)
            ]
        ];
    }

    public function getDetailedPO($poId)
    {
        $po = $this->db->fetch(
            "SELECT po.*, s.name as seller_name, s.phone as seller_phone, 
                    b.name as branch_name, u.full_name as user_name
             FROM {$this->table} po
             LEFT JOIN sellers s ON po.seller_id = s.id
             LEFT JOIN branches b ON po.branch_id = b.id
             LEFT JOIN users u ON po.user_id = u.id
             WHERE po.id = ?",
            [$poId]
        );

        if (!$po) {
            return null;
        }

        // Get items
        $items = $this->db->fetchAll(
            "SELECT poi.*, p.name as product_name, p.sku, ic.name as condition_name, ic.color_code
             FROM purchase_order_items poi
             JOIN products p ON poi.product_id = p.id
             JOIN item_conditions ic ON poi.condition_id = ic.id
             WHERE poi.purchase_order_id = ?",
            [$poId]
        );

        $po['items'] = $items;
        return $po;
    }
}
```

---

#### Task 2.2.2: Create Purchase Order Item Model
**File:** `/api/Models/PurchaseOrderItem.php`

```php
<?php
class PurchaseOrderItem extends Model
{
    protected $table = 'purchase_order_items';

    public function getByPOId($poId)
    {
        return $this->db->fetchAll(
            "SELECT * FROM {$this->table} WHERE purchase_order_id = ?",
            [$poId]
        );
    }
}
```

---

#### Task 2.2.3: Create Purchase Orders Controller
**File:** `/api/Controllers/PurchaseOrdersController.php`

```php
<?php
class PurchaseOrdersController extends Controller
{
    public function createPO()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['seller_id', 'branch_id', 'items']);

        if (empty($data['items'])) {
            Response::error('PO must have at least one item', 400);
        }

        $poModel = new PurchaseOrder();

        try {
            $poData = [
                'seller_id' => $data['seller_id'],
                'branch_id' => $data['branch_id'],
                'user_id' => $this->user['user_id'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_status' => $data['payment_status'] ?? 'pending',
                'notes' => $data['notes'] ?? ''
            ];

            $poId = $poModel->create($poData, $data['items']);

            Logger::logActivity(
                $this->user['user_id'],
                'create_purchase_order',
                "Created PO from seller ID: {$data['seller_id']}"
            );

            Response::success('Purchase order created', ['id' => $poId]);
        } catch (Exception $e) {
            Response::error('Failed to create PO: ' . $e->getMessage());
        }
    }

    public function getPOs()
    {
        $pagination = $this->getPaginationParams();
        $branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null;
        $sellerId = isset($_GET['seller_id']) ? intval($_GET['seller_id']) : null;
        $status = isset($_GET['status']) ? $this->sanitizeInput($_GET['status']) : null;

        $poModel = new PurchaseOrder();
        $result = $poModel->getPOsWithPagination(
            $pagination['page'],
            $pagination['limit'],
            $branchId,
            $sellerId,
            $status
        );

        Response::success('Purchase orders retrieved', $result);
    }

    public function getPODetails($id)
    {
        if (!$id) {
            Response::error('PO ID is required', 400);
        }

        $poModel = new PurchaseOrder();
        $po = $poModel->getDetailedPO($id);

        if (!$po) {
            Response::error('Purchase order not found', 404);
        }

        Response::success('PO details retrieved', $po);
    }

    public function acceptPO()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['id']);

        $poId = intval($data['id']);
        $poModel = new PurchaseOrder();

        try {
            $poModel->acceptPO($poId, $this->user['user_id']);

            Logger::logActivity(
                $this->user['user_id'],
                'accept_purchase_order',
                "Accepted PO ID: {$poId}"
            );

            Response::success('Purchase order accepted');
        } catch (Exception $e) {
            Response::error('Failed to accept PO: ' . $e->getMessage());
        }
    }

    public function rejectPO()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['id', 'reason']);

        $poId = intval($data['id']);
        $reason = $this->sanitizeInput($data['reason']);

        $poModel = new PurchaseOrder();

        try {
            $poModel->rejectPO($poId, $reason, $this->user['user_id']);
            Response::success('Purchase order rejected');
        } catch (Exception $e) {
            Response::error('Failed to reject PO: ' . $e->getMessage());
        }
    }
}
```

---

### Sprint 2.3: Update Router with Seller & PO Routes (Day 10)

**File:** `/api/Router.php` (Add to registerRoutes method)

```php
// Seller routes
$this->routes[] = ['route' => 'sellers', 'controller' => 'SellersController', 'method' => 'getSellers', 'verb' => 'GET'];
$this->routes[] = ['route' => 'sellers', 'controller' => 'SellersController', 'method' => 'createSeller', 'verb' => 'POST'];
$this->routes[] = ['route' => 'sellers/seller', 'controller' => 'SellersController', 'method' => 'getSeller', 'verb' => 'GET'];
$this->routes[] = ['route' => 'sellers/seller', 'controller' => 'SellersController', 'method' => 'updateSeller', 'verb' => 'PUT'];
$this->routes[] = ['route' => 'sellers/search', 'controller' => 'SellersController', 'method' => 'searchByIdCard', 'verb' => 'GET'];

// Purchase Order routes
$this->routes[] = ['route' => 'purchase-orders', 'controller' => 'PurchaseOrdersController', 'method' => 'getPOs', 'verb' => 'GET'];
$this->routes[] = ['route' => 'purchase-orders', 'controller' => 'PurchaseOrdersController', 'method' => 'createPO', 'verb' => 'POST'];
$this->routes[] = ['route' => 'purchase-orders/details', 'controller' => 'PurchaseOrdersController', 'method' => 'getPODetails', 'verb' => 'GET'];
$this->routes[] = ['route' => 'purchase-orders/accept', 'controller' => 'PurchaseOrdersController', 'method' => 'acceptPO', 'verb' => 'POST'];
$this->routes[] = ['route' => 'purchase-orders/reject', 'controller' => 'PurchaseOrdersController', 'method' => 'rejectPO', 'verb' => 'POST'];
```

---

## PHASE 3: FRONTEND IMPLEMENTATION (Week 3-4)

### Sprint 3.1: Create Admin Pages for Branches & Sellers (Days 11-14)

#### Task 3.1.1: Create Branches Admin Page
**File:** `/admin/branches.html`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branches - POS System</title>
    <link rel="stylesheet" href="../assets/css/fonts.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <!-- Include sidebar navigation -->
        </aside>

        <main class="content-area">
            <div class="topbar">
                <!-- Include topbar -->
            </div>

            <div class="page-header">
                <h1>Branch Management</h1>
                <button class="btn btn-primary" id="addBranchBtn">
                    <i class="icon-plus"></i> Add Branch
                </button>
            </div>

            <div class="branches-list" id="branchesList">
                <!-- Branches will be loaded here -->
            </div>
        </main>
    </div>

    <!-- Branch Modal -->
    <div class="modal" id="branchModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="branchModalTitle">Add Branch</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="branchForm">
                    <div class="form-group">
                        <label for="branchName">Branch Name *</label>
                        <input type="text" id="branchName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="branchAddress">Address</label>
                        <textarea id="branchAddress" class="form-control"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="branchPhone">Phone</label>
                        <input type="tel" id="branchPhone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="branchManager">Manager</label>
                        <select id="branchManager" class="form-control">
                            <option value="">Select Manager</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="closeBranchModal">Cancel</button>
                <button class="btn btn-primary" id="saveBranchBtn">Save Branch</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/config.js"></script>
    <script src="../assets/js/common.js"></script>
    <script src="../assets/js/branches.js"></script>
</body>
</html>
```

---

#### Task 3.1.2: Create Sellers Admin Page
**File:** `/admin/sellers.html`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sellers - POS System</title>
    <link rel="stylesheet" href="../assets/css/fonts.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <!-- Include sidebar navigation -->
        </aside>

        <main class="content-area">
            <div class="topbar">
                <!-- Include topbar -->
            </div>

            <div class="page-header">
                <h1>Seller Management</h1>
                <button class="btn btn-primary" id="registerSellerBtn">
                    <i class="icon-plus"></i> Register Seller
                </button>
            </div>

            <div class="sellers-list" id="sellersList">
                <!-- Sellers will be loaded here -->
            </div>
        </main>
    </div>

    <!-- Seller Modal -->
    <div class="modal" id="sellerModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Register Seller</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="sellerForm">
                    <div class="form-group">
                        <label for="sellerName">Seller Name *</label>
                        <input type="text" id="sellerName" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="idCardType">ID Card Type *</label>
                        <select id="idCardType" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="national_id">National ID</option>
                            <option value="passport">Passport</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="idCardNumber">ID Card Number *</label>
                        <input type="text" id="idCardNumber" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="sellerPhone">Phone</label>
                        <input type="tel" id="sellerPhone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label for="sellerAddress">Address</label>
                        <textarea id="sellerAddress" class="form-control"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="closeSellerModal">Cancel</button>
                <button class="btn btn-primary" id="saveSellerBtn">Register Seller</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/config.js"></script>
    <script src="../assets/js/common.js"></script>
    <script src="../assets/js/sellers.js"></script>
</body>
</html>
```

---

#### Task 3.1.3: Create Purchase Orders Admin Page
**File:** `/admin/purchase-orders.html`

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Orders - POS System</title>
    <link rel="stylesheet" href="../assets/css/fonts.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <!-- Include sidebar navigation -->
        </aside>

        <main class="content-area">
            <div class="topbar">
                <!-- Include topbar -->
            </div>

            <div class="page-header">
                <h1>Purchase Orders</h1>
                <button class="btn btn-primary" id="createPOBtn">
                    <i class="icon-plus"></i> Create PO
                </button>
            </div>

            <div class="filters">
                <select id="branchFilter" class="form-control">
                    <option value="">All Branches</option>
                </select>
                <select id="statusFilter" class="form-control">
                    <option value="">All Status</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="accepted">Accepted</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <div class="pos-list" id="posList">
                <!-- POs will be loaded here -->
            </div>
        </main>
    </div>

    <!-- PO Modal -->
    <div class="modal" id="poModal">
        <div class="modal-content large">
            <div class="modal-header">
                <h2>Create Purchase Order</h2>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="poForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="poSeller">Seller *</label>
                            <input type="text" id="poSellerSearch" class="form-control" placeholder="Search by name or ID card">
                            <select id="poSeller" class="form-control" required></select>
                        </div>
                        <div class="form-group">
                            <label for="poBranch">Branch *</label>
                            <select id="poBranch" class="form-control" required></select>
                        </div>
                    </div>

                    <div class="po-items">
                        <h3>Items</h3>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Condition</th>
                                    <th>Quantity</th>
                                    <th>Unit Price</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="poItemsTable">
                                <!-- Items will be added here -->
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm" id="addPOItemBtn">
                            <i class="icon-plus"></i> Add Item
                        </button>
                    </div>

                    <div class="form-group">
                        <label for="poNotes">Notes</label>
                        <textarea id="poNotes" class="form-control"></textarea>
                    </div>

                    <div class="summary">
                        <div class="summary-row">
                            <span>Total Amount:</span>
                            <span id="poTotal">฿0.00</span>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="closePOModal">Cancel</button>
                <button class="btn btn-primary" id="savePOBtn">Create PO</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/config.js"></script>
    <script src="../assets/js/common.js"></script>
    <script src="../assets/js/purchase-orders.js"></script>
</body>
</html>
```

---

## PHASE 4: TESTING & DEPLOYMENT (Week 4)

### Sprint 4.1: Testing Checklist

#### Unit Tests
- [ ] Branch model CRUD operations
- [ ] Seller model CRUD operations
- [ ] PurchaseOrder model creation and acceptance
- [ ] Product condition stock tracking
- [ ] Inventory transaction recording

#### Integration Tests
- [ ] Create PO → Accept → Verify inventory updated
- [ ] Register seller → Create PO → Accept flow
- [ ] Multi-branch product filtering
- [ ] Condition-based stock tracking

#### UI/UX Tests
- [ ] Branch selector in header
- [ ] Seller search by ID card
- [ ] PO creation workflow
- [ ] Condition selection in POS
- [ ] Branch-specific reporting

---

### Sprint 4.2: Deployment Checklist

- [ ] Database backup before migration
- [ ] Run migration scripts
- [ ] Verify all tables created
- [ ] Update API routes
- [ ] Deploy new controllers and models
- [ ] Deploy frontend pages
- [ ] Test all endpoints
- [ ] Verify permissions and access control
- [ ] Performance testing with sample data
- [ ] Security audit
- [ ] User training documentation

---

## ESTIMATED TIMELINE

| Phase | Sprint | Days | Hours |
|-------|--------|------|-------|
| 1 | 1.1-1.4 | 5 | 20 |
| 2 | 2.1-2.3 | 5 | 24 |
| 3 | 3.1 | 4 | 16 |
| 4 | 4.1-4.2 | 2 | 8 |
| **TOTAL** | | **16 days** | **68 hours** |

---

**Document Status:** READY FOR IMPLEMENTATION  
**Next Step:** Begin Phase 1, Sprint 1.1 - Database Schema Modifications
