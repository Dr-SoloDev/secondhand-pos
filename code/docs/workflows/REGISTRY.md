# 🗺️ Workflow Registry — Secondhand POS

เอกสารฉบับนี้ใช้ควบคุมประวัติการพัฒนา คุณลักษณะของฟังก์ชันระบบ และโครงสร้างที่ได้รับการพัฒนาจริงตามข้อตกลง

---

## สถานะการพัฒนาฟีเจอร์ระบบ (Workflow & Feature Status)

| WF ID | ชื่อ Feature / ระบบงาน | สถานะ (Status) | รายละเอียดและเอกสารจำเพาะ (Spec) |
|---|---|---|---|
| **WF-PRE** | Migration Consolidation (001 - 063) | ✅ DONE | โครงสร้างฐานข้อมูลและการอัปเกรดข้อมูลล่าสุด |
| **WF-01** | Photo Upload — QR handoff + mobile camera | ✅ DONE | ถ่ายรูปสินค้าและรูปบัตรผู้ขาย [→](WORKFLOW-01-photo-upload.md) |
| **WF-02** | Receipts — Type A / Type B (Precious metals) | ✅ DONE | ระบบพิมพ์บิลรับซื้อแยกตามหมวดหมู่ประเภทโลหะ [→](WORKFLOW-02-receipts.md) |
| **WF-03** | Seller Search + Blacklist Alert | ✅ DONE | สืบค้นประวัติผู้ขายและการดักจับการติดแบล็กลิสต์ [→](WORKFLOW-03-seller-search-blacklist.md) |
| **WF-04** | 4-Branch Dashboard + auto-refresh | ✅ DONE | สรุปยอดข้อมูลรวมรายเดือนของ 4 สาขา [→](WORKFLOW-04-dashboard.md) |
| **WF-05** | Purchase Flow UX — Keyboard-first | ✅ DONE | หน้ารับซื้อสินค้าด่วนสำหรับพนักงานหน้าร้าน [→](WORKFLOW-05-purchase-flow-ux.md) |
| **WF-06** | Daily Cash Sessions (Cash Drawer Control) | 🔄 REDESIGN IN PROGRESS | แยกยอดเงินรวมกิจการออกจากยอดในลิ้นชัก, รองรับ reserve/owner cash และแก้ flow เปิด-ปิดยอดตาม `WORKFLOW-06-cash-control-redesign.md` |
| **WF-07** | PO Cancellation Approval Workflow | ✅ DONE | การยกเลิกใบเสร็จรับซื้อด้วยระบบอนุมัติคู่ข้ามบัญชี (Dual-Authorization) |
| **WF-08** | Stock Transfers & Reversals | ✅ DONE | การโอนสินค้าข้ามสาขา และสิทธิ์ยื่นคำขอส่งสต็อกคืน (Reversal Request) |
| **WF-09** | Adjustment Documents Administration | ✅ DONE | ระบบกรอกเอกสารแก้ไขย้อนหลังและล้างบิลทุจริตสำหรับสิทธิ์แอดมิน |
| **WF-10** | Unified Owner's Dashboard | 📑 PRD DRAFT | แผนการปรับปรุงหน้าสรุปธุรกิจเพื่อเจ้าของกิจการ [→](../prd-owner-unified-dashboard.md) |

---

## สรุปข้อมูลการอัปเดตระบบฐานข้อมูล (Migration Tracking)
*   **จำนวน Migration ปัจจุบัน:** 63 ไฟล์ (เลข 001_add_branches.sql ถึง 063_add_adjustment_documents.sql)
*   **โฟลเดอร์สำหรับพัฒนาต่อยอด:** `code/customizations/database/migrations` (เป็นโฟลเดอร์หลักโฟลเดอร์เดียวที่ถูกรันจริงและใช้ติดตั้งบนระบบ DevOps)
