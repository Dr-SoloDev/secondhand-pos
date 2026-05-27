# Secondhand POS — Code Project
**ระบบจัดการร้านรับซื้อของเก่า 4 สาขา**

---

## โครงสร้างโปรเจกต์

```
code/
├── base-pos/                       # ฐาน goragodwiriya/pos-system (แก้ไขตรงเมื่อจำเป็น)
│   ├── api/                        # Backend PHP
│   │   ├── Router.php              # ALL routes registered (core + custom)
│   │   ├── config.php              # DB config, JWT secret
│   │   └── autoload.php            # PSR-4 autoloader (base-pos + customizations)
│   ├── admin/                      # Admin pages (PHP) — active delivery target
│   │   ├── index.html              # Dashboard
│   │   ├── purchase-orders.html    # รับซื้อของ
│   │   ├── sale-lots.html          # ขาย Lot
│   │   ├── sellers.html            # ผู้ขาย
│   │   └── ...                     # inventory, sales, reports, settings, users, price-tiers
│   ├── pos/                        # POS terminal
│   └── assets/                     # CSS, JS (common.js, config.js)
│
└── customizations/                 # Custom overlay (mounted via autoloader)
    ├── api/
    │   ├── Models/                 # Branch, Seller, PurchaseOrder, SaleLot, PurchaseItemCatalog
    │   └── Controllers/            # Branches, Sellers, PurchaseOrders, SaleLots, PriceTiers, etc.
    ├── database/
    │   ├── migrations/             # 013 migrations (001-013)
    │   └── run-migrations.sh
    └── frontend-react/             # DEPRECATED — React v2 app, no longer active
```

---

## Key Design Points

### Two-layer Architecture
- **base-pos:** Core system files (goragodwiriya/pos-system) — files here are edited in-place
- **customizations:** Custom PHP code loaded by autoloader — Models, Controllers, migrations
- **Autoloader** (`base-pos/api/autoload.php`) scans both `base-pos/` and `customizations/`

### Delivery Target
- **PHP base-pos** (`code/base-pos/admin/`) = active target (Vanilla PHP + Vanilla JS)
- **React v2** (`code/customizations/frontend-react/`) = DEPRECATED, no longer maintained

### Business Flows
| Flow | Page | Purpose |
|------|------|---------|
| รับซื้อ | `purchase-orders.html` | Buy scrap from individual sellers |
| ขาย Lot | `sale-lots.html` | Sell bulk lots to collection centers |
| ขายปลีก | `pos/index.html` + `sales.html` | Retail sales (minor flow) |

---

## ขั้นตอนการ Setup Dev Environment

### 1. สร้าง database และรัน migration
```bash
cd /home/drsolodev/projects/secondhand-pos/code/customizations/database
./run-migrations.sh root yourpassword
```

### 2. ตั้งค่า base-pos config
```php
// base-pos/api/config.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'pos_system');
define('DB_USER', 'root');
define('DB_PASS', 'yourpassword');
```

### 3. Symlink เข้า web root
```bash
sudo ln -s /home/drsolodev/projects/secondhand-pos/code/base-pos /var/www/html/pos
sudo chown -R www-data:www-data /home/drsolodev/projects/secondhand-pos/code
```

### 4. เปิดทดสอบ
- Admin: http://localhost/pos/admin/
- API: http://localhost/pos/api/index.php/
- Default login: admin / admin

---

## Migration Strategy

- **DO NOT** modify `base-pos/database/pos_system.sql`
- All changes → `customizations/database/migrations/NNN_description.sql`
- Run in numerical order via `run-migrations.sh`

## Tables ที่เพิ่ม (13 migrations)

| Migration | Table(s) | Purpose |
|---|---|---|
| 001 | `branches` | สาขา |
| 002 | `sellers` | ผู้ขาย |
| 003 | `item_conditions` | (deprecated) |
| 004 | `purchase_orders`, `purchase_order_items` | ใบรับซื้อ |
| 005 | — | Seed categories |
| 006 | — | Demo data |
| 007 | `price_tiers` | ราคา 3 ระดับ |
| 008 | ALTER `purchase_order_items` | ADD price_tier |
| 009 | `sale_lots`, `sale_lot_items` | ขาย Lot |
| 010 | ALTER `purchase_order_items` | ADD consumed_qty, fifo_cost |
| 011 | ALTER `sellers` | ADD vehicle_plate |
| 012 | `purchase_item_catalog` | Master catalog |
| 013 | ALTER `purchase_order_items` | REPLACE condition_id → weight_deduction |

---

## Quick Reference

### API Pattern
```javascript
const res = await apiRequest('sale-lots', 'POST', payload);
// res = { status: 'success'|'error', data: {...}, message: '...' }
```

### Route Registration
```php
$this->routes[] = ['route' => 'resource/action', 'controller' => 'FooController', 'method' => 'bar', 'verb' => 'GET'];
```
