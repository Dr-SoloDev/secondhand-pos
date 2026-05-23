# Learnings

Corrections, insights, and knowledge gaps captured during development.

**Categories**: correction | insight | knowledge_gap | best_practice

---

## 2026-05-18 — ขายคุณค่าได้ผลกว่าขายราคา

**Category:** best_practice
**Context:** ปิดดีล POS ร้านรับซื้อของเก่า 4 สาขา

**Pattern-Key:** value-pricing-over-job-pricing

**Learning:**
ราคาเริ่มต้นที่ OpenClaw ประเมินคือ 22,000 บาท (Package A) — Dr.Solodev ตัดสินใจตั้งราคาที่ **35,000 บาท** ด้วยเหตุผล "scope งานลึกกว่าที่วิเคราะห์ + ขายผลงาน ไม่ได้ขายวิญญาณ" — สุดท้ายปิดดีลได้ที่ **40,000 บาท** (สูงกว่าตั้งเอง 5,000)

**Why it worked:**
1. ไม่อาสาลดราคาเอง (ลดเฉพาะเมื่อลูกค้าขอ)
2. Justify ราคาด้วย *การไปหน้างาน 4 สาขา + เทรนพนักงานถึงที่* (ไม่ใช่แค่ "ทำเสร็จส่งให้")
3. Demo ฟรี 7 วัน — ตัดความเสี่ยงลูกค้า → trust ขึ้น
4. ฟังปัญหาลูกค้าก่อน ไม่รีบเสนอ solution

**How to apply:**
- เมื่อประเมินราคา POS/SaaS custom สำหรับ SMB ไทย — ราคาเริ่มต้น 30,000+ ไม่ใช่ของแพง ถ้ามี service component (on-site visit, training)
- AI estimate (จาก code complexity) ต่ำเกินจริง — ไม่ได้นับ "การเดินทาง + การสื่อสาร + การ debug หน้างาน + emotional labor"
- Dr.Solodev mindset: "ขายผลงาน ไม่ได้ขายวิญญาณ" — ห้าม optimize for "ปิดดีลให้ได้" ตัดราคา

---

## 2026-05-18 — Trust > Price สำหรับลูกค้าที่เคยโดนทิ้งงาน

**Category:** insight
**Pattern-Key:** trust-first-for-burned-customers

**Learning:**
ลูกค้าที่เคยจ้างเดฟแล้วโดนทิ้งงาน 2 ครั้ง — เขาไม่ได้ sensitive เรื่องราคา เขา sensitive เรื่อง **ความน่าเชื่อถือ**

**Pattern ที่ทำงาน:**
- "ผมอยู่สุรินทร์เหมือนกัน" → ความใกล้เคียงทางกายภาพ = trust
- "Demo ฟรี 7 วัน ไม่พอใจไม่จ่าย" → กำจัด risk ฝั่งลูกค้า
- "ส่งมอบ 4 รอบ จ่ายตาม milestone" → ไม่ต้องจ่ายก้อนเดียวแล้วลุ้น
- "ซอร์สโค้ดเป็นของลูกค้า" → ถ้า dev หาย ก็มีของในมือ
- ฟังปัญหาเก่าก่อน เห็นใจ ไม่โทษคนเก่า

**Anti-pattern (อย่าทำ):**
- "เกือบครบครับ กำลังพัฒนาอยู่" → ลูกค้าจะ trigger trauma เก่า (คนเก่าก็พูดแบบนี้)
- ใช้ "ฟีเจอร์ที่ขาดคือ X ใช้เวลา Y สัปดาห์" — ตัวเลขชัดสร้าง trust

---

## 2026-05-18 — Docker Compose ดีกว่า apt install สำหรับ PHP/MySQL dev

**Category:** best_practice
**Pattern-Key:** docker-for-legacy-php-stack

**Learning:**
ตอนตั้ง dev environment สำหรับ goragodwiriya/pos-system (PHP+Apache+MySQL) — ใช้ Docker Compose แทน apt install ดีกว่ามาก

**Why:**
- ไม่ pollute เครื่อง dev ด้วย system packages
- Migration อัตโนมัติผ่าน MySQL /docker-entrypoint-initdb.d/
- Reset ทุกอย่างได้ด้วย docker compose down -v
- Reproducible — ลูกค้า/ทีมอื่นก็รันได้เหมือนกัน
- phpMyAdmin ติดมาฟรีบน port แยก

**How to apply:**
ใช้ pattern นี้กับทุกโปรเจกต์ legacy PHP — โดยเฉพาะที่ลูกค้าต้องลอง demo

---

## 2026-05-18 — ห้ามแก้ base repo ของ open source โดยตรง

**Category:** best_practice
**Pattern-Key:** customization-overlay-pattern

**Learning:**
เมื่อใช้ open source เป็นฐาน (เช่น goragodwiriya/pos-system) — สร้าง folder customizations/ แยก แทนการแก้ใน base-pos/ โดยตรง

**Structure:**
- code/base-pos/              # อย่าแตะ ยกเว้นจำเป็นจริงๆ
- code/customizations/api/Models/        # Model ใหม่
- code/customizations/api/Controllers/
- code/customizations/database/migrations/
- code/customizations/admin/             # Page ใหม่

**ข้อยกเว้นที่แก้ base-pos ได้:**
- config.php (ทำให้อ่าน env vars)
- Router.php (เพิ่ม routes ใหม่ — patch แบบ minimal + comment เหตุผล)

**Why:**
- Update upstream ได้ง่าย (git pull ใน base-pos ไม่ conflict)
- เห็นชัดว่าอันไหนของเราเขียนเอง vs ของเดิม
- Migration เป็นไฟล์แยก — เห็น history ของ schema changes

---

## 2026-05-18 — TaskUpdate API caveat

**Category:** knowledge_gap
**Pattern-Key:** taskupdate-taskid-string-required

**Learning:**
TaskUpdate tool require taskId เป็น string ไม่ใช่ number — แม้ว่า task IDs ที่เห็นจะเป็นตัวเลข 1, 2, 3 แต่ schema strict ตรง type validation บางครั้งก็ reject ทั้งคู่ workaround คือใช้ TaskCreate ใหม่แทน
