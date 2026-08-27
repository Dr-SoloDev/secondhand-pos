# เฟส A — เก็บภาพสำหรับคู่มือ (Manual Screenshots)

> สถานะ: เตรียมโครงไว้แล้ว — รอแคปภาพจริง 10 หน้า

## รายการภาพที่ต้องเก็บ (10 หน้า)

| # | หน้า | ไฟล์ | สิ่งที่ต้องแคป |
|---|------|------|----------------|
| 1 | เข้าสู่ระบบ | `index.html` | หน้า login |
| 2 | รับซื้อ ⭐ | `purchase-orders.html` | หน้าหลัก + modal บิลเรียบหรู + ต้นขั้ว + thermal preview |
| 3 | ขาย Lot | `sale-lots.html` | รายการ Lot + modal 6 cards + พิมพ์ A4 |
| 4 | ผู้ขาย | `sellers.html` | รายการ + modal ประวัติ |
| 5 | สต็อก | `inventory.html` | ตารางสต็อก |
| 6 | รายงาน | `reports.html` | 3 ตาราง + Total Row |
| 7 | ลิ้นชักเงินสด | `cash-sessions.html` | เปิด/ปิดยอด |
| 8 | สาขา | `branches.html` | รายการ 2 สาขา |
| 9 | แคตตาล็อก | `catalog.html` | 210 รายการ |
| 10 | Import | `import-excel.html` | อัปโหลด + ผลลัพธ์ |

## วิธีแคป (Chrome Headless)

```bash
mkdir -p docs/manual-screenshots
# ตัวอย่าง — แคปแต่ละหน้า (ต้อง login ก่อนถ้าหน้าต้อง auth)
google-chrome --headless --disable-gpu --window-size=1280,800 \
  --screenshot=docs/manual-screenshots/01-login.png \
  http://localhost:8080/admin/index.html
```

> หมายเหตุ: Browser headless ของเครื่องนี้ติด permission popup — ต้อง Allow remote debugging ก่อน หรือแคปด้วยวิธี manual (เปิดเบราว์เซอร์แล้วกดแคปเอง) ก็ได้

## สถานะ
- [ ] 01-login.png
- [ ] 02-purchase-orders.png
- [ ] 02-bill-modal.png (บิลเรียบหรู + ต้นขั้ว)
- [ ] 03-sale-lots.png
- [ ] 03-lot-modal-6cards.png
- [ ] 04-sellers.png
- [ ] 05-inventory.png
- [ ] 06-reports.png
- [ ] 07-cash-sessions.png
- [ ] 08-branches.png
- [ ] 09-catalog.png
- [ ] 10-import-excel.png

## ถัดไป (เฟส B)
เมื่อภาพครบ → ร่าง `docs/manual-draft.pdf` (25 หน้า) โดยเทอโบ (แผน B)
