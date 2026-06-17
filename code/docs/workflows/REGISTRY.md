# WF Registry — Secondhand POS

> ดู [AGENT-PLAYBOOK.md](../agents/PLAYBOOK.md) สำหรับกฎ + วิธีทำงานของทีม

## สถานะรวม

| WF ID | ชื่อ Feature | Phase | วันที่ | SPEC |
|-------|-------------|-------|-------|------|
| WF-PRE | Migration Consolidation (34 migrations) | ✅ DONE | 2026-06-14 | inline |
| WF-01 | Photo Upload — QR handoff + mobile camera | ✅ DONE | 2026-06-14 | [→](WORKFLOW-01-photo-upload.md) |
| WF-02 | Receipts — Type A normal + Type B precious metals | ✅ DONE | 2026-06-14 | [→](WORKFLOW-02-receipts.md) |
| WF-03 | Seller Search + Blacklist | ✅ DONE | 2026-06-14 | [→](WORKFLOW-03-seller-search-blacklist.md) |
| WF-04 | 4-Branch Dashboard + auto-refresh | ✅ DONE | 2026-06-15 | [→](WORKFLOW-04-dashboard.md) |
| WF-05 | _(ยังไม่กำหนด)_ | 🔲 TODO | — | — |

**Deadline**: 30 มิถุนายน 2569 (2026-06-30)  
**Progress**: 5/5 WF เสร็จ ✅

---

## เริ่ม WF ใหม่

```bash
# 1. spawn Explore agent → MAP
# 2. เขียน WORKFLOW-XX-name.md
# 3. BUILD: migration → api → frontend
# 4. TEST: db → curl → http → browser
```
