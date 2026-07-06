> **⚠️ Timeline ถูก supersede แล้ว** — ดูแผนใหม่: [`docs/OPS-DEPLOYMENT-PLAN.md`](OPS-DEPLOYMENT-PLAN.md)
> สถานะ: **NO-GO** จนกว่า 3 blockers จะปิด (G3 SQL, admin/admin, HTTPS)
> Exec Council กำหนด phased 7-week rollout: สาขาแรก 21 ก.ค. → สาขา 4 11 ส.ค.

# 🚀 LAUNCH DAY: Day 5 — Go-Live

**วันที่:** กำหนดตามตาราง (แนะนำ: พุธ/พฤหัส — มีวันถัดไปสำหรับ fix hot issues)  
**ทีมที่เกี่ยวข้อง:** DevOps + Backend Security + PHP Full-stack + UX/Frontend + Architect + เจ้าของร้าน  
**บทบาท Architect วันนี้:** Commander — ทุกคำสั่งผ่าน Architect เท่านั้น

---

## Pre-condition (ต้องได้จาก Day 4 ก่อนเริ่ม)

```
□ Tablet responsive — purchase-orders, sale-lots, inventory ใช้ได้
□ Error pages — 404, 500, 401 expired message
□ Loading states — skeleton, spinner, save button feedback
□ Remaining pages — inventory, stock-transfers, expenses, financial-summary, etc.
□ Offline detection — banner + timeout
□ UX consistency — modal, notification, sidebar, badges
```

**❌ ถ้ายังไม่ผ่าน Architect Assessment → เลื่อน Launch 1 วัน, อย่าเสี่ยง deploy**

---

## ภารกิจหลัก

**ทำให้ระบบเปิดใช้งานจริงได้ โดยไม่ต้อง rollback ใน 24 ชม. แรก**

---

## 🕐 Timeline (ตามเวลาจริง)

```
08:00 — 08:30  Team standup + Final briefing
08:30 — 10:00  Full integration test (ทุก business flow)
10:00 — 11:00  Staff training walkthrough (เจ้าของร้าน + พนักงาน 1-2 คน)
11:00 — 11:30  Fix critical issues จาก training
11:30 — 12:00  Pre-flight: DB backup + migration check
12:00 — 13:00  Lunch break (DB backup ควรเสร็จ)
13:00 — 14:00  Production deploy
14:00 — 15:00  Monitor + Hotfix window
15:00 — 15:30  GO-LIVE ✅
15:30 — 17:00  Live monitor + Support
17:00          Day 5 ends — Handoff to operations
```

---

## Phase 1: Final Integration Test (08:00-10:00)

### Scope: Test ทุก Business Flow ที่สำคัญ

ให้ทีมแยกกันเดิน test ตาม role:

### Flow A: รับซื้อของ (เจ้าของร้าน + พนักงาน)

```
[ ] login → หน้า dashboard → stat cards โหลด
[ ] เลือกสาขา → รับซื้อของ
[ ] ค้นหาผู้ขาย → select → แสดงข้อมูล
[ ] เลือก tier (บิล1/2/3)
[ ] พิมพ์รหัสสินค้า → ชื่อ + หมวดหมู่ + ราคา auto-fill
[ ] กรอกน้ำหนัก 100 กก.
[ ] กรอกหักน้ำหนัก 2 กก.
[ ] ราคารวมคำนวณอัตโนมัติ
[ ] [+ เพิ่ม] → Cart มีรายการ
[ ] ถ่ายรูปสินค้า (📷) → รูปแสดงใน cart
[ ] เพิ่ม 3 รายการ → Cart รวมยอดถูกต้อง
[ ] เลือกสินค้าเสี่ยง (เช่น ทองแดง) → แจ้งเตือน
[ ] ถ่ายรูปบัตรประชาชน
[ ] เซ็นรับรอง (Signature pad)
[ ] บันทึก PO → receipt modal → success
[ ] QR code สำหรับถ่ายรูปหลังบันทึก
[ ] Recent POs มี PO ใหม่
[ ] ตรวจสอบ activity_log → มี record PO ใหม่
```

### Flow B: ขาย Lot (แคชเชียร์)

```
[ ] หน้า sale-lots → list โหลด
[ ] กด + ขาย Lot → modal
[ ] เลือกสาขา
[ ] เพิ่มทองแดงน้ำหนัก 50 กก. ราคา 180 บาท
[ ] กรอกค่าขนส่ง 500 บาท
[ ] ยอดรวม subtotal ถูกต้อง
[ ] transport cost แสดง
[ ] profit สุทธิแสดง (รวม transport)
[ ] บันทึก → Lot ใน list
[ ] กดยืนยัน → consumed_qty อัปเดต
[ ] ตรวจสอบ inventory → stock ลด
```

### Flow C: รายงาน + สรุปการเงิน (เจ้าของร้าน)

```
[ ] Dashboard → stat cards, charts โหลด
[ ] Reports → purchase report, sale lot report
[ ] Financial summary → revenue, expenses, purchase total
[ ] Export CSV (ถ้ามี)
[ ] ค่าใช้จ่าย → เพิ่ม/ลบ/ดู
[ ] ดู payroll expense จาก employee module
```

### Flow D: จัดการระบบ (Admin)

```
[ ] สร้างพนักงาน
[ ] จ่ายเงินเดือน
[ ] ดู activity log
[ ] Backup → create → download (verify file)
```

### ผลลัพธ์ Phase 1:
```
□ Flow A (PO): PASS / FAIL
□ Flow B (Lot): PASS / FAIL
□ Flow C (Report): PASS / FAIL
□ Flow D (Admin): PASS / FAIL
```
**FAIL 1 ข้อ → หยุด, แจ้ง Architect — ก่อน training**

---

## Phase 2: Staff Training Walkthrough (10:00-11:00)

### Setup

```bash
# เปิด production server (หรือ staging ถ้า production ยังไม่พร้อม)
# ให้พนักงานใช้ tablet จริง (ไม่ใช่ browser simulator)
# เปิด DevTools console log เพื่อดู error ที่เกิดขึ้นจริง
```

### Training Topics

| เวลา | หัวข้อ | ใครสอน |
|------|--------|--------|
| 10:00-10:10 | Login + Dashboard | Architect |
| 10:10-10:30 | **รับซื้อของ** (สำคัญที่สุด) | Architect |
| 10:30-10:40 | ขาย Lot | PHP Full-stack |
| 10:40-10:50 | Employee + Expenses | PHP Full-stack |
| 10:50-11:00 | Q&A + เจ้าของร้านลองทำเอง | ทั้งทีม |

### สิ่งที่ต้องสังเกตระหว่าง Training

```
[ ] พนักงานกดถูกต้องตาม flow หรือไม่?
[ ] มี step ไหนที่พนักงานสะดุด / งง / ถามซ้ำ?
[ ] มี error ใน console log ไหม?
[ ] เจ้าของร้านพอใจกับ UX หรือไม่?
[ ] มี feature ไหนที่ต้องใช้จริงแต่ยังไม่มี?
```

**ถ้าเจอ Critical Issue → Record + ประเมินว่าต้อง fix ก่อน deploy หรือ post-launch**

---

## Phase 3: Pre-flight (11:30-12:00)

### 3.1 Production DB Backup

```bash
#!/bin/bash
# ===== Pre-flight Check =====
echo "=== Pre-flight Checks ==="

# DB Backup
docker compose exec -T db mysqldump -u root -p"${MYSQL_ROOT_PASSWORD}" \
  --all-databases --single-transaction --routines --triggers \
  > "data/backups/pre-launch-$(date +%Y%m%d-%H%M%S).sql"

# Verify backup
BACKUP_FILE=$(ls -t data/backups/pre-launch-*.sql | head -1)
echo "Backup: $BACKUP_FILE ($(du -h "$BACKUP_FILE" | cut -f1))"
gzip "$BACKUP_FILE"
```

### 3.2 Migration Check

```bash
# Check applied migrations
docker compose exec db mysql -u root -p"${MYSQL_ROOT_PASSWORD}" pos_system \
  -e "SELECT migration FROM schema_migrations ORDER BY migration;"
# EXPECTED: 001-041 ทั้งหมด
```

### 3.3 Server Health

```bash
# Resource check
echo "RAM:  $(free -h | awk '/^Mem:/{print $3 "/" $2}')"
echo "DISK: $(df -h . | awk 'NR==2{print $3 "/" $2}')"
echo "UPTIME: $(uptime -p)"
echo "LOAD:  $(uptime | awk -F'load average:' '{print $2}')"

# Docker health
docker compose ps
# EXPECTED: ทุก container "Up"
```

### 3.4 Rollback Readiness

```bash
# ตรวจสอบว่า deploy.sh rollback mechanism พร้อม
# git status → clean (ไม่มี uncommitted changes ที่ไม่ตั้งใจ)
git status

# เตรียม rollback script:
cat > /tmp/rollback.sh << 'ROLLBACK'
#!/bin/bash
# Emergency Rollback
echo "=== EMERGENCY ROLLBACK ==="
cd /home/drsolodev/projects/scrap-pos/code
docker compose down
# Restore from latest backup
gunzip -k data/backups/pre-launch-*.sql.gz
mysql -u root -p${MYSQL_ROOT_PASSWORD} pos_system < data/backups/pre-launch-*.sql
docker compose up -d
echo "Rollback complete."
ROLLBACK
chmod +x /tmp/rollback.sh
echo "Rollback script ready at /tmp/rollback.sh"
```

### Pre-flight Checklist

```
[ ] 1. DB backup สำเร็จ — ไฟล์ gzip ไม่ใช่ 0 byte
[ ] 2. schema_migrations ครบ 41
[ ] 3. RAM ไม่เกิน 80%
[ ] 4. DISK เหลือ > 5 GB
[ ] 5. docker compose ps → ทุก container healthy
[ ] 6. git status clean (commit ทุกอย่าง)
[ ] 7. Rollback script พร้อม
[ ] 8. เจ้าของร้านรับทราบกำหนดการ (deploy 13:00, go-live 15:00)
```

---

## Phase 4: Production Deploy (13:00-14:00)

### Execute

```bash
# ทุกคนหยุดทำงาน — Focus mode
# เปิด Terminal 1: Monitor
# เปิด Terminal 2: Execute

# Terminal 2:
cd /home/drsolodev/projects/scrap-pos/code
bash deploy.sh

# ถ้า deploy.sh → "✅ Deploy successful!" → proceed
# ถ้า fail → run /tmp/rollback.sh → แจ้ง Architect
```

### Post-deploy Verification

```bash
# Terminal 1 (Monitor):
echo "=== Post-Deploy Health Check ==="

# 1. Container status
docker compose ps

# 2. API health
curl -s -o /dev/null -w "API: HTTP %{http_code}\n" https://domain.com/api/index.php/auth/verify

# 3. Login test
TOKEN=$(curl -s -X POST https://domain.com/api/index.php/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin).get('data',{}).get('token','FAIL'))")
echo "Auth token: ${TOKEN:0:20}..."

# 4. Database connection test
docker compose exec db mysqladmin ping -u root -p"${MYSQL_ROOT_PASSWORD}" 2>/dev/null

# 5. Error log check
docker compose logs web --since 1m | grep -c "FATAL\|ERROR"
# EXPECTED: 0

# 6. Upload directory writable
docker compose exec web touch /var/www/html/uploads/health-check.txt && echo "Uploads: writable ✓"
```

### Post-deploy Checklist (15 นาทีหลัง deploy)

```
[ ] docker compose ps → Up (healthy)
[ ] API → 200 OK
[ ] Login → token ได้
[ ] DB → ping OK
[ ] Error log → 0 (อนุโลม warning ที่เกิดจาก browser prefetch/preconnect)
[ ] Uploads → writable
[ ] HTTPS → valid cert, ไม่มี warning
[ ] Page load → dashboard, purchase-orders, sale-lots — 200 OK
[ ] สามารถเข้าหน้า employees.html (G4) ได้
```

---

## Phase 5: GO-LIVE (15:00)

### Go-Live Ceremony

```bash
echo "🚀 GO-LIVE: รักษ์สะอาดรีไซเคิล POS System"
echo "เวลา: $(date '+%H:%M %d/%m/%Y')"
echo "เวอร์ชัน: $(git log --oneline -1)"
echo ""

# แสดง Server Info
echo "Server:    $(curl -s ifconfig.me 2>/dev/null || echo 'N/A')"
echo "API:       https://domain.com/api/index.php/auth/verify"
echo "Admin:     https://domain.com/admin/"
```

### ทีมต้องทำหลัง Go-Live

| บทบาท | หน้าที่ |
|--------|--------|
| **Architect** | สังเกตการณ์, ตัดสินใจ hotfix/rollback |
| **DevOps** | Monitor server (CPU, RAM, Disk, Error log) |
| **PHP Full-stack** | Support พนักงานหน้าร้าน (ถ้ามีปัญหา) |
| **UX/Frontend** | Monitor console error + UX feedback |
| **เจ้าของร้าน** | ลองทำ PO จริงกับของจริง (ถ้ามีลูกค้า) |

### Monitor Dashboard (เปิดไว้ทุกจอ)

```bash
# Terminal 1: API error log
docker compose logs web -f --tail 50 | grep -E "FATAL|ERROR|CRITICAL"

# Terminal 2: Container health
watch -n 10 'docker compose ps && echo "---" && df -h . && echo "---" && free -h'

# Terminal 3: Web access log (ดู request pattern)
docker compose logs web -f --tail 100 | grep -E "GET /api|POST /api"
```

---

## Phase 6: Live Monitor + Support (15:00-17:00)

### Hotfix Policy

| Severity | คำจำกัดความ | Action |
|----------|-------------|--------|
| **Critical** | ระบบรับซื้อของใช้ไม่ได้, ข้อมูลสูญหาย, เงินผิดพลาด | หยุดทุกอย่าง, Hotfix ทันที, Rollback ได้ |
| **High** | ระบบทำงานได้แต่ UX พัง, หน้าสำคัญ error | Fix ถ้าทำได้ใน 30 นาที, ถ้าไม่ → Post-launch |
| **Medium** | ฟีเจอร์ไม่ critical (report, export) มีปัญหา | Post-launch |
| **Low** | สะกดผิด, สีผิด, alignment | Post-launch |

### Communication

```
ทุกการตัดสินใจ hotfix/rollback → ผ่าน Architect เท่านั้น
แจ้งเจ้าของร้านทุกครั้งที่มีการเปลี่ยนแปลง production
```

---

## Phase 7: End of Day (17:00)

### Day 5 Close-out

```bash
# สรุป production state
echo "=== Day 5 Close-out ==="
echo "Uptime: $(docker compose ps --format '{{.Names}} {{.Status}}')"
echo "Error count (last 2h): $(docker compose logs web --since 2h | grep -c 'FATAL\|ERROR')"
echo "API calls (last 2h): $(docker compose logs web --since 2h | grep -c '/api/index.php')"
echo "Backup file: $(ls -lh data/backups/post-launch-*.sql.gz 2>/dev/null || echo 'N/A')"

# Final backup
docker compose exec -T db mysqldump -u root -p"${MYSQL_ROOT_PASSWORD}" \
  --all-databases --single-transaction \
  > "data/backups/post-launch-$(date +%Y%m%d-%H%M%S).sql"
gzip data/backups/post-launch-*.sql
```

### Handoff Document

```
วันที่ launch: _______________
เวลา go-live: _______________
ใคร deploy: ________________
Version (git commit): _______________

เปิดใช้งานแล้ว:
  [ ] Purchase Orders (รับซื้อของ)
  [ ] Sale Lots (ขาย Lot)
  [ ] Inventory (สินค้าคงคลัง)
  [ ] Price Board (บอร์ดราคา)
  [ ] Employees (พนักงาน)
  [ ] Expenses (ค่าใช้จ่าย)
  [ ] Financial Summary (สรุปธุรกิจ)
  [ ] Stock Transfers (โอนสต็อก)
  [ ] Reports (รายงาน)
  [ ] Dashboard (หน้าหลัก)

Known Issues (Post-launch):
  1. _________________________________
  2. _________________________________
  3. _________________________________

Post-launch priority:
  P0: _________________________________
  P1: _________________________________
  P2: _________________________________

Signature (เจ้าของร้าน): _______________
Signature (Architect): ________________
```

---

## 🎯 Definition of Done สำหรับ Launch Day (Day 5)

> **"ระบบเปิดใช้งานจริง, พนักงานใช้รับซื้อของจริงได้, ไม่มี Critical Error ใน 2 ชม. แรก, มี backup + rollback plan พร้อม, เจ้าของร้านเซ็นรับมอบ — โปรเจกต์ Production สำเร็จ"**

---

## ⛔ สิ่งที่ห้ามทำใน Launch Day

| ข้อห้าม | เพราะ |
|---------|-------|
| ❌ ห้าม deploy โดยไม่ pre-flight check | เสี่ยง rollback |
| ❌ ห้ามแก้ code ใน production โดยไม่ผ่าน Architect | uncontrolled risk |
| ❌ ห้ามเพิ่ม feature ใหม่ | freeze — แก้ bug เท่านั้น |
| ❌ ห้ามลืมแจ้งเจ้าของร้าน | transparency |
| ❌ ห้าม panic — ถ้าไม่แน่ใจ → rollback | safety first |

---

## 🏁 5-Day Production Launch: Summary

| Day | ทีม | ภารกิจ |
|-----|-----|--------|
| **Day 1** | DevOps | Server, SSL, Docker, Migration fix, deploy.sh |
| **Day 2** | Backend Security | Error leak, Cart state, Idempotency, Rate limit |
| **Day 3** | PHP Full-stack | Purchase form, FIFO test, Employee, Transport cost |
| **Day 4** | UX/Frontend | Responsive, Error pages, Loading, Polish, Offline |
| **Day 5** | **All Teams** | Integration test, Training, Deploy, Go-live, Monitor |

---

*Document version: 1.0 | 2026-07-03 | System Architect*
