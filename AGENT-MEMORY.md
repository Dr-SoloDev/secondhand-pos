# 🤖 Agent Memory — Secondhand POS
**Last updated:** 8 มิถุนายน 2569
**Status:** GOALS G1-G10 เสร็จครบ — อยู่ระหว่างรอผลคุยเจ้าของ 9 มิ.ย.

---

## 🎯 Deal & Context

- **ราคา:** 40,000 บาท | ปิดดีล 18 พ.ค. 2569
- **ลูกค้า:** ร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์
- **⚠️ ลูกค้าโดนทิ้งงาน 2 ครั้ง** → trust สำคัญที่สุด อัปเดต progress ทุกสัปดาห์
- **Mindset:** ขายผลงาน ไม่ได้ขายวิญญาณ — อย่าอาสาลดราคาเอง

---

## ✅ GOALS (G1-G10)

| Goal | สถานะ | รายละเอียด |
|---|---|---|
| G1 | ⏳ | ถ่ายรูปสินค้า — รอเจ้าของเลือก storage |
| G2 | ✅ | ใบรับซื้อพิมพ์ได้ 2 แบบ (ปกติ + โลหะมีค่า auto-detect) |
| G3 | ✅ | Blacklist alert popup สีแดง + blacklist_reason |
| G4 | ✅ | ค้นหาผู้ขาย real-time + blacklist badge |
| G5 | ✅ | Dashboard 4 สาขา: รวม / เลือกสาขา / side-by-side |
| G6 | ✅ | บอร์ดราคาพิมพ์ได้ (price-board.html) |
| G7 | ✅ | ประวัติผู้ขายต่อคน (modal) |
| G8 | ✅ | Export CSV 4 แบบ |
| G9 | ✅ | โอนสต็อกระหว่างสาขา + audit trail |
| G10 | ✅ | Stock alert — threshold ต่อหมวด |

---

## ⏳ Todo

### ด่วน — Pending จากคุยเจ้าของ 9 มิ.ย.
- [ ] G1: upload รูปภาพ (หลังเจ้าของเลือก storage: NAS ~10,000฿ / B2 ~12฿/เดือน / Hybrid)
- [ ] Cloudflare Tunnel — remote access dashboard จากมือถือ
- [ ] Commit 3 ไฟล์ค้าง: `purchase-orders.html`, `layout.css`, `purchase-orders.js`

### Tech Debt
- [ ] JWT_SECRET ย้ายออกจาก apache-config.conf ก่อน production
- [ ] sidebar ใน stock-transfers.html + price-board.html เพิ่มลิงก์เมนูครบ
- [ ] ลบ `code/base-pos/backups/.htaccess` (legacy)
- [ ] เมนู "สาขา" ถูกลบจาก sidebar — ตัดสินใจเอาคืนหรือลบ controller

### Phase ถัดไป
- [ ] Reports filter by branch
- [ ] รายงาน: กำไรต่อชิ้น, สินค้าค้างนาน, เปรียบเทียบสาขา, Top 10 ผู้ขาย
- [ ] Hybrid Online/Offline (Phase 4)

---

## 📁 Schema — Migrations

```
001-013  core: branches, sellers, purchase_orders, sale_lots, price_tiers
014      missing indexes
015-016  catalog tier prices (json)
017      sale_lots expenses
018      rename tier labels → บิล1/2/3
019-023  reports, dashboard, responsive, weighted avg cost
024      global categories (merge 4สาขา → 1)
025      default_unit per category
026      (reserved)
027      business expenses
028      blacklist fields (sellers)
029      precious receipt flag (categories)
030      stock transfers table
031      stock alert threshold (categories)
032      catalog_id + item_name (sale_lot_items)
```

---

## 🚀 Commands

```bash
cd /home/drsolodev/projects/secondhand-pos/code

docker compose up -d
docker compose logs -f web
docker compose restart web
docker compose down -v && docker compose up -d --build

# MySQL CLI
docker exec -it secondhand-pos-db mysql -uroot -prootpass pos_system
# ⚠️ ภาษาไทยต้องใส่ --default-character-set=utf8mb4
```

**URLs:** Admin `http://localhost:8080/admin/` (admin/admin123) | PMA `http://localhost:8081/` (root/rootpass)

---

## ⚠️ ข้อควรระวัง

- **ห้ามแก้ base-pos/ โดยตรง** ยกเว้นจำเป็น + comment เหตุผล
- Migration รันครั้งเดียว — แก้เก่าต้อง `docker compose down -v`
- production: เปลี่ยน JWT_SECRET ใน .env ก่อน deploy
- token key ในระบบ: `posToken` / `posUser` (ไม่ใช่ `token`/`user`)

---

## 🤝 Dr.Solodev Style

- ฟังก่อน ไม่รีบ jump to solution
- บอกตรงๆ ได้ ไม่ต้องกลัวเถียง
- ทำตรงตามที่สั่ง ไม่บวกขอบเขตเอง
- Solo dev — ไม่มีทีม ไม่มี safety net

---

*Session logs ย้อนหลัง → `AGENT-HISTORY.md`*
