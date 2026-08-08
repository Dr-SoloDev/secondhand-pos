# PRD v2: ระบบสิทธิ์การเข้าถึงและการป้องกันข้อมูลอ่อนไหว (Access Control & Data Security Remediation)

**โปรเจกต์:** Dr-SoloDev/secondhand-pos
**ผู้จัดทำ:** Full Cycle Developer (SoloCorp) + CEO Review (เทอโบ)
**วันที่:** 5 สิงหาคม 2569
**สถานะ:** ✅ **APPROVED — Owner ตัดสินใจครบแล้ว 3 ข้อเปิดคำถาม | เริ่ม implementation ทันที (ก่อน Deploy ใช้จริง)**
**อ้างอิง v1:** `docs/PRD-Access-Control-Data-Security-v1.md` (ตรวจโค้ดจริง ณ commit a88b137)
**หมายเหตุ v2:** ทีม CEO verify โค้ดจริงที่ HEAD ปัจจุบัน (หลัง a88b137) — ข้อค้นพบ A–D ใน Section 7

---

## 1. Problem Statement

ระบบ POS รับซื้อของเก่าที่กำลังจะขึ้น production มีสถาปัตยกรรมสิทธิ์การเข้าถึง (RBAC) 4 ระดับ (cashier, manager, super_manager, admin) ที่ implement ไว้แล้วในโค้ด แต่พบว่า **มีจุดที่โค้ดปัจจุบันให้สิทธิ์ไม่ตรงกับที่ต้องการ 5 จุด (P0-1 ถึง P0-5)** และ **เลขบัตรประชาชนของผู้ขายเก็บเป็น plaintext ไม่มีการเข้ารหัส** และ **UI ซ่อนเมนูไม่ตรงกับสิทธิ์ที่ API อนุญาตจริง**

**หมายเหตุ:** การที่ cashier ทุกสาขาเห็นข้อมูลผู้ขายข้ามสาขาได้ **เป็นความตั้งใจทางธุรกิจ** (เจ้าของเดียวกันทุกสาขา ต้องใช้เช็คแบล็คลิสต์/เทียร์ราคาเสมอภาคกัน) — แทนที่ด้วย audit trail (P1-2)

ผลกระทบถ้าไม่แก้ก่อนขึ้น production:
- พนักงานทำงานไม่ได้ตามที่ควร (cashier รับสต็อก/จัดการแคทตาล็อก/ตั้งเทียร์เองไม่ได้) → ต้องพึ่งผู้จัดการ/เจ้าของตลอด
- ผู้จัดการสาขาอนุมัติงานสาขาตัวเองไม่ได้ → **คอขวดที่ admin คนเดียว (one-person company)**
- เลขบัตรประชาชน plaintext → **ความเสี่ยง PDPA ร่วมกันของเจ้าของระบบและผู้พัฒนา**

---

## 2. Goals

1. สิทธิ์ในโค้ดตรงกับ spec ที่เจ้าของระบบกำหนด 100% ทุก role (cashier / manager / super_manager / admin)
2. **Cashier ทำงาน flow ประจำวันได้จบในตัวเอง** (รับซื้อ, แคทตาล็อก, เทียร์บิล, โอนสต็อก, เปิด-ปิดยอด) โดยไม่ต้องรอผู้จัดการ/ admin
3. **ผู้จัดการสาขาอนุมัติงานในขอบเขตสาขาตัวเองได้** (ยกเลิก PO, เปิด/ปิดยอด) โดยไม่ต้องรอ admin
4. UI เมนูแสดงตามสิทธิ์ที่ API อนุญาตจริง (ไม่ซ่อนฟีเจอร์ที่ใช้ได้ / ไม่โชว์ที่ใช้ไม่ได้)
5. ข้อมูลอ่อนไหว (เลขบัตรประชาชน) เข้ารหัสที่ฐานข้อมูล
6. ทุกการกระทำสืบย้อนหลังได้ (ช่วงวันที่ + ระดับสิทธิ์) ภายใน 1 หน้าจอ
7. เจ้าของระบบเซ็นรับ scope ที่ส่งมอบจริงก่อนขึ้น production

---

## 3. Non-Goals (ไม่รวมใน scope นี้)

| ไม่รวม | เหตุผล |
|---|---|
| การเข้ารหัสทั้งฐานข้อมูล (full-disk/DB encryption) | งาน infra/server — ทำแยกที่ layer Ubuntu/MySQL |
| 2FA / MFA | ไม่ใช่ scope เดิม + deadline ไม่รองรับ |
| Data retention policy (ลบข้อมูลผู้ขายอัตโนมัติ) | ต้องคุยนโยบาย + กฎหมายก่อน |
| **การปรับ UI/UX หน้าจอใหม่ตามสิทธิ์ (redesign)** | ❌ **ถูกยกกลับเข้ามาใน P0-6 แล้ว** — เฉพาะส่วน menu visibility ที่ตรงกับ API matrix ไม่ใช่ redesign ทั้งระบบ |
| การจำกัดมองเห็นข้อมูลผู้ขายตามสาขา | **ตัดสินใจแล้ว:** เห็นข้ามสาขาได้ (ธุรกิจเดียวกัน) → ใช้ audit trail แทน |

---

## 4. User Stories

**Cashier / พนักงานหน้าร้าน (คนนั่งหน้าคอมทั้งวัน)**
- ฉันต้องการออกใบโอนสต็อกและรับสต็อกเข้าสาขาตัวเองได้
- ฉันต้องการเพิ่ม/แก้ไขรายการแคทตาล็อกได้ (รับซื้อของใหม่ที่ยังไม่เคยบันทึก)
- ฉันต้องการกำหนดเทียร์บิลผู้ขายได้ (จัดการข้อมูลประจำวันเอง)
- ฉันต้องการเปิด/ปิดยอดเงินสดของสาขาตัวเองได้ (เห็นเมนูใน UI ด้วย)

**ผู้จัดการรายสาขา**
- ฉันต้องการอนุมัติ/ปฏิเสธคำขอยกเลิกใบรับซื้อของสาขาตัวเองได้
- ฉันต้องการอนุมัติ/ปฏิเสธการเปิด/ปิดยอดของสาขาตัวเองได้

**Admin / Owner**
- ฉันต้องการค้นหา log ทั้งหมดโดยกำหนดช่วงวันที่และ role ได้

**ทุก role (ความปลอดภัยข้อมูล)**
- เลขบัตรประชาชนไม่ถูกเก็บเป็น plaintext

---

## 5. Requirements

### 🔴 P0 — ต้องแก้ก่อน Deploy (ทั้งหมดในรอบนี้)

#### P0-1: ผู้จัดการสาขาอนุมัติ/ปฏิเสธการยกเลิก PO ของสาขาตัวเอง
**สถานะปัจจุบัน:** `approveCancellation()`/`rejectCancellation()` = `['admin','super_manager']` เท่านั้น + **ไม่มี branch check**
- [ ] เพิ่ม `manager` + **ต้องเพิ่ม branch check คู่กัน** (session/PO ต้องเป็นสาขาเดียวกับผู้ใช้) — pattern อ้างอิงจาก `StockTransfersController::confirm()` (มีอยู่แล้ว)
- [ ] manager สาขา A อนุมัติ PO สาขา A → สำเร็จ; PO สาขา B → 403
- [ ] log ระบุ role ที่อนุมัติ

#### P0-2: Cashier เพิ่ม/แก้ไขรายการแคทตาล็อก
**สถานะปัจจุบัน:** `createItem()`/`updateItem()` = `['admin','manager']`
- [ ] เพิ่ม `cashier` ใน createItem/updateItem
- [ ] `deleteItem` **คงจำกัดเฉพาะ `admin`** ตามเดิม
- [ ] audit log บันทึก cashier คนไหนแก้รายการอะไร เมื่อไหร่ (มีอยู่แล้ว — verify ครบ)

#### P0-3: Cashier กำหนดเทียร์บิลผู้ขายได้ — **เปิดอิสระ (Owner ตัดสินใจ 5 ส.ค.)**
**สถานะปัจจุบัน:** `SellersController::updateSeller()` บังคับ tier ไว้ที่ `['admin','manager']`
- [ ] `cashier` เปลี่ยน `tier_level` (1-3) ได้อิสระ **ไม่มี guardrail** — cashier คือคนจัดการข้อมูลนี้ตลอดเวลา
- [ ] audit log `update_seller_tier` ระบุ cashier คนไหนเปลี่ยน

#### P0-4: Cashier ออกใบโอนสต็อก + ยืนยันรับสต็อกสาขาตัวเอง
**สถานะปัจจุบัน:** `store()`/`confirm()` = `['admin','manager','super_manager']`
- [ ] เพิ่ม `cashier` ใน `store()` และ `confirm()`
- [ ] ✅ **Verified: branch check มีอยู่แล้วทั้ง 2 ฟังก์ชัน** (confirm: to_branch ต้องตรง branch ผู้ใช้ → 403; store: ห้ามโอนจากสาขาอื่น → 403) — แค่เพิ่ม role ใน requireAuth list
- [ ] `cancel()/requestReversal()/approveReversal()/rejectReversal()` คงเดิม (admin/manager/super_manager)

#### P0-5: 🆕 ผู้จัดการสาขาอนุมัติ/ปฏิเสธเปิด-ปิดยอดสาขาตัวเอง (Owner ตัดสินใจเพิ่ม 5 ส.ค.)
**สถานะปัจจุบัน:** `CashSessionsController` — open/close ทุก role ได้, แต่ `approveOpen()/approveClose()/reject*()` = `['admin','super_manager']` เท่านั้น
- [ ] เพิ่ม `manager` ใน approve/reject ของ cash session
- [ ] **ต้องเพิ่ม branch check คู่กัน** (cash session ที่จะอนุมัติ ต้องเป็นสาขาเดียวกับ manager) — ตรวจว่า controller มี branch check เดิมหรือไม่ ถ้าไม่มีให้เพิ่ม (pattern เดียวกับ P0-1)
- [ ] cashier สาขา A เปิดยอด → manager สาขา A อนุมัติได้ / manager สาขา B อนุมัติไม่ได้ (403)

#### P0-6: 🆕 UI เมนูตามสิทธิ์จริง (Owner ตัดสินใจทำในรอบนี้ 5 ส.ค.)
**สถานะปัจจุบัน:** `common.js:applyRoleNavigation()` ซ่อน `.admin-only` ทั้งหมดจาก non-admin — แต่ API อนุญาต cashier/manager เปิด-ปิดยอดได้แล้ว → เมนู "เปิด-ปิดยอด" หายจากคนที่ใช้ได้จริง
- [ ] แก้ `common.js` ให้ role matrix ตรงกับ backend: cashier/manager เห็นเมนูที่ API อนุญาต (เปิด-ปิดยอด, แคทตาล็อก, โอนสต็อก ตาม scope)
- [ ] super_manager ยังคงเห็นเฉพาะที่กำหนดเดิม (financial-summary) + เปิด-ปิดยอดตามที่ API อนุญาต
- [ ] admin เห็นทั้งหมดตามเดิม
- [ ] **ไม่ redesign** — แค่ visibility ตาม role

### 🟠 P1 — หลัง Deploy (ตาม timeline เดิม)

#### P1-1: เข้ารหัสเลขบัตรประชาชน (AES-256 at rest + hash สำหรับค้นหา)
- [ ] AES-256 encrypt สำหรับแสดงผล + hash (ค้นหา) — **แยก key ออกจาก DB/โค้ด/migration** (ใน .env)
- [ ] `findByIdCard`/`searchSellers` ยังทำงานถูกหลังเข้ารหัส
- [ ] migration แปลงข้อมูลเดิม — **backup เต็มก่อนรันทุกครั้ง + staging test ก่อน**

#### P1-2: Audit trail การเปิดดูข้อมูลผู้ขาย (PII view log)
- [ ] log `getSeller()`, `getSellerDataCenter()`, `viewPhoto()` (แยก action `view_seller_id_card_photo`)
- [ ] **ไม่ log** `getSellers()`/`searchSellers()` (autocomplete รก)
- [ ] index `(action, created_at)` บน activity_log

#### P1-3: Filter วันที่ + role ใน Activity Log
- [ ] `start_date`/`end_date` (created_at) + `role` (join users) — ใช้ร่วมกับ user_id เดิม (AND)
- [ ] endpoint คง admin-only, paginate เหมือนเดิม

### 🟡 P2 — Future
- Data retention/auto-purge, field-level permission (mask บัตร), anomaly detection การเข้าถึงผิดปกติ

---

## 6. Risks & Safeguards (อัปเดตหลัง verify)

| ความเสี่ยง | ผลกระทบ | วิธีป้องกัน | สถานะหลัง verify |
|---|---|---|---|
| เพิ่ม role โดยไม่เช็ค branch scope | เปิดช่องข้ามสาขา | test "role ถูก + สาขาผิด → 403" ทุกจุด | **P0-4 ปลอดภัยแล้ว** (check มีอยู่) / **P0-1, P0-5 ต้องสร้าง** — เป็นงานหลักของรอบนี้ |
| Migration เข้ารหัส id_card พัง | ข้อมูลกู้คืนไม่ได้ถาวร | backup เต็ม + staging ก่อนรัน | v1 เดิม |
| แก้หลายจุดใกล้ deadline | regression สูง (คนเดียว) | แก้ทีละจุด + รัน tests/api หลังทุกจุด | v1 เดิม — **บวก: ลำดับการทำ P0-4→P0-2→P0-3→P0-1→P0-5→P0-6 (ง่าย→ยาก)** |
| **🆕 Default password `admin`/`admin` (verify แล้ว)** | ใครก็เข้าสู่ระบบได้ | **Launch gate: เปลี่ยนรหัส admin + manager ทุกสาขาก่อนเปิดร้าน** + พิจารณาบังคับเปลี่ยนในระบบ (P2) | เพิ่มใหม่ |

---

## 7. Verified Findings (CEO review 5 ส.ค. 2569 — ตรวจโค้ดจริงที่ HEAD)

- **A. P0-4 branch check มีอยู่แล้ว**: `StockTransfersController::confirm()` (non-admin ต้อง to_branch = branch ตัวเอง → 403) และ `store()` (ห้ามโอนจากสาขาอื่น → 403) — ความเสี่ยงของ P0-4 ลดลงจาก v1 มาก
- **B. P0-1 ไม่มี branch check จริง** (ตาม v1) — ต้องสร้างกำแพง + เพิ่ม role พร้อมกัน = จุดที่ต้องระวังที่สุด
- **C. Pattern เดียวกับ P0-1 ที่ v1 ไม่ครอบคลุม**: cash session approvals (admin/super_manager เท่านั้น) → กลายเป็น P0-5 แล้ว
- **D. UI menu ไม่ตรง API** (admin-only ซ่อนที่ API อนุญาต) → กลายเป็น P0-6
- **E. Migration 065 (sale_lot_stock_allocations — FIFO allocation ledger) + 061 (cash ledger immutable) มีจริง** — ตัวเลขต้นทุน/กำไรเชื่อถือได้สำหรับภาษี/ตัดสินใจ
- **F. Default password admin/admin จริง** → launch gate (Risk table)

---

## 8. Open Questions — ✅ ปิดครบแล้ว (5 ส.ค. 2569)

| # | คำถาม | คำตัดสิน Owner |
|---|-------|----------------|
| 1 | P0-3 เทียร์บิล guardrail? | **เปิดอิสระ** — cashier คือคนจัดการข้อมูลนี้ตลอดเวลา |
| 2 | เห็นข้อมูลผู้ขายข้ามสาขา? | **เห็นได้** — ธุรกิจเดียวกัน ใช้ audit trail แทน (v1 ปิดแล้ว) |
| 3 | ต้องเก็บรูปบัตรจริงไหม? | ⚠️ ค้างไว้ — ส่งให้เจ้าของร้านพิจารณากับที่ปรึกษากฎหมาย (ไม่บล็อก release — ขอแค่บันทึกในเอกสารส่งมอบ) |
| 4 | 🆕 P0-5 ผู้จัดการอนุมัติเปิด/ปิดยอดสาขาตัวเอง? | **เพิ่มเลย** |
| 5 | 🆕 UI เมนูตามสิทธิ์? | **ทำในรอบนี้ (P0-6)** |

---

## 9. Timeline & Phasing (อัปเดต — เร่ง Deploy)

| ช่วง | งาน | หมายเหตุ |
|---|---|---|
| **ตอนนี้ → Deploy** | P0-1 ถึง P0-6 ทั้งหมด | ลำดับ: P0-4 → P0-2 → P0-3 → P0-1 → P0-5 → P0-6; test หลังทุกจุด; UI งานสุดท้าย (ไม่บล็อก backend) |
| Launch gate | เปลี่ยนรหัส admin/manager, เซ็นรับ scope เอกสารนี้ | ไม่มีข้อยกเว้น |
| สัปดาห์แรกหลัง launch | P1-3 (audit log filter) | ไม่กระทบ user-facing |
| ภายใน 2 สัปดาห์หลัง launch | P1-1 (เข้ารหัส id_card), P1-2 (view audit trail) | staging test ก่อน P1-1 เสมอ |
| ไม่กำหนดเวลา | P2 | รอ business decision |

---

## 10. Success Metrics

**Leading (หลังแก้ทันที):**
- 0 endpoint ที่ role เข้าถึงผิดขอบเขต (จาก automated test)
- 100% P0 ผ่าน acceptance criteria ก่อน deploy

**Lagging (30 วันแรกหลัง launch):**
- 0 incident ข้อมูลรั่ว/เข้าถึงผิดสิทธิ์
- จำนวนครั้งที่ cashier ต้องรออนุมัติงานที่ทำเองได้แล้ว → ลดลง (วัดจาก feedback ร้าน)
