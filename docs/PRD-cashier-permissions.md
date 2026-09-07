# PRD — สิทธิ์แคชเชียร์ (Cashier) — ปรับให้ตรง Scope ร้าน

**วันที่:** 2026-09-07 | **Owner:** Dr.SoloDev | **CEO:** เทอโบ | **Status:** Draft → Testing → Done (ลบหลัง deploy)
**Scope ปิด:** สิทธิ์ผู้ใช้ + บิล (นอกนั้น = เฟสต่อขยาย คิดเงินเพิ่ม)

## เป้าหมาย
แคชเชียร์เห็น 8 เมนู สะอาด ไม่งง — ตรงที่ร้านระบุ

## 8 สิทธิ์ที่ร้านระบุ (แคชเชียร์)
1. รับซื้อ — เข้าได้
2. แคตตาล็อก — ดูอย่างเดียว (แก้ไม่ได้)
3. จัดการผู้ขาย — เข้าได้
4. สินค้าคงคลัง — ดูเฉพาะสาขาตัวเอง
5. รายงาน — เข้าได้ (operational)
6. เปิด-ปิดยอด — เข้าได้
7. ค่าใช้จ่ายร้าน — เข้าได้
8. บอร์ดราคา — เข้าได้
- โอนสต็อก — ซ่อนเมนูไว้ก่อน (สิทธิ์ยังอยู่ ปีหน้าเปิดกลับ)

## งานที่ทำ 4 อย่าง (ลำดับ)
1. เปลี่ยนชื่อ `พนักงานขาย` → `แคชเชียร์` (users.html/js — label อย่างเดียว ไม่แตะ DB enum `cashier`)
2. ล็อคแคตตาล็อก: `catalog.create/update => $isManager` (แคชเชียร์ดูอย่างเดียว) — frontend ซ่อนปุ่ม เพิ่ม/แก้ไข/ลบ + backend block
3. สต็อกสาขาเดียว: `inventory.read` ยัง true แต่ InventoryController เช็ค branch_id (cashier เห็นสาขาตัวเอง)
4. ซ่อนเมนูโอนสต็อก + ซ่อน dropdown เลือกสาขา สำหรับ cashier (common.js)

## ไฟล์ที่แตะ (ไม่แตะ DB)
- `PermissionsController.php`
- `users.html` + `users.js`
- `common.js` (ซ่อน dropdown)
- `catalog.js` (ซ่อนปุ่มตาม permission)

## เทสเช็คลิสต์ (2 role)
- [ ] Login แคชเชียร์ → เมนู = 8 อัน, ไม่มี โอนสต็อก/ขาย Lot/สาขา/ผู้ใช้/บันทึก
- [ ] แคตตาล็อก → ไม่มีปุ่ม เพิ่ม/แก้ไข/ลบ, ยิง API PUT ตรงๆ → 403
- [ ] สินค้าคงคลัง → เห็นเฉพาะสาขาตัวเอง (branch_id)
- [ ] รับซื้อ/ผู้ขาย/รายงาน/เปิด-ปิดยอด/ค่าใช้จ่าย/บอร์ดราคา → เข้าได้ปกติ
- [ ] Login admin/manager → เห็นครบเหมือนเดิม

## Deploy
- Branch: `feat/cashier-permissions`
- Backup DB → pull → restart web → verify pos.mkxmeme.xyz
- Rollback: revert PermissionsController 1 ไฟล์

## ลบเอกสารนี้เมื่อ deploy เสร็จ
