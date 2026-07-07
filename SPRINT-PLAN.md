# Secondhand POS — Sprint Plan with Department Assignment

> **Orchestrator:** พี่วุฒิ  
> **Quality Gate:** งานทุกชิ้นต้องผ่าน **9/10** ก่อน handoff  
> **QA Gate:** เมื่อครบทุก task ใน goal → QA ตรวจก่อน release  
> **Owner Review:** หลัง QA pass → Dr.solodev ตรวจสอบขั้นสุดท้าย

---

## Department Roles (สำหรับโปรเจกต์นี้)

| Department | Head | หน้าที่ |
|:-----------|:-----|:--------|
| Engineering | ช่างฟูล | PHP backend, DB migration, API endpoints |
| UI Designer | UI-Designer | HTML components, print layouts, CSS |
| QA | QA-ทีม | Test suite, quality gate 9/10 |
| Orchestrator | พี่วุฒิ | Handoff, coordination, quality oversight |

---

## Sprint 1 — Core Features (Pre-Deploy)

### G4 — ค้นหาผู้ขาย Real-time

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Score Target:** 9/10 per task

#### Engineering Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G4-E1 | เพิ่ม `GET /api/sellers/search?q=` — ค้นจาก `national_id` + `full_name`, return: id, name, national_id, is_blacklisted, blacklist_reason | ช่างฟูล-backend | 9/10 |
| G4-E2 | เพิ่ม index `sellers(full_name, national_id)` — migration 022 | ช่างฟูล-backend | 9/10 |
| G4-E3 | Unit test: search แบบ partial match, blacklist flag ใน response | ช่างฟูล-qa | 9/10 |

#### UI Designer Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G4-U1 | Autocomplete dropdown component ใน `purchase-orders.html` — debounce 300ms, แสดง ชื่อ + เลขบัตร + ⚠️ badge ถ้า blacklist | ui-specialist | 9/10 |
| G4-U2 | Auto-fill: เลือก seller → เติม ชื่อ, เลขบัตร, เบอร์ โทรอัตโนมัติ | ui-specialist | 9/10 |
| G4-U3 | ถ้าเลือก blacklist seller → trigger G3 popup ทันที (integrate กับ G3-U1) | ui-specialist | 9/10 |

**QA Check:** G4-E + G4-U ทั้งหมด → QA ทดสอบ end-to-end flow

---

### G3 — Blacklist Alert

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Score Target:** 9/10 per task

#### Engineering Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G3-E1 | Migration 023: เพิ่ม `blacklist_reason VARCHAR(255)` + `blacklisted_at DATETIME NULL` ใน `sellers` table | ช่างฟูล-backend | 9/10 |
| G3-E2 | อัปเดต `SellersController`: `PATCH /api/sellers/{id}/blacklist` รับ `{reason, blacklisted}` | ช่างฟูล-backend | 9/10 |
| G3-E3 | อัปเดต `Seller` model: include `is_blacklisted`, `blacklist_reason`, `blacklisted_at` ใน response | ช่างฟูล-backend | 9/10 |
| G3-E4 | Unit test: blacklist/unblacklist flow, search return flag | ช่างฟูล-qa | 9/10 |

#### UI Designer Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G3-U1 | Blacklist warning popup — สีแดง, แสดง ชื่อ + เหตุผล + วันที่, ปุ่ม "ดำเนินการต่อ" / "ยกเลิก" | ui-specialist | 9/10 |
| G3-U2 | Sellers page: เพิ่ม blacklist reason field + date ใน seller form | ui-specialist | 9/10 |
| G3-U3 | Sellers list: แสดง blacklist badge ⚠️ + reason tooltip ใน table | ui-specialist | 9/10 |

**QA Check:** ทดสอบ blacklist flow ครบ — set/unset, popup trigger, form save

---
### G2 — ใบรับซื้อพิมพ์ได้ 2 แบบ (A4 แบ่งครึ่ง = 2 สำเนา)

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Score Target:** 9/10 per task

#### Engineering Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G2-E1 | `GET /api/purchase-orders/{id}/print` — return PO data พร้อม items, seller, branch, cashier name | ช่างฟูล-backend | 9/10 |
| G2-E2 | Logic: auto-detect "precious metal" bill — ถ้า items มีหมวดที่มีคำว่า "ทองแดง" → flag `is_precious_metal: true` ใน response | ช่างฟูล-backend | 9/10 |
| G2-E3 | Unit test: print endpoint, auto-detect flag กรณีมี/ไม่มีทองแดง | ช่างฟูล-qa | 9/10 |

#### UI Designer Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G2-U1 | Print layout Template 1 — บิลปกติ: ชื่อร้าน+สาขา, วันที่+เวลา, เลขบิล, แคชเชียร์, รายการ, ยอดรวม, ข้อความท้ายบิล | ui-specialist | 9/10 |
| G2-U2 | Print layout Template 2 — บิลโลหะมีค่า: ทุกอย่างจาก T1 + ส่วนลงนาม + checkbox หลักฐาน + พื้นที่แนบรูปบัตร | ui-specialist | 9/10 |
| G2-U3 | CSS print: A4 แบ่งครึ่งแนวนอน = 2 สำเนาต่อแผ่น, `@media print` ซ่อน UI อื่น, ปุ่ม "พิมพ์" บนหน้า PO detail | ui-specialist | 9/10 |

**QA Check:** พิมพ์จริง (หรือ print preview) ทั้ง 2 แบบ ตรวจ layout ครบ

---

### G7 — ประวัติผู้ขายต่อคน

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Score Target:** 9/10 per task

#### Engineering Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G7-E1 | `GET /api/sellers/{id}/history` — return: total_amount, total_orders, last_visit, orders[] (date, ref_no, items summary, total) | ช่างฟูล-backend | 9/10 |
| G7-E2 | Unit test: seller ที่มี orders หลายรายการ, seller ที่ยังไม่เคยขาย | ช่างฟูล-qa | 9/10 |

#### UI Designer Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G7-U1 | Seller profile page — stat cards: ยอดรวมทั้งหมด / จำนวนครั้ง / ครั้งล่าสุด | ui-specialist | 9/10 |
| G7-U2 | ตารางประวัติ: วันที่ / เลขบิล / รายการ / ยอด — sortable, responsive | ui-specialist | 9/10 |
| G7-U3 | Link จาก sellers.html → seller profile page | ui-specialist | 9/10 |

**QA Check:** ทดสอบ seller ที่มีประวัติมากกว่า 10 records, seller ใหม่ที่ยังไม่มี PO

---

## Sprint 2 — Post-Deploy Week 1

### G5 — Dashboard 4 สาขา

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Score Target:** 9/10 per task

#### Engineering Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G5-E1 | `GET /api/dashboard/branches` — return per-branch: today_purchase_total, today_po_count, month_total, branch info | ช่างฟูล-backend | 9/10 |
| G5-E2 | Support query params: `?branch_id=` (single), ไม่มี param = all branches | ช่างฟูล-backend | 9/10 |
| G5-E3 | Unit test: all-branch, single-branch, branch ที่ยังไม่มี PO วันนี้ | ช่างฟูล-qa | 9/10 |

#### UI Designer Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G5-U1 | Mode 1: รวมทุกสาขา — stat cards รวม + breakdown mini cards | ui-specialist | 9/10 |
| G5-U2 | Mode 2: เลือกสาขาเดียว — branch selector dropdown | ui-specialist | 9/10 |
| G5-U3 | Mode 3: side-by-side 4 สาขา — highlight สาขาที่มียอดสูงสุด, responsive grid | ui-specialist | 9/10 |
| G5-U4 | Responsive: mobile (1 col) → tablet (2 col) → desktop (4 col) | ui-specialist | 9/10 |

**QA Check:** ทดสอบทั้ง 3 modes, responsive บน mobile/tablet, กรณีไม่มีข้อมูลวันนี้

---

### G8 — Export CSV นักบัญชี

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Score Target:** 9/10 per task

#### Engineering Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G8-E1 | `GET /api/reports/purchase/export?month=&year=&branch_id=` → CSV file download | ช่างฟูล-backend | 9/10 |
| G8-E2 | `GET /api/reports/sale-lots/export?month=&year=&branch_id=` → CSV | ช่างฟูล-backend | 9/10 |
| G8-E3 | `GET /api/reports/expenses/export?month=&year=&branch_id=` → CSV | ช่างฟูล-backend | 9/10 |
| G8-E4 | `GET /api/reports/summary/export?month=&year=&branch_id=` → P&L summary CSV | ช่างฟูล-backend | 9/10 |
| G8-E5 | Branch_id ไม่ระบุ = รวมทุกสาขา + คอลัมน์ "สาขา" ใน output | ช่างฟูล-backend | 9/10 |
| G8-E6 | Unit test: export มีข้อมูล, export เดือนที่ไม่มีข้อมูล, multi-branch export | ช่างฟูล-qa | 9/10 |

#### UI Designer Tasks

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G8-U1 | Filter UI ใน reports.html: month picker + year picker + branch selector | ui-specialist | 9/10 |
| G8-U2 | Export buttons: ปุ่มแยกต่อรายงาน (4 ปุ่ม) + trigger download | ui-specialist | 9/10 |

**QA Check:** เปิด CSV ใน Excel ได้ถูกต้อง, UTF-8 BOM (ภาษาไทยไม่เป็นขยะ), filter ทำงานถูก

---

## Sprint 3 — Month 2

### G9 — โอนสต็อกระหว่างสาขา

**Owner:** Engineering (ช่างฟูล) + UI Designer

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G9-E1 | ตรวจ migrations ที่มีอยู่ — stock_transfers table พร้อมแล้วหรือต้องเพิ่ม fields | ช่างฟูล-backend | 9/10 |
| G9-E2 | `POST /api/stock-transfers` — create transfer: from_branch, to_branch, category, weight_kg | ช่างฟูล-backend | 9/10 |
| G9-E3 | `PATCH /api/stock-transfers/{id}/confirm` — ปลายทางกด "รับของแล้ว" → update stock both branches | ช่างฟูล-backend | 9/10 |
| G9-E4 | Unit test: create→confirm flow, stock ลด/เพิ่มถูกต้อง, audit trail | ช่างฟูล-qa | 9/10 |
| G9-U1 | Transfer form: from/to branch selector, category, weight | ui-specialist | 9/10 |
| G9-U2 | Transfer list: pending/confirmed status, ปุ่ม "รับของแล้ว" สำหรับปลายทาง | ui-specialist | 9/10 |

---

### G10 — Stock Alert เมื่อหมด

**Owner:** Engineering (ช่างฟูล) + UI Designer

| ID | Task | Sub-agent | Score Gate |
|:---|:-----|:----------|:----------:|
| G10-E1 | Migration: เพิ่ม `stock_threshold_kg DECIMAL(10,2) NULL` ใน inventory/catalog table | ช่างฟูล-backend | 9/10 |
| G10-E2 | API: ตั้ง threshold ต่อหมวดได้ | ช่างฟูล-backend | 9/10 |
| G10-E3 | Alert check logic: inventory_kg <= threshold → flag `is_low_stock: true` | ช่างฟูล-backend | 9/10 |
| G10-U1 | Badge แดงใน dashboard + inventory เมื่อ is_low_stock = true | ui-specialist | 9/10 |
| G10-U2 | Settings: หน้าตั้งค่า threshold ต่อหมวด | ui-specialist | 9/10 |

---

### G1 — รูปภาพ (รอ storage decision)

**Status: BLOCKED** — รอเจ้าของตัดสินใจ storage: NAS / Backblaze B2 / Hybrid  
ทำงานต่อได้หลังจาก storage decision แล้ว

---

## Quality & Governance Framework

### Quality Gate — 9/10 Scoring Criteria

ทุก task ถูกประเมินโดย Department Head ก่อน handoff:

| มิติ | น้ำหนัก | คำอธิบาย |
|:-----|:-------:|:---------|
| Correctness | 3 | ทำงานตรงตาม spec ทุก edge case |
| Code Quality | 2 | อ่านง่าย, ไม่มี dead code, naming ชัดเจน |
| Security | 2 | ไม่มี injection, input validated, auth ถูกต้อง |
| Test Coverage | 2 | test ครอบคลุม happy path + error cases |
| UX/Output Quality | 1 | output ที่ user เห็นสวยงาม ใช้งานง่าย |

**Score < 9 → Head ส่งกลับ sub-agent พร้อม feedback ชัดเจน**  
**Score ≥ 9 → Head handoff ให้ Orchestrator (พี่วุฒิ) รับทราบ**

---

### Pipeline Flow

```
Sub-agent ทำงาน
    ↓
Head ตรวจสอบ (score?)
    ├── < 9 → ส่งกลับ + feedback
    └── ≥ 9 → พี่วุฒิ รับทราบ
                   ↓
         Goal ทั้งหมดครบ?
                   ↓
              QA ตรวจสอบ
                   ↓
         Dr.solodev review ขั้นสุดท้าย
```

---

### Handoff Protocol

เมื่อ task score ≥ 9:

```
FROM: [Head] → TO: Orchestrator (พี่วุฒิ)
TASK_ID: [G4-E1]
SCORE: 9/10
EVIDENCE: [brief description of what was built + test result]
NEXT: [next task หรือ "Goal complete, ready for QA"]
```

---

### QA Checklist (per Goal)

QA ทดสอบเมื่อ Engineering + UI Designer ทุก task ใน goal ผ่าน 9/10 แล้ว:

- [ ] Happy path ทำงานครบ
- [ ] Edge cases (ข้อมูลว่าง, ข้อมูลมาก, Thai characters)
- [ ] Mobile responsive (ถ้า UI task)
- [ ] No console errors
- [ ] API returns correct HTTP status codes
- [ ] Security: auth required endpoints ต้องมี JWT

---

## Values

> **สร้างสิ่งที่มีคุณค่า**
>
> ระบบนี้จะช่วยร้านรับซื้อของเก่า 4 สาขาในสุรินทร์  
> ผู้ขายรายย่อย — ชาวบ้านที่นำของมาขายทุกวัน — จะได้รับใบเสร็จที่ถูกต้อง  
> เจ้าของร้านจะมีข้อมูลที่เชื่อถือได้ ตัดสินใจได้ดีขึ้น  
> งานทุกชิ้นที่เราสร้างต้องสมกับความไว้วางใจนั้น

---

*Updated: 2026-07-07 | Orchestrator: พี่วุฒิ*
