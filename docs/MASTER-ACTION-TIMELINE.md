# MASTER ACTION TIMELINE — scrap-pos
**จัดทำโดย:** SoloCorp OS Exec Council
**วันที่:** 6 กรกฎาคม 2569 | **เวอร์ชัน:** 1.0

---

## PRODUCT READINESS SCORE — CURRENT vs TARGET

| มิติ | ตอนนี้ | หลัง CSS+SQL | หลัง G5+CSV | หลัง Full Deploy |
|---|---|---|---|---|
| Design/UX | 6.2/10 | **7.5/10** | 7.5/10 | 7.5/10 |
| Legal/Compliance | 5.0/10 | 6.5/10 | 7.0/10 | **8.5/10** |
| Technical | 4.0/10 | 5.5/10 | **7.0/10** | 8.0/10 |
| Operational | 4.0/10 | 4.5/10 | 6.0/10 | **8.5/10** |
| **Overall** | **4.8/10** | **6.0/10** | **6.9/10** | **8.1/10** |

**NO-GO จนกว่าจะปิด 3 blockers:** G3 SQL, admin/admin, HTTPS

---

## PHASE 0 — CRITICAL BLOCKERS (วันที่ 6-7 ก.ค. 2569)

**เจ้าของ:** CTO + Lead Dev

| # | Action | เวลา | Owner | Status |
|---|---|---|---|---|
| B1 | สร้าง `47-046_enforce_precious_receipt_all_risk_categories.sql` | 15 นาที | CTO | ❌ TODO |
| B2 | รัน migration บน staging → production | 15 นาที | CTO | ❌ TODO |
| B3 | เปลี่ยน admin/admin password ทุก environment | 10 นาที | CTO | ❌ TODO |
| B4 | ตั้งค่า HTTPS (Cloudflare Tunnel หรือ Let's Encrypt) | 2-3 ชม. | CTO | ❌ TODO |
| B5 | Apply CSS 5 fixes (tokens, tables, buttons, purchase-orders) | 45 นาที | Dev | ❌ TODO |

**CEO NO-GO จนกว่า B1-B4 เสร็จ**

---

## PHASE 1 — PRE-LAUNCH PREP (7-20 ก.ค. 2569)

### สัปดาห์ที่ 1 (7-13 ก.ค.)

| วัน | Action | เจ้าของ |
|---|---|---|
| 7 ก.ค. | Apply migrations 043-045 บน production | CTO |
| 7 ก.ค. | ส่งเอกสาร Legal package ให้ทนาย | Legal + CEO |
| 8 ก.ค. | รัน full test suite — ต้องผ่านทั้งหมด | Dev |
| 8-9 ก.ค. | G5 transport cost migration SQL + frontend | Dev |
| 10 ก.ค. | ทดสอบ backup/restore 1 รอบสมบูรณ์ | Ops |
| 11-13 ก.ค. | Excel/CSV migration tool `/admin/import-sellers.php` | Dev |

### สัปดาห์ที่ 2 (14-20 ก.ค.)

| วัน | Action | เจ้าของ |
|---|---|---|
| 14-16 ก.ค. | Data collection สาขานำร่อง (template Excel → ผจก.) | Ops |
| 14 ก.ค. | Creator: เริ่มผลิต Demo video 5 นาที | Creator |
| 15 ก.ค. | Marketing: Infographic ม.357 + Compliance Checklist | Marketing |
| 17-18 ก.ค. | Train the Trainer — SoloCorp team | HR |
| 17-18 ก.ค. | Data validation + dry-run migration | CTO + Ops |
| 19-20 ก.ค. | Full staff training สาขานำร่อง | HR |
| 19 ก.ค. | Data migration จริง สาขานำร่อง | CTO |
| 20 ก.ค. | Go/No-Go review ก่อน pilot | CEO + ทีม |

---

## PHASE 2 — PILOT LAUNCH (21-27 ก.ค. 2569)

| วัน | Action | เจ้าของ |
|---|---|---|
| 21 ก.ค. | **สาขานำร่อง GO-LIVE** | Ops |
| 21 ก.ค. | Marketing: Facebook Group launch post + YouTube demo video | Marketing + Creator |
| 21-27 ก.ค. | Monitor daily: errors, feedback, support tickets | Ops |
| 22 ก.ค. | Marketing: LINE OA launch + "ทดลองฟรี 3 เดือน" post | Marketing |
| 24 ก.ค. | Data collection สาขา 2 | Ops |
| 25-26 ก.ค. | Training สาขา 2 | HR |
| 27 ก.ค. | **Pilot Review:** < 5 tickets = GO สาขา 2 / critical = HOLD | CEO |

---

## PHASE 3 — FULL ROLLOUT (28 ก.ค. — 11 ส.ค. 2569)

| วัน | Milestone |
|---|---|
| 28 ก.ค. | สาขา 2 GO-LIVE |
| 28-30 ก.ค. | Data collection สาขา 3 |
| 1-2 ส.ค. | Training สาขา 3 |
| 4 ส.ค. | สาขา 3 GO-LIVE |
| 4-6 ส.ค. | Data collection สาขา 4 |
| 7-8 ส.ค. | Training สาขา 4 |
| 11 ส.ค. | **สาขา 4 GO-LIVE — Full Deployment Complete** |

---

## PHASE 4 — STABILIZATION + GROWTH (12 ส.ค. เป็นต้นไป)

| เวลา | Action | เจ้าของ |
|---|---|---|
| สัปดาห์ 7 | Post-launch survey ทุกสาขา | HR |
| สัปดาห์ 7 | Sales: Follow-up กับ leads ทั้งหมดจาก pilot | Sales |
| เดือน 1 หลัง launch | PDPA lawyer review Phase 2 | Legal |
| เดือน 1 | G2 Signature pad — decision + spec | CEO + Legal |
| เดือน 2 | G4 Employee module — spec + build | Product + Dev |
| เดือน 2 | Sidebar PHP include refactor | Dev |
| เดือน 3 | CSV seller import tool (ถ้ายังไม่เสร็จ) | Dev |
| เดือน 3 | API versioning `/api/v1/` | Dev |
| Q4 | Queue system (ถ้าขยายเกิน 5 สาขา) | CTO |
| Annual | Legal compliance review ม.357 | Legal |

---

## TASK OWNERSHIP MATRIX

| Task | Product | CTO/Dev | Legal | Ops | HR | Marketing | Creator | Sales |
|---|---|---|---|---|---|---|---|---|
| G3 SQL fix | — | **LEAD** | Review | — | — | — | — | — |
| admin/admin | — | **LEAD** | — | Verify | — | — | — | — |
| HTTPS | — | **LEAD** | — | Verify | — | — | — | — |
| CSS fixes | Review | **LEAD** | — | — | — | — | — | — |
| G5 transport | **LEAD** | Build | — | — | — | — | — | — |
| CSV import | **LEAD** | Build | — | **LEAD** | — | — | — | — |
| Staff training | — | Support | — | **LEAD** | **LEAD** | — | — | — |
| Demo video | Brief | — | Verify msg | — | — | Brief | **LEAD** | — |
| GTM launch | — | — | — | — | — | **LEAD** | **LEAD** | **LEAD** |
| Pilot leads | — | — | — | — | — | Generate | — | **LEAD** |
| Lawyer package | Support | Support | **LEAD** | — | — | — | — | — |

---

## BUDGET SUMMARY (ประมาณการ)

| รายการ | ค่าใช้จ่าย |
|---|---|
| ทนายความ Phase 1 (ม.357) | 15,000–30,000 บาท |
| ทนายความ Phase 2 (PDPA) | 15,000–25,000 บาท |
| Marketing Month 1 (Facebook + LINE OA) | 8,000 บาท |
| Creator content (video production) | 5,000–15,000 บาท |
| Cloudflare Tunnel / SSL | ฟรี – 3,000 บาท/ปี |
| **รวม (ไม่รวม dev time)** | **~50,000–80,000 บาท** |

---

## SCORE PROJECTION

```
วันนี้ (6 ก.ค.):       4.8/10   [NO-GO — 3 blockers open]
หลัง Phase 0 (7 ก.ค.): 6.0/10   [blockers closed, CSS fixed]
หลัง Phase 1 (20 ก.ค.): 6.9/10  [G5, CSV tool, migrations applied]
หลัง Pilot (27 ก.ค.):  7.5/10   [real-world validation]
หลัง Full Deploy (11 ส.ค.): 8.1/10  [4 สาขา live]
หลัง Lawyer + G2 (ส.ค.):   8.7/10  [compliance complete]
หลัง G4 Employee (ก.ย.):   9.0/10  [full feature set]
```

---

## DOCUMENT INDEX

| เอกสาร | สถานะ | เจ้าของ |
|---|---|---|
| `docs/EXEC-BRIEFING.md` | ✅ สร้างแล้ว | Support |
| `docs/CEO-DIRECTIVE.md` | ✅ สร้างแล้ว | CEO |
| `docs/LEGAL-ACTION-PLAN.md` | ✅ สร้างแล้ว | Legal |
| `docs/CTO-TECHNICAL-PLAN.md` | ✅ สร้างแล้ว | CTO |
| `docs/OPS-DEPLOYMENT-PLAN.md` | ✅ สร้างแล้ว | Ops |
| `docs/HR-CHANGE-MANAGEMENT.md` | ✅ สร้างแล้ว | HR |
| `docs/GTM-JOINT-PLAN.md` | ✅ สร้างแล้ว | Sales/Marketing/Creator |
| `docs/MASTER-ACTION-TIMELINE.md` | ✅ สร้างแล้ว (ไฟล์นี้) | Exec Council |
| `docs/LEGAL-357-SIGNOFF.md` | ⚠️ ต้องแทนด้วยเอกสารทนาย | Legal |
