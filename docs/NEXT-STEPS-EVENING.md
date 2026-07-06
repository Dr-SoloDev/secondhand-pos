# Next Steps — ทำตอนเย็น
> ~~สร้างโดย Orchestrator พี่วุฒิ | 2026-07-06~~
> **⚠️ เอกสารนี้ถูก supersede แล้ว** — ดูเอกสารที่อัพเดทแทน:
> - **แผน timeline เต็ม:** [`docs/MASTER-ACTION-TIMELINE.md`](MASTER-ACTION-TIMELINE.md)
> - **Exec Briefing:** [`docs/EXEC-BRIEFING.md`](EXEC-BRIEFING.md)
> - **CEO Directive + blockers:** [`docs/CEO-DIRECTIVE.md`](CEO-DIRECTIVE.md)
>
> **Exec Council Review (6 ก.ค.)** เปลี่ยนคะแนนจาก **8.5/10** เป็น **4.8/10** หลังพบ compliance gaps ใหม่
> - G3 SQL ยังไม่ activate ทุกหมวด (migration `47-046` ยังไม่รัน)
> - HTTPS ยังไม่ตั้งค่า
> - admin/admin ยังอยู่

---

> เนื้อหาเดิม (archived — ใช้อ้างอิงเท่านั้น)

---

## สิ่งที่ทำเสร็จแล้ว (รอบบ่าย)
- ✅ Migration 043 — `blacklisted_by` column
- ✅ Migration 044 — FK RESTRICT (ป้องกันลบรูปหลักฐาน)
- ✅ Migration 045 — `pdpa_consented_at` column
- ✅ PDPA consent checkbox ใน seller registration
- ✅ Enforcement ม.357 ใน `createPurchaseOrder()` (422 ถ้าไม่มีบัตร)
- ✅ Caddyfile HTTPS config พร้อมแล้ว
- ✅ คู่มือผู้ใช้ภาษาไทย (`USER-MANUAL-CASHIER-TH.md`)
- ✅ PHPUnit config + test scaffolds

---

## งานที่เหลือ — ทำตอนเย็น

### 🔴 CRITICAL — ต้องทำก่อน deploy

---

#### STEP 1: รัน Legal Corrective SQL (15 นาที)
**ทำไม:** category `โลหะ`, `เครื่องใช้ไฟฟ้า`, `แบตเตอรี่` ยังไม่มี `requires_precious_receipt = 1`
ทั้งที่กฎหมาย ม.357 ครอบคลุมถึง

**วิธีทำ:**
```bash
# เข้า DB container
docker exec -it scrap-pos-db mysql -u posuser -ppospass pos_system

# รัน SQL นี้
UPDATE categories
SET requires_precious_receipt = 1
WHERE name IN ('โลหะ', 'เครื่องใช้ไฟฟ้า', 'แบตเตอรี่', 'อะลูมิเนียม', 'ของเก่าโบราณ')
   OR name LIKE '%อลูมิเนียม%'
   OR name LIKE '%ทองเหลือง%'
   OR name LIKE '%สายไฟ%'
   OR name LIKE '%ชิ้นส่วนรถ%';

# ตรวจผล
SELECT id, name, requires_precious_receipt FROM categories ORDER BY requires_precious_receipt DESC;
```

**ไฟล์อ้างอิง:** `docs/LEGAL-357-SIGNOFF.md`

---

#### STEP 2: Apply Migrations 043–045 (10 นาที)
**ทำไม:** migrations ใหม่ยังไม่ได้รันบน DB จริง

**วิธีทำ (ถ้า dev DB กำลังรันอยู่):**
```bash
cd /home/drsolodev/projects/scrap-pos/code

# Option A: รัน migration script โดยตรง
docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < customizations/database/migrations/043_add_blacklisted_by_to_sellers.sql

docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < customizations/database/migrations/044_fix_po_photos_fk_restrict.sql

docker exec -i scrap-pos-db mysql -u posuser -ppospass pos_system \
  < customizations/database/migrations/045_add_pdpa_consent_to_sellers.sql

# ตรวจ
docker exec -it scrap-pos-db mysql -u posuser -ppospass pos_system \
  -e "DESCRIBE sellers;" | grep -E "blacklisted_by|pdpa"
```

**หรือ Option B:** `docker compose down -v && docker compose up -d` (fresh deploy — migrations รันอัตโนมัติ)

---

#### STEP 3: เปลี่ยน Default Admin Password (5 นาที)
**ทำไม:** `admin / admin` ยังอยู่ใน `00-base-schema.sql:157` — ถ้า deploy แล้วลืมเปลี่ยน = ช่องโหว่ใหญ่มาก

**วิธีทำ:**
```bash
# เข้าระบบด้วย admin/admin แล้วไปที่ Settings > Change Password
# หรือ รันตรงๆ ใน DB:

docker exec -it scrap-pos-db mysql -u posuser -ppospass pos_system -e "
UPDATE users
SET password = '\$2y\$10\$[NEW_BCRYPT_HASH]'
WHERE username = 'admin';
"

# Generate bcrypt hash ก่อน:
docker exec scrap-pos-web php -r "echo password_hash('YOUR_NEW_PASSWORD', PASSWORD_BCRYPT);"
```

**ไฟล์อ้างอิง:** `docs/DEPLOY-CHECKLIST.md`

---

### 🟡 HIGH — ทำให้ครบก่อนเปิดใช้จริง

---

#### STEP 4: ตั้งค่า HTTPS (20 นาที)
**ทำไม:** ระบบยัง HTTP — ข้อมูลบัตรประชาชน/JWT วิ่งแบบ plain text

**ต้องการก่อน:**
- [ ] Domain ชี้ A-record มาที่ IP server แล้ว
- [ ] เปิด port 80 และ 443 บน server/firewall

**วิธีทำ:**
```bash
# 1. แก้ .env
echo "DOMAIN=pos.yourdomain.com" >> code/.env
echo "ACME_EMAIL=admin@yourdomain.com" >> code/.env

# 2. Restart
cd code && docker compose down && docker compose up -d

# 3. ตรวจ
curl -I https://pos.yourdomain.com/api/index.php
# Expected: HTTP/2 401
```

**ไฟล์อ้างอิง:** `docs/HTTPS-SETUP.md`

---

#### STEP 5: E2E Test บน Tablet จริง (1 ชั่วโมง)
**ทำไม:** Chrome DevTools emulate ≠ tablet จริงที่ counter

**Checklist ทดสอบ:**
```
[ ] เปิดระบบบน tablet จริง (Chrome)
[ ] Login ด้วย cashier account
[ ] สร้าง PO ที่มีสินค้าเสี่ยง (ทองแดง/สายไฟ)
    → ต้องเห็น warning สีส้มใน seller section
    → กด "บันทึก" โดยไม่ใส่บัตร → ต้องเห็น error 422
    → ใส่บัตรแล้ว → บันทึกสำเร็จ
[ ] ถ่ายรูปสินค้าจาก add-row (ก่อนกด +เพิ่ม)
    → thumbnail ต้องเห็นใน add-row ทันที
[ ] Seller registration form
    → PDPA checkbox ต้องเห็นและบังคับติ๊ก
[ ] ทดสอบ TAB navigation บน keyboard/tablet keyboard
[ ] ตรวจว่า layout ไม่พังที่ 768px
```

---

### 🟢 NICE TO HAVE — ถ้ามีเวลาเพิ่ม

---

#### STEP 6: เขียน PHPUnit Tests จริง (3-4 ชั่วโมง)
scaffold ไว้แล้วที่ `code/tests/` — ยังเป็น TODO stubs

ไฟล์ที่ต้องเขียน:
- `tests/Unit/FifoCalculationTest.php` — ซื้อ 3 lot ราคาต่างกัน → ขาย → ตรวจ FIFO cost
- `tests/Unit/NetProfitCalculationTest.php` — net_profit = amount - cost - transport
- `tests/Integration/PreciousReceiptEnforcementTest.php` — POST PO โลหะมีค่า ไม่มีบัตร → 422

---

## สรุปลำดับเย็นนี้

| ลำดับ | งาน | เวลา | ความสำคัญ |
|-------|-----|------|-----------|
| 1 | Legal SQL (categories flag) | 15 นาที | 🔴 CRITICAL |
| 2 | Apply migrations 043-045 | 10 นาที | 🔴 CRITICAL |
| 3 | เปลี่ยน admin password | 5 นาที | 🔴 CRITICAL |
| 4 | HTTPS setup (ถ้า domain พร้อม) | 20 นาที | 🟡 HIGH |
| 5 | E2E test tablet จริง | 60 นาที | 🟡 HIGH |
| 6 | PHPUnit tests | 3-4 ชั่วโมง | 🟢 NICE |

**รวม Critical + High: ~1.5 ชั่วโมง**
**ถ้าทำครบทั้งหมด: คะแนน 9.0/10 ✅**

---

> *อ้างอิงเพิ่มเติม:*
> - `docs/LEGAL-357-SIGNOFF.md` — sign-off เอกสารกฎหมาย
> - `docs/DEPLOY-CHECKLIST.md` — checklist ก่อน deploy
> - `docs/HTTPS-SETUP.md` — ขั้นตอน HTTPS
> - `docs/USER-MANUAL-CASHIER-TH.md` — คู่มือแคชเชียร์
