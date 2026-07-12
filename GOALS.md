# GOALS — Secondhand POS
**ตกผลึกจาก session: 2026-06-08 | อัปเดตล่าสุด: 2026-07-12**
**ทำจนกว่าจะครบ — ทำงานได้จริง flow ราบรื่น**

---

## G1 — ถ่ายรูปสินค้า + ผู้ขาย
- upload รูปได้หลายรูปต่อ PO (รูปกองสินค้า)
- upload รูปบัตรประชาชน หรือ selfie ต่อ seller (optional ไม่บังคับ)
- timestamp อัตโนมัติ ปลอมไม่ได้
- ดูย้อนหลังได้จากหน้า PO detail และ seller profile
- **pending:** เจ้าของเลือก storage — NAS / Backblaze B2 / Hybrid

## G2 — ใบรับซื้อพิมพ์ได้ 2 แบบ (A4 แบ่งครึ่ง = 2 สำเนา)

### แบบที่ 1 — บิลปกติ
- ชื่อร้าน + สาขา + วันที่ + เวลา + เลขที่บิล
- ชื่อแคชเชียร์ (ดึงจาก login อัตโนมัติ)
- ราคาบิลวันนี้: บิล1 / บิล2 / บิล3
- รหัสสินค้า | ชื่อสินค้า | น้ำหนัก (กก.) | จำนวนเงิน
- ยอดรวม
- ข้อความท้ายบิล: "ร้านปิดวันพฤหัส | 084-8233782 | บริการดี ราคาดี ตาชั่งดิจิตอลมาตรฐานกระทรวง"

### แบบที่ 2 — บิลโลหะมีค่า (auto-detect เมื่อมีหมวด "ทองแดง")
- ข้อมูลทุกอย่างเหมือนแบบที่ 1
- เพิ่มส่วนลงนาม: "ข้าพเจ้าได้นำสินค้าที่ระบุในบิลนี้มาโดยสุจริตจริง"
- ช่องลายมือชื่อผู้ขาย + เวลา + เลขบิล
- checkbox หลักฐานที่แนบ: สำเนาบัตรประชาชน / ใบขับขี่ / เอกสารราชการ
- ข้อความ: "ยินยอมให้ถ่ายรูปสำเนาบัตรประชาชนไว้เป็นหลักฐาน"
- ข้อความ: "ทางร้านไม่รับซื้อของที่มีการลักทรัพย์โดยเด็ดขาด ทางร้านไม่รับผิดชอบต่อสินค้าที่เกิดจากการกระทำผิดกฎหมายทางอาญาทุกกรณี"
- ครึ่งล่าง: พื้นที่แนบ/พิมพ์รูปบัตรประชาชน

## G3 — Blacklist Alert
- เพิ่ม field: `blacklist_reason VARCHAR(255)` + `blacklisted_at DATETIME`
- ค้นหาเจอ blacklist → popup แจ้งเตือนสีแดง แสดงชื่อ + เหตุผล + วันที่
- ไม่ block — ร้านตัดสินใจเอง ปุ่ม "ดำเนินการต่อ" หรือ "ยกเลิก"

## G4 — ค้นหาผู้ขาย Real-time
- พิมพ์ตัวแรก → ค้นหาทันที (debounce 300ms)
- ค้นจาก: เลขบัตรประชาชน + ชื่อ
- dropdown แสดง ชื่อ + เลขบัตร + ⚠️ badge ถ้า blacklist
- เลือกแล้ว → ดึงข้อมูลเติมทุกช่องอัตโนมัติ
- ถ้าเลือก blacklist → trigger G3 popup ทันที
- ถ้าไม่เจอ → กรอกใหม่เป็น seller ใหม่

## G5 — Dashboard 4 สาขา
- mode 1: รวมทุกสาขา
- mode 2: เลือกสาขาเดียว
- mode 3: side-by-side 4 สาขา — เห็นพร้อมกัน highlight สาขาสูงสุด
- ข้อมูลต่อสาขา: ยอดรับซื้อวันนี้ / จำนวน PO / ยอดเดือนนี้
- responsive — ใช้ได้บนมือถือ tablet PC
- remote access: Cloudflare Tunnel (คุยกับเจ้าของ)

## G6 — บอร์ดราคาพิมพ์ได้
- ดึงจาก catalog ที่มีอยู่แล้ว — ไม่มี feature ใหม่ด้านหลัง
- จัดหน้า: ชื่อสินค้า | บิล1 | บิล2 | บิล3
- พิมพ์ติดหน้าร้านได้เลย

## G7 — ประวัติผู้ขายต่อคน
- หน้า seller profile แสดง: ยอดรวมทั้งหมด / จำนวนครั้ง / ครั้งล่าสุด
- ตารางประวัติ: วันที่ | เลขบิล | รายการ | ยอด
- ข้อมูลมีอยู่ใน DB แล้ว — แค่ยังไม่มีหน้าแสดง

## G8 — Export CSV นักบัญชี
- รายงานที่ export ได้: รับซื้อ / ขาย Lot / ค่าใช้จ่าย / สรุปกำไรขาดทุน
- export แบบ 1: รายสาขา
- export แบบ 2: ภาพรวมทุกสาขา (มีคอลัมน์ "สาขา")
- filter: เลือกเดือน + ปี → กด export
- format: CSV เปิด Excel ได้เลย

## G9 — โอนสต็อกระหว่างสาขา
- สร้าง "ใบโอนสต็อก": จาก / ไปยัง / หมวด / น้ำหนัก / ผู้สร้าง
- สาขาปลายทางกด "รับของแล้ว" → confirm
- stock_kg สาขาต้นทาง ลด / สาขาปลายทาง เพิ่ม
- มี audit trail — ตรวจสอบย้อนหลังได้

## G10 — Stock Alert เมื่อหมด
- admin ตั้ง threshold ต่อหมวดได้ (ค่า default = ไม่แจ้งเตือน)
- ตั้งได้ถึง 0 กก. (แจ้งเมื่อหมดจริงๆ)
- แสดง badge แดงใน dashboard + inventory

---

## G11 — Mobile / Tablet Support 🆕 (12 ก.ค. 2569)
- Plan A: **Tablet-Responsive** — touch-friendly 44px min-height, 16px font (iOS zoom prevention), table column priority hiding (priority-2/priority-3), modal fullscreen on <640px, sidebar overlay + hamburger on 769-1024px
- Plan B: **Mobile PO Wizard** — standalone 4-step wizard at `/mobile/purchase.html` (Branch → Seller → Items → Review & Save), catalog search with autocomplete, tier price bottom-sheet, cart management, reuses existing API
- ✅ เสร็จแล้ว — ใช้ได้ทั้ง tablet (layout.css responsive) และมือถือ (mobile/purchase.html)

---

## ❌ ตัดออก
- Line Notify — ดูจาก dashboard มือถือได้เลย

---

## Pending — ต้องคุยกับเจ้าของก่อน implement
- [ ] G1: เลือก storage รูปภาพ (NAS / Backblaze B2 / Hybrid)
- [ ] Cloudflare Tunnel สำหรับ remote access (test ด้วย mobile/purchase.html)

---

## Priority Order
```
Sprint 1 (ก่อน deploy) — ✅ COMPLETE:
  G4 → ค้นหาผู้ขาย real-time ✅
  G3 → Blacklist alert + เหตุผล ✅
  G2 → ใบรับซื้อพิมพ์ได้ 2 แบบ ✅
  G7 → ประวัติผู้ขายต่อคน ✅

Sprint 2 (หลัง deploy สัปดาห์แรก) — ✅ COMPLETE:
  G5 → Dashboard 4 สาขา ✅
  G8 → Export CSV ✅

Sprint 3 (เดือน 2) — ✅ COMPLETE:
  G9 → โอนสต็อก ✅
  G10 → Stock alert ✅

Sprint 4 (Production Polish) — ✅ COMPLETE:
  G11 → Mobile/Tablet Support (Plan A + Plan B) ✅
  Production Audit (54 issues, 90% readiness) ✅
  FIFO Code Review (Architecture 9/10) ✅
  Competitive Analysis (5 คู่แข่ง) ✅

Sprint 5 (v2 — รอหลังนำเสนอลูกค้า):
  G1 → รูปภาพ (หลังเจ้าของเลือก storage)
  Scale Integration (digital weighing scale)
  Offline Mode (PWA)
  State transition business rules
  Deadlock lock order refactor
  FIFO mapping per sale item
  Reconcile audit script
```
