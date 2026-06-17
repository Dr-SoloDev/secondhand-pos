# WF-04: 4-Branch Side-by-Side Dashboard
**Status**: SPEC — พร้อม implement  
**Date**: 2026-06-15

---

## What EXISTS (ทำงานได้แล้ว)

| Component | File:Line | Note |
|-----------|-----------|------|
| `branches/summary` API → per-branch metrics | BranchesController | ✅ today/month/stock |
| `renderBranchSummary(mode='side')` 4-col grid | dashboard.js:82-90 | ✅ auto 4 col |
| Branch cards: ยอดวันนี้, เดือนนี้, สต็อก, ใบรับซื้อ | dashboard.js:92-129 | ✅ |
| ⭐ "สูงสุด" badge | dashboard.js:98-99 | ✅ |
| Mode buttons: รวม / เปรียบเทียบ / รายสาขา | index.html:241-256 | ✅ |
| Chart.js line+bar charts | dashboard.js:137-195 | ✅ |

## What MISSING (3 gaps จริงๆ)

### Gap 1: Single-branch mode → top stats ไม่กรองตาม branch
เมื่อกดปุ่ม "สาขา 1" → branch card grid กรองได้  
แต่ top 8 stat cards (วันนี้, เดือนนี้, ฯลฯ) **ยังแสดงรวมทุกสาขา**  
`getDashboardStats()` ไม่รับ `branch_id` param

### Gap 2: ไม่มี auto-refresh
Dashboard แสดงข้อมูลตอน load ครั้งเดียว  
**ไม่ update อัตโนมัติ** — ต้องกด F5 เพื่อดูข้อมูลล่าสุด  
ร้านรับซื้อต้องการดูยอดแบบ real-time

### Gap 3: Chart ไม่กรองตาม branch ใน single-branch mode
`fetchPurchaseChartData()` เรียก `reports/purchase-chart` โดยไม่ส่ง branch_id

---

## Implementation Order

```
Step 1: ReportService::getDashboardStats($branch_id=null) — เพิ่ม WHERE clause
Step 2: ReportsController → รับ ?branch_id param → ส่งต่อ Service
Step 3: dashboard.js fetchDashboardData(branchId) → ส่ง branch_id เมื่อ single mode
Step 4: Auto-refresh ทุก 2 นาที (setInterval)
Step 5: chart endpoints รับ branch_id (purchase-chart, salelot-chart)
```

---

## Scope Boundary (ไม่ทำ — เกินกว่า deadline)
- ❌ Mini sparkline ต่อ branch ใน branch cards
- ❌ Date range picker
- ❌ Export PDF/Excel

---

## Test Cases

| TC | Scenario | Expected |
|----|----------|---------|
| TC-01 | โหลด dashboard → กด "เปรียบเทียบ 4 สาขา" | 4 cards side-by-side |
| TC-02 | กด "สาขา 1" → top stats เปลี่ยนเป็น branch 1 only | ✅ |
| TC-03 | กด "รวมทุกสาขา" → top stats กลับเป็น aggregate | ✅ |
| TC-04 | รอ 2 นาที → data refresh อัตโนมัติ | ✅ |
