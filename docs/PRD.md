# PRD: ระบบรับซื้อของเก่า — รักษ์สะอาดรีไซเคิล

> **Product Requirements Document**
> Version 2.0 | อัพเดท 2026-07-06
> Owner: Dr.solodev | Status: **NO-GO** — 3 blockers ต้องปิดก่อน deploy
>
> **Exec Council Review (6 ก.ค. 2569):** Overall score **4.8/10** → target 9.0/10
> ดูแผนเต็ม: [`docs/MASTER-ACTION-TIMELINE.md`](MASTER-ACTION-TIMELINE.md)

---

## เล่าที่มา (For New Devs / AI Agents)

นี่คือระบบ POS สำหรับร้านรับซื้อของเก่า มี 4 สาขา เจ้าของร้านเคยจ้าง Dev มา 2 เจ้าก่อนหน้านี้แต่ทำงานไม่เสร็จ — รอบนี้คือความหวังสุดท้าย ถ้าทำไม่สำเร็จ ร้านมีสิทธิ์ปิด

---

## 1. Lean UX Canvas

### Box 1 — Business Problem (ปัญหาธุรกิจ)
- **สิ่งที่เปลี่ยนไป:** กฎหมาย ม.357 โทษอาญาฐานรับซื้อของโจร — จำคุกสูงสุด 5 ปี หรือปรับ 100,000 บาท (คดีอาญาแผ่นดิน ยอมความไม่ได้)
- **ปัจจุบัน:** ใช้ Excel บันทึก 4 สาขา — ข้อมูลกระจัดกระจาย ป้องกันทุจริตไม่ได้
- **พนักงานทุจริต:** เคยมีกรณีคีย์ PO ปลอม (รับซื้อที่ไม่มีจริง)
- **บริหาร 4 สาขา:** ต้องวิ่งไปดึง Excel ทีละสาขาแล้วนำมารวมกันเอง
- **ผลกระทบ:** ถ้าไม่มีระบบแก้ปัญหา → **ร้านปิดใน 1 ปี**

### Box 2 — Business Outcomes (ผลลัพธ์ทางธุรกิจ)
| Outcome | วัดยังไง |
|---------|---------|
| PO ปลอมหมดไป | ทุก PO มีรูปถ่ายสินค้า, Audit log ย้อนหลังได้ |
| FIFO คำนวณอัตโนมัติ | กำไร Lot แม่นยำ รวมค่าขนส่ง |
| ข้อมูล 4 สาขารวมในที่เดียว | Dashboard รวม ลดการวิ่งไปดึง Excel |
| ตัดสินใจขยายสาขา | ข้อมูลรายได้/กำไร/สต็อกแบบ real-time |
| พนักงานมีสวัสดิการ | Module จัดการพนักงาน + เงินเดือน + ประกันสังคม |

### Box 3 — Users (ผู้ใช้งาน)
| Role | สิทธิ์ |
|------|--------|
| **แคชเชียร์ / พนักงานหน้าร้าน** | คีย์ PO, ถ่ายรูปสินค้า, ดู PO สาขาตัวเอง, อนุมัติ Lot ขาย, Audit log ทุก action |
| **เจ้าของร้าน (Admin)** | ดูภาพรวม 4 สาขา, ดูรายงานทั้งหมด, ตัดสินใจขยาย |
| **คนทำบัญชี** | คีย์รายจ่าย, Audit ย้อนหลัง, ดึงข้อมูลรับ-จ่ายแยก/รวมสาขา |

### Box 4 — User Outcomes (ประโยชน์ที่ user ได้)
- **แคชเชียร์:** ทำงานง่ายขึ้น, ไม่โดนข้อหาทุจริต
- **เจ้าของร้าน:** สบายใจเรื่องคดี ม.357, รู้ภาพรวมธุรกิจ, **มีเวลาครอบครัว**
- **คนทำบัญชี:** ตัวเลขอัตโนมัติ, audit ได้
- **vision ที่ลึกกว่า:** คนเก็บของเก่าไม่ใช่ขยะสังคม — เป็นฟันเฟืองเศรษฐกิจหมุนเวียนที่ควรมีเครื่องมือที่เหมาะสมกับบริบทของตัวเอง

### Box 5 — Solutions (สิ่งที่ต้องมี)
| Feature | Priority | สถานะ (6 ก.ค. 2569) |
|---------|----------|---------------------|
| PO รับซื้อ + ถ่ายรูปสินค้า | P0 | ✅ |
| ระบบขาย Lot (FIFO) | P0 | ✅ |
| ค่าขนส่ง Lot (G5) | P0 | ❌ Schema ยังไม่เพิ่ม |
| Price Tiers 3 ระดับ | P0 | ✅ |
| รายงานแยก/รวม 4 สาขา | P0 | ✅ |
| Audit log ทุกการกระทำ | P0 | ✅ |
| บันทึกรายจ่ายธุรกิจ | P0 | ✅ |
| Stock Transfer ระหว่างสาขา | P0 | ✅ |
| Photo upload แนบ PO | P0 | ✅ |
| ถ่ายรูปตอนคีย์สินค้า (add-row) | P1 | ✅ G1 Done |
| ม.357 enforcement code | P1 | ✅ PurchaseOrdersController.php |
| ม.357 enforcement DB categories | P1 | 🔴 **BLOCKER** migration `47-046` ยังไม่รัน |
| PDPA consent + schema | P1 | 🟡 Schema ✅ / UI partial |
| HTTPS | P1 | 🔴 **BLOCKER** ยังไม่ตั้งค่า |
| Admin password เปลี่ยนจาก default | P1 | 🔴 **BLOCKER** admin/admin ยังอยู่ |
| Signature pad (G2) | P1 | ❌ รอ decision |
| Employee module (G4) | P2 | ❌ Backlog |

### Box 6 — Hypotheses (สมมติฐานที่ทดสอบได้)

1. **H1 (PO Photo):** PO ปลอมจะหมดไป ถ้าพนักงานถ่ายรูปทุก PO ด้วยมือถือ/เว็บแคม/แท็บเล็ต → เจ้าของไม่ต้องเสี่ยงเจอคดี ม.357
2. **H2 (FIFO + ขนส่ง):** กำไร Lot แม่นยำ ถ้าระบบตัด FIFO อัตโนมัติ + ใส่รายจ่ายขนส่งเพื่อคำนวณกำไรสุทธิ
3. **H3 (Dashboard รวมสาขา):** เจ้าของตัดสินใจขยายสาขาได้ ถ้าดูข้อมูล real-time รวม 4 สาขาจาก dashboard เดียว → มีเวลาครอบครัว
4. **H4 (Employee):** พนักงานมีสวัสดิการ ถ้าระบบมีจัดการพนักงาน + เงินเดือน + ประกันสังคม

### Box 7 — Learn First (สิ่งที่ต้องรู้ก่อน)
**ความเสี่ยงที่สุด:** Flow ของระบบที่วางไม่ถูก — ถ้า System Flow ไม่ connect กัน ระบบจะพัง

### Box 8 — Experiments
(ยังไม่ได้ทำ — เริ่มจาก implement เลยเพราะปัญหาเข้าใจตรงกันแล้ว)

---

## 2. Purchase Flow (Flow รับซื้อ)

> Flow นี้ map จากการสัมภาษณ์เจ้าของร้าน (Dr.solodev) ภาษาชาวบ้าน

```
┌─────────────────────────────────────────────────────────────┐
│                 FLOW รับซื้อของ (Purchase)                    │
└─────────────────────────────────────────────────────────────┘

[STEP 1] ผู้ขายเดินเข้าร้าน
  │
  ▼
[STEP 2] พนักงานถาม "เอาอะไรมาขาย"
  │
  ▼
[STEP 3] ค้นหาผู้ขาย — อันดับแรก ⭐ WF-05
  │
  ├── ค้นหาจากชื่อ/เบอร์/บัตร
  ├── ถ้าเจอ → เลือก
  └── ถ้าใหม่ → + ผู้ขายใหม่
  │
  ▼
[STEP 4] คีย์รายการสินค้า (วนทีละรายการจนครบ)
  │
  ├── 4.1 เลือกบิลราคา — บิล1 auto ✅ ไม่ต้องกด
  ├── 4.2 คีย์รหัสสินค้า → ⭐ ถ้าผลลัพธ์เดียว auto-select → focus ข้ามไปน้ำหนัก
  ├── 4.3 ⭐ focus น้ำหนัก → select-all → พิมพ์ทับ
  ├── 4.4 กรอกหักน้ำหนัก (สิ่งเจือปน)
  ├── 4.5 ราคาคำนวณ auto ตาม Tier
  ├── 4.6 ถ่ายรูปสินค้า 📸
  └── 4.7 [+ เพิ่ม] → เข้า Cart
  │
  ▼
[STEP 5] รวมยอด → Cart แสดงยอดรวม
  │
  ├── ของทั่วไป → ชื่อ/เบอร์/ทะเบียน (ไม่บังคับ)
  │                  (กม.ไม่ได้บังคับ แต่ระบบควร encourage)
  │
  └── ของเสี่ยง (ทองแดง/สายไฟ/เครื่องใช้ไฟฟ้า)
       ├── [GAP ❌] ต้องมีรูปบัตรประชาชน → ถ้าซ้ำใช้ประวัติเก่า
       ├── [GAP ❌] ต้องเซ็นรับรองบนหน้าจอสัมผัส
       │               (PC ยังไม่รู้วิธี)
       └── ถ้าไม่สะดวกให้ข้อมูล → ไม่รับซื้อ
  │
  ▼
[STEP 6] ระบบจำประวัติ → ครั้งหน้าคีย์ชื่อบางส่วน → auto-complete
  │
  ├── เคยมีบัตรแล้ว → แค่เซ็นทับ
  └── ประวัติหาย → บันทึกใหม่
  │
  ▼
[STEP 7] จ่ายเงินตามบิล
  │
  ▼
[STEP 8] พนักงานยกของไปจัดเก็บแยกประเภท
```

---

## 3. Gaps Analysis (ช่องว่าง vs ระบบปัจจุบัน)

> **Legend:** ✅ Done | 🟡 Partial | ❌ ยังไม่มี | 🔴 BLOCKER

| # | Gap | Category | Priority | สถานะ (6 ก.ค. 2569) |
|---|-----|----------|----------|--------------------|
| G1 | ถ่ายรูปตอนคีย์สินค้า (ก่อนกดเพิ่ม) | UX | P1 | ✅ Done |
| G2 | Signature pad สำหรับเซ็นรับรอง | Feature | P1 | ❌ รอ decision PC flow |
| G3 | ตรวจจับสินค้าเสี่ยง → บังคับบัตร+เซ็น | Logic | P1 | 🟡 Code ✅ / DB ❌ — migration `47-046` ยังไม่รัน |
| G4 | Employee module | Feature | P2 | ❌ Backlog |
| G5 | ค่าขนส่งตอนขาย Lot | Feature | P1 | ❌ Schema ยังไม่เพิ่ม |

### G1: ถ่ายรูปตอนคีย์สินค้า ✅ DONE

**สถานะ:** ปุ่มถ่ายรูปอยู่ใน add-row แล้ว — FAB + photo strip + seller ID card upload ครบแล้ว
**Commit:** `626eb43` ux: complete photo capture UX overhaul

### G2: Signature Pad

**ปัจจุบัน:** ไม่มีฟีเจอร์เซ็นรับรอง

**ต้องการ:** 
- บนแท็บเล็ต/มือถือ → canvas ให้เซ็นด้วยนิ้ว
- บน PC → ยังไม่รู้วิธี (รอคุยกับเจ้าของร้าน)
- ลายเซ็นถูกอัปโหลดเป็นรูป พร้อม timestamp + user

### G3: ตรวจจับสินค้าเสี่ยง 🟡 PARTIAL

**Code enforcement:** ✅ `PurchaseOrdersController.php:140-153` — ตรวจ `requires_precious_receipt` และ return 422 ถ้าไม่มีบัตร

**DB data:** ❌ **BLOCKER** — migration `34-032` activate เฉพาะ `โลหะมีค่า` เท่านั้น
หมวดที่ยัง `requires_precious_receipt = 0` (ต้องแก้):
- เศษเหล็ก, เครื่องใช้ไฟฟ้า, แบตเตอรี่, มือถือ/อุปกรณ์

**Fix:** รัน migration `47-046_enforce_precious_receipt_all_risk_categories.sql`
ดูรายละเอียด: `docs/LEGAL-ACTION-PLAN.md`

### G4: Employee Module

**ต้องการจัดการ:**
- ข้อมูลพนักงาน (ชื่อ, ตำแหน่ง, เงินเดือน, เลขบัตร, ที่อยู่)
- ประกันสังคม
- เงินรายวัน / ค่าแรง
- เชื่อมกับ BusinessExpenses

### G5: ค่าขนส่ง Sale Lot

**ต้องการ:** 
- ตอนขาย Lot ให้กรอกค่าขนส่ง (เทรลเลอร์, รถสิบล้อ)
- คำนวณกำไรสุทธิ = total_amount - total_cost - ขนส่ง

---

## 4. Priority Matrix

```
                    Effort
               เล็ก         กลาง        ใหญ่
      ┌──────────────────────────────────────
P0    │  (done)                              │
(ต้อง  │                                      │
มี)    │                                      │
      │                                      │
──────┼───────────────────────────────────────
P1    │  G1, G3, G5       G2                 │
(ควร   │                                      │
มี)    │                                      │
──────┼───────────────────────────────────────
P2    │                    G4                 │
(ดี    │                                      │
ให้มี) │                                      │
```

---

## 5. Implementation Plan (Suggested Order)

| Phase | Gap | ทำอะไร |
|-------|-----|--------|
| **Phase 1** | G1 | ย้าย photo button ไป add-row — ถ่ายรูปตอนคีย์ |
| **Phase 2** | G3 | เพิ่ม flag `requires_id_card` ใน categories/catalog |
| **Phase 3** | G3 (ต่อ) | ตรรกะ: ถ้ามีรายการเสี่ยง → ต้องกรอกบัตร |
| **Phase 4** | G2 | Signature pad (canvas) |
| **Phase 5** | G5 | ค่าขนส่งใน Sale Lot |
| **Phase 6** | G4 | Employee Module |

---

## 6. Glossary (ศัพท์ที่ควรรู้)

| คำศัพท์ | ความหมาย |
|---------|----------|
| **PRD** | Product Requirements Document — เอกสารกำหนดความต้องการของผลิตภัณฑ์ |
| **Lean UX Canvas** | แบบฟอร์ม 8 ช่อง ช่วยคิดก่อนเริ่มทำ — โฟกัสที่ปัญหา ไม่ใช่ solution |
| **Business Problem** | ปัญหาทางธุรกิจที่ต้องแก้ — ไม่ใช่ "เราจะสร้างฟีเจอร์ X" |
| **Business Outcome** | ผลลัพธ์ทางธุรกิจที่วัดได้ — เช่น PO ปลอมหมดไป |
| **User Outcome** | ประโยชน์ที่ user ได้ — เช่น "สบายใจไม่กลัวคดี" |
| **Hypothesis** | สมมติฐานที่ทดสอบได้ — "เราเชื่อว่า X จะเกิดขึ้น ถ้า Y" |
| **FIFO** | First-In-First-Out — ตัดต้นทุนจากของที่ซื้อก่อนขายก่อน |
| **Tier Pricing** | ราคาหลายระดับ — ลูกค้าประจำได้ราคาดีกว่า |
| **Signature Pad** | ฟีเจอร์ให้เซ็นชื่อบนหน้าจอสัมผัส |
| **Auto-complete** | พิมพ์บางส่วน → ระบบเด้งข้อมูลที่เหลือให้ |
| **Gap Analysis** | การวิเคราะห์ช่องว่างระหว่างของที่มีกับของที่ต้องการ |
| **P0 / P1 / P2** | ลำดับความสำคัญ: P0=ต้องมีก่อนเปิด, P1=ควรมี, P2=มีดีขึ้น |

---

## 7. Compliance & Readiness Status (อัพเดท 6 ก.ค. 2569)

> Exec Council Review — Overall Score: **4.8/10** → target **9.0/10**

| มิติ | คะแนน | blockers หลัก |
|---|---|---|
| Design/UX | 6.2/10 | CSS5 fixes ยังไม่ apply |
| Legal/Compliance | 5.0/10 | G3 DB, ไม่มีทนาย, HTTPS |
| Technical | 4.0/10 | G3 migration, G5 transport cost |
| Operational | 4.0/10 | ยังไม่ migrate data สาขา |

**3 BLOCKERS (CEO NO-GO จนกว่าปิด):**
1. รัน `47-046_enforce_precious_receipt_all_risk_categories.sql`
2. เปลี่ยน admin/admin password
3. ตั้งค่า HTTPS

**เอกสารอ้างอิงทีม:**
- [`docs/MASTER-ACTION-TIMELINE.md`](MASTER-ACTION-TIMELINE.md) — timeline + ownership ครบ
- [`docs/CEO-DIRECTIVE.md`](CEO-DIRECTIVE.md) — binding directive
- [`docs/CTO-TECHNICAL-PLAN.md`](CTO-TECHNICAL-PLAN.md) — immediate fixes < 2 ชั่วโมง

---

## 8. Architecture Note (For AI Agents / Devs)

- **ระบบนี้สร้างบน base-pos (PHP Vanilla) + customizations**
- Frontend: Vanilla JS (no framework)
- API Pattern: `apiRequest(endpoint, method, data)` → `/api/index.php/{endpoint}`
- Auth: Bearer JWT (httpOnly cookie fallback)
- DB: MySQL/MariaDB
- All custom tables in `customizations/database/migrations/`
- Custom controllers in `customizations/api/Controllers/`
- Custom models in `customizations/api/Models/`
- **อย่าแก้ไฟล์ใน `base-pos/api/Controllers/` หรือ `base-pos/api/Models/`** — ใช้ custom แทน
- Routes ลงทะเบียนใน `base-pos/api/Router.php`
- Front-end JS file: `base-pos/assets/js/purchase-orders.js`
- Front-end HTML file: `base-pos/admin/purchase-orders.html`
- Page-specific CSS: `base-pos/assets/css/components/purchase-orders.css`
