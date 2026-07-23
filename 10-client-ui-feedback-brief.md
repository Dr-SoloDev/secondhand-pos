# Design Brief — Client UI Feedback (2026-07-24)

**Project:** Secondhand POS (Junk Shop POS)
**Source:** Client feedbag — direct feedback จากผู้ว่าจ้าง
**Owner:** Dr.solodev | **CEO:** เทอโบ

---

## Background

ระบบ Secondhand POS เป็น POS สำหรับร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์
ปัจจุบัน UI ใช้โทน warm amber + slate ตาม DESIGN.md ที่มีอยู่

ลูกค้าให้ feedback มาว่าอยากให้ปรับปรุง 3 เรื่องหลัก:

---

## Requirements

### 1. 🔤 ตัวอักษรเข้มขึ้น — อ่านง่ายขึ้น

**ปัญหา:** ลูกค้าบอกว่าตัวอักษรในระบบอ่านยาก จางเกินไป
**ปัจจุบัน:** `--color-text: #1e293b` (slate-800)
**ต้องการ:** เพิ่มความคมชัดของ text โดยเฉพาะ:
- Body text (16px, 400 weight)
- Table text (14px)
- Labels, headings
- ตัวเลข (price, total, profit)
- ต้องใช้ในที่แสงจ้าได้ (ร้านหน้าร้าน)

**Guideline:**
- ปรับ `--color-text` ให้ darker
- ทบทวน font-weight scale (อาจเพิ่มเป็น 400→500 ในบางจุด)
- WCAG contrast ratio AA (4.5:1) สำหรับทุก text
- หลีกเลี่ยงการใช้ `--color-text-light` (#64748b) และ `--color-text-lighter` (#94a3b8) สำหรับเนื้อหาสำคัญ

### 2. 🃏 ขอบการ์ดเข้มขึ้น — มองเห็นชัดขึ้น

**ปัญหา:** ปัจจุบัน `.card` ใช้ `box-shadow: var(--shadow-sm)` อย่างเดียว ไม่มี border ทำให้ card กลืนกับพื้นหลัง
**ปัจจุบัน:**
```css
.card {
  box-shadow: var(--shadow-sm);
  /* no border */
}
```

**ต้องการ:**
- เพิ่ม border ให้การ์ดมองเห็นชัดเจนขึ้น
- หรือปรับ shadow ให้เข้มขึ้นอย่างชัดเจน
- คำนึงถึงการใช้งานในที่แสงจ้า ( outdoor / หน้าร้าน )

**Guideline:**
- เพิ่ม `border: 1px solid` หรือปรับ `--shadow-sm`/`--shadow-md` ให้เข้มขึ้น
- แยก variant สำหรับ card แต่ละประเภท (dashboard card, stat card, form card, table container)
- คงความ "อบอุ่น" ของ warm-tinted shadow ไว้

### 3. 🎨 สีสันมากขึ้น — ไม่จำเจ

**ปัญหา:** ปัจจุบันใช้โทน amber + slate เป็นหลัก ลูกค้าอยากให้มีสีสันสดใสขึ้น
**ปัจจุบัน:**
- Primary: `#D97706` (amber-600)
- Neutrals: slate tones
- Semantic: emerald (success), red (danger), sky (info)

**ต้องการ:**
- เพิ่ม accent color หรือ secondary palette
- อาจใช้ gradient เล็กน้อยตามจุดสำคัญ
- ใช้สีช่วยแยก section/function อย่างมีความหมาย
- ยังคงความ "อบอุ่น สะอาดตา" ตาม Creative North Star เดิม

**Guideline:**
- ทบทวน color palette — เพิ่ม accent colors (teal? indigo? violet?)
- ใช้สีช่วยบอกสถานะ/หมวดหมู่
- ไม่เปลี่ยน core brand identity (warm amber + clean)
- คำนึงถึง WCAG contrast ทุกคู่สี

---

## Technical Context

### Tech Stack
- **Frontend:** Vanilla PHP + HTML + CSS (no framework)
- **CSS Architecture:** Tokens → Layout → Components (แยกไฟล์)
- **Design System:** DESIGN.md อยู่ในโปรเจกต์แล้ว

### Key Files
| File | Path |
|------|------|
| Design System Doc | `/code/DESIGN.md` |
| CSS Tokens | `/code/base-pos/assets/css/tokens.css` |
| Cards | `/code/base-pos/assets/css/components/cards.css` |
| Buttons | `/code/base-pos/assets/css/components/buttons.css` |
| Layout | `/code/base-pos/assets/css/layout.css` |
| Main CSS | `/code/base-pos/assets/css/main.css` |

### Design Tokens (ปัจจุบัน)
```css
--color-text: #1e293b;
--color-text-light: #64748b;
--color-text-lighter: #94a3b8;
--color-bg: #f7f6f3;
--color-border: #e2e8f0;
--shadow-sm: 0 1px 3px rgba(217,119,6,0.04), 0 1px 2px rgba(0,0,0,0.02);
--shadow-md: 0 4px 6px rgba(217,119,6,0.04), 0 2px 4px rgba(0,0,0,0.03);
```

---

## Deliverables ที่ต้องการจากทีม Design

1. **Revised Design Tokens** — ข้อเสนอปรับ `--color-text`, `--color-border`, `--shadow-*`, accent colors
2. **Card Redesign** — ตัวอย่าง `.card` ใหม่พร้อม border/shadow specification
3. **Typography Adjustment** — font-weight/color mapping ใหม่
4. **Visual Examples** — ถ้าทำได้: ตัวอย่าง HTML/CSS ประกอบการตัดสินใจ
5. **Migration Note** — อะไรที่ต้องระวังตอนเปลี่ยน tokens (ผลกระทบกับ components อื่น)

---

## Timeline

- Design Proposal: **ภายใน 24-48 ชม.**
- Review by CEO + Owner
- Implementation โดย Engineering
- QA + Deploy

---

*Brief สร้างโดย CEO เทอโบ | 2026-07-24*
