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

## Sprint 1 — Core Features (Pre-Deploy) ✅ COMPLETE

### G4 — ค้นหาผู้ขาย Real-time ✅

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Status: DONE**

#### Engineering Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G4-E1 | `GET /api/sellers/search?q=` — slim payload: name, national_id, is_blacklisted, blacklist_reason | ✅ |
| G4-E2 | Index `idx_sellers_search(full_name, id_card)` + `idx_sellers_id_card(id_card)` — migration 48-047 | ✅ |
| G4-E3 | Tests: partial name/id_card, blacklist flag, empty query → error | ✅ |

#### UI Designer Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G4-U1 | Autocomplete dropdown — debounce 300ms, ⚠️ badge blacklist, ชื่อ + เลขบัตร | ✅ |
| G4-U2 | Auto-fill: เลือก seller → เติมข้อมูลใน form อัตโนมัติ | ✅ |
| G4-U3 | เลือก blacklist seller → trigger G3 popup ทันที | ✅ |

**QA Check:** ✅ ผ่าน

---

### G3 — Blacklist Alert ✅

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Status: DONE**

#### Engineering Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G3-E1 | `blacklist_reason` + `blacklisted_at` — มีอยู่แล้ว (migration 033-031), ไม่ต้อง migrate ใหม่ | ✅ |
| G3-E2 | `POST /api/sellers/blacklist` + `POST /api/sellers/unblacklist` — มีอยู่แล้วใน SellersController | ✅ |
| G3-E3 | Seller model include `is_blacklisted`, `blacklist_reason`, `blacklisted_at` — มีอยู่แล้ว | ✅ |
| G3-E4 | Tests: blacklist/unblacklist flow, search return flag | ✅ |

#### UI Designer Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G3-U1 | Blacklist warning popup — สีแดง, ชื่อ + เหตุผล + วันที่, ปุ่มดำเนินการต่อ/ยกเลิก | ✅ |
| G3-U2 | Sellers page: blacklist reason field + date ใน seller form | ✅ |
| G3-U3 | Sellers list: badge ⚠️ บัญชีดำ + tooltip แสดงเหตุผล | ✅ |

**QA Check:** ✅ ผ่าน

---
### G2 — ใบรับซื้อพิมพ์ได้ 2 แบบ ✅

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Status: DONE**

#### Engineering Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G2-E1 | Route `GET /api/purchase-orders/print?id=N` → `getPurchaseOrderForPrint()` | ✅ |
| G2-E2 | Auto-detect precious metal: `requires_precious_receipt=1` OR category มีคำว่า "ทองแดง" → flag `is_precious_metal` | ✅ |
| G2-E3 | Tests: print endpoint (missing id/invalid id/valid PO), is_precious_metal flag | ✅ |

#### UI Designer Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G2-U1 | Template 1 (standard): ชื่อร้าน+สาขา, วันที่, เลขบิล, แคชเชียร์, รายการ, ยอดรวม, footer | ✅ |
| G2-U2 | Template 2 (โลหะมีค่า): T1 + ส่วนลงนาม + checkbox หลักฐาน + zone รูปบัตร | ✅ |
| G2-U3 | `print-receipt.html` standalone, A4 landscape 2 สำเนาเคียงกัน, `@media print`, ปุ่ม "พิมพ์ใบรับซื้อ" เปิด new tab | ✅ |

**QA Check:** รอ QA ตรวจ print preview ทั้ง 2 template

---

### G7 — ประวัติผู้ขายต่อคน ✅

**Owner:** Engineering (ช่างฟูล) + UI Designer  
**Status: DONE**

#### Engineering Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G7-E1 | `GET /api/sellers/history?id=N` + `GET /api/sellers/data-center?id=N` — history, summary (total_pos, total_amount, first/last transaction), transactions[] | ✅ |
| G7-E2 | Tests: valid seller (items array), invalid seller → error, data-center → summary+transactions | ✅ |

#### UI Designer Tasks

| ID | Task | Status |
|:---|:-----|:------:|
| G7-U1 | `seller-history.html` — stat cards: ครั้งที่มาขาย / รายการทั้งหมด / ยอดรวม / วันล่าสุด | ✅ |
| G7-U2 | ตารางประวัติ accordion: วันที่ / เลขบิล / สาขา / จำนวนรายการ / ยอด — กด expand ดู items | ✅ |
| G7-U3 | Link ปุ่มประวัติ (icon-report) ใน sellers.html ทุก row → `seller-history.html?id=N` | ✅ |

**QA Check:** รอ QA ตรวจ seller ที่มีประวัติ + seller ใหม่ไม่มี PO

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

*Updated: 2026-07-07 | Sprint 1 COMPLETE — G2, G3, G4, G7 ✅ | Sprint 2 (G5, G8) → next | Orchestrator: พี่วุฒิ*
