# WF-02: Printable Receipts
**Status**: DONE — implemented + verified  
**Date**: 2026-06-14

---

## What EXISTS (ตามโค้ดปัจจุบัน)

| Component | Current code |
|-----------|--------------|
| `showReceipt()` flow | `base-pos/assets/js/purchase-orders.js` |
| Type A: ใบรับซื้อปกติ (2 copy, fold) | `base-pos/assets/js/purchase-orders.js` |
| Type B: โลหะมีค่า + signature box | `base-pos/assets/js/purchase-orders.js` |
| `requires_precious_receipt` flag | categories table + migration 032/046 |
| API returns all receipt fields | `customizations/api/Models/PurchaseOrder.php` |
| Print CSS `@media print` | `base-pos/assets/js/purchase-orders.js` |
| `window.print()` button | `base-pos/admin/purchase-orders.html` |

## Verification Notes

| Check | Result |
|------|--------|
| `#poQrSection` hidden in print | already implemented |
| page-break handling for long Type B receipt | already implemented |
| print-safe receipt wrapper styles | already implemented |
| signature area for precious metals | already implemented |
| `@page` landscape sizing | already set in code |

---

## Implementation Order

```
Step 1: แก้ @media print CSS — hide QR, handle page-break
Step 2: เพิ่ม signature line ที่ใช้งานได้จริง
Step 3: ทดสอบ render ทั้ง Type A และ Type B
```

---

## Test Cases

| TC | Scenario | Expected |
|----|----------|---------|
| TC-01 | PO ปกติ → showReceipt → พิมพ์ | Type A: 2 columns, ไม่มี signature, ไม่มี QR |
| TC-02 | PO มี item ที่ `requires_precious_receipt=1` → พิมพ์ | Type B: มี signature box, legal warning |
| TC-03 | พิมพ์ → QR section ไม่ปรากฏ | ✅ hidden by print CSS |
| TC-04 | Type B ยาวกว่า A4 → page break ถูกต้อง | ไม่มี content ถูก clip |
