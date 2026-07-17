# Agent Playbook — Secondhand POS

> **ชุดทีม**: DEV-WORKFLOW v1.0  
> **โปรเจกต์**: Secondhand POS (ร้านรับซื้อของมือสอง 4 สาขา สุรินทร์)  
> **สถาปนิก**: Dr.SoloDev  
> **ตกผลึก**: 2026-06-15

---

## ภาพรวมทีม

```
┌─────────────────────────────────────────────────────────┐
│                    WORKFLOW LOOP                        │
│                                                         │
│   ┌──────────┐    ┌──────────┐    ┌──────────────────┐ │
│   │  EXPLORE │───▶│  CLAUDE  │───▶│  WORKFLOW FILE   │ │
│   │  (MAP)   │    │  MAIN    │    │  (SPEC+OUTPUT)   │ │
│   └──────────┘    │(SPEC+    │    └──────────────────┘ │
│                   │ BUILD+   │                          │
│                   │ TEST)    │                          │
│                   └──────────┘                          │
└─────────────────────────────────────────────────────────┘

Sequential — ไม่ Parallel (ทำทีละขั้น รอผลก่อนไปต่อ)
```

---

## Agent Cards

---

### Agent A — Explore
**บทบาท**: นักสืบ / ผู้สำรวจ (MAP Phase เท่านั้น)

| ข้อมูล | รายละเอียด |
|--------|-----------|
| **Phase** | MAP เท่านั้น |
| **Mode** | Read-only |
| **ขอบเขต** | อ่าน ค้นหา รายงาน — ห้ามแก้ไฟล์ |

**เก่งด้าน**:
- ค้นหาไฟล์ตาม pattern (`find`, `grep`, glob)
- อ่านและสรุปโค้ดที่มีอยู่
- ตอบคำถาม EXISTS vs MISSING
- วิเคราะห์ gap ระหว่าง requirement กับ codebase จริง

**เครื่องมือที่ใช้**:
```
Read, Bash (read-only), Grep, WebSearch
```

**Output ที่ส่งต่อ**:
```
EXISTS:  <ชื่อไฟล์>:<บรรทัด> — <อธิบายสิ่งที่มีอยู่>
MISSING: <ชื่อ feature> — <ต้องสร้างอะไร>
GAP:     <ชื่อ feature> — <มีแต่ไม่สมบูรณ์ เพราะ...>
```

**กฎเด็ดขาด**:
- ❌ ห้ามแก้ไฟล์ใดๆ ทั้งสิ้น
- ❌ ห้าม implement แม้จะ "ง่ายมาก"
- ✅ รายงานเท่านั้น ส่งต่อให้ Claude Main

---

### Agent B — Claude Main
**บทบาท**: สถาปนิก + นักพัฒนา + QA (SPEC + BUILD + TEST)

| ข้อมูล | รายละเอียด |
|--------|-----------|
| **Phase** | SPEC → BUILD → TEST |
| **Mode** | Full access |
| **Input** | รับรายงาน EXISTS/MISSING จาก Explore |

**เก่งด้าน**:
- เขียน SPEC จาก gap analysis (WORKFLOW-XX.md)
- เรียงลำดับ BUILD: migration → api → frontend
- แก้บัก + debug จากผล test จริง
- ประเมินว่า feature อยู่ใน scope หรือเกิน deadline

**เครื่องมือที่ใช้**:
```
Read, Edit, Write, Bash, Agent (spawn Explore)
```

**Output**:
- `docs/workflows/WORKFLOW-XX-name.md` (SPEC)
- Code files ที่แก้/สร้าง (BUILD)
- Test results + ยืนยัน HTTP 200 (TEST)

**กฎ**:
- ✅ ต้องรับ MAP report ก่อนเริ่ม SPEC เสมอ
- ✅ สร้าง WORKFLOW-XX.md ก่อน code แรกเสมอ
- ✅ BUILD order: database → api → frontend (ห้ามข้าม)
- ✅ TEST order: db → curl → http → browser
- ❌ ห้าม build สิ่งที่ Explore ยังไม่ได้ MAP

---

## Workflow Protocol

### ขั้นตอนมาตรฐาน (ทุก WF)

```
STEP 1: MAP
  ▶ spawn Explore agent
  ▶ ถามว่า: "feature X มีอยู่แล้วมั้ย? อยู่ที่ไหน? ขาดอะไร?"
  ▶ รอผล EXISTS/MISSING/GAP

STEP 2: SPEC
  ▶ Claude Main รับ MAP report
  ▶ เขียน docs/workflows/WORKFLOW-XX-name.md
  ▶ ระบุ: EXISTS, MISSING, Implementation Order, Test Cases

STEP 3: BUILD
  ▶ ทำตาม Implementation Order ใน SPEC
  ▶ order: migration → api → frontend
  ▶ commit แต่ละ layer ก่อนไปต่อ

STEP 4: TEST
  ▶ db:    SELECT โดยตรงใน MySQL container
  ▶ curl:  curl -X POST/GET endpoint
  ▶ http:  เปิด browser ดู Network tab
  ▶ file:  ตรวจ output file (upload, PDF, etc.)
```

### Handoff Conditions (เงื่อนไขส่งต่อ)

| จาก → ถึง | เงื่อนไข |
|----------|---------|
| MAP → SPEC | Explore ส่ง report ครบ (EXISTS + MISSING ทุก component) |
| SPEC → BUILD | WORKFLOW-XX.md เขียนเสร็จ + ผู้ใช้ยืนยัน scope |
| BUILD → TEST | Code compile ได้ + container up |
| TEST → DONE | ทุก test case pass + HTTP ไม่มี 500 |

---

## Stack Rules — Secondhand POS

กฎเฉพาะ stack นี้ (PHP + MySQL + vanilla JS) สำหรับ Claude Main:

```php
// ✅ ถูก
$db->query($sql, $params);   // สำหรับ INSERT/UPDATE/SELECT
$db->fetch();                // ดึงผลลัพธ์ (ไม่ใช่ fetchOne)

// ❌ ผิด — error จริง
$db->execute($sql, $params); // execute() รับ PDO Statement ไม่ใช่ string
$db->fetchOne();             // method นี้ไม่มีใน Database class
```

```php
// ✅ ถูก — tryJwtAuth
public function tryJwtAuth(): bool {
    return $this->user !== null;  // return bool เฉยๆ
}

// ❌ ผิด — requireAuth() เรียก exit() ทันที ห้ามใช้ใน try/catch
try { $this->requireAuth(); } catch (...) { ... }  // ไม่ทำงานตามที่คิด
```

```javascript
// ✅ URL routing ถูก (path-based)
`${API_BASE}/purchase-orders/photos`

// ❌ ผิด
`api/index.php?route=purchase-orders/photos`
```

```bash
# ✅ volume mount ใหม่
docker compose up -d web   # recreate container

# ❌ ไม่พอ
docker compose restart web  # ไม่ mount volume ใหม่
```

---

## WF Registry

| WF ID | ชื่อ | สถานะ | SPEC file |
|-------|------|-------|-----------|
| WF-PRE | Migration Consolidation | ✅ DONE | — |
| WF-01 | Photo Upload (QR + Mobile) | ✅ DONE | WORKFLOW-01-photo-upload.md |
| WF-02 | Receipts (Type A + B) | ✅ DONE | WORKFLOW-02-receipts.md |
| WF-03 | Seller Search + Blacklist | ✅ DONE | WORKFLOW-03-seller-search-blacklist.md |
| WF-04 | 4-Branch Dashboard | ✅ DONE | WORKFLOW-04-dashboard.md |
| WF-05 | Purchase Flow UX — keyboard-first cashier redesign | ✅ DONE | WORKFLOW-05-purchase-flow-ux.md |

**Deadline: 2026-06-30** | **WF-05 Done: 2026-07-13**

---

## Template — เพิ่ม WF ใหม่

เมื่อต้องการเริ่ม WF ใหม่ ให้ copy โครงสร้างนี้:

```markdown
# WF-XX: [ชื่อ Feature]
**Status**: SPEC — พร้อม implement
**Date**: YYYY-MM-DD

## What EXISTS
| Component | File:Line | Note |
|-----------|-----------|------|
| ...       | ...       | ✅   |

## What MISSING
### Gap 1: ...
### Gap 2: ...

## Implementation Order
Step 1: [migration]
Step 2: [api]
Step 3: [frontend]

## Scope Boundary
- ❌ ไม่ทำ X (เกิน deadline)

## Test Cases
| TC | Scenario | Expected |
|----|----------|---------|
| TC-01 | ... | ✅ |
```

---

## Expansion — แผนกอื่น

Pattern นี้ขยายได้ทุก department:

```
DEV-WORKFLOW     ← ปัจจุบัน (software features)
FINANCE-WORKFLOW ← รายรับ-รายจ่าย, payroll, tax
HR-WORKFLOW      ← พนักงาน, shift, attendance
OPS-WORKFLOW     ← inventory reorder, logistics

กฎเดียวกันทุก dept: MAP → SPEC → BUILD → TEST
```

เมื่อขยาย department ใหม่:
1. สร้าง `docs/[dept]-workflows/REGISTRY.md`
2. ระบุ Stack Rules เฉพาะ dept
3. เพิ่มแถวใน WF Registry ของ playbook นี้

---

*"MAP ก่อนเสมอ ไม่มีข้อยกเว้น"*
