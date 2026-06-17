# WF-03: Real-time Seller Search + Blacklist Alert
**Status**: SPEC — พร้อม implement  
**Date**: 2026-06-14

---

## What EXISTS (ดีมากแล้ว — ไม่แตะ)

| Component | File:Line | Quality |
|-----------|-----------|---------|
| Debounced real-time search | purchase-orders.js:17 | ✅ 300ms |
| `[Blacklist]` badge ใน results | purchase-orders.js:612 | ✅ |
| `showBlacklistAlert()` popup | purchase-orders.js:629-659 | ✅ สวยและครบ |
| Cancel / ดำเนินการต่อ buttons | purchase-orders.js:651-658 | ✅ |
| Reason + วันที่ ใน popup | purchase-orders.js:647-648 | ✅ |
| Search API returns blacklist fields | sellers/search endpoint | ✅ |

## What MISSING (2 gaps ที่ต้องปิด)

### Gap 1: ไม่มี blacklist indicator ใน selected seller box
เมื่อ confirm แล้ว → `doSelectSeller()` แสดงชื่อ/โทรศัพท์ปกติ  
**ไม่มี visual ว่า "seller นี้อยู่ใน blacklist"**  
พนักงานคนอื่นที่มาดูหน้าจอจะไม่รู้

### Gap 2: ไม่มี check ตอน save
`savePurchaseOrder()` line 365: ตรวจแค่ `!selectedSeller`  
**ไม่ตรวจ `selectedSeller.is_blacklisted`**  
ถ้า staff confirm popup แล้วรอนาน (phone call etc.) → save โดยไม่มี 2nd reminder

---

## Workflow Tree (ที่ควรเป็น)

```
พนักงานพิมพ์ชื่อผู้ขาย
    ↓ debounce 300ms
ผลการค้นหา:
    - ปกติ: ชื่อ + เลขบัตร + โทร
    - Blacklist: ชื่อ + [⛔ Blacklist] badge แดง
        ↓ คลิก
    [EXISTS] showBlacklistAlert popup
        ├── ยกเลิก → clear
        └── ดำเนินการต่อ →
            [NEW] doSelectSeller + blacklist badge ใน selected box
                ↓ กด "บันทึกใบรับซื้อ"
            [NEW] 2nd check: ถ้า blacklisted → toast warning (ไม่ block)
                ↓
            บันทึก PO ตามปกติ (allow with warning)
```

---

## Files to Edit

### 1. `doSelectSeller()` — เพิ่ม blacklist badge ใน selected box
```js
// ถ้า s.is_blacklisted == 1 → เพิ่ม badge แดงใต้ชื่อ
<div style="color:#c00;font-size:12px;font-weight:600;margin-top:4px">
  ⛔ ผู้ขายรายนี้อยู่ในบัญชีดำ
</div>
```

### 2. `savePurchaseOrder()` — เพิ่ม soft warning ก่อน save
```js
if (selectedSeller.is_blacklisted == 1) {
  showNotification('⚠️ กำลังบันทึก PO ให้ผู้ขายที่อยู่ในบัญชีดำ', 'warning');
  // ไม่ block — แค่ remind (staff confirm แล้วตอน select)
}
```

---

## Test Cases

| TC | Scenario | Expected |
|----|----------|---------|
| TC-01 | ค้นหาผู้ขายปกติ | แสดงผลทันที ≤300ms |
| TC-02 | ผู้ขาย blacklist ใน results | badge `[⛔ Blacklist]` แดง |
| TC-03 | คลิก blacklist seller | popup แสดงเหตุผล + ปุ่ม |
| TC-04 | กด "ยกเลิก" | popup ปิด ไม่เลือก |
| TC-05 | กด "ดำเนินการต่อ" | เลือกได้ + selected box มี badge แดง |
| TC-06 | กด "บันทึก" หลังเลือก blacklist seller | toast warning ปรากฏ + PO save สำเร็จ |

---

## Implementation Order
```
Step 1: doSelectSeller() → badge blacklist ใน selected box
Step 2: savePurchaseOrder() → soft warning toast
Step 3: ทดสอบ TC-01 → TC-06
```
