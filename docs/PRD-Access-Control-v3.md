# PRD v3: Access Control, Branch Scope และ Audit Trail

**โครงการ:** Secondhand POS (รับซื้อของเก่า)
**เจ้าของระบบ:** Dr.solodev
**วันที่:** 5 สิงหาคม 2569
**สถานะ:** Baseline สำหรับ implementation และ test review
**แหล่งอ้างอิงหลัก:** เอกสารฉบับนี้เท่านั้น
**หลักฐานการส่งมอบ:** `W10-ACCESS-CONTROL-RELEASE-GATE.md`

เอกสาร `PRD-Access-Control-Data-Security-v1.md`, `PRD-Access-Control-Data-Security-v2.md` และไฟล์ PRD ที่อยู่ใน Downloads เป็นเอกสารประวัติการตัดสินใจ ไม่ใช้เป็นเกณฑ์ตัดสินสิทธิ์เมื่อขัดกับ v3

## 1. วัตถุประสงค์

กำหนดสิทธิ์ 4 ระดับให้ตรงกับงานจริงของร้านหลายสาขา โดยทุกการอนุญาตต้องตรวจทั้งสิทธิ์ของ role และขอบเขตสาขาของข้อมูล/เอกสาร ไม่พึ่งการซ่อนเมนูหน้าเว็บเป็นกลไกความปลอดภัย

ผลลัพธ์ที่ต้องได้:

- Cashier ทำงานประจำวันของสาขาตนเองได้จบ flow
- Manager อนุมัติงานของสาขาตนเองได้โดยไม่ต้องรอ Owner
- Super manager ทำงานข้ามสาขาได้ แต่ไม่สามารถยกระดับสิทธิ์ตนเองเป็น Owner
- Admin/Owner จัดการธุรกรรมทุกสาขาและอนุมัติรายการของตนเองได้ตามนโยบายที่บันทึกไว้
- ทุกการเปลี่ยนแปลงข้อมูลและการเข้าถึงข้อมูลอ่อนไหวตรวจสอบย้อนหลังได้
- UI และ API ใช้ permission matrix เดียวกัน

## 2. โมเดลสิทธิ์

### 2.1 Role identifiers

ใช้ค่า role เดิมในฐานข้อมูลและ JWT เท่านั้น:

- `cashier` — พนักงานรับซื้อ/แคชเชียร์ประจำสาขา
- `manager` — ผู้จัดการสาขาเดียว มี `branch_id` บังคับ
- `super_manager` — ผู้จัดการข้ามสาขา โดยปกติไม่มีสาขาประจำ
- `admin` — Owner/ผู้ดูแลระบบเต็มสิทธิ์

ไม่เพิ่ม role ใหม่ในรอบนี้

### 2.2 หลักการอนุญาต

1. ตรวจ authentication ก่อนเสมอ: ไม่ login ได้ `401`
2. ตรวจ action permission จาก backend: role ไม่ได้รับอนุญาตได้ `403`
3. ตรวจ branch scope หลังโหลด resource จากฐานข้อมูล ไม่เชื่อ `branch_id` จาก request ของผู้ใช้ที่เป็น non-admin
4. `admin` และ `super_manager` อ่าน/จัดการข้ามสาขาได้ตาม action ที่ matrix ระบุ
5. ผู้สร้างคำขอห้ามเป็นผู้อนุมัติคำขอเดียวกัน ยกเว้น `admin` ตามกติกา self-approval ใน matrix และข้อ 3.2-3.3
6. การแสดงเมนูเป็น UX เท่านั้น การยิง URL/API โดยตรงต้องได้ผลลัพธ์เดียวกับการกดผ่าน UI
7. หาก action ไม่ได้ระบุไว้ ให้ใช้หลัก least privilege และปฏิเสธไว้ก่อน

### 2.3 ขอบเขตข้อมูลกลางและข้อมูลสาขา

- **ข้อมูลกลางทุกสาขา:** ผู้ขาย, blacklist, tier ผู้ขาย, แคตตาล็อก และบอร์ดราคา การแก้ไขมีผลกับร้านทุกสาขาและต้องมี audit log
- **ข้อมูลประจำสาขา:** ใบรับซื้อ, สต็อก, Sale Lot, เงินสด, ค่าใช้จ่าย, พนักงาน และเอกสารโอน ใช้ branch scope บังคับ
- **ผู้ขาย:** cashier ทุกสาขาเห็นข้อมูลผู้ขายข้ามสาขาได้ เป็นนโยบายธุรกิจที่เจ้าของระบบยืนยันแล้ว

## 3. Permission Matrix

คำว่า “สาขาตน” หมายถึง `branch_id` ใน JWT ตรงกับสาขาของ resource และ “ทุกสาขา” หมายถึงเลือกสาขาได้เฉพาะ role ที่ได้รับอนุญาต

| โมดูล | Cashier | Manager | Super manager | Admin/Owner |
|---|---|---|---|---|
| หน้าหลัก | ดูสรุปสาขาตน | ดูสรุปสาขาตน | ดูทุกสาขา | ดูทั้งหมด |
| รับซื้อ (PO) | ดู/สร้าง/พิมพ์/ขอยกเลิกสาขาตน | สิทธิ cashier + อนุมัติ/ปฏิเสธคำขอยกเลิกของสาขาตน (ห้ามอนุมัติของตน) | ทุกสาขาและพิจารณาข้ามสาขา (ห้ามอนุมัติของตน) | ทั้งหมด อนุมัติของตนเองได้ |
| แคตตาล็อก | ดู/เพิ่ม/แก้รายการและราคา ห้ามลบ | สิทธิ cashier + จัดการหมวด/ปิดใช้งาน | จัดการ master data ได้ทุกสาขา | ทั้งหมด รวมลบถาวร |
| ผู้ขาย | ดู/เพิ่ม/แก้ข้อมูล/กำหนด tier ทุกสาขา; ดูรูปได้และต้อง log | สิทธิ cashier + blacklist/unblacklist | ทั้งหมดข้ามสาขา | ทั้งหมด |
| คงคลัง | อ่านเฉพาะสาขาตน | อ่านเฉพาะสาขาตน; ไม่ปรับยอดตรง | อ่านทุกสาขา; ปรับผ่านเอกสารที่อนุญาต | ทั้งหมดและเอกสารปรับปรุง |
| ขาย Lot | ไม่มีสิทธิ์เข้าหน้าธุรกรรม | สร้าง/แก้/ยืนยัน/ยกเลิก/บันทึกรายรับ เฉพาะสาขาตน | ทุกสาขา | ทั้งหมด |
| รายงาน | รายงานปฏิบัติการสาขาตน | รายงานเต็มสาขาตน | รายงานทุกสาขา | ทั้งหมด |
| พนักงาน | ไม่มีสิทธิ์ | ดู/เพิ่ม/แก้/ปิดใช้งานเฉพาะสาขาตน | จัดการพนักงานทุกสาขา | ทั้งหมด |
| ค่าใช้จ่าย | ดู/สร้าง/ยกเลิกคำขอตนเองในสาขาตน | ดู/อนุมัติ/ปฏิเสธตามสาขาและวงเงิน | ดู/อนุมัติ/ปฏิเสธทุกสาขาตามวงเงิน | ทั้งหมดและอนุมัติของตนเองได้ |
| โอนสต็อก | สร้างจากสาขาตน; ตรวจรับเฉพาะรายการรอเข้าสาขาตน; ห้ามยกเลิก/ย้อนกลับ | สิทธิ cashier + ยกเลิก/พิจารณา/อนุมัติตาม state ในสาขาตน | ทุกสาขา | ทั้งหมดและ override ได้ |
| บอร์ดราคา | ดู/พิมพ์ | ดู/พิมพ์ | ดู/พิมพ์ | ดู/แก้/พิมพ์ |
| เปิด-ปิดยอด | เปิด/ปิด/ขอเติมเงินสดสาขาตน | สิทธิ cashier + อนุมัติ/ปฏิเสธคำขอของผู้อื่นในสาขาตน | ทุกสาขา (ห้ามอนุมัติของตน) | ทั้งหมด รวม self-approval และ reopen |
| ผู้ใช้งาน | ไม่มีสิทธิ์ | ไม่มีสิทธิ์ | จัดการเฉพาะ `cashier`/`manager` และห้ามเปลี่ยนเป็น role สูงกว่า | จัดการทุก role |
| ตั้งค่า | ไม่มีสิทธิ์ | ตั้งค่าปฏิบัติการของสาขาตนเท่านั้น | ตั้งค่าปฏิบัติการทุกสาขา | ตั้งค่าระบบ/สาขา/backup/security ทั้งหมด |
| Audit Log | ไม่มีสิทธิ์ | ไม่มีสิทธิ์ | ไม่มีสิทธิ์ | ดู/ค้นหา/export; ห้ามแก้หรือลบ |

### 3.1 กติกาเฉพาะผู้ขาย

- Cashier เปลี่ยน `tier_level` 1-3 ได้อิสระตามนโยบายเจ้าของระบบ
- Cashier ห้าม blacklist/unblacklist เว้นแต่เจ้าของระบบเพิ่มสิทธิ์ภายหลัง
- `getSellerDataCenter()` และ `viewPhoto()` ถือเป็นการดูข้อมูลอ่อนไหว ต้องมี audit event ทุกครั้ง
- ห้ามเขียนเลขบัตรประชาชน รูปบัตร, password, JWT หรือ secret ลง audit description

### 3.2 กติกาโอนสต็อก

- การสร้างใบโอนปกติยังไม่ย้ายยอดจนกว่าจะตรวจรับสำเร็จ
- `confirm` ต้องตรวจว่าใบโอนมีอยู่จริง สถานะรอตรวจรับ และ `to_branch_id` ตรงกับสาขาผู้รับ
- น้ำหนักรับจริงต่ำกว่าใบโอนได้เมื่อระบุหมายเหตุ แต่ห้ามสูงกว่าน้ำหนักของรายการในใบโอน
- การขอย้อนกลับทำได้เฉพาะผู้มีสิทธิ์ของสาขาที่เกี่ยวข้อง และผู้สร้างคำขอห้ามอนุมัติคำขอเดียวกัน
- การอนุมัติย้อนกลับของ manager ต้องเป็นรายการที่อยู่ในขอบเขตสาขาตน และไม่ใช่คำขอของตนเอง
- Owner สามารถ override และ self-approve ได้ แต่ต้องบันทึกเหตุผลและ flag `self_approved`

### 3.3 กติกาเงินสดและค่าใช้จ่าย

- Cashier เปิด/ปิดยอดได้เฉพาะสาขาตนเอง
- Manager อนุมัติคำขอเปิด/ปิดยอดของสาขาตนได้เมื่อไม่ใช่ผู้ยื่นคำขอ
- ใช้วงเงินปัจจุบันเป็นค่าเริ่มต้น: manager ไม่เกิน 500 บาท, super manager ไม่เกิน 5,000 บาท, admin ไม่จำกัด
- ผู้ยื่นคำขอห้ามอนุมัติเองสำหรับทุก role ยกเว้น admin
- การ reopen รอบที่ปิดแล้วเป็น admin-only

## 4. Audit Trail

### 4.1 เหตุการณ์ที่ต้องบันทึก

บันทึกทุก backend business mutation และ security-sensitive action:

- สร้าง/แก้ไข/ลบ/ปิดใช้งาน/ยกเลิกเอกสาร
- ขออนุมัติ/อนุมัติ/ปฏิเสธ/ย้อนกลับ/self-approve
- เปิดดูรายละเอียดผู้ขาย, data center, รูปบัตร และการ export/print ข้อมูลสำคัญ
- login สำเร็จ/ล้มเหลว, logout, เปลี่ยนรหัสผ่าน, เปลี่ยน role/status และความพยายามที่ถูกปฏิเสธ
- autocomplete/list ทั่วไปไม่ต้องสร้าง event รายแถว แต่ endpoint ต้องมี access log แบบ aggregate หรือ policy ที่กำหนดชัดเจน

### 4.2 โครงสร้าง event ขั้นต่ำ

`actor_id`, `actor_role`, `actor_branch_id`, `action`, `module`, `entity_type`, `entity_id`, `entity_branch_id`, `outcome`, `reason`, `before_json`, `after_json`, `self_approved`, `ip_address`, `user_agent`, `request_id`, `created_at`

`actor_role` และ `actor_branch_id` ต้องเป็น snapshot ณ เวลาทำรายการ ห้ามอาศัย role ปัจจุบันใน users ตอนค้นย้อนหลัง เพราะผู้ใช้สามารถเปลี่ยน role ภายหลังได้

Audit เป็น append-only ในระดับ application: ไม่มี endpoint แก้ไข/ลบ และ UI admin มีเฉพาะดู/ค้นหา/export การลบตาม retention ในอนาคตต้องเป็น migration/job ที่บันทึกเหตุการณ์ระบบแยกต่างหาก

### 4.3 การค้นหา

หน้าจอเดียวต้องรองรับ server-side pagination และ filter ร่วมกันแบบ AND:

- `start_datetime`, `end_datetime`
- role snapshot: `cashier`, `manager`, `super_manager`, `admin`
- branch, user, module/action, entity/reference, outcome
- keyword ในคำอธิบายที่ผ่านการปกปิดข้อมูล

ดัชนีขั้นต่ำ: `(created_at, id)`, `(actor_role, created_at)`, `(actor_branch_id, created_at)`, `(action, created_at)`, `(entity_type, entity_id, created_at)`

## 5. แผนงานย่อย

แต่ละ package ต้อง merge และทดสอบแยกกัน ไม่รวม permission หลายโมดูลใน commit เดียวโดยไม่มีเหตุผล

| Package | ขอบเขต | ผลส่งมอบ/เกณฑ์ผ่าน |
|---|---|---|
| W0 | Freeze matrix, route inventory, fixtures | ตาราง endpoint ครบ; fixture cashier/manager 2 สาขา/super/admin พร้อมใช้งาน |
| W1 | Central authorization policy | policy กลางแยก action กับ branch scope; controller ไม่รับสิทธิจาก UI |
| W2 | PO, catalog, seller tier | P0 actions ผ่าน positive/negative/self tests; cashier แก้ tier ได้; delete catalog ยัง admin-only |
| W3 | Stock transfer state | cashier สร้าง/รับได้; ผิดปลายทาง 403; manager reversal/cancel ตาม state; ไม่มี double confirm |
| W4 | Sale Lot และ inventory | manager ใช้ Sale Lot สาขาตนได้; cashier อ่าน inventory เท่านั้น; direct adjustment ไม่เปิดเกิน matrix |
| W5 | Cash session และ expenses | manager review own branch; owner self-approval; วงเงินและห้ามผู้ยื่นอนุมัติเองถูกทดสอบ |
| W6 | Employees, users, settings | manager branch-only; super จัดการ role ต่ำกว่าเท่านั้น; settings แยก global/branch; backup admin-only |
| W7 | UI permission map | ทุกหน้าใช้ permission response เดียวกัน; direct URL/API ให้ผลเหมือนกัน; cashier ไม่เห็น Sale Lot |
| W8 | Audit schema/search | structured event, snapshot role/branch, PII redaction, filter/pagination/export admin-only |
| W9 | Seller ID encryption | AES-256 + search hash, key จาก environment, migration staging/backup/rollback verified |
| W10 | Release gate | PHP lint, unit/integration/API/browser tests, security negative matrix 100%, owner sign-off |

ลำดับแนะนำ: `W0 → W1 → W2 → W3 → W4 → W5 → W6 → W7 → W8 → W9 → W10`

## 6. Test Matrix ก่อนแก้โค้ด

### 6.1 Test fixtures

สร้างข้อมูลทดสอบแบบ deterministic:

- Branch A และ Branch B
- `cashier-A`, `cashier-B`
- `manager-A`, `manager-B`
- `super-manager`, `admin`
- เอกสาร/ผู้ขาย/สต็อกที่ผูก Branch A และ Branch B
- ผู้ใช้คำขอและผู้ใช้อนุมัติแยกคนกัน ยกเว้นกรณี admin self-approval

### 6.2 Backend authorization matrix

| ID | Actor | Action | Resource/scope | Expected |
|---|---|---|---|---|
| AUTH-01 | anonymous | `GET /purchase-orders` | ไม่มี token | `401` |
| AUTH-02 | cashier-A | `POST /purchase-orders` | `branch_id=A` | `2xx` + audit create |
| AUTH-03 | cashier-A | `POST /purchase-orders` | `branch_id=B` | `403` |
| AUTH-04 | cashier-A | `POST /purchase-orders/cancel` | PO สาขา A | `2xx` + cancellation request |
| AUTH-05 | cashier-A | `POST /purchase-orders/cancellation-approve` | คำขอใดๆ | `403` |
| AUTH-06 | manager-A | approve PO cancellation | PO สาขา A, requester คนอื่น | `2xx` + role snapshot |
| AUTH-07 | manager-A | approve PO cancellation | PO สาขา B | `403` |
| AUTH-08 | manager-A | approve own request | requester = manager-A | `4xx` |
| AUTH-09 | cashier-A | create/update catalog | master catalog | `2xx` + audit before/after |
| AUTH-10 | cashier-A | delete catalog | catalog item | `403` |
| AUTH-11 | cashier-A | update seller tier | seller ใดก็ได้ | `2xx` + `update_seller_tier` |
| AUTH-12 | cashier-A | blacklist seller | seller ใดก็ได้ | `403` |
| AUTH-13 | manager-A | create/confirm Sale Lot | branch A | `2xx` |
| AUTH-14 | manager-A | update/cancel Sale Lot | branch B | `403` |
| AUTH-15 | cashier-A | read inventory | branch A | `2xx` |
| AUTH-16 | cashier-A | read inventory | บังคับ `branch_id=B` | `2xx` แต่ผลต้องถูกบังคับเป็น Branch A และห้ามมีข้อมูล B |
| AUTH-17 | cashier-A | direct inventory adjustment | branch A | `403`; การปรับยอดต้องใช้เอกสาร workflow แยก |
| AUTH-18 | cashier-A | create stock transfer | from A to B | `2xx` |
| AUTH-19 | cashier-A | create stock transfer | from B to A | `403` |
| AUTH-20 | cashier-B | confirm transfer | pending to B | `2xx` + stock movement |
| AUTH-21 | cashier-A | confirm transfer | pending to B | `403` |
| AUTH-22 | cashier-A | cancel/reversal approve transfer | transfer ใดๆ | `403` |
| AUTH-23 | manager-A | transfer review | resource outside A | `403` |
| AUTH-24 | cashier-A | open/close cash session | branch A | `2xx` |
| AUTH-25 | cashier-A | open/close cash session | branch B | `403` |
| AUTH-26 | manager-A | approve cash request | branch A, requester คนอื่น | `2xx` |
| AUTH-27 | manager-A | approve cash request | branch B | `403` |
| AUTH-28 | admin | approve own cash/expense/PO request | own resource | `2xx` + `self_approved=1` |
| AUTH-29 | super-manager | user management | create/update cashier/manager | `2xx` |
| AUTH-30 | super-manager | user management | create/update admin/super-manager | `403` |
| AUTH-31 | manager-A | settings write | branch A setting | `2xx` |
| AUTH-32 | manager-A | settings write | global/security/backup | `403` |
| AUTH-33 | super-manager | cross-branch report | branch A/B | `2xx` |
| AUTH-34 | cashier-A | cross-branch report | branch B | `2xx` แต่ผลต้องถูกบังคับเป็น Branch A และห้ามมีข้อมูล B |
| AUTH-35 | non-admin | activity log | any filter | `403` |
| AUTH-36 | admin | activity log | date + role + branch + user | `2xx`, server-side filtered |

### 6.3 Audit and security tests

| ID | Scenario | Expected |
|---|---|---|
| AUD-01 | seller detail opened | event has actor, role snapshot, seller ID, timestamp |
| AUD-02 | seller ID-card photo opened | separate `view_seller_id_card_photo` event |
| AUD-03 | seller search/autocomplete | no per-result log explosion |
| AUD-04 | role changed after old event | old event still filters by old role snapshot |
| AUD-05 | denied branch/action | denied event contains action, actor and resource scope without secrets |
| AUD-06 | catalog/seller/PO update | before/after is redacted and does not contain full ID card/password/token |
| AUD-07 | audit API mutation attempt | no update/delete route exists; event remains unchanged |
| AUD-08 | pagination/date boundary | inclusive start, inclusive end-of-day, stable ordering by `created_at,id` |
| SEC-01 | forged `branch_id` in JSON/query | non-admin cannot read/write another branch |
| SEC-02 | direct URL to hidden page | backend returns `403` or page guard redirects |
| SEC-03 | stale JWT after role/branch change | token invalidated or current user policy denies access |
| SEC-04 | repeated POST/idempotency key | second request rejected without duplicate document |

### 6.4 UI permission smoke tests

ตรวจทุก role ที่ viewport desktop และ mobile:

- เมนูที่ matrix อนุญาตต้องมองเห็นและเปิดได้
- เมนูที่ไม่อนุญาตต้องไม่แสดง หรือเปิดแล้วถูก redirect อย่างถูกต้อง
- ปุ่ม create/edit/cancel/approve ต้องตรงกับ action permission ไม่ใช่เพียง page permission
- เปลี่ยนสาขาใน selector ต้องถูกปิดสำหรับ cashier/manager
- การ refresh หรือเปิด URL โดยตรงต้องไม่ bypass backend

## 7. Definition of Done และ Release Gate

Package จะถือว่าผ่านเมื่อ:

1. มี controller/model code, migration (ถ้าจำเป็น) และ test case ของ package เดียวกัน
2. Positive, wrong-branch, wrong-role และ self-approval cases ผ่านครบ
3. PHP lint ผ่านทั้ง `base-pos` และ `customizations`
4. API test suite ผ่าน และ browser smoke ตรวจเมนู/ปุ่มตาม role
5. ไม่มีการแก้ `base-pos/database/pos_system.sql`; schema ใหม่อยู่ใน `customizations/database/migrations/`
6. ไม่มี default password `admin/admin` ใน production และมีการเปลี่ยน credential ก่อนเปิดร้าน
7. Owner ตรวจ acceptance checklist และเซ็นรับ scope ก่อน deploy

คำสั่งตรวจขั้นต่ำ:

```bash
find code/base-pos/api -name '*.php' -exec php -l {} \;
find code/customizations/api -name '*.php' -exec php -l {} \;
cd code/tests/api && bash run.sh auth sellers purchase_orders stock_transfers cash_sessions
cd code && phpunit
```

## 8. ข้อจำกัดที่ต้องบันทึกไว้

- เอกสารนี้กำหนด application-level authorization ไม่แทน firewall, full-disk encryption, secret manager หรือ 2FA
- การเก็บรูปบัตรประชาชนและระยะเวลา retention ต้องยืนยันกับเจ้าของร้าน/ที่ปรึกษากฎหมาย
- การเปิดสิทธิ์ cashier แก้ราคาแคตตาล็อกเป็นการแก้ master data ที่กระทบทุกสาขา ต้องมีเหตุผลและ audit ทุกครั้ง
- ความเสถียรทางธุรกิจยังต้องพิสูจน์ด้วย pilot ใช้งานจริงและ incident review ไม่สามารถสรุปจากการมี RBAC เพียงอย่างเดียว
