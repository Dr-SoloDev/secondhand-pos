# 2026-06-01: Inventory SKU & Cost Field Fixes

## Context
ร้านรับซื้อของเก่า — ไม่ใช่ร้านค้าทั่วไป
- ราคาทุนจริง = ราคารับซื้อจาก Purchase Orders
- ไม่ต้องกรอกราคาทุนตอนเพิ่มสินค้าในหน้า Inventory

## Changes Made

### 1. ลบช่อง "ราคาทุน" จากหน้า Inventory
**Why:** ร้านรับซื้อของเก่า ราคาทุนมาจาก PO ไม่ใช่ตอนเพิ่มสินค้า

**Files:**
- `code/base-pos/admin/inventory.html` — ลบ form field "ราคาทุน"
- `code/base-pos/admin/inventory.html` — ลบคอลัมน์ "ราคาทุน" จากตาราง
- `code/base-pos/assets/js/inventory.js` — ตั้ง `cost: 0` ตอนบันทึก
- `code/base-pos/assets/js/inventory.js` — ลบการแสดง/โหลด cost
- `code/base-pos/api/Controllers/InventoryController.php` — ลบ `cost` จาก validation

**Result:** ✅ เพิ่มสินค้าได้โดยไม่ต้องกรอกราคาทุน

---

### 2. แก้ `category_id` ให้ส่ง `null` แทน `""`
**Problem:** MySQL error `Incorrect integer value: '' for column 'category_id'`

**Fix:**
```javascript
category_id: document.getElementById('categoryId').value || null
```

**Why:** MySQL `INT` column ไม่รับ empty string `""` ต้องเป็น `null`

**Result:** ✅ เพิ่มสินค้าได้โดยไม่เลือกหมวดหมู่

---

### 3. แก้ช่อง SKU ให้กรอกเองได้ (แค่ตัวเลข)
**Requirement:** ลูกค้าต้องการกรอก SKU เอง เป็นเลข 1, 2, 3... (ไม่มี GOLD001, IRON001)

**Initial Misunderstanding:** คิดว่าต้องทำ auto-generate SKU
**Actual Requirement:** กรอกเอง แต่แนะนำให้กรอกเป็นตัวเลขเรียง

**Files:**
- `code/base-pos/admin/inventory.html` — เปลี่ยนจาก `readonly` เป็น `required` + placeholder "1, 2, 3..."
- `code/base-pos/api/Controllers/InventoryController.php` — ลบโค้ด auto-generate SKU
- `code/base-pos/api/Models/Product.php` — ลบ method `getLastProduct()` (ไม่ใช้แล้ว)

**Result:** ✅ กรอก SKU เองได้ ระบบไม่ generate ให้

---

## Key Learnings

### 1. ร้านรับซื้อของเก่า ≠ ร้านค้าทั่วไป
**ร้านทั่วไป:**
- ซื้อสินค้าเข้า → ราคาทุน (เช่น 100 บาท)
- ขายออก → ราคาขาย (เช่น 150 บาท)

**ร้านรับซื้อของเก่า:**
- **รับซื้อจากลูกค้า** → ราคารับซื้อ = **ราคาทุน** (มาจาก PO)
- **ขายต่อ** → ราคาบิล 1/2/3 = **ราคาขาย**

**Implication:** ไม่ต้องกรอกราคาทุนในหน้า Inventory

---

### 2. MySQL `INT` column + empty string = error
**Problem:**
```javascript
category_id: document.getElementById('categoryId').value  // "" → error
```

**Solution:**
```javascript
category_id: document.getElementById('categoryId').value || null  // null → OK
```

**Why:** MySQL `INT` column ไม่รับ `""` ต้องเป็น `null`

---

### 3. ฟังให้ชัดก่อนทำ
**Mistake:** เข้าใจว่า "รหัสสินค้าเรียง 1-2-3" = auto-generate
**Reality:** ลูกค้าต้องการกรอกเอง แค่แนะนำให้กรอกเป็นตัวเลข

**Lesson:** ถามให้ชัดก่อนลงมือ — "auto-generate หรือกรอกเอง?"

---

## Testing Results

### Before
- ❌ บันทึกไม่ได้เพราะ `category_id = ""`
- ❌ ต้องกรอกราคาทุน (ไม่เหมาะกับร้านรับซื้อของเก่า)

### After
- ✅ เพิ่มสินค้าได้โดยไม่เลือกหมวดหมู่
- ✅ ไม่ต้องกรอกราคาทุน
- ✅ กรอก SKU เองได้ (1, 2, 3...)

---

## Files Modified
```
code/base-pos/admin/inventory.html
code/base-pos/assets/js/inventory.js
code/base-pos/api/Controllers/InventoryController.php
code/base-pos/api/Models/Product.php
```

---

**Date:** 2026-06-01  
**Session:** secondhand-pos inventory fixes  
**Model:** Claude Opus 4.8 (claude-opus-4-8)
