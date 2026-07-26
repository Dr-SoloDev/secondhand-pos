# WF-04: 4-Branch Side-by-Side Dashboard
**Status**: DONE — implemented + verified  
**Date**: 2026-06-15

---

## What EXISTS (ตามโค้ดปัจจุบัน)

| Component | Current code | Note |
|-----------|--------------|------|
| `reports/summary` API → per-branch metrics | `base-pos/api/Controllers/ReportsController.php` | ✅ |
| `renderBranchSummary(mode='side')` 4-col grid | `base-pos/assets/js/dashboard.js` | ✅ |
| Branch cards: ยอดวันนี้, เดือนนี้, สต็อก, ใบรับซื้อ | `base-pos/assets/js/dashboard.js` | ✅ |
| ⭐ "สูงสุด" badge | `base-pos/assets/js/dashboard.js` | ✅ |
| Mode buttons: รวม / เปรียบเทียบ / รายสาขา | `base-pos/admin/index.html` | ✅ |
| Chart.js line+bar charts | `base-pos/assets/js/dashboard.js` | ✅ |
| Branch-aware stats / recent lists | `base-pos/assets/js/dashboard.js` | ✅ |
| Auto-refresh timer | `base-pos/assets/js/dashboard.js` | ✅ |

## Verification Notes

- เมื่อสลับ branch mode, top stats และ recent data รับ `branch_id` ตาม branch ที่เลือก
- Dashboard refresh ข้อมูลอัตโนมัติตาม timer ใน frontend
- Chart data และ inventory alerts อยู่ภายใต้ branch context ของผู้ใช้ที่ล็อกอิน

---

## Scope Boundary

- ยังไม่เพิ่ม mini sparkline ต่อ branch ใน branch cards
- ยังไม่เพิ่ม date range picker บน dashboard
- ยังไม่ export PDF/Excel จากหน้าดashboard
