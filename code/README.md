# Secondhand POS — Code Project
**ระบบจัดการร้านรับซื้อของเก่า 4 สาขา**

---

## โครงสร้างโปรเจกต์

```
code/
├── base-pos/                       # ฐาน goragodwiriya/pos-system (ห้ามแก้ไขโดยตรง)
│   ├── api/                        # Backend PHP
│   ├── admin/                      # Admin pages
│   ├── pos/                        # POS terminal
│   ├── assets/                     # CSS, JS
│   └── database/pos_system.sql     # Base schema
│
├── customizations/                 # ส่วนที่เราพัฒนาเพิ่มสำหรับร้านของเก่า
│   ├── database/
│   │   ├── migrations/             # SQL migrations (เรียงตามลำดับ)
│   │   │   ├── 001_add_branches.sql
│   │   │   ├── 002_add_sellers.sql
│   │   │   ├── 003_add_item_conditions.sql
│   │   │   ├── 004_add_purchase_orders.sql
│   │   │   └── 005_seed_categories.sql
│   │   └── run-migrations.sh       # สคริปต์รัน migration
│   ├── api/
│   │   ├── Models/                 # Branch.php, Seller.php, PurchaseOrder.php
│   │   └── Controllers/            # BranchesController, SellersController, ...
│   ├── admin/                      # หน้า admin ใหม่ (branches, sellers, purchase-orders)
│   └── assets/images/              # ที่เก็บรูปสินค้าที่รับซื้อ
│
└── docs/                           # เอกสารทาง technical
```

---

## ขั้นตอนการ Setup Dev Environment

### 1. ติดตั้ง dependencies

**Ubuntu/Debian:**
```bash
sudo apt update
sudo apt install -y apache2 php php-mysql php-mbstring php-json php-curl mysql-server
```

**เริ่ม services:**
```bash
sudo systemctl start apache2 mysql
sudo systemctl enable apache2 mysql
```

### 2. สร้าง database และรัน migration

```bash
cd /home/drsolodev/projects/secondhand-pos/code/customizations/database
./run-migrations.sh root yourpassword
```

### 3. ตั้งค่า base-pos config

แก้ไข `base-pos/api/config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'pos_system');
define('DB_USER', 'root');
define('DB_PASS', 'yourpassword');
```

### 4. Symlink เข้า web root

```bash
sudo ln -s /home/drsolodev/projects/secondhand-pos/code/base-pos /var/www/html/pos
sudo chown -R www-data:www-data /home/drsolodev/projects/secondhand-pos/code
```

### 5. เปิดทดสอบ

- Admin: http://localhost/pos/admin/
- POS: http://localhost/pos/pos/
- Default login: ดูใน `base-pos/database/pos_system.sql` (ส่วน INSERT INTO users)

---

## Migration Strategy

**หลักการ: ไม่แก้ไข base-pos โดยตรง**

- Schema เพิ่มเติม → `customizations/database/migrations/`
- Models ใหม่ → `customizations/api/Models/`
- Controllers ใหม่ → `customizations/api/Controllers/`
- Pages ใหม่ → `customizations/admin/`

**เมื่อต้องแก้ไฟล์ใน base-pos** (เช่น Router.php) — ทำ patch + comment บอกว่าทำไม

---

## Tables ที่เพิ่มเข้ามา

| ตาราง | หน้าที่ |
|---|---|
| `branches` | สาขา 4 สาขา |
| `sellers` | คนเอาของมาขาย (เก็บบัตรประชาชน) |
| `item_conditions` | สภาพสินค้า (ดี/พอใช้/ชำรุด) |
| `purchase_orders` | ใบรับซื้อ |
| `purchase_order_items` | รายการของในใบรับซื้อ |
| `purchase_order_photos` | รูปถ่ายของ |

**Tables เดิมที่ถูก ALTER:**
- `users` — เพิ่ม `branch_id`
- `products` — เพิ่ม `branch_id`, `condition_id`
- `sales` — เพิ่ม `branch_id`
- `inventory_transactions` — เพิ่ม `branch_id`

---

## Roadmap (ตาม 06-implementation-roadmap.md)

| Phase | Week | งาน | Status |
|---|---|---|---|
| 1 | 1-2 | DB schema + Branch model | 🟡 In Progress |
| 2 | 2-3 | Seller + PurchaseOrder system | ⏳ Pending |
| 3 | 3-4 | Frontend + Reports | ⏳ Pending |
| 4 | 4 | Testing + Deployment | ⏳ Pending |

---

## License

ฐาน goragodwiriya/pos-system อยู่ภายใต้ license ของผู้พัฒนาเดิม
ส่วน customizations เป็นของ Dr.SoloDev และลูกค้า (ตามสัญญา CT-2569-001)
