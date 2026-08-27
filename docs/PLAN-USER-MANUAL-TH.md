# แผนคู่มือการใช้งานภาษาไทย — รักษ์สะอาดรีไซเคิล (ragsaaad_v1)

> ไฟล์นี้สำหรับ Agent ใดๆ ที่เข้ามาทำงานต่อ — อ่านไฟล์นี้จบจะเข้าใจเป้าหมาย ขั้นตอน และสถานะปัจจุบันทันที ไม่ต้องให้เจ้าของเล่าใหม่

## 1. ภาพรวมโปรเจกต์
- **ระบบ:** Secondhand POS — รับซื้อของเก่า 4 สาขา จ.สุรินทร์
- **Repo:** `Dr-SoloDev/secondhand-pos` branch `main` (ล่าสุด `c701d67`)
- **Owner:** Dr.solodev — SoloCorp OS, ปรัชญา "มองเป็นทีมเดียวกันเสมอ"
- **Design:** Amber `#D97706` / Slate `#1e293b` / IBM Plex Sans Thai (ดู `DESIGN.md`)

## 2. สถาปัตยกรรม (Agent ต้องรู้)
- `code/customizations/` = แก้ตรงนี้ก่อน (overlay)
- `code/base-pos/` = core framework (goragodwiriya/pos-system) — แก้เมื่อจำเป็น
- Routes ทั้งหมดใน `code/base-pos/api/Router.php`
- Auth: `POST /auth/login` → httpOnly cookie `posToken` + fallback `Bearer`
- Migrations: `customizations/database/migrations/NNN_*.sql` ห้ามแก้ `base-pos/database/pos_system.sql`
- Server ร้าน: `ragsaaadserver` (Tailscale `100.91.242.99`, user `ragsaaad_v1`, path `~/secondhand-pos`)
- Tunnel: `cloudflared tunnel run` → `https://POS.mkxmeme.xyz` (Cloudflare cache 4 ชม.)
- Local dev: `~/projects/scrap-pos/code` → `http://localhost:8080`

## 3. สถานะปัจจุบัน (2026-08-28)
- **Commit ล่าสุด:** `c701d67 feat(ui): premium polish — บิลเรียบหรู + ต้นขั้ว + preview cards + thermal + import` — push แล้ว, deploy บน `ragsaaadserver` แล้ว, `import_jobs` (074) สร้างแล้ว
- **DB:** `pos_system` — ล้างธุรกรรมแล้ว เหลือ `catalog 210 / categories 13 / branches 2 (BR01, BR02)` พร้อมเริ่มใหม่คลีนๆ (`reset-keep-catalog.sql`)
- **งานเสร็จ:**
  - บิลรับซื้อเรียบหรู (slate+amber, meta bar, seller card, table สลับสี, hero amber, ต้นขั้วฉีกเก็บ)
  - Preview 6 cards (sale-lots) + wrapper cards (purchase-orders, sellers)
  - Thermal 80mm: `print-receipt-thermal.html` + `print_receipt.py` (GS v0 raster, supersample 3x)
  - Reports Total Row + maskIdCard/Phone + XSS fix
  - Import Excel (074) Skeleton
  - Test suite 440/440 บน fresh DB
- **ค้าง:** คู่มือภาษาไทย (แผนนี้)

## 4. เป้าหมายคู่มือ
- ให้แคชเชียร์/เจ้าของ/แอดมิน ใช้งานได้โดยไม่ต้องถาม dev
- ลดความผิดพลาดหน้างาน — มีรูปทุกขั้นตอน ภาษาไทยล้วน
- ส่งมอบพร้อมระบบ (45,000) ให้รู้สึกคุ้มราคา

## 5. คนอ่าน (Persona)
| กลุ่ม | ใช้เมนู | ต้องการ |
|------|---------|----------|
| แคชเชียร์ | รับซื้อ, ขาย Lot, ปิดยอด | สั้น มีรูป ทำตามได้ทันที |
| เจ้าของ/ผจก. | รายงาน, สาขา, ผู้ใช้ | สรุปยอด + อนุมัติ |
| แอดมิน | ตั้งค่า, Import, Backup | ละเอียด มีคำเตือน |

## 6. สารบัญคู่มือ (25 หน้า A4 + Quick Start 1 แผ่น)
1. เข้าสู่ระบบ + เปลี่ยนรหัส (2 หน้า)
2. รับซื้อของเก่า — ค้นผู้ขาย → เลือกของ → ชั่ง → จ่ายเงิน → พิมพ์บิล A4/80mm + ต้นขั้ว (6 หน้า) ⭐
3. ขาย Lot — สร้าง Lot → FIFO → พิมพ์บิล (4 หน้า)
4. จัดการผู้ขาย + ประวัติ (2 หน้า)
5. สต็อก & โอนสต็อก (2 หน้า)
6. รายงาน — รับซื้อ/ขาย/กำไร/พนักงาน (3 หน้า)
7. ปิดยอดประจำวัน — เทียบต้นขั้ว ↔ ระบบ (2 หน้า) ⭐
8. ตั้งค่า — สาขา/ผู้ใช้/หมวดหมู่ (2 หน้า)
9. Import Excel (1 หน้า)
10. แก้ปัญหา + ติดต่อ (1 หน้า)
ภาคผนวก: Quick Start 1 หน้า (ลามิเนตติดเคาน์เตอร์)

## 7. รูปแบบส่งมอบ
- PDF A4 ภาษาไทย (สารบัญคลิกได้) — พิมพ์แจก 4 สาขา
- Quick Start ลามิเนต 1 แผ่น
- ต้นฉบับ .docx ให้ร้านแก้เองได้
- ต่อยอดจาก `docs/USER-GUIDE.md` ที่มีอยู่

## 8. ขั้นตอนทำงาน (ไม่ด่วน แต่ไม่ค้าง)
| เฟส | งาน | ผลลัพธ์ |
|-----|-----|----------|
| A. เตรียม | แคปหน้าจอจริงทุกเมนู (เบลอข้อมูลจริง) + เตรียมข้อความไทย | โฟลเดอร์ `docs/manual-screenshots/` |
| B. ร่าง | Agent ร่าง PDF 25 หน้า (ใช้ `document-generator` skill) | `docs/manual-draft.pdf` |
| C. ตรวจ | เจ้าของตรวจ + ให้แคชเชียร์ 1 คนลองทำตาม | checklist แก้ |
| D. ส่ง | ส่ง PDF + พิมพ์ Quick Start ให้ 4 สาขา | ส่งมอบ |

## 9. คำถามที่ต้องตัดสินใจ (ถามเจ้าของก่อนร่าง)
1. น้ำเสียง: เป็นกันเอง ("คุณลูกค้า/แคชเชียร์") หรือทางการ?
2. รูป: ใช้ข้อมูลจริงเบลอ หรือข้อมูลตัวอย่าง?
3. ต้องการวิดีโอสั้น 3 นาทีประกอบไหม?

## 10. คำสั่งสำคัญ (สำหรับ Agent ใหม่)
```bash
# ดูสถานะ
cd ~/secondhand-pos && git log --oneline -1 && git status --short
docker ps --format "{{.Names}} {{.Status}}" | grep scrap
curl -s http://localhost:8080/api/index.php/auth/verify -w " HTTP:%{http_code}\n"

# Deploy (เมื่อมี commit ใหม่)
cd ~/secondhand-pos && git pull origin main
docker exec -i scrap-pos-db mysql -u root -p"$MYSQL_ROOT_PASSWORD" pos_system < customizations/database/migrations/0XX_*.sql  # ถ้ามี migration ใหม่
docker compose -f code/docker-compose.yml restart web
```

## 11. ไฟล์อ้างอิง
- `docs/USER-GUIDE.md`, `docs/TECHNICAL.md`, `docs/INSTALLATION.md`
- `DESIGN.md`, `AGENTS.md`
- `code/base-pos/assets/js/purchase-orders.js` (บิล + ต้นขั้ว)
- `code/base-pos/assets/js/sale-lots.js` (6 cards)
- `code/print-server/print_receipt.py` (GS v0)

---
*สร้าง: 2026-08-28 — อัปเดตเมื่อเริ่มเฟส A/B/C/D*
