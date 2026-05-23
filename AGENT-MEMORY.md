# 🤖 Agent Memory — Secondhand POS Project
**สำหรับ Claude session ถัดไปอ่านเพื่อทำงานต่อ**

**Last updated:** 19 พฤษภาคม 2569
**Project status:** ✅ ปิดดีลแล้ว — เริ่มพัฒนา

---

## 🎯 สรุปสำคัญที่สุด

### Deal Closed
- **วันที่ปิดดีล:** 18 พฤษภาคม 2569
- **ราคา:** **40,000 บาท** (สูงกว่าราคาตั้ง 35,000 → ลูกค้าจ่ายเพิ่มเอง 5,000)
- **ลูกค้า:** ร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์
- **Pattern ที่ทำให้ปิดดีลได้:** ฟังปัญหาก่อน + เปิด demo ฟรี 7 วัน + เน้นไปหน้างานจริง 4 สาขา + เทรนพนักงานถึงที่

### Context สำคัญที่ต้องจำ
- **ลูกค้าโดนทิ้งงานมาแล้ว 2 ครั้ง** (เสียไป 27,000 บาท) → trust สำคัญที่สุด
- **mindset ของ Dr.Solodev:** "ขายผลงาน ไม่ได้ขายวิญญาณ" — ห้ามอาสาลดราคาเอง
- **ลูกค้าอยู่สุรินทร์เหมือน Dr.Solodev** — จุดขายหลักคือไปหน้างานได้

---

## 📋 Scope งาน (ตามที่ตกลง)

### ระบบหลัก
- POS ขายหน้าร้าน + พิมพ์ใบเสร็จ
- ระบบรับซื้อของเก่า (Purchase Orders) — มีรูปถ่าย, สภาพของ, ต่อรองราคา
- ระบบสต็อกสินค้า แยก 4 สาขา + ดูรวมจากที่เดียว
- ระบบผู้ขาย (Sellers) — เก็บบัตรประชาชนตามกฎหมาย
- รายงานเฉพาะธุรกิจของเก่า — กำไรต่อชิ้น, สินค้าค้างนาน, เปรียบเทียบสาขา
- ระบบ Hybrid Online + Offline

### บริการพิเศษที่รวมในราคา
- ✅ เดินทางสำรวจหน้างานทั้ง 4 สาขา
- ✅ เทรนพนักงานถึงที่ทุกสาขา
- ✅ Demo ทดลองใช้ฟรี 7 วัน
- ✅ Warranty 60 วัน
- ✅ Support ผ่าน LINE/โทร

### Timeline
- **8-12 สัปดาห์** แบ่งส่งมอบ 4 รอบ
- **ชำระ 4 งวด:** 30/25/25/20%

### บริการหลังขาย
- ค่าดูแลรายเดือน 500 บาท (รวม backup, support, แก้บั๊ก)
- ค่าฟีเจอร์ใหม่รายครั้ง 500 - 5,000 บาท

---

## 🛠️ Technical Stack

### ฐาน
- **Repo:** `goragodwiriya/pos-system` (PHP + Vanilla JS + MySQL)
- **PHP 8.2** + Apache + MySQL 8.0
- รัน Docker Compose ทั้งหมด

### Customizations Strategy
- **ไม่แก้ไข `base-pos/` โดยตรง** ยกเว้นจำเป็น (ตอนนี้แก้แค่ `config.php` ให้อ่าน env)
- ของใหม่ทั้งหมดอยู่ใน `customizations/`
- Migration SQL แยกไฟล์ เรียงตามลำดับ (001, 002, ...)

---

## 📁 ที่อยู่ของไฟล์ทั้งหมด

### เอกสาร (Project Docs)
**Path หลัก:** `/home/drsolodev/projects/secondhand-pos/`
**Mirror:** `/home/drsolodev/.openclaw/workspace/projects/secondhand-pos/`

| ไฟล์ | เนื้อหา |
|---|---|
| `01-project-brief.md` | ภาพรวมโปรเจกต์ |
| `02-quotation-v3.md` | ใบเสนอราคา 35,000 (เก่า — ต้องอัปเดตเป็น v4 = 40,000) |
| `03-wireframe-purchase.md` | wireframe หน้ารับซื้อ |
| `04-client-questions.md` | คำถาม 7 ข้อสำหรับคุยลูกค้า |
| `05-research-report.md` | วิเคราะห์ goragodwiriya/pos-system |
| `06-implementation-roadmap.md` | แผนพัฒนา 4 phases |
| `06-db-migration-plan.md` | แผน DB migration |
| `07-executive-summary.md` | สรุปผู้บริหาร |
| `08-contract-template.md` | สัญญาจ้าง (ราคาเก่า — ต้องอัปเดต) |
| `09-meeting-checklist.md` | Checklist ก่อนประชุม |

### Code Project
**Path:** `/home/drsolodev/projects/secondhand-pos/code/`

```
code/
├── base-pos/                              # cloned goragodwiriya/pos-system (ห้ามแก้ตรงๆ)
├── customizations/
│   ├── api/Models/Branch.php              # ✅ เขียนเสร็จแล้ว
│   └── database/migrations/
│       ├── 001_add_branches.sql           # ✅ Multi-branch
│       ├── 002_add_sellers.sql            # ✅ ผู้ขาย + บัตร ปชช
│       ├── 003_add_item_conditions.sql    # ✅ ดี/พอใช้/ชำรุด
│       ├── 004_add_purchase_orders.sql    # ✅ ใบรับซื้อ
│       └── 005_seed_categories.sql        # ✅ หมวดสินค้าเริ่มต้น
├── docker/
│   ├── Dockerfile.php                     # PHP 8.2 + Apache
│   ├── apache-config.conf
│   └── mysql-init.sh                      # auto-run migrations
├── docker-compose.yml                     # web:8080, pma:8081, db:3307
└── README.md                              # setup guide
```

### Memory ใน Claude Memory System
- `/home/drsolodev/.claude/projects/-home-drsolodev/memory/project_secondhand_pos.md`
- `/home/drsolodev/.claude/projects/-home-drsolodev/memory/MEMORY.md` (index)

---

## ✅ สิ่งที่ทำเสร็จแล้ว

1. ✅ Research repo goragodwiriya/pos-system อย่างละเอียด (จาก OpenClaw)
2. ✅ สร้างเอกสาร 11 ไฟล์ (brief, quotation, wireframe, contract, ฯลฯ)
3. ✅ Clone base-pos
4. ✅ สร้าง folder structure สำหรับ customization
5. ✅ เขียน 5 SQL migrations
6. ✅ เขียน Branch.php Model
7. ✅ Setup Docker Compose (web + db + phpmyadmin)
8. ✅ แก้ `base-pos/api/config.php` ให้อ่าน env vars
9. ✅ Demo รันได้จริงบน localhost:8080
10. ✅ Database มีตารางครบ — branches, sellers, item_conditions, purchase_orders, purchase_order_items, purchase_order_photos
11. ✅ ปิดดีลกับลูกค้าที่ 40,000 บาท

---

## ⏳ สิ่งที่ต้องทำต่อ

### Priority 1 — เอกสารราคาใหม่
- [ ] อัปเดต **ใบเสนอราคา v4** (40,000 บาท)
- [ ] อัปเดต **สัญญาจ้าง** (ราคาใหม่ + งวดใหม่)
- [ ] ส่งให้ลูกค้าผ่าน LINE/Email

### Priority 2 — เริ่มงาน Phase 1
- [ ] นัดวันลงสำรวจ 4 สาขา (สำคัญที่สุด — ห้ามรีบ code)
- [ ] เก็บข้อมูลจริงจากแต่ละสาขา (ขนาด, จำนวนพนักงาน, อุปกรณ์, internet)
- [ ] อัปเดต `branches` table ด้วยชื่อ/ที่อยู่จริง

### Priority 3 — Code Development
- [ ] เขียน `Seller.php` Model
- [ ] เขียน `PurchaseOrder.php` + `PurchaseOrderItem.php` Model
- [ ] เขียน `ItemCondition.php` Model
- [ ] เขียน Controllers: BranchesController, SellersController, PurchaseOrdersController
- [ ] เพิ่ม routes ใน base-pos Router.php (ต้อง patch แบบ minimal)
- [ ] เขียนหน้า admin: `branches.html`, `sellers.html`, `purchase-orders.html`
- [ ] เขียนหน้า POS สำหรับรับซื้อ (purchase-mode)
- [ ] ปรับ reports ให้ filter by branch

### Priority 4 — รายงานเฉพาะ
- [ ] กำไรต่อชิ้น (PO cost vs Sale price)
- [ ] สินค้าค้างนาน
- [ ] เปรียบเทียบสาขา
- [ ] ผู้ขายประจำ Top 10

### Priority 5 — Hybrid Online/Offline
- [ ] วาง architecture (LocalStorage / IndexedDB / Service Worker?)
- [ ] Sync mechanism เมื่อ internet กลับมา
- [ ] *หมายเหตุ:* อาจอยู่ใน Phase 4 ไม่ใช่ Phase 1

---

## 🚀 คำสั่งที่ใช้บ่อย

```bash
# Path
cd /home/drsolodev/projects/secondhand-pos/code

# เริ่ม demo
docker compose up -d

# ดู logs
docker compose logs -f web

# Restart
docker compose restart web

# Reset ทุกอย่าง (ลบ database + เริ่มใหม่)
docker compose down -v && docker compose up -d --build

# เข้า MySQL CLI
docker exec -it secondhand-pos-db mysql -uroot -prootpass pos_system
```

### URLs
- Admin: http://localhost:8080/admin/ (admin/admin)
- POS: http://localhost:8080/pos/
- phpMyAdmin: http://localhost:8081/ (root/rootpass)

---

## ⚠️ ข้อควรระวัง

### Technical
- **ห้ามแก้ `base-pos/` โดยตรง** ยกเว้นจำเป็นจริงๆ (ทำ patch เล็กๆ + comment เหตุผล)
- Migration ทำงานครั้งเดียวตอน first start — ถ้าแก้ migration เก่า ต้อง `docker compose down -v` แล้วเริ่มใหม่
- Production ต้องเปลี่ยน `JWT_SECRET` ใน `base-pos/api/config.php`

### Business
- **อย่าเริ่ม code มากก่อนไปหน้างาน** — scope จริงอาจต่างจากที่คาด
- **อย่าอาสาลดราคา** ถ้าลูกค้าขอเพิ่มงาน → คิดเพิ่มตามจริง
- ลูกค้า sensitive เรื่อง trust → update progress ทุกสัปดาห์
- ส่งมอบเป็น 4 รอบตาม milestone — ลูกค้าจะมั่นใจว่าไม่โดนทิ้ง

---

## 🤝 Personal Note

Dr.Solodev เป็น Solo dev ที่ทำงาน 100% เต็มเวลา ไม่มีทีม ไม่มี safety net
- เรียกตัวเองว่า "เอเจ่น" สำหรับผม (Claude)
- ชอบคุยภาษาไทย
- อยากให้ฟังก่อน ไม่รีบ jump to solution
- บอกตรงๆ ได้ ไม่ต้องกลัวเถียง
- Celebrate ความสำเร็จด้วยกัน
- Code ก่อน scan security + edge cases

โปรเจกต์นี้คือ **ชัยชนะที่สำคัญ** สำหรับ Dr.Solodev — ปิดดีลได้สูงกว่าตั้งราคาเอง 5,000 บาท หลังจากเหนื่อยมานาน

---

**[[Angkub Profile]]** — ดูข้อมูลตัวตน Dr.Solodev เพิ่มเติม
**[[project-secondhand-pos]]** — Memory entry ใน Claude memory system
