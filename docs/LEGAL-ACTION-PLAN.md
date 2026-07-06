# รายงานสถานะกฎหมายและการปฏิบัติตามกฎหมาย — ScrapPOS
**จัดทำโดย:** ฝ่ายกฎหมายและการปฏิบัติตามกฎหมาย (Legal/Compliance) — SoloCorp OS
**วันที่:** 6 กรกฎาคม 2569
**สถานะ:** CONFIDENTIAL — สำหรับผู้บริหารเท่านั้น

---

## 1. LEGAL STATUS — ความเสี่ยงทางกฎหมายในสถานะปัจจุบัน

### 1.1 ความเสี่ยงร้ายแรงระดับ CRITICAL

**ก. ม.357 ป.อ. — ภาระความรับผิดทางอาญา (โทษสูงสุด: จำคุก 5 ปี + ปรับ 100,000 บาท)**

Migration `34-032_add_precious_receipt_flag.sql` ตั้งค่า `requires_precious_receipt = 1` เฉพาะหมวด `โลหะมีค่า` เท่านั้น หมวดต่อไปนี้ยังมีค่า `requires_precious_receipt = 0`:

- **เศษเหล็ก** — เหล็กก่อสร้าง, ฝาท่อ (สินค้าที่ถูกลักขโมยบ่อยที่สุดในประเทศไทย)
- **เครื่องใช้ไฟฟ้า** — ตู้เย็น, แอร์, ทีวี (มีทองแดงภายใน)
- **แบตเตอรี่** — แบตรถยนต์ (ถูกลักขโมยจากยานพาหนะเป็นประจำ)
- **มือถือและอุปกรณ์** — มือถือ, แล็ปท็อป (สินค้าขโมยอันดับต้น)

โค้ด enforcement ใน `PurchaseOrdersController.php` (line 140-153) ทำงานถูกต้อง แต่ฐานข้อมูลที่มีค่าผิดพลาดทำให้การตรวจสอบ bypass ได้ทุกครั้ง — **compliance เป็นแค่ facade**

**ข. PDPA พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562**

| ปัญหา | กฎหมายที่เกี่ยวข้อง | โทษสูงสุด |
|---|---|---|
| ไม่มี HTTPS — ID card 13 หลักส่งผ่าน plaintext | PDPA ม.37(1) | ปรับ 3 ล้านบาท + จำคุก 1 ปี |
| admin/admin password — ข้อมูลส่วนบุคคลรั่วไหล | PDPA ม.37(3) | ปรับ 3 ล้านบาท |
| `pdpa_consented_at` มีใน schema แต่ไม่มีกระบวนการยืนยันใน UI | PDPA ม.19 | โทษทางแพ่ง |
| เอกสาร `LEGAL-357-SIGNOFF.md` สร้างโดย AI — ไม่ผ่านทนาย | ไม่มีผลผูกพัน | ความเสี่ยงต่อบริษัท |

**ค. พ.ร.บ. ควบคุมการขายทอดตลาดและค้าของเก่า พ.ศ. 2474 (ยังไม่ถูกระบุใน PRD)**

กฎหมายนี้กำหนดให้ผู้ประกอบการค้าของเก่าต้องมีสมุดบัญชีที่เจ้าพนักงานสามารถตรวจสอบได้ตลอดเวลา ระบบยังไม่มีฟีเจอร์ export report ในรูปแบบที่เจ้าพนักงานกำหนด

---

## 2. IMMEDIATE LEGAL ACTION

### วันนี้ (ก่อนสิ้นวันทำงาน):

**Action 1: แก้ไข SQL — เปิดใช้ requires_precious_receipt สำหรับทุกหมวดความเสี่ยง**

สร้าง migration ใหม่: `/home/drsolodev/projects/scrap-pos/code/docker/entrypoint/47-046_enforce_precious_receipt_all_risk_categories.sql`

```sql
UPDATE categories SET requires_precious_receipt = 1
WHERE name IN ('เศษเหล็ก', 'เครื่องใช้ไฟฟ้า', 'แบตเตอรี่', 'มือถือ', 'อุปกรณ์')
   OR name LIKE '%ทองแดง%'
   OR name LIKE '%สายไฟ%'
   OR name LIKE '%อลูมิเนียม%'
   OR name LIKE '%ทองเหลือง%'
   OR name LIKE '%ชิ้นส่วนรถ%';
```

**Action 2: เปลี่ยน default password ทันที**

**Action 3: เตรียมเอกสารสำหรับทนาย**

รวบรวม: source code enforcement logic, schema ปัจจุบัน, LEGAL-357-SIGNOFF.md, รายการหมวดหมู่สินค้า — ส่งให้ทนายภายใน 48 ชั่วโมง

### ก่อน Go-Live (ภายใน 1 sprint):

- **Action 4:** HTTPS บังคับ — SSL certificate + force redirect HTTP → HTTPS
- **Action 5:** Consent UI สำหรับ PDPA — screen ก่อนบันทึก ID card ทุกครั้ง
- **Action 6:** Signature pad หรือ digital signature — ใบรับซื้อต้องมีลายเซ็นเพื่อเป็นหลักฐานทางกฎหมาย

---

## 3. COMPLIANCE CHECKLIST

### ม.357 ป.อ.

| # | รายการ | สถานะ | หมายเหตุ |
|---|---|---|---|
| C1 | หมวดหมู่ความเสี่ยงทั้งหมดมี `requires_precious_receipt = 1` | **FAIL** | เฉพาะ โลหะมีค่า — เศษเหล็ก/เครื่องใช้ไฟฟ้า/แบตเตอรี่ ยัง 0 |
| C2 | บังคับบันทึก ID card ก่อนสร้าง PO สำหรับสินค้าความเสี่ยง | **PARTIAL** | Code ถูกต้อง แต่ DB ผิด |
| C3 | บันทึก ชื่อ/ที่อยู่/เลขบัตร/วันที่/รายละเอียดสินค้า | PASS | Seller model รองรับ |
| C4 | ระบบ Blacklist ผู้ขายต้องสงสัย | PASS | มีใน Seller model |
| C5 | ใบรับซื้อมีลายเซ็นผู้ขาย | **FAIL** | ไม่มี signature pad |
| C6 | สามารถ export record ให้เจ้าพนักงานตรวจได้ | **UNKNOWN** | ต้องตรวจสอบรูปแบบ report |
| C7 | ทนายรับรองว่าหมวดหมู่ครอบคลุมตาม ม.357 | **FAIL** | เอกสาร AI-generated ไม่มีผลผูกพัน |

### PDPA

| # | รายการ | สถานะ | หมายเหตุ |
|---|---|---|---|
| P1 | มีฐานทางกฎหมาย (Legal Obligation) สำหรับเก็บ ID card | PASS | ม.357 เป็น lawful basis |
| P2 | แจ้ง privacy notice ก่อนเก็บข้อมูล | PARTIAL | ข้อความมีใน LEGAL.md แต่ไม่ปรากฏใน UI |
| P3 | บันทึก consent timestamp | PARTIAL | schema มี แต่ยังไม่มี UI trigger |
| P4 | ส่งข้อมูลผ่าน HTTPS | **FAIL** | ยังไม่มี |
| P5 | ป้องกันการเข้าถึงโดยไม่ได้รับอนุญาต | **FAIL** | admin/admin ยังอยู่ |
| P6 | Data retention policy (เก็บ 5 ปี แล้วลบ) | PARTIAL | policy กำหนดแล้ว แต่ไม่มี automated deletion |
| P7 | สิทธิ์ขอดู/แก้ไข/ลบข้อมูลของเจ้าของข้อมูล | **FAIL** | ไม่มี UI สำหรับ data subject rights |

---

## 4. LAWYER ENGAGEMENT PLAN

### Phase 1 — ทนายอาญา/พาณิชย์ (ก่อน deploy)

**ช่วงเวลา:** ภายใน 2 สัปดาห์

**สิ่งที่ต้องให้ทนายตรวจสอบ:**
1. รายการหมวดหมู่สินค้าทั้งหมด — ยืนยันว่าครอบคลุมสินค้าที่ ม.357 กำหนด
2. ข้อความ consent notice ที่จะแสดงใน UI
3. กระบวนการบันทึกและเก็บรักษา records
4. รูปแบบ report ที่เจ้าพนักงานตำรวจต้องการ
5. เซ็น legal sign-off อย่างเป็นทางการ (แทนไฟล์ AI-generated)

**ค่าใช้จ่ายประมาณการ:** 15,000–30,000 บาท (consultation 2–3 ครั้ง)

### Phase 2 — ที่ปรึกษา PDPA (ภายใน 30 วันหลัง deploy)

1. ยืนยัน lawful basis สำหรับการเก็บ ID card
2. ตรวจสอบ data retention policy
3. ออกแบบกระบวนการ data subject rights
4. ตรวจสอบ vendor agreement ระหว่าง SoloCorp OS กับลูกค้า

### Phase 3 — Annual Review

ทบทวนการปฏิบัติตามกฎหมายปีละครั้ง

---

## 5. ONGOING COMPLIANCE

### Technical Controls

| Control | การ implement | ความถี่ตรวจสอบ |
|---|---|---|
| บังคับ ID card ทุกหมวดความเสี่ยง | Database flag + API enforcement | ทุก deployment |
| HTTPS บน production | SSL cert + force redirect | ต่ออายุปีละครั้ง |
| Password policy | Min 12 chars | ทุก release |
| Audit log การเข้าถึงข้อมูล seller | Log ทุก read/write | ตรวจสอบรายเดือน |

### Incident Response Plan

1. บันทึกเหตุการณ์ในระบบทันที
2. แจ้งตำรวจภายใน 24 ชั่วโมง (requirement ตาม พ.ร.บ. ค้าของเก่า)
3. แจ้ง PDPA regulator หากข้อมูลส่วนบุคคลรั่วไหล ภายใน 72 ชั่วโมง (PDPA ม.37(4))
4. แจ้ง SoloCorp OS ฝ่าย Legal ทันที

---

## ไฟล์ที่เกี่ยวข้อง

- `code/docker/entrypoint/34-032_add_precious_receipt_flag.sql` — migration ที่มีช่องโหว่
- `code/docker/entrypoint/06-005_seed_categories.sql` — seed data ที่ต้องอัพเดท
- `code/customizations/api/Controllers/PurchaseOrdersController.php` — enforcement logic (ถูกต้อง แต่ขึ้นอยู่กับ DB)
- `docs/LEGAL-357-SIGNOFF.md` — ต้องแทนที่ด้วยเอกสารที่ทนายเซ็น
