# แผนปฏิบัติการด้านเทคนิค — scrap-pos
**จัดทำโดย:** CTO, SoloCorp OS | **วันที่:** 6 กรกฎาคม 2569

---

## 1. IMMEDIATE FIXES — วันนี้ รวม < 2 ชั่วโมง

### 1.1 G3 Categories SQL Activation [15 นาที] — CRITICAL

```bash
docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < code/docker/entrypoint/47-046_enforce_precious_receipt_all_risk_categories.sql

# ตรวจผล
docker exec -it scrap-pos-db mysql -u posuser -ppospass pos_system \
  -e "SELECT id, name, requires_precious_receipt FROM categories ORDER BY requires_precious_receipt DESC;"
```

### 1.2 ลบ default admin/admin password [10 นาที]

```bash
# Generate bcrypt hash
docker exec scrap-pos-web php -r "echo password_hash('YOUR_NEW_PASSWORD', PASSWORD_BCRYPT);"

# อัพเดท
docker exec -it scrap-pos-db mysql -u posuser -ppospass pos_system -e "
UPDATE users SET password = '\$2y\$10\$...' WHERE username = 'admin';
"
```

### 1.3 CSS Critical Fixes [45 นาที]

| # | ไฟล์ | การแก้ไข | เวลา |
|---|---|---|---|
| 1 | `tokens.css` | `--color-warning: #D97706` → `#F59E0B` | 2 นาที |
| 2 | `tables.css` | `font-size: 13px` → `14px` | 5 นาที |
| 3 | `buttons.css` | `min-height: 44px` universal | 5 นาที |
| 4 | `buttons.css` | เพิ่ม `.btn:focus-visible { outline: 2px solid var(--color-primary); outline-offset: 2px; }` | 5 นาที |
| 5 | `purchase-orders.css` | `.po-grid { grid-template-columns: 3fr 2fr; }` | 5 นาที |

---

## 2. SHORT-TERM — สัปดาห์นี้

### 2.1 G5 Transport Cost [4–6 ชั่วโมง]

```sql
-- Migration ใหม่:
ALTER TABLE sale_lots
  ADD COLUMN transport_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER sale_price,
  ADD COLUMN net_profit DECIMAL(10,2) GENERATED ALWAYS AS
    (sale_price - purchase_cost - transport_cost) STORED;
```

Frontend: เพิ่ม field ใน sale-lots form + อัปเดต reports ให้แสดง `net_profit`

**Warning:** ข้อมูล lot เก่าจะมี `transport_cost = 0` — แจ้ง user ว่าตัวเลขย้อนหลังอาจ approximate

### 2.2 HTTPS Setup [2–3 ชั่วโมง]

```yaml
# Option A (แนะนำ): Cloudflare Tunnel — deploy ได้ใน 30 นาที ไม่ต้องแตะ Nginx
# Option B: Let's Encrypt + Certbot
```

### 2.3 Excel/CSV Migration Tool [6–8 ชั่วโมง]

Deliverable: `/admin/import-sellers.php`
- รองรับ CSV columns: ชื่อ, เบอร์โทร, เลขบัตรประชาชน, ที่อยู่, สาขา
- Validation: duplicate ID card, format เบอร์โทร
- Preview mode ก่อน import จริง
- Download error report เป็น CSV

### 2.4 Apply Pending DB Migrations [1–2 ชั่วโมง]

```bash
docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < code/customizations/database/migrations/043_add_blacklisted_by_to_sellers.sql

docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < code/customizations/database/migrations/044_fix_po_photos_fk_restrict.sql

docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < code/customizations/database/migrations/045_add_pdpa_consent_to_sellers.sql
```

---

## 3. MEDIUM-TERM — เดือนนี้

### 3.1 G2 Signature Pad [3–5 วัน]

ตัดสินใจก่อนเขียน code:

| Option | ราคา | Complexity | แนะนำ |
|---|---|---|---|
| Canvas + mouse (browser) | ฟรี | ต่ำ | MVP ถ้าทนายยืนยันว่าใช้ได้ |
| Wacom USB pad | ~3,000 THB/สาขา | กลาง | ถ้า legal ต้องการ |
| Topaz signature hardware | ~8,000 THB | สูง | ถ้าต้องการ legal-grade |

### 3.2 G4 Employee Module [1 สัปดาห์ spec + 2 สัปดาห์ build]

ต้องเริ่ม spec ก่อน เพราะ employee data link กับ: Audit log, Commission, Branch assignment

### 3.3 Test Coverage [เริ่มสัปดาห์ 2]

Priority:
1. PHP Unit Tests — AuthController, profit calculation, G3 detection
2. Integration Tests — PO create flow, sale lot flow
3. E2E Tests — G1 photo capture บน tablet

---

## 4. TECHNICAL DEBT

### 4.1 HTML Sidebar Duplication [3–4 ชั่วโมง]

```php
// สร้าง: /admin/partials/sidebar.php
// ทุกไฟล์แทนด้วย: <?php include 'partials/sidebar.php'; ?>
```

### 4.2 CSS Token Leaks

```bash
# Audit:
grep -rn "#[0-9A-Fa-f]\{6\}" code/base-pos/assets/css/
```

### 4.3 Add API Versioning [30 นาที ใน Router.php]

เพิ่ม `/api/v1/` prefix ตอนนี้ก่อนมี external consumer

---

## 5. FUTURE ARCHITECTURE RISKS (10+ สาขา)

| Risk | ปัญหา | แนวทาง |
|---|---|---|
| Single DB ไม่มี multi-tenant | query ผิด = ข้อมูลข้ามสาขารั่ว | Row-Level Security policy ใน MySQL |
| Vanilla PHP — maintenance cliff | onboarding dev ใหม่ยาก | สร้าง BaseController + enforce convention |
| No queue system | Report timeout บน 5+ สาขา | Redis Queue หรือ MySQL queue table |
| No centralized logging | trace ปัญหา production ไม่ได้ | JSON structured logging → Grafana Loki |
| Stock transfer ไม่มี distributed consistency | inventory หายระหว่าง transfer | status tracking: pending/in_transit/received/failed |

---

## Priority Matrix

| Task | Impact | Effort | ทำเมื่อ |
|---|---|---|---|
| G3 SQL Activate | ป้องกันคุก | 15 นาที | ตอนนี้ |
| ลบ admin/admin | ป้องกัน breach | 10 นาที | ตอนนี้ |
| CSS 5 fixes | UX + accessibility | 45 นาที | วันนี้ |
| G5 Transport cost | กำไรถูกต้อง | 6 ชั่วโมง | สัปดาห์นี้ |
| HTTPS | Security audit ผ่าน | 3 ชั่วโมง | สัปดาห์นี้ |
| CSV Import tool | Ops onboarding | 8 ชั่วโมง | สัปดาห์นี้ |
| Sidebar include | Maintenance | 3 ชั่วโมง | หลัง launch |
| G2 Signature | Workflow ครบ | 3–5 วัน | เดือนนี้ |
| G4 Employee | Long-term | 3 สัปดาห์ | เดือนหน้า |
| Queue system | Scale 5+ สาขา | 1 สัปดาห์ | Q4 |
