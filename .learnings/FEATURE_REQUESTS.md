# Feature Requests

Capabilities requested by the user (Dr.Solodev) or end customer (ร้านรับซื้อของเก่า).

> **Progress Update (2026-05-23):** Demo dashboard complete — cards/chart/table เปลี่ยนเป็น purchase data หมดแล้ว, purchase_orders + sellers + item_conditions tables สร้างพร้อม, PHP backend พร้อม API endpoints, Thai localization + UTF-8 fixes เสร็จ ยังไม่ได้ implement backend CRUD สำหรับ sellers/purchase_orders/item_conditions และฟีเจอร์ลูกค้าด้านล่าง

---

## จากลูกค้า (ร้านรับซื้อของเก่า, สุรินทร์)

### High Priority (จาก scope ที่ตกลง 40,000 บาท)

- [ ] **Multi-branch Dashboard** — เจ้าของดูทุกสาขาจากที่เดียว, real-time
  - Pain point: ต้องโทรถามพนักงานทุกวัน → ข้อมูลผิดบ่อย

- [ ] **Walk-in Seller Registration** — เก็บบัตรประชาชนผู้ขาย
  - Pain point: ของหายบ่อย ไม่มีหลักฐานว่าใครเอามาขาย → เสี่ยงกฎหมาย

- [ ] **Audit Trail / Activity Log** — ตรวจสอบพนักงานทุจริต/ผิดพลาด
  - Pain point: พนักงานทอนเงินผิด บันทึกราคาผิด จับไม่ได้

- [ ] **Item Condition Grading** — ดี/พอใช้/ชำรุด พร้อมตัวคูณราคา

- [ ] **Photo Capture per Item** — ถ่ายรูปของที่รับซื้อทุกชิ้น

- [ ] **Hybrid Online/Offline** — ขายต่อได้แม้ internet หลุด

### Medium Priority (Phase 3+)

- [ ] **Profit per Item Report** — ซื้อ X ขาย Y กำไร Z
- [ ] **Aging Stock Report** — สินค้าค้างนานเกิน N วัน
- [ ] **Top Sellers Report** — ผู้ขายที่เอาของมาบ่อย Top 10
- [ ] **Branch Comparison** — เปรียบเทียบ KPI 4 สาขา

### Low Priority (อาจเป็น future feature)

- [ ] **OCR บัตรประชาชน** — สแกนบัตรอัตโนมัติ ไม่ต้องพิมพ์ (Cloud Vision API)
- [ ] **Barcode label printing** — พิมพ์บาร์โค้ดให้ของที่รับซื้อ
- [ ] **LINE notification** — แจ้งเตือนยอดสรุปวันให้เจ้าของทาง LINE

---

## จาก Dr.Solodev (Workflow improvements)

- [ ] **Auto-translate quotation to PDF** — แปลง .md → PDF สวยๆ ส่งลูกค้า
- [ ] **Contract auto-fill** — ดึงข้อมูลจาก project memory มา fill template

---

## ที่อยู่นอกขอบเขตสัญญานี้ (เก็บไว้คุยรอบหน้า)

- [ ] Mobile app สำหรับเจ้าของดูยอด real-time บนมือถือ
- [ ] AI-suggested pricing — แนะนำราคารับซื้อตามประวัติ
- [ ] Integration กับโรงงานรีไซเคิล (ขายต่อให้)
