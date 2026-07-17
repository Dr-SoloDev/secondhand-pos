# WF-05: Purchase Flow UX — Client Feedback Implementation
**Status:** ✅ DONE — 13 กรกฎาคม 2569  
**Trigger:** Client demo feedback — ปรับ UX ให้เร็วขึ้น คล่องตัวขึ้น สำหรับแคชเชียร

---

## 📋 Client Requirements

ลูกค้าต้องการให้ flow การทำงานของแคชเชียร **เร็ว ลดคลิก ไม่ต้องใช้เมาส์** โดยเฉพาะช่วงเร่งด่วน

### 5.1 Layout — Top Row Rearranged

**จากเดิม:** `[สาขา]  |  [บิล1 บิล2 บิล3]`  
**เป็น:** `[ค้นหาผู้ขาย...]  [บิล1 บิล2 บิล3]  [สาขา]  [+ ผู้ขายใหม่]`

**เหตุผล:** Flow การทำงานจริงเริ่มที่ "ค้นหาผู้ขาย" เสมอ — สาขาเลือกแค่ครั้งเดียวอยู่ท้ายสุด

| ลำดับ | Element | เหตุผล |
|:-----:|:--------|:-------|
| 1 | `searchSellerInput` | อันดับแรก — ขอข้อมูลลูกค้าก่อน |
| 2 | `globalTierButtons` (บิล1/2/3) | กำหนดราคา — อันดับสอง |
| 3 | `branchSelect` (สาขา) | เลือกครั้งเดียว ไม่ต้องเปลี่ยนบ่อย |
| 4 | `createNewSellerBtn (+ผู้ขายใหม่)` | เมื่อค้นหาไม่เจอ ค่อยเพิ่ม |

### 5.2 Default Tier — บิล1 Auto-Select

**ก่อน:** `globalTier = { level: null }` → แคชเชียรต้องกดเลือกบิลทุกครั้ง  
**หลัง:** `globalTier = { level: 1 }` → **บิล1 ถูกเลือกให้อัตโนมัติ**

- แคชเชียรไม่ต้องกดเลือกบิล ถ้าใช้ราคาบิล1
- ถ้าต้องการ บิล2/บิล3 → กดเลือกได้ตามปกติ
- ข้อความเริ่มต้น: "บิล1 — ค่าเริ่มต้น (กดเปลี่ยนเป็น บิล2/บิล3)"

### 5.3 Auto-Select Product on Single Result

**ก่อน:** พิมพ์รหัส → แสดง dropdown → แคชเชียรคลิกเลือก  
**หลัง:** พิมพ์รหัส → **ถ้าผลลัพธ์เดียว → auto-select ทันที → focus ขยับไปช่องน้ำหนัก**

- กรณีตรงรหัสเป๊ะ (`code === q`) → auto-select
- กรณีผลลัพธ์เดียว (`items.length === 1`) → auto-select
- กรณีหลายผลลัพธ์ → แสดง dropdown ให้คลิกเลือกเหมือนเดิม
- **Tab flow:** คีย์รหัส → auto-select → Tab → กรอกน้ำหนัก

### 5.4 Select-All on Focus — Weight Fields

**ก่อน:** focus ช่องน้ำหนัก → พิมพ์ "25" → ได้ "0.025" (เพราะเลขเก่าไม่ถูกลบ)  
**หลัง:** focus → `this.select()` → พิมพ์ "25" → แทนที่ → ได้ "25"

- ใช้กับทั้ง `itemQuantity` และ `itemWeightDeduct`
- ไม่ต้องกดลบเลขเก่าก่อนพิมพ์

### 5.5 Focus Ring — Mouse vs Keyboard

แยก visual feedback ระหว่าง mouse กับ keyboard:

| Input method | Focus style |
|:-------------|:------------|
| **Mouse click** | `box-shadow: 0 0 0 3px rgba(217,119,6,0.08)` (subtle) |
| **Keyboard Tab** | `outline: 2px solid #D97706` + `outline-offset: 2px` (ชัดเจน) |

- ใช้ `:focus` สำหรับ mouse, `:focus-visible` สำหรับ keyboard
- `outline: none` ถูกลบออกจาก `:focus` — ไม่ override global focus ring

### 5.6 Bottom Row Card Proportions

| Card | Ratio | Content |
|:-----|:-----:|:--------|
| ผู้ขาย (selected) | **0.8fr** | แค่ชื่อผู้ขาย + ปุ่มเซ็น |
| วิธีจ่าย + หมายเหตุ | **1fr** | select + textarea |
| บันทึก | **1.2fr** | ปุ่มบันทึก + ล้าง |

---

## 🔧 Files Changed

| File | Changes |
|:-----|:--------|
| `assets/js/purchase-orders.js` | globalTier default 1, auto-select single result, focusNextField(), select-all on focus |
| `admin/purchase-orders.html` | top row layout, DOM order, seller search ย้ายขึ้นบน |
| `assets/css/components/purchase-orders.css` | flex ratios, bottom-row grid, focus-visible, responsive order |
| `assets/css/components/forms.css` | fix duplicate :focus, add :focus-visible |

---

## 🧪 Test Cases

| TC | Scenario | Expected |
|:---|:---------|:---------|
| TC-01 | โหลดหน้า purchase-orders ใหม่ | บิล1 ถูกเลือกเป็น default |
| TC-02 | คีย์รหัสสินค้าที่มีในระบบ → auto-select | สินค้าถูกเลือก + focus ที่น้ำหนัก |
| TC-03 | คีย์รหัสที่ตรงเป๊ะ | auto-select ทันที ไม่แสดง dropdown |
| TC-04 | พิมพ์รหัสแล้วได้หลายผลลัพธ์ | แสดง dropdown ให้เลือก |
| TC-05 | focus ช่องน้ำหนัก → พิมพ์ 25 | ได้ 25 แทนที่ 1.00 (ไม่ใช่ 0.025) |
| TC-06 | Tab จาก search → tier → branch | order ถูกต้อง ไม่ข้าม |
| TC-07 | Tab จนถึงน้ำหนัก | focus → select-all → พิมพ์ทันที |
| TC-08 | กดปุ่ม "ผู้ขายใหม่" | modal เพิ่มผู้ขายเปิด (เหมือนเดิม) |

---

## 🖼️ Visual Layout

```
┌──────────────────────────────────────────────────────────────────┐
│ [ค้นหาผู้ขาย_________]  [บิล1|บิล2|บิล3]  [สาขา ▼]  [+ ผู้ขายใหม่] │
│        ①                       ②            ③           ④     │
├──────────────────────────────────────────────────────────────────┤
│ ┌── เพิ่มรายการรับซื้อ ─────────────────────────────────────────┐ │
│ │ สินค้า: [________]  หน่วย: [กก.]                              │ │
│ │ น้ำหนัก: [1.00]   หัก: [0.00]   ราคา/กก.   รวม   📷  [+ เพิ่ม] │ │
│ │           ↑ focus → select-all → พิมพ์ทันที                    │ │
│ └───────────────────────────────────────────────────────────────┘ │
│ ┌── ตารางรายการรับซื้อ ─────────────────────────────────────────┐ │
│ │ ...                                                           │ │
│ └───────────────────────────────────────────────────────────────┘ │
│ ┌──────────────┐ ┌──────────────┐ ┌────────────────────────────┐ │
│ │ ผู้ขาย: สมชาย │ │ วิธีจ่าย: สด │ │ [🔵 บันทึกใบรับซื้อ] [ล้าง] │ │
│ │ [✍️ เซ็น]    │ │ หมายเหตุ:.. │ │                            │ │
│ └──────────────┘ └──────────────┘ └────────────────────────────┘ │
│  0.8fr             1fr              1.2fr                        │
└──────────────────────────────────────────────────────────────────┘
```

---

## 📌 Key Principle for Future Agents

> **"Cashier flow first"** — การออกแบบ UI ต้องเรียงตามลำดับการทำงานของแคชเชียรหน้าร้าน ไม่ใช่ตามตรรกะของระบบ คีย์บอร์ดสำคัญกว่าเมาส์ — ทุกอย่างต้อง Tab-friendly
