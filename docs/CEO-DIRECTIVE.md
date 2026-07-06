# คำสั่งผู้บริหารสูงสุด — SCRAP-POS LAUNCH DECISION
**จาก:** เทอโบ (CEO, SoloCorp)
**วันที่:** 6 กรกฎาคม 2569
**สถานะ:** BINDING DIRECTIVE — มีผลทันที

---

## 1. STRATEGIC VERDICT

**NO-GO — ยังไม่ launch**

ไม่ใช่เพราะ product ไม่พร้อม — product ดีกว่า 80% ของคู่แข่งในตลาดนี้แล้ว

แต่เพราะ **ระบบที่ deploy ออกไปวันนี้มีโอกาสทำให้ลูกค้าติดคุก** — ไม่ใช่ตัวเรา แต่ลูกค้าเรา คนที่เราบอกว่า "มีศักดิ์ศรี" G3 ยังไม่ activate ก็เท่ากับเราขาย compliance software ที่ไม่ comply กับกฎหมายจริง นั่นคือการโกหก และ SoloCorp ไม่โกหกลูกค้า

เงื่อนไข go-live มี **3 ข้อ และต้องครบทั้ง 3:**
1. G3 SQL activate แล้วและทดสอบแล้ว
2. admin/admin password ไม่มีใน schema production
3. HTTPS configured บน server target

ทั้ง 3 ข้อนี้ไม่ใช่ optional — เป็น **legal minimum** และ **security minimum**

---

## 2. PRIORITY DIRECTIVE (เรียงตามความเร่งด่วน)

**#1 — Ship G3 SQL วันนี้ก่อนเลิกงาน (ประมาณ 15-30 นาที)**

เอา SQL migration ของ G3 categories ไปรันบน production schema ทันที ทดสอบว่า flag สินค้าเสี่ยงทำงานได้จริง บันทึกผลเก็บไว้เป็น legal audit trail

**#2 — ปิด admin/admin และ configure HTTPS ก่อน server ใดๆ เปิด public (48 ชั่วโมง)**

ไม่มีเหตุผลใดที่ default credential จะยังอยู่ในระบบที่จะ go-live HTTPS ต้องมี cert และ redirect จาก HTTP ทุก endpoint ก่อน production traffic แรกเข้า

**#3 — Build CSV seller import ก่อน onboard สาขาแรก**

4 สาขา มีข้อมูล seller อยู่แล้ว ถ้าไม่มี migration tool ก็ onboard ไม่ได้

---

## 3. RESOURCE ALLOCATION

| งาน | ผู้รับผิดชอบ | Deadline |
|---|---|---|
| G3 SQL migration | Dev | วันนี้ |
| Remove admin/admin + HTTPS | Dev + Infra | 48 ชั่วโมง |
| CSV seller import tool | Dev | ก่อน pilot สาขาแรก |
| G5 transport cost in schema | Dev | ก่อน lot ถัดไปหลัง launch |
| ทนายตรวจ LEGAL-357-SIGNOFF.md | CEO track โดยตรง | ภายใน 1 สัปดาห์ |
| CSS quick wins (5 fixes) | Design/Dev | ระหว่าง sprint นี้ — ไม่ block launch |
| G2 Signature pad | รอ decision ใน sprint planning ถัดไป | — |
| G4 Employee module | Backlog — ไม่ใช่ MVP | — |

---

## 4. RISK ACCEPTANCE

**ยอมรับไม่ได้ (Hard Stop):**
- G3 ไม่ activate — ความเสี่ยงอาญาตกที่ลูกค้า
- admin/admin บน production server — one breach = ข้อมูล 4 สาขารั่ว
- No HTTPS บน public server — MITM attack เปิดกว้าง

**ยอมรับได้แบบมีเงื่อนไข:**
- G5 transport cost ขาด — launch ได้แต่ต้อง communicate กับลูกค้าว่า profit ยัง approximate, fix ก่อน lot ถัดไป
- G2 Signature pad ขาด — pilot ได้โดยใช้ paper signature ชั่วคราว ต้องมี workaround ชัดเจนก่อน onboard
- Audit log ครอบคลุมไม่ครบ — acceptable ใน pilot phase
- CSS/Design issues — ยอมรับได้ใน v1 ไม่ block launch

**ยอมรับได้ระยะยาว:**
- Design score 6.2/10 — polish ได้หลัง launch
- G4 Employee module ขาด — scope creep ใน v1
- Operational Readiness 4/10 — ทำได้ parallel กับ development

---

## 5. GO/NO-GO DECISION

**NO-GO — จนกว่าจะปิด 3 blockers ข้างต้น**

เมื่อ G3 SQL activate, admin/admin ถูกลบ, และ HTTPS config แล้ว → **GO สำหรับ pilot 1 สาขา**

ไม่ใช่ full launch — pilot ก่อน 1 สาขา 2-4 สัปดาห์ เก็บ feedback real-world แล้วค่อย roll out สาขาที่เหลือ

ประเมินว่า 3 blockers ปิดได้ใน **72 ชั่วโมง** ถ้าทีมโฟกัส

Product ดี Vision ดี ฐานแข็ง — ถ้า miss legal ตอนนี้ ทุกอย่างที่สร้างมาไม่มีความหมาย

**เริ่มเดี๋ยวนี้**

— เทอโบ, CEO SoloCorp
