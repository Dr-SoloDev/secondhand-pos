# WORKFLOW: Migration Consolidation (WF-PRE)
**Version**: 0.1
**Date**: 2026-06-14
**Author**: Workflow Architect
**Status**: Draft
**Implements**: D-4 (Registry) — กันระบบพังตอน deploy เครื่องลูกค้า 30 มิ.ย.
**Priority**: 🚨 CRITICAL — ต้องเสร็จก่อนงาน feature ทุกตัว และก่อน deploy

---

## Overview

ระบบมีไฟล์ migration กระจาย 2 โฟลเดอร์ แต่ตัวรันจริง (Docker + `run-migrations.sh`) มองเห็นแค่โฟลเดอร์เดียว ทำให้ migration 024–033 (รวม blacklist, precious receipt, stock transfers, business expenses) **ไม่เคยถูกรันอัตโนมัติ** และยังมีลำดับ dependency ที่ข้ามโฟลเดอร์จนทำให้ fresh deploy halt กลางคัน

workflow นี้รวม migration ทั้งหมดมาไว้ที่เดียว เรียงตาม dependency ให้ถูก ทำให้ idempotent (รันซ้ำได้) และเพิ่มตาราง `schema_migrations` เพื่อ track ว่าอันไหนรันไปแล้ว — เป้าหมายคือ **`docker compose up` บนเครื่องเปล่าต้องรันผ่าน 100% โดยไม่มี error และได้ schema ครบทุก column/table**

---

## ⚠️ Verified Findings (ตรวจกับโค้ดจริงแล้ว)

| # | Finding | Severity | หลักฐาน |
|---|---------|----------|---------|
| F-1 | `docker-compose.yml:20` + `run-migrations.sh` รันเฉพาะ `customizations/database/migrations/` (001–023) | 🔴 Critical | mount path ชี้ DIR B เท่านั้น |
| F-2 | `customizations/migrations/` (023–033) ไม่ถูกรันโดยทั้ง Docker และ runner | 🔴 Critical | ไม่มี mount/loop ชี้ DIR A |
| F-3 | DIR B `023_add_transfer_logistics_fields` สั่ง `ALTER TABLE stock_transfers` แต่ตารางถูกสร้างใน DIR A `030` (ไม่ถูกรัน) → fresh deploy halt ที่ 023 | 🔴 Critical | cross-dir dependency |
| F-4 | เลข `023` ซ้ำข้าม 2 โฟลเดอร์ (`populate_catalog_categories` vs `add_transfer_logistics_fields`) | 🟠 High | ทั้งคู่ชื่อขึ้นต้น 023 |
| F-5 | เลข `006` ซ้ำใน DIR B เอง (`change_to_three_tier_pricing` + `seed_demo_data`) | 🟠 High | 2 ไฟล์เลข 006 |
| F-6 | migration ส่วนใหญ่ไม่มี `IF NOT EXISTS` guard (เช่น 024,025,026,028,029,031,032,033) → รันซ้ำบน DB ที่มี column แล้วจะ error "Duplicate column" | 🟠 High | grep guards=0 |
| F-7 | ไม่มีตาราง `schema_migrations` / migration tracking → ไม่มีใครรู้ว่าอันไหนรันแล้ว | 🟡 Medium | ไม่พบใน schema |

**ทำไม dev ยังใช้ได้:** volume `db_data` เก็บ schema ที่ถูก apply ด้วยมือสะสมมา จึงไม่เห็นปัญหา — แต่เครื่องลูกค้า (fresh volume) จะเจอ F-3 ทันที

---

## Actors

| Actor | บทบาทใน workflow นี้ |
|---|---|
| Workflow Architect | นิยาม target state + ลำดับ dependency + acceptance test (เอกสารนี้) |
| Database Optimizer | รวม/renumber/เพิ่ม guard ให้ migration, ออกแบบ `schema_migrations` |
| DevOps Automator | แก้ runner/Docker ให้รันจากที่เดียว + เพิ่ม fresh-deploy CI test |
| Reality Checker | ยืนยันว่า fresh `docker compose up` ผ่านจริง ไม่มี error |

---

## Prerequisites

- backup DB dev ปัจจุบัน (`mysqldump`) ก่อนแตะอะไร — กันข้อมูลหาย
- มี Docker ใช้ทดสอบ fresh deploy ได้ (volume เปล่า)
- รายการ migration ครบทั้ง 2 โฟลเดอร์ (มีใน Registry View 2)

---

## Trigger

งานเริ่มเมื่อ: ก่อนเริ่ม WF-01 และก่อน deploy ใดๆ (manual trigger จากแผนนี้)

---

## Target End-State (Definition of Done)

1. มี migration **โฟลเดอร์เดียว** (`customizations/database/migrations/`) เรียงเลขต่อเนื่องไม่ซ้ำ ตาม dependency ที่ถูกต้อง
2. ทุก migration **idempotent** — รันซ้ำได้โดยไม่ error (ใช้ `IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS` / guard ที่เหมาะกับ MySQL 8)
3. มีตาราง `schema_migrations(version, filename, applied_at)` — runner ข้ามอันที่รันแล้ว
4. `customizations/migrations/` (DIR A) ถูกลบหรือ archive — ไม่มี migration ลอยอยู่นอกเส้นทางอีก
5. **fresh `docker compose up` บน volume เปล่า รันผ่าน 100% ไม่มี error** และ schema มีครบทุก column/table ที่โค้ดอ้างถึง
6. รันซ้ำบน DB dev เดิม (ที่มี column อยู่แล้ว) ก็ต้องผ่าน ไม่ทำข้อมูลพัง

---

## Workflow Tree

### STEP 1: Backup + Snapshot schema ปัจจุบัน
**Actor**: Database Optimizer
**Action**: `mysqldump` ทั้ง DB dev + dump `SHOW CREATE TABLE` ทุกตาราง เก็บเป็น baseline
**Output SUCCESS**: ไฟล์ backup + รายการ column/table จริงปัจจุบัน → GO STEP 2
**Output FAILURE**:
  - `FAILURE(no_access)`: ต่อ DB ไม่ได้ → หยุด แจ้ง ไม่ดำเนินต่อ (ห้ามแก้ migration โดยไม่มี backup)
**Observable**: ไฟล์ `backups/pre-migration-consolidation-YYYYMMDD.sql` ต้องมีจริงก่อนไป step ถัดไป

### STEP 2: สร้าง dependency map ของ migration ทั้งหมด
**Actor**: Database Optimizer
**Action**: ไล่ทุกไฟล์ทั้ง 2 โฟลเดอร์ บันทึกว่าแต่ละอัน CREATE/ALTER ตารางอะไร และต้องมีตารางอะไรอยู่ก่อน
**Output SUCCESS**: ตาราง dependency (ดู "Migration Ordering Plan") → GO STEP 3
**Key constraint**: `stock_transfers` (DIR A 030) **ต้องมาก่อน** `transfer_logistics_fields` (DIR B 023) — F-3

### STEP 3: Renumber + รวมเป็นลำดับเดียว
**Actor**: Database Optimizer
**Action**: เรียงใหม่ตาม dependency, แก้เลขซ้ำ (006, 023), ย้าย DIR A เข้า DIR B
**Output SUCCESS**: ไฟล์เรียง 001..N ต่อเนื่อง ไม่ซ้ำ → GO STEP 4
**Output FAILURE**:
  - `FAILURE(circular_dep)`: เจอ dependency วน → หยุด ยกขึ้นมาคุย (ไม่ควรเกิด แต่ต้องเช็ค)

### STEP 4: เพิ่ม idempotency guard ทุกไฟล์
**Actor**: Database Optimizer
**Action**: ใส่ `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` (MySQL 8.0.29+) หรือ guard ผ่าน `information_schema` สำหรับ ALTER ที่ไม่รองรับ IF NOT EXISTS
**Output SUCCESS**: ทุก DDL มี guard → GO STEP 5
**Note**: ตรวจ MySQL version (`image: mysql:8.0`) ว่ารองรับ `ADD COLUMN IF NOT EXISTS` หรือไม่ — ถ้าไม่ ใช้ stored-procedure guard pattern

### STEP 5: เพิ่ม schema_migrations tracking + แก้ runner
**Actor**: DevOps Automator
**Action**: สร้างตาราง `schema_migrations`, แก้ `mysql-init.sh`/`run-migrations.sh` ให้บันทึก + ข้ามอันที่รันแล้ว
**Output SUCCESS**: runner track ได้ → GO STEP 6
**Handoff**: ดู Handoff Contract ด้านล่าง

### STEP 6: Fresh-deploy verification (สำคัญที่สุด)
**Actor**: Reality Checker + DevOps
**Action**: `docker compose down -v` (ลบ volume) → `docker compose up` → ตรวจ log ไม่มี error + เทียบ schema กับ baseline STEP 1
**Output SUCCESS**: schema ครบ ไม่มี error → DONE ✅
**Output FAILURE**:
  - `FAILURE(migration_error)`: มี error ตอนรัน → ระบุไฟล์ที่พัง → ย้อน STEP 3/4 แก้
  - `FAILURE(missing_column)`: รันผ่านแต่ column/table ขาด → เทียบกับโค้ดที่อ้างถึง → เพิ่ม migration

---

## Migration Ordering Plan (แนะนำ — Database Optimizer finalize)

หลักการ: คงลำดับ DIR B 001–022 ไว้ แล้วแทรก DIR A เข้าโดยเคารพ dependency และแก้เลขซ้ำ

| ลำดับใหม่ | ที่มาเดิม | หมายเหตุ dependency |
|-----------|-----------|---------------------|
| 001–005 | DIR B 001–005 | ตามเดิม |
| 006 / 007 | DIR B `006_change_to_three_tier_pricing` / `006_seed_demo_data` | **แก้เลขซ้ำ 006** → แยกเป็น 006, 007 (เลื่อนที่เหลือ) |
| ... | DIR B 007–022 | เลื่อนเลขตามผลด้านบน |
| (ก่อน transfer_logistics) | DIR A `030_add_stock_transfers` (CREATE) | **ต้องมาก่อน** transfer_logistics — F-3 |
| (หลัง stock_transfers) | DIR B `023_add_transfer_logistics_fields` (ALTER) | depends on stock_transfers |
| ต่อท้าย | DIR A 023(populate), 024, 025, 026, 027, 028, 029, 031, 032, 033 | เรียงตาม dependency ของแต่ละอัน |

> เลขสุดท้ายที่แน่นอน Database Optimizer เป็นคนกำหนด ภายใต้ constraint: (1) ต่อเนื่องไม่ซ้ำ (2) `stock_transfers` ก่อน ALTER ของมัน (3) ตารางถูกสร้างก่อนถูก seed/populate

---

## Handoff Contracts

### Workflow Architect → Database Optimizer
**ส่งมอบ**: เอกสารนี้ (target state + ordering constraints + findings)
**คาดหวังกลับ**: โฟลเดอร์ migration เดียว เรียง+idempotent + รายงานเลขเก่า→ใหม่

### Database Optimizer → DevOps Automator
**ส่งมอบ**: migration ที่ idempotent + schema ของ `schema_migrations`
**คาดหวังกลับ**: runner/Docker ที่ track + fresh-deploy CI test

### DevOps → Reality Checker
**ส่งมอบ**: `docker compose up` ที่รันจากโฟลเดอร์เดียว
**คาดหวังกลับ**: ยืนยัน fresh deploy ผ่าน + schema ตรง baseline

---

## schema_migrations Design (แนะนำ)

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
  version     VARCHAR(20) PRIMARY KEY,   -- เช่น '024'
  filename    VARCHAR(255) NOT NULL,
  applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
runner: ก่อนรันแต่ละไฟล์ เช็ค `version` ใน `schema_migrations` — ถ้ามีแล้ว ข้าม; ถ้าไม่มี รันแล้ว INSERT

---

## Cleanup Inventory

| สิ่งที่สร้าง/แก้ | สร้างที่ step | rollback |
|------------------|--------------|----------|
| ไฟล์ migration renumber | STEP 3 | git revert (ยังไม่ commit จนกว่าจะผ่าน STEP 6) |
| ตาราง `schema_migrations` | STEP 5 | `DROP TABLE` (ไม่กระทบ data อื่น) |
| โฟลเดอร์ DIR A | STEP 3 | git — archive ไม่ลบถาวรจนกว่าจะ verify |

---

## Test Cases

| Test | Trigger | Expected |
|------|---------|----------|
| TC-01: Fresh deploy | `docker compose down -v && up` บน volume เปล่า | รันผ่าน 100% ไม่มี error |
| TC-02: Schema completeness | หลัง TC-01 | ทุก column/table ที่โค้ดอ้าง (blacklist, precious_receipt, stock_transfers, business_expenses) มีครบ |
| TC-03: Idempotent re-run | รัน migration ซ้ำบน DB dev เดิม | ไม่มี "Duplicate column"/"Table exists" error |
| TC-04: Tracking skip | รัน runner รอบสอง | ข้ามอันที่รันแล้ว ไม่รันซ้ำ |
| TC-05: F-3 regression | fresh deploy | `transfer_logistics` ALTER สำเร็จเพราะ `stock_transfers` ถูกสร้างก่อนแล้ว |
| TC-06: Data preserved | รันบน DB dev | ข้อมูลเดิม (PO/sellers/sale_lots) ยังครบ |

---

## Assumptions

| # | Assumption | ตรวจที่ไหน | เสี่ยงถ้าผิด |
|---|-----------|-----------|-------------|
| A1 | DIR A เคยถูก apply ด้วยมือบน DB dev จึงไม่ error ตอนนี้ | ยังไม่ยืนยัน — ควรตรวจ DB จริง | ถ้าจริงบางส่วน schema dev จะไม่ตรงกับ migration set |
| A2 | MySQL 8.0 ใน Docker รองรับ `ADD COLUMN IF NOT EXISTS` | ต้องเช็ค minor version | ถ้าไม่รองรับ ต้องใช้ guard แบบ information_schema |
| A3 | ไม่มีโค้ด PHP รัน migration เองตอน boot | ยังไม่ยืนยัน | ถ้ามี อาจ apply ซ้ำ |

---

## Open Questions

- DB dev ปัจจุบัน apply migration ไหนไปแล้วบ้าง? (ต้องตรวจ `SHOW COLUMNS` เทียบ migration set — ทำใน STEP 1)
- ต้องการเก็บ `006_seed_demo_data` ไว้ใน production migration ไหม หรือแยกเป็น seed dev-only? (demo data ไม่ควรลงเครื่องลูกค้า)

---

## Spec vs Reality Audit Log

| Date | Finding | Action |
|------|---------|--------|
| 2026-06-14 | สร้าง spec จาก discovery (F-1..F-7 ตรวจกับโค้ดจริง) | — |
