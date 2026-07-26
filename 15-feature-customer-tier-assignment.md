# Feature Request — Customer Tier Assignment (บิล 2/3 ต่อลูกค้า)

**Source:** Client feedbag (2026-07-24)
**Mirror Check:** ✅ Passed (L3, 66%)
**Owner:** Dr.solodev | **CEO:** เทอโบ

---

## Problem

ปัจจุบันระบบใช้ **3 ระดับราคา (บิล 1/2/3)** แต่การเลือกระดับขึ้นอยู่กับแคชเชียร์เป็นคนจำว่าลูกค้าคนไหนได้ราคาพิเศษระดับไหน

**ปัญหาที่ลูกค้าเจอ:**
1. แคชเชียร์จำไม่ได้ว่าลูกค้าคนไหนได้ราคาพิเศษระดับไหน
2. ถ้าลูกค้าไปขายที่สาขาอื่น แคชเชียร์สาขานั้นก็จำไม่ได้ → ลูกค้าไม่พอใจ
3. ถ้าแคชเชียร์คนเก่าออก คนใหม่เข้างาน ต้องเริ่มเรียนรู้ใหม่
4. ข้อมูลสิทธิ์ลูกค้าควรอยู่ที่ระบบ ไม่ใช่ที่ตัวบุคคล

## Solution

**เพิ่มฟีเจอร์:** ในหน้าจัดการข้อมูลผู้ขาย (sellers) ให้สามารถกำหนดระดับราคาพิเศษ (Bill Tier) ให้กับลูกค้าเฉพาะรายได้

### Requirements

#### 1. Database — Seller Table
- เพิ่มฟิลด์ `tier_level` ในตาราง `sellers`
- ค่า: `NULL` หรือ `1` (ค่าเริ่มต้น = บิล 1 ราคาทั่วไป), `2` (บิล 2), `3` (บิล 3)
- Default: `1` (บิล 1 — ราคาทั่วไป ไม่ต้องกำหนด)

#### 2. Seller Management UI (หน้า sellers)
- ในฟอร์มแก้ไขข้อมูลผู้ขาย (edit seller) เพิ่ม dropdown/select:
  - **"ระดับราคาพิเศษ"** (Special Price Tier)
  - ตัวเลือก: `บิล 1 (ทั่วไป)`, `บิล 2`, `บิล 3`
  - แสดงเฉพาะสำหรับ admin/supervisor (ไม่ใช่แคชเชียร์ทั่วไป)
  - แสดงในหน้า detail/view ผู้ขายด้วย

#### 3. Purchase Flow Integration (หน้า PO)
- เมื่อเลือกผู้ขายในหน้าสร้างใบรับซื้อ (PO) → ระบบต้อง:
  - ดึง `tier_level` ของผู้ขายนั้นจาก DB
  - **Auto-select** tier ที่ตรงกับสิทธิ์ของผู้ขาย (ถ้ามี)
  - แต่ยังให้แคชเชียร์ override ได้ (เผื่อกรณีพิเศษ)
  - แสดง badge หรือ label แจ้งว่าระดับราคาที่เลือก是根据 "สิทธิ์ลูกค้า"

#### 4. Cross-Branch Sync
- `tier_level` เก็บในตาราง `sellers` ซึ่งแชร์กันทั้งระบบอยู่แล้ว
- ทุกสาขาเห็นข้อมูลเดียวกัน ไม่ต้อง sync เพิ่มเติม
- ไม่ผูกกับ employee/user — ข้อมูลติดตัวผู้ขายเสมอ

#### 5. Migration Plan
- เพิ่ม migration script: `ALTER TABLE sellers ADD COLUMN tier_level INT DEFAULT 1 AFTER [appropriate_column];`
- ข้อมูลเดิมทั้งหมด → `tier_level = 1` (บิล 1 — ราคาทั่วไป)
- ไม่มีการสูญเสียข้อมูล

#### 6. UI/UX Requirements
- แก้ไขหน้า sellers → edit form
- แก้ไขหน้า PO → auto-detect tier
- แก้ไขหน้า detail/view seller → แสดง tier
- แก้ไข history/view ของผู้ขาย → แสดง tier

### Files ที่ต้องแก้ (คาดการณ์)

| File | การเปลี่ยนแปลง |
|:-----|:--------------|
| `customizations/database/migrations/053_add_tier_level_to_sellers.sql` | Migration ใหม่ |
| `customizations/api/Controllers/SellerController.php` | รองรับ tier_level |
| `customizations/api/Models/Seller.php` | เพิ่ม field tier_level |
| `base-pos/admin/sellers.html` | UI แสดง/แก้ไข tier |
| `base-pos/api/Controllers/PurchaseOrdersController.php` | ดึง tier_level ของ seller |
| `base-pos/assets/js/sellers.js` | JS จัดการ tier form |
| `base-pos/assets/js/purchase-orders.js` | JS auto-select tier |
| `base-pos/assets/css/components/sellers.css` | Styling เพิ่มเติม |

### Risk Assessment

| Risk | Impact | Mitigation |
|:-----|:-------|:-----------|
| migration fail | Low | ALTER TABLE ADD COLUMN with DEFAULT — safe, no data loss |
| PO flow broken if tier not found | Medium | Graceful fallback: tier = 1 (default) |
| UI complexity for cashiers | Low | Auto-select — cashier ไม่ต้องคิด |
| Wrong tier assigned to seller | Low | Admin-only edit + visible indicator |

### Rollback
```sql
ALTER TABLE sellers DROP COLUMN tier_level;
```
No data loss — revert + remove UI changes.

---

*Spec by CEO เทอโบ | Approved by Mirror Check | 2026-07-24*
