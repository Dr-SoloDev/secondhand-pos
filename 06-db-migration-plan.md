# DB Migration Plan: Secondhand POS System (4 Branches)

## 1. สรุป Schema เดิม

### ตารางที่มีอยู่
- **users** - ผู้ใช้งาน (admin, manager, cashier)
- **categories** - หมวดหมู่สินค้า
- **products** - สินค้า (sku, barcode, name, price, cost, quantity)
- **customers** - ลูกค้า
- **suppliers** - ผู้จัดจำหน่าย
- **inventory_transactions** - บันทึกการเปลี่ยนแปลง inventory
- **sales** - ใบขาย
- **sale_items** - รายการสินค้าในใบขาย
- **purchases** - ใบซื้อ
- **purchase_items** - รายการสินค้าในใบซื้อ
- **settings** - การตั้งค่าระบบ
- **activity_log** - บันทึกกิจกรรม
- **backup_history** - ประวัติการ backup

### ความสามารถปัจจุบัน
✅ ระบบขาย-ซื้อพื้นฐาน
✅ จัดการ inventory
✅ บันทึกกิจกรรม
✅ ระบบผู้ใช้และสิทธิ์

---

## 2. Gap Analysis: สิ่งที่ขาด/ต้องแก้

### ❌ ปัญหาหลัก

| ปัญหา | ผลกระทบ | วิธีแก้ |
|------|--------|--------|
| **ไม่มี branch/สาขา** | ไม่สามารถแยก inventory ตามสาขา | เพิ่มตาราง `branches` + `branch_id` ในตารางที่เกี่ยวข้อง |
| **ไม่มี seller** | ไม่สามารถติดตามใครขายสินค้า | เพิ่มตาราง `sellers` + `seller_id` ในตาราง sales |
| **ไม่มี condition grading** | สินค้ามือสองไม่มีระดับคุณภาพ | เพิ่ม `condition` field ในตาราง products |
| **ไม่มี purchase_orders** | ไม่สามารถจัดการการซื้อสินค้ามือสอง | เพิ่มตาราง `purchase_orders` + `purchase_order_items` |
| **inventory ไม่แยกตามสาขา** | ไม่รู้สินค้าอยู่สาขาไหน | เพิ่มตาราง `branch_inventory` |
| **ไม่มี seller_id ในตาราง users** | ไม่สามารถระบุว่าผู้ใช้เป็น seller | เพิ่ม `seller_id` ในตาราง users |

---

## 3. SQL Migration Script

```sql
-- ========================================
-- STEP 1: สร้างตาราง Branches
-- ========================================
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    address TEXT,
    phone VARCHAR(20),
    email VARCHAR(100),
    manager_id INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ========================================
-- STEP 2: สร้างตาราง Sellers (ผู้ขายสินค้ามือสอง)
-- ========================================
CREATE TABLE IF NOT EXISTS sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    address TEXT,
    bank_account VARCHAR(50),
    bank_name VARCHAR(100),
    id_card_number VARCHAR(20),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    total_sold DECIMAL(12, 2) DEFAULT 0,
    total_earnings DECIMAL(12, 2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- ========================================
-- STEP 3: เพิ่ม branch_id + seller_id ในตาราง users
-- ========================================
ALTER TABLE users ADD COLUMN branch_id INT AFTER role;
ALTER TABLE users ADD COLUMN seller_id INT AFTER branch_id;
ALTER TABLE users ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE SET NULL;

-- ========================================
-- STEP 4: เพิ่ม condition + branch_id ในตาราง products
-- ========================================
ALTER TABLE products ADD COLUMN condition ENUM('new', 'like_new', 'good', 'fair', 'poor') DEFAULT 'new' AFTER status;
ALTER TABLE products ADD COLUMN branch_id INT AFTER condition;
ALTER TABLE products ADD COLUMN seller_id INT AFTER branch_id;
ALTER TABLE products ADD CONSTRAINT fk_products_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;
ALTER TABLE products ADD CONSTRAINT fk_products_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE SET NULL;

-- ========================================
-- STEP 5: เพิ่ม seller_id ในตาราง sales
-- ========================================
ALTER TABLE sales ADD COLUMN seller_id INT AFTER user_id;
ALTER TABLE sales ADD COLUMN branch_id INT AFTER seller_id;
ALTER TABLE sales ADD CONSTRAINT fk_sales_seller FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE SET NULL;
ALTER TABLE sales ADD CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;

-- ========================================
-- STEP 6: เพิ่ม branch_id ในตาราง purchases
-- ========================================
ALTER TABLE purchases ADD COLUMN branch_id INT AFTER user_id;
ALTER TABLE purchases ADD CONSTRAINT fk_purchases_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;

-- ========================================
-- STEP 7: สร้างตาราง Purchase Orders (สำหรับซื้อสินค้ามือสอง)
-- ========================================
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(20) NOT NULL UNIQUE,
    seller_id INT NOT NULL,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL,
    status ENUM('draft', 'pending', 'approved', 'received', 'completed', 'rejected') DEFAULT 'draft',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ========================================
-- STEP 8: สร้างตาราง Purchase Order Items
-- ========================================
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    condition ENUM('new', 'like_new', 'good', 'fair', 'poor') NOT NULL,
    notes TEXT,
    total DECIMAL(12, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ========================================
-- STEP 9: สร้างตาราง Branch Inventory
-- ========================================
CREATE TABLE IF NOT EXISTS branch_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    low_stock_threshold INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_branch_product (branch_id, product_id),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ========================================
-- STEP 10: เพิ่ม branch_id ในตาราง inventory_transactions
-- ========================================
ALTER TABLE inventory_transactions ADD COLUMN branch_id INT AFTER user_id;
ALTER TABLE inventory_transactions ADD CONSTRAINT fk_inv_trans_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE;

-- ========================================
-- STEP 11: สร้างตาราง Seller Payments (จ่ายเงินให้ seller)
-- ========================================
CREATE TABLE IF NOT EXISTS seller_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'cheque', 'other') NOT NULL,
    reference_no VARCHAR(50),
    notes TEXT,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ========================================
-- STEP 12: เพิ่ม branch_id ในตาราง activity_log
-- ========================================
ALTER TABLE activity_log ADD COLUMN branch_id INT AFTER user_id;
ALTER TABLE activity_log ADD CONSTRAINT fk_activity_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

-- ========================================
-- STEP 13: INSERT ข้อมูล Branch เริ่มต้น
-- ========================================
INSERT INTO branches (code, name, address, phone, email, status) VALUES
('BR001', 'สาขาหลัก', '123 ถนนหลัก กรุงเทพ', '0299887766', 'main@secondhand.com', 'active'),
('BR002', 'สาขาลาดพร้าว', '456 ถนนลาดพร้าว กรุงเทพ', '0299887767', 'ladprao@secondhand.com', 'active'),
('BR003', 'สาขาเชียงใหม่', '789 ถนนสันปลายน้ำ เชียงใหม่', '0299887768', 'chiangmai@secondhand.com', 'active'),
('BR004', 'สาขาภูเก็ต', '101 ถนนราษฎร ภูเก็ต', '0299887769', 'phuket@secondhand.com', 'active');

-- ========================================
-- STEP 14: อัปเดต categories สำหรับสินค้ามือสอง
-- ========================================
INSERT INTO categories (name, description) VALUES
('Electronics (Used)', 'อุปกรณ์อิเล็กทรอนิกส์มือสอง'),
('Furniture (Used)', 'เฟอร์นิเจอร์มือสอง'),
('Clothing (Used)', 'เสื้อผ้ามือสอง'),
('Books (Used)', 'หนังสือมือสอง'),
('Appliances (Used)', 'เครื่องใช้ไฟฟ้ามือสอง');

-- ========================================
-- STEP 15: สร้าง INDEX สำหรับ Performance
-- ========================================
CREATE INDEX idx_products_branch ON products(branch_id);
CREATE INDEX idx_products_seller ON products(seller_id);
CREATE INDEX idx_sales_branch ON sales(branch_id);
CREATE INDEX idx_sales_seller ON sales(seller_id);
CREATE INDEX idx_purchases_branch ON purchases(branch_id);
CREATE INDEX idx_branch_inventory_branch ON branch_inventory(branch_id);
CREATE INDEX idx_branch_inventory_product ON branch_inventory(product_id);
CREATE INDEX idx_purchase_orders_seller ON purchase_orders(seller_id);
CREATE INDEX idx_purchase_orders_branch ON purchase_orders(branch_id);
CREATE INDEX idx_sellers_user ON sellers(user_id);
CREATE INDEX idx_users_branch ON users(branch_id);
CREATE INDEX idx_users_seller ON users(seller_id);
```

---

## 4. ER Diagram (Text Format)

```
┌─────────────────┐
│    branches     │
├─────────────────┤
│ id (PK)         │
│ code            │
│ name            │
│ address         │
│ phone           │
│ email           │
│ manager_id (FK) │
│ status          │
└────────┬────────┘
         │
    ┌────┴─────────────────────────────────────┐
    │                                           │
    ▼                                           ▼
┌──────────────┐                    ┌──────────────────┐
│    users     │                    │   sellers        │
├──────────────┤                    ├──────────────────┤
│ id (PK)      │◄───────┐          │ id (PK)          │
│ username     │        │          │ user_id (FK)     │
│ password     │        │          │ name             │
│ full_name    │        │          │ phone            │
│ email        │        │          │ email            │
│ role         │        │          │ address          │
│ branch_id(FK)│        │          │ bank_account     │
│ seller_id(FK)├────────┘          │ id_card_number   │
└──────────────┘                    │ status           │
    │                               │ total_sold       │
    │                               │ total_earnings   │
    │                               └────────┬─────────┘
    │                                        │
    │                    ┌───────────────────┼───────────────────┐
    │                    │                   │                   │
    │                    ▼                   ▼                   ▼
    │            ┌──────────────────┐  ┌──────────────┐  ┌──────────────────┐
    │            │ purchase_orders  │  │    sales     │  │ seller_payments  │
    │            ├──────────────────┤  ├──────────────┤  ├──────────────────┤
    │            │ id (PK)          │  │ id (PK)      │  │ id (PK)          │
    │            │ reference_no     │  │ reference_no │  │ seller_id (FK)   │
    │            │ seller_id (FK)   │  │ customer_id  │  │ amount           │
    │            │ branch_id (FK)   │  │ user_id (FK) │  │ payment_method   │
    │            │ user_id (FK)     │  │ seller_id(FK)│  │ reference_no     │
    │            │ total_amount     │  │ branch_id(FK)│  │ user_id (FK)     │
    │            │ status           │  │ total_amount │  └──────────────────┘
    │            └────────┬─────────┘  │ discount     │
    │                     │            │ tax_amount   │
    │                     │            │ grand_total  │
    │                     │            │ payment_meth │
    │                     │            │ payment_stat │
    │                     │            └──────────────┘
    │                     │                   │
    │                     ▼                   ▼
    │        ┌──────────────────────┐  ┌──────────────┐
    │        │purchase_order_items  │  │  sale_items  │
    │        ├──────────────────────┤  ├──────────────┤
    │        │ id (PK)              │  │ id (PK)      │
    │        │ purchase_order_id(FK)│  │ sale_id (FK) │
    │        │ product_id (FK)      │  │ product_id   │
    │        │ quantity             │  │ quantity     │
    │        │ unit_price           │  │ unit_price   │
    │        │ condition            │  │ discount     │
    │        │ notes                │  │ total        │
    │        │ total                │  └──────────────┘
    │        └──────────────────────┘
    │
    ▼
┌──────────────────────┐
│     products         │
├──────────────────────┤
│ id (PK)              │
│ sku                  │
│ barcode              │
│ name                 │
│ description          │
│ category_id (FK)     │
│ price                │
│ cost                 │
│ quantity             │
│ condition (NEW)      │
│ branch_id (FK)       │
│ seller_id (FK)       │
│ low_stock_threshold  │
│ status               │
└──────────────────────┘
         │
         ▼
┌──────────────────────┐
│  branch_inventory    │
├──────────────────────┤
│ id (PK)              │
│ branch_id (FK)       │
│ product_id (FK)      │
│ quantity             │
│ low_stock_threshold  │
└──────────────────────┘
```

---

## 5. Key Changes Summary

### ตารางใหม่
1. **branches** - จัดการ 4 สาขา
2. **sellers** - ข้อมูล seller สินค้ามือสอง
3. **purchase_orders** - ใบสั่งซื้อสินค้ามือสอง
4. **purchase_order_items** - รายการในใบสั่งซื้อ
5. **branch_inventory** - inventory แยกตามสาขา
6. **seller_payments** - จ่ายเงินให้ seller

### ตารางที่แก้ไข
- **users**: เพิ่ม `branch_id`, `seller_id`
- **products**: เพิ่ม `condition`, `branch_id`, `seller_id`
- **sales**: เพิ่ม `seller_id`, `branch_id`
- **purchases**: เพิ่ม `branch_id`
- **inventory_transactions**: เพิ่ม `branch_id`
- **activity_log**: เพิ่ม `branch_id`

### Condition Grading (สินค้ามือสอง)
```
- new        : สินค้าใหม่ยังไม่ใช้
- like_new   : ใช้แล้วแต่สภาพเหมือนใหม่
- good       : ใช้แล้วแต่สภาพดี
- fair       : ใช้แล้วมีรอยใช้งาน
- poor       : ใช้แล้วมีรอยชำรุด
```

---

## 6. Implementation Checklist

### Phase 1: Database Migration (ทำก่อน)
- [ ] Backup database เดิม
- [ ] รัน SQL migration script ทั้งหมด
- [ ] ตรวจสอบ foreign keys และ constraints
- [ ] ทดสอบ data integrity

### Phase 2: Application Updates
- [ ] อัปเดต ORM models (branches, sellers, purchase_orders)
- [ ] เพิ่ม branch selector ในหน้า dashboard
- [ ] เพิ่ม seller management page
- [ ] อัปเดต product form เพิ่ม condition field
- [ ] เพิ่ม purchase order workflow

### Phase 3: Testing
- [ ] ทดสอบ multi-branch inventory
- [ ] ทดสอบ seller payment flow
- [ ] ทดสอบ condition grading
- [ ] ทดสอบ reports แยกตามสาขา

### Phase 4: Deployment
- [ ] Deploy database changes
- [ ] Deploy application updates
- [ ] ทำการ training ให้ staff
- [ ] Monitor logs

---

## 7. Query Examples (สำหรับ Reference)

### ดูสินค้าในสาขาหนึ่ง
```sql
SELECT p.*, b.name as branch_name, s.name as seller_name
FROM products p
LEFT JOIN branches b ON p.branch_id = b.id
LEFT JOIN sellers s ON p.seller_id = s.id
WHERE p.branch_id = 1 AND p.status = 'active';
```

### ดูยอดขายตามสาขา
```sql
SELECT b.name as branch, SUM(s.grand_total) as total_sales, COUNT(s.id) as transactions
FROM sales s
JOIN branches b ON s.branch_id = b.id
WHERE DATE(s.created_at) = CURDATE()
GROUP BY s.branch_id;
```

### ดูสินค้ามือสองตามสภาพ
```sql
SELECT p.name, p.condition, COUNT(*) as qty, SUM(p.price * bi.quantity) as total_value
FROM products p
JOIN branch_inventory bi ON p.id = bi.product_id
WHERE p.condition IN ('good', 'fair', 'poor')
GROUP BY p.id, p.condition;
```

### ดูยอดขายของ seller
```sql
SELECT s.name, COUNT(so.id) as total_sales, SUM(so.grand_total) as total_amount
FROM sellers s
LEFT JOIN sales so ON s.id = so.seller_id
GROUP BY s.id
ORDER BY total_amount DESC;
```

---

## 8. Notes & Recommendations

### ⚠️ สิ่งที่ต้องระวัง
1. **Data Migration**: ต้องจัดการข้อมูล products เดิมให้ได้ branch_id ที่ถูกต้อง
2. **Inventory Sync**: ต้องซิงค์ข้อมูล quantity ระหว่าง `products` และ `branch_inventory`
3. **Seller Verification**: ต้องมี process ตรวจสอบ seller ก่อนอนุมัติ
4. **Condition Grading**: ต้องมี SOP ชัดเจนสำหรับการให้คะแนนสภาพสินค้า

### 💡 Best Practices
1. ใช้ transaction เมื่อ update inventory หลายตาราง
2. เพิ่ม audit trail สำหรับ condition changes
3. ทำ backup ก่อน migrate
4. ทดสอบ migration script ใน dev environment ก่อน

### 🔄 Future Enhancements
- [ ] Condition history tracking (เมื่อไหร่เปลี่ยนสภาพ)
- [ ] Seller rating system
- [ ] Automated seller payment scheduling
- [ ] Branch transfer workflow
- [ ] Condition photo documentation

---

**Created**: 2026-05-17
**Status**: Ready for Implementation
