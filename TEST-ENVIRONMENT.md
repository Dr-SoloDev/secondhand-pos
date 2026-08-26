# Test Environment Guide — Scrap POS API Test Suite

> สรุปจากการแก้ไข test failures ชุดใหญ่ (25 fails → 0) วันที่ 2026-08-26
> ผู้เขียน: Engineering (ช่างฟูล) + QA (QA-ทีม) — SoloCorp OS

---

## 1. สถานะปัจจุบัน

| Environment | Suite | หมายเหตุ |
|---|---|---|
| Fresh DB (isolated stack) | **440/440** | base schema + migrations 001–073b ครบ |
| Shared production DB | 415–437/440 (ผันได้) | ผลลัพธ์ขึ้นกับ test artifacts ที่ค้างใน DB |

**บทเรียนสำคัญ:** suite นี้ถูกออกแบบให้รันบน DB สะอาด (ตาม CI flow) — การรันซ้ำบน shared DB
ที่ไม่เคย reset ทำให้เกิด false failures จาก state ตกค้าง ไม่ใช่ code bug

---

## 2. วิธีรัน tests ให้เสถียร

### 2.1 รันบน isolated stack (แนะนำ — ผลนิ่ง 440/440)

```bash
cd code

# spin ชุดทดสอบแยก (web :8090, mysql :3308, volume pos-test_db_data)
docker compose -p pos-test -f docker-compose.test.yml up -d --build

# รอ healthy แล้วรัน suite ทั้งหมด
API_BASE=http://localhost:8090/api/index.php bash tests/api/run.sh

# เฉพาะกลุ่ม
API_BASE=http://localhost:8090/api/index.php bash tests/api/run.sh cash_sessions stock_transfers

# teardown (ปลอดภัย — ลบเฉพาะ project pos-test, ไม่กระทบ production)
docker compose -p pos-test -f docker-compose.test.yml down -v --remove-orphans
sudo rm -rf uploads-test   # หรือ: docker run --rm -v "$(pwd)/uploads-test:/t" alpine rm -rf /t/*
```

จุดสำคัญของ `docker-compose.test.yml`:
- `-p pos-test` → container/volume/network แยกทั้งชุด (`pos-test_db_data` สร้างใหม่เสมอ)
- web bind-mount **โค้ดชุดเดียวกับ production** → ทดสอบโค้ดจริงที่จะ deploy
- uploads ชี้ไป `./uploads-test` ไม่ใช่ `./uploads` → ภาพทดสอบไม่ปนไฟล์จริง
- `APP_ENV=development`

⚠️ **ห้าม** รัน `down -v` ด้วย project name อื่นที่ไม่ใช่ `pos-test` — production ใช้ default project
(ตาม directory name) และมี volume `db_data` ของจริง

### 2.2 รันบน production DB (เมื่อจำเป็น)

```bash
bash tests/api/run.sh          # ใช้ http://localhost:8080 โปร่งๆ
```

- คาดหวัง fail ได้ในกลุ่ม cash position/close ถ้า branch 3–6 มี session/baseline ตกค้าง
- ก่อนรับผล ให้เคลียร์ test artifacts ก่อน:
  ```bash
  bash scripts/reset-test-cash-state.sh <branch_id>            # ปิด session ค้างแบบ audit-correct
  bash scripts/reset-test-cash-state.sh <branch_id> --purge    # ลบเฉพาะ rows ที่ marking เป็น test artifact
  ```
- **ห้ามรัน parallel กับการใช้งานจริง** — tests สร้าง/แก้ข้อมูลจริงใน DB (sellers, POs, sessions)

---

## 3. Isolation Strategy ที่แนะนำ (go-forward)

1. **Default = isolated stack** ทุกครั้งที่แก้โค้ด/ก่อน PR (เร็วพอ, ~6–8 นาที)
2. Production DB ใช้ "smoke" เท่านั้น: `run.sh auth branches` (กลุ่มที่ read-only และ idempotent)
3. พิจารณาเพิ่ม `make test-fresh` / script wrapper ที่ทำ up→wait→run→teardown ให้อัตโนมัติ
4. CI (.github/workflows/test.yml) อยู่แล้วบน schema สะอาด — ให้เป็น gate หลักก่อน merge

## 4. Deploy Checklist — Migration 073/073b (catalog_id stock identity)

> ⚠️ อัปเดตสำคัญ: **073 และ 073b ถูก apply บน production DB แล้ว** (2026-08-25 23:42,
> ตรวจจาก `schema_migrations` + information_schema — columns catalog_id ครบ 3 ตาราง)
> ขั้นตอน migrate จึง**ไม่ต้องทำซ้ำ** — checklist นี้ใช้ verify แทน

1. [ ] Backup DB: `docker exec scrap-pos-db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" pos_system' > backup_$(date +%F).sql`
2. [ ] Verify migration applied: `SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 2;` → ต้องเห็น 073b, 073
3. [ ] Verify columns: `information_schema.COLUMNS WHERE COLUMN_NAME='catalog_id'` → branch_stock, purchase_order_items, stock_transfer_items
4. [ ] Review unmatched backfill: `SELECT COUNT(*) FROM branch_stock WHERE catalog_id IS NULL;` (ปัจจุบัน 52 rows)
       → rows เหล่านี้ต้อง map ชื่อ manual หรือปล่อย NULL ชั่วคราว (migration ออกแบบไว้แบบนั้น)
5. [ ] Full suite ผ่าน 440/440 บน isolated stack กับโค้ด commit ที่จะ deploy
6. [ ] Restart web container เพื่อ clear opcache: `docker compose restart web` *(ต้องอนุมัติจาก CEO)*
7. [ ] Health check: `curl -s http://localhost:8080/api/index.php/auth/verify` + login จริง 1 รอบ
8. [ ] Rollback plan: code revert ปลอดภัย (columns nullable, code รองรับ NULL); ไม่ต้อง rollback DB

## 5. ข้อจำกัด / ความเสี่ยงที่รู้อยู่

- Branches 3–6 บน production เป็น **test branches** ("Test Branch", "Safe Test", "Ex-A", "Ex-B")
  พร้อม baseline/session/movements ปนเปื้อน — ต้องเก็บกวาดหรือ deprecate ก่อน go-live จริง
- Real branches (1 สาขา 1, 2 สาขาบ้านตะบัล) **ยังไม่มี cash_position_baselines** →
  ต้อง initialize ยอดตั้งต้นจริง (ผ่าน owner approval flow) ก่อนเปิดใช้ WF-06 v2.1
- User `manager-br4` (id=39, ไม่มีเลขศูนย์) บน production เป็น artifact — seed จริงคือ
  `manager-br01..04`; พิจารณาลบตอนเก็บกวาด
