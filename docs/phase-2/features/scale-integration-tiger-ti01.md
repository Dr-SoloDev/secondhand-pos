# Phase 2 — เชื่อมตาชั่งดิจิตอล Tiger TI-01 (RS232) — แผนฉบับ Final

> **สถานะ:** ✅ อนุมัติแล้ว (22 ก.ย. 2569) — พร้อมลงมือทำ
> **เจ้าของ:** Dr.solodev | **ผู้ว่าจ้าง:** ร้านรับซื้อของเก่า (Surin)
> **เฟส 1:** ส่งมอบ Production แล้ว — เฟส 2 ต่อเนื่อง

---

## 1. เป้าหมาย (Goal)

เชื่อมตาชั่งดิจิตอล **Tiger TI-01 (RS232)** เข้าระบบ POS แบบตรง ป้องกันการคีย์น้ำหนักผิด/ฮั้วพนักงาน-ผู้ขาย และสร้างความโปร่งใสให้ลูกค้า — โดยระบบยังต้อง **ทำงานได้ปกติเมื่อไม่มีตาชั่ง** (แท็บเล็ต `pos.mkxmeme.xyz`)

**Success Criteria:**
- น้ำหนักต้นทางมาจากตาชั่งโดยตรงเมื่อต่ออยู่ (ลด human error → 0)
- แท็บเล็ต/สาขาไม่มีตาชั่ง ยังคีย์มือได้ 100% ไม่พัง flow เดิม
- เพิ่มสาขาใหม่ได้ไม่จำกัด ไม่ล็อคจำนวนสาขา

---

## 2. กติกา Anti-Fraud ที่ตกลง

| กติกา | รายละเอียด |
|---|---|
| **น้ำหนัก (กก.) = Auto จากตาชั่ง** | เมื่อเจอตาชั่ง → ช่อง `น้ำหนัก` ล็อค read-only, ค่ามาจากปุ่ม `จับน้ำหนัก` / auto-capture เท่านั้น |
| **หักน้ำหนัก (กก.) = Manual** | ให้พนักงานใช้ดุลพินิจพิมพ์มือได้ปกติ (สิ่งเจือปน/ความชื้น) |
| **คำนวณเหมือนเดิม** | `สุทธิ = น้ำหนักตาชั่ง - หัก` → `รวม = สุทธิ × ราคา/กก.` (`purchase-orders.js:427` / `PurchaseOrder.php:315`) |
| **Fallback อัตโนมัติ** | ไม่เจอตาชั่ง 3 ครั้งติด → ปลดล็อคเป็นคีย์มือทันที (แท็บเล็ตจะอยู่โหมดนี้ตลอด) |
| **Audit** | เก็บ `weight_source` (`scale`/`manual`/`manual_override`) + `scale_raw_kg` + `captured_at` ทุกใบ |

---

## 3. ฮาร์ดแวร์ที่คอนเฟิร์ม

- **Brand/Model:** Tiger TI-01, S/N TI010965797, พอร์ต **RS232**
- **PC สาขา:** Windows 10, ใช้หัวแปลง **USB-RS232** (COM1-COM10 auto-scan)
- **สาขา:** เพิ่มได้ไม่จำกัด (`branches` มีปุ่มเพิ่มอยู่แล้ว) — ตั้งค่าตาชั่ง **แยกรายสาขา**
- **จอลูกค้า:** ยังไม่ต้องทำในเฟส 2

> Parser จะทำแบบ generic `9600 8N1` ต่อเนื่อง + เก็บ `raw` log ไว้จูนหน้างาน — ถ้ามีคู่มือโปรโตคอล Tiger จะแม่นขึ้นอีก

---

## 4. สถาปัตยกรรม (แนะนำ: Local Scale Bridge — reuse pattern Print Server)

```
[Tiger TI-01] --RS232/USB--> [scale-agent:9130 @ Win10] --HTTP localhost--> [purchase-orders.js] --JWT--> [PHP API] --> [MySQL]
                                |-> auto-scan COM, /weight { weight, stable, raw, ts }
```

- **ทำไมต้อง Bridge:** `code/print-server/print_server_http.py:45` พิสูจน์แล้วว่า Docker PHP ↔ Host HTTP เวิร์ค, รองรับทุก Browser, auto-reconnect, ไม่โดน Mixed Content บล็อค (localhost ถือเป็น Secure Context)
- **Fallback:** ถ้า `fetch http://localhost:9130/weight` ไม่ได้ → ถือว่าไม่มีตาชั่ง → คีย์มือปกติ

**โหมดต่อสาขา (`branches.scale_mode`):**
- `disabled` (default) — คีย์มืออย่างเดียว ปลอดภัยสุด
- `auto` — เจอตาชั่ง = ล็อค auto, ไม่เจอ = คีย์มือ (โหมดหลักของเฟส 2)
- `required` — บังคับตาชั่งเท่านั้น (ปิดไว้ก่อน เผื่ออนาคต)

---

## 5. Trigger ดึงน้ำหนัก (ตกลง: Live + Auto-Capture)

**Flow แคชเชียร์:**
1. พิมพ์รหัสสินค้า (`itemName` `purchase-orders.html:106`) → เลือกของ
2. วางของบนตาชั่ง → ตัวเลขสดวิ่งบน widget `12.34 กก. ○รอ` → นิ่ง 1.5 วิ → `●นิ่ง ✓จับแล้ว` (auto-capture)
3. พิมพ์ `หักน้ำหนัก` เอง → `Enter` เพิ่มลงตะกร้า (`purchase-orders.js:136`)

- ยังมีปุ่ม `จับน้ำหนัก` ให้กดมือได้เป็นสำรอง
- ไม่ดึงน้ำหนักตอนคีย์รหัสสินค้า (ตอนนั้นของยังไม่ได้วาง จะได้ 0)

---

## 6. สิ่งที่ต้องทำ (ละเอียดพอลงมือ)

### 6.1 DB Migrations (ใหม่ `076+` — ห้ามแก้ `pos_system.sql`)

- `076_add_scale_mode_to_branches.sql` — `ALTER TABLE branches ADD COLUMN scale_mode ENUM('disabled','auto','required') DEFAULT 'disabled'`
- `077_create_scale_devices.sql` — `scale_devices(id, branch_id FK, code, model VARCHAR(50) DEFAULT 'Tiger TI-01', port VARCHAR(20), baud_rate INT DEFAULT 9600, status ENUM('active','inactive'))`
- `078_create_scale_readings.sql` — `scale_readings(id, device_id, branch_id, raw_value DECIMAL(10,2), stable TINYINT, captured_at DATETIME, po_item_id nullable)` (audit)
- `079_add_scale_fields_to_po_items.sql` — `ALTER TABLE purchase_order_items ADD COLUMN weight_source ENUM('manual','scale','manual_override') DEFAULT 'manual', ADD COLUMN scale_device_id INT NULL, ADD COLUMN scale_raw_kg DECIMAL(10,2) NULL, ADD COLUMN scale_stable TINYINT NULL, ADD COLUMN captured_at DATETIME NULL, ADD COLUMN override_reason VARCHAR(500) NULL`

### 6.2 Backend PHP (`customizations/api/`)

- ใหม่ `Models/ScaleDevice.php`, `Models/ScaleReading.php`
- ใหม่ `Controllers/ScaleController.php` — `GET /scale/devices`, `GET /scale/reading?device_id=`, `GET /scale/health`, `POST /scale/capture` (รับ HMAC จาก bridge คล้าย `Router.php:29` photo)
- แก้ `Controllers/PurchaseOrdersController.php:228-267` — รับ `weight_source/scale_*` ต่อ item, ถ้า `branch.scale_mode='required'` และ `weight_source != 'scale'` → `422`
- แก้ `Models/PurchaseOrder.php:339` — บันทึก `weight_source/scale_*` ลง `purchase_order_items`
- แก้ `Router.php:190` — เพิ่ม 4 routes ข้างบน
- แก้ `Controllers/BranchesController.php` — CRUD `scale_devices` + `scale_mode`

### 6.3 Scale Agent (`code/scale-agent/` ใหม่ — คล้าย `print-server/`)

- `scale_agent.py` (~150 บรรทัด) — `pyserial` อ่าน RS232, parse Tiger TI-01, endpoint `GET /weight → { weight, stable, unit:"kg", raw, device_id, ts }`, `GET /health`, CORS `*`, auto-scan COM1-COM10
- `scale-agent.env.example`, `requirements.txt` (`pyserial`), `install.bat` / `install.sh` (Win10 service)
- Debug: `GET /raw` โชว์ string ดิบจากตาชั่ง ไว้จูนหน้างาน 5 นาที

### 6.4 Frontend

- ใหม่ `assets/js/scale-bridge.js` — poll `http://localhost:9130/weight` ทุก 500ms, 3 ครั้งเจอ = ล็อค, 3 ครั้งไม่เจอ = ปลดล็อค
- แก้ `admin/purchase-orders.html:121` — `po-measure-row` เพิ่ม widget `LIVE 12.34 กก. ●นิ่ง` + badge `🟢 ตาชั่ง` / `⚪ คีย์มือ` + ปุ่ม `จับน้ำหนัก`
- แก้ `assets/js/purchase-orders.js:122-142` — ช่อง `itemQuantity` สลับ read-only ตามสถานะตาชั่ง, `addItemToCart():445` อ่าน `capturedWeight` แทน input, `savePurchaseOrder():773` ส่ง `weight_source/scale_raw_kg/scale_device_id` ต่อ item
- ไม่กระทบ `globalTier`, Tab order, `Enter` เพิ่มรายการ (`WORKFLOW-05`)

### 6.5 สิทธิ์ & รายงาน

- `activity_log` — event `scale_capture`, `scale_manual_override`
- รายงาน — เพิ่มคอลัมน์ `weight_source` ดูสัดส่วน `scale vs manual` ต่อสาขา

---

## 7. Deployment & Rollout

1. **Pilot 1 สาขา PC** — ติดตั้ง `scale-agent` + Tiger TI-01, ตั้ง `scale_mode='auto'` สาขานั้นสาขาเดียว
2. **จูน Parser** — ดู `/raw` หน้างาน ปรับ stable threshold (1.5s)
3. **Rollout สาขาอื่น** — ก๊อป `scale-agent` + ตั้ง `scale_mode='auto'` ทีละสาขา — แท็บเล็ตคง `disabled`
4. **Zero Downtime** — feature flag ต่อสาขา, สาขาไม่พร้อมยังคีย์มือได้

---

## 8. Testing

- **Unit:** `phpunit` — `createWithItems` ปฏิเสธ manual เมื่อ `required`, ผ่านเมื่อ `auto`
- **Integration:** `tests/api/run.sh` เพิ่ม `test_scale` — ยิง `purchase-orders` ด้วย `scale`/`manual`
- **Hardware:** `socat` จำลอง serial, เทส stable/unstable, สายหลุด, 0.00
- **UAT:** แคชเชียร์จริงลอง `ค้นหาผู้ขาย → เลือกสินค้า → วางของ → auto-capture → ใส่หัก → Enter` ด้วยคีย์บอร์ดล้วน

---

## 9. ความเสี่ยง & รับมือ

| ความเสี่ยง | รับมือ |
|---|---|
| Tiger TI-01 โปรโตคอลไม่ตรง | Parser pluggable + โหมด debug `/raw` จูนหน้างานได้ทันที |
| สาย USB-RS232 หลวม/หลุด | โชว์ `●offline` แดง, บล็อคจับ, fallback คีย์มือ + log |
| Mixed Content HTTPS→HTTP | localhost อนุโลมเป็น Secure Context, ถ้าโดนบล็อคให้ bridge เสิร์ฟ HTTPS self-signed |
| สาขาใหม่เพิ่มเรื่อยๆ | `scale_devices` ผูก `branch_id` เพิ่มได้ไม่จำกัดอยู่แล้ว |

---

## 10. Timeline ประมาณ

- **Week 1:** Migrations + Backend + scale-agent (Tiger TI-01)
- **Week 2:** Frontend widget + poll + auto-capture + Pilot 1 สาขา
- **Week 3:** จูนหน้างาน + Rollout สาขา PC ที่เหลือ + UAT

---

## 11. เอกสารอ้างอิง

- `code/base-pos/assets/js/purchase-orders.js:122` — จุดคีย์น้ำหนักเดิม
- `code/customizations/api/Controllers/PurchaseOrdersController.php:228` — จุดรับ PO
- `code/print-server/print_server_http.py:45` — pattern Bridge ที่ reuse
- `code/customizations/database/migrations/` — กฎ migration

---

**อนุมัติโดย:** ผู้ว่าจ้าง + Dr.solodev (22 ก.ย. 2569) — พร้อมเริ่ม Week 1
