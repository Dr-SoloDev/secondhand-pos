# WF-02: Printable Receipts
**Status**: SPEC — พร้อม implement  
**Date**: 2026-06-14

---

## What EXISTS (ไม่ต้องสร้าง)

| Component | File:Line |
|-----------|-----------|
| `showReceipt()` function | purchase-orders.js:450-545 |
| Type A: ใบรับซื้อปกติ (2 copy, fold) | purchase-orders.js:520-538 |
| Type B: โลหะมีค่า + signature box | purchase-orders.js:499-518 |
| `requires_precious_receipt` flag | categories table, migration 032 |
| API returns all receipt fields | PurchaseOrder::getById() |
| Print CSS `@media print` | purchase-orders.js:526-533 |
| `window.print()` button | purchase-orders.html:338 |

## What MISSING (ต้องทำ)

| Gap | ผลกระทบ |
|-----|---------|
| `#poQrSection` ไม่ถูก hide ใน print | QR code จะพิมพ์ออกมาด้วย |
| ไม่มี page-break ถ้า Type B ยาวเกิน A4 | receipt ถูก clip |
| ไม่มี `.pu-toast`, `.pu-state` print-safe | อาจ render ขยะ |
| Signature line แคบ (text underline) | เขียนลายเซ็นลำบาก |
| `@page` landscape ต้อง confirm size | บาง printer อ่านผิด |

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
