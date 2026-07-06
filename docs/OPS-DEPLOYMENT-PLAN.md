# OPERATIONS PLAN — scrap-pos DEPLOYMENT
**จัดทำโดย:** Operations Head — SoloCorp OS
**วันที่:** 6 กรกฎาคม 2569 | **เวอร์ชัน:** 1.0

---

## 1. PRE-LAUNCH OPERATIONS CHECKLIST

### สัปดาห์ที่ 1 — ปิด Blocker (วันที่ 7-13 ก.ค.)

**วันที่ 1-2 (Critical)**

- [ ] รัน G3 Categories SQL migration บน staging ก่อน → production
- [ ] บันทึก timestamp + ผู้รัน ทุก migration (หลักฐานทางกฎหมาย ม.357)
- [ ] เปลี่ยน default admin/admin password ทุกสาขา (password ต่างกัน, เก็บใน password manager)
- [ ] ตรวจสอบ `.env` ว่าไม่มี credential ใน git repository

**วันที่ 3-5 (Infrastructure)**

- [ ] ติดตั้ง SSL/TLS certificate — Let's Encrypt หรือ Cloudflare SSL
- [ ] ทดสอบ `https://` ใช้งานได้ + redirect HTTP → HTTPS อัตโนมัติ
- [ ] ตั้งค่า Docker Compose production — resource limits + `restart: unless-stopped`
- [ ] ทดสอบ backup/restore ครบ 1 รอบ ก่อน deploy จริง

**วันที่ 6-7 (Pre-Deploy Validation)**

- [ ] รัน full test suite — ต้องผ่านทั้งหมด
- [ ] ทดสอบ workflow หลัก: PO → รับซื้อ → ชั่งน้ำหนัก → จ่ายเงิน
- [ ] ตรวจสอบ G5 transport cost — ถ้ายังไม่มี ต้อง block lot ใหม่จนกว่าจะแก้

### สัปดาห์ที่ 2 — Operational Readiness (วันที่ 14-20 ก.ค.)

- [ ] Excel migration tool พร้อม + ทดสอบกับข้อมูลจริงจาก 1 สาขา
- [ ] Runbook เสร็จ: startup, shutdown, backup, restore, troubleshooting
- [ ] Staff training เสร็จสาขานำร่อง
- [ ] Monitoring + alerting ตั้งค่าเสร็จ
- [ ] Go/No-Go review ก่อน deploy production

---

## 2. DATA MIGRATION PLAN

**ระยะ A — Data Collection (3-5 วัน ก่อน deploy แต่ละสาขา)**
- ส่ง template Excel มาตรฐานให้ผจก.สาขากรอก
- ฟิลด์บังคับ: ชื่อ-นามสกุล, เลขบัตรประชาชน 13 หลัก, เบอร์โทร, ที่อยู่
- ส่งไฟล์กลับผ่านช่องทางที่ปลอดภัย (ไม่ใช่ LINE แบบ unsecured)

**ระยะ B — Data Validation (1-2 วัน)**
- เลขบัตรประชาชน: 13 หลัก, checksum ถูก, ไม่ซ้ำ
- เบอร์โทร: format ไทย (0xx-xxx-xxxx)
- ชื่อ: encoding UTF-8 ถูกต้อง

**ระยะ C — Migration Execution**
1. Export validation report → ผจก.สาขา approve
2. รัน dry-run (ไม่ commit) บน staging
3. รัน จริงบน production
4. Verify: count rows ที่ import vs ต้นฉบับ
5. Spot check: สุ่ม 10 records เทียบกับ Excel

**ระยะ D — Post-Migration Verification (1 วัน)**
- ผจก.สาขา search seller 5 คนที่รู้จัก — ยืนยันข้อมูลถูก
- ทดสอบ PO workflow กับ seller ที่ migrate แล้ว
- เก็บ backup Excel ต้นฉบับไว้ตลอดไป (หลักฐาน)

### Timeline Data Migration

| สาขา | Collection | Validation | Migration | Go-Live |
|------|-----------|------------|-----------|---------|
| สาขานำร่อง | 14-16 ก.ค. | 17-18 ก.ค. | 19 ก.ค. | 21 ก.ค. |
| สาขา 2 | 21-23 ก.ค. | 24 ก.ค. | 25 ก.ค. | 28 ก.ค. |
| สาขา 3 | 28-30 ก.ค. | 31 ก.ค. | 1 ส.ค. | 4 ส.ค. |
| สาขา 4 | 4-6 ส.ค. | 7 ส.ค. | 8 ส.ค. | 11 ส.ค. |

---

## 3. STAFF TRAINING PLAN

| บทบาท | รูปแบบ | เวลา |
|-------|--------|------|
| แคชเชียร์ | Hands-on workshop | ครึ่งวัน (4 ชม.) |
| ผู้จัดการสาขา | Workshop + admin training | เต็มวัน (8 ชม.) |
| เจ้าของ/ผู้ดูแลระบบ | Full system training | 1 วัน |

**Module 1 — แคชเชียร์ (4 ชั่วโมง)**
- ชม. 1: Login + Navigation
- ชม. 2: รับซื้อ (PO) ตั้งแต่ต้นจนจบ
- ชม. 3: ฝึกปฏิบัติ simulation
- ชม. 4: Q&A + แจก Quick Reference Card (A5 กันน้ำ)
- ทดสอบ 10 ข้อ — ต้องผ่าน 8/10

**Module 2 — ผจก.สาขา (8 ชั่วโมง)**
- เช้า: Module 1 ครบ
- บ่าย: Reports + Financial Summary, Expense Management, Troubleshooting

### Timeline อบรม

```
วันที่ 17-18 ก.ค.: Train the Trainer (SoloCorp team)
วันที่ 19-20 ก.ค.: สาขานำร่อง — full training + เก็บ feedback
วันที่ 25-26 ก.ค.: สาขา 2
วันที่ 1-2 ส.ค.:   สาขา 3
วันที่ 7-8 ส.ค.:   สาขา 4
```

---

## 4. MONITORING & SUPPORT PLAN

### Monitoring Stack

**Infrastructure:** UptimeRobot (free tier)
- Health check ทุก 5 นาที: `GET /api/health`
- Alert ทาง: Line Notify + Email
- Target uptime: 99.5%

**Application Alerts:**
- Login failed > 5 ครั้ง/นาที (brute force)
- Disk usage > 80%
- DB connection error
- API response time > 5 วินาที

### Backup Strategy

| ประเภท | ความถี่ | เก็บไว้ | ที่เก็บ |
|--------|---------|--------|--------|
| Full DB | ทุกวัน 02:00 | 7 วัน | local + Google Drive |
| Incremental | ทุก 4 ชม. | 24 ชม. | local |
| File uploads | ทุกวัน 03:00 | 30 วัน | cloud |

ทดสอบ restore **ทุกเดือน** — บันทึกผล + เวลาที่ใช้

### Support Tiers

| Tier | ปัญหา | Response | ช่องทาง |
|------|-------|----------|---------|
| 1 — ผจก.สาขา | Login ไม่ได้, cache | ทันที | Quick Reference Card |
| 2 — LINE Group | Bug ไม่ร้ายแรง, คำถาม | 4 ชม. (เวลาทำการ) | Line Notify → IT on-call |
| 3 — Emergency | ระบบ down, ข้อมูลหาย, Security | 1 ชม. | โทรตรง IT lead |

---

## 5. ROLLOUT STRATEGY — Phased (ทีละสาขา)

**ทำไมไม่ deploy all-at-once:**
1. Risk Isolation — bug กระทบแค่ 25% ไม่ใช่ 100%
2. Learning Curve — สาขาแรกพบปัญหาที่คาดไม่ถึงเสมอ
3. Migration Tool ยังไม่พิสูจน์ตัวเอง — pilot ก่อน
4. ถ้ามีปัญหา legal compliance + deploy 4 สาขาพร้อมกัน = ความเสี่ยงคูณ 4

### Pilot สาขานำร่อง

เลือกเกณฑ์: ปริมาณ seller น้อยที่สุด + ผจก.เปิดรับ tech

Go/No-Go หลัง 7 วัน:
- ถ้าปัญหา < 5 tickets และ severity ต่ำ → ดำเนินการสาขาถัดไป
- ถ้าปัญหา critical → หยุด, แก้ไข, นัด re-review

### Rollback Plan

1. ผจก.สาขา: กลับใช้ Excel ชั่วคราว
2. IT: restore DB จาก backup ก่อน migration
3. Export ข้อมูลใหม่ก่อน rollback
4. Notify เจ้าของ + กำหนดวัน re-deploy

```bash
# เก็บ image เก่าไว้เสมอ
docker tag app:current app:rollback-YYYYMMDD
```

---

## SUMMARY TIMELINE

```
สัปดาห์ 1 (7-13 ก.ค.):  ปิด critical blockers
สัปดาห์ 2 (14-20 ก.ค.): Migration tool, training prep, staging
สัปดาห์ 3 (21-27 ก.ค.): PILOT — สาขานำร่อง go-live
สัปดาห์ 4 (28 ก.ค.):    สาขา 2
สัปดาห์ 5 (4 ส.ค.):     สาขา 3
สัปดาห์ 6 (11 ส.ค.):    สาขา 4
สัปดาห์ 7 (18 ส.ค.):    Post-launch stabilization
```

**รวม: ~7 สัปดาห์** จากวันนี้ถึง full deployment ทั้ง 4 สาขา
