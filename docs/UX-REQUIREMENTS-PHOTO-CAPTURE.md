# UX Requirements — Photo Capture Feature (Scrap POS)
**Document Type:** UX Requirements Specification
**Author:** Central Bus Agent (Current)
**Status:** Draft for Architect Review
**Date:** 2026-06-30

---

## 1. Overview

Feature: Inline photo capture for Purchase Order flow
- Seller ID card photo 📇
- Item photos 📦
- Capture method: Webcam (Desktop) / Native Camera (Mobile/Tablet) / File Picker
- Upload timing: Auto after PO save

---

## 2. User Personas & Context

| Persona | Device | Context | Pain Point |
|:--------|:-------|:--------|:-----------|
| พนักงานหน้าร้าน (Cashier) | Desktop PC + Webcam | นั่งโต๊ะรับซื้อ | ต้องลุกไปถ่ายรูปมือถือแล้วส่งมา |
| พนักงานสาขา (Branch Staff) | Tablet + Camera หลัง | ยืนชั่งของหน้าร้าน | ไม่สะดวกถือของ+มือถือ |
| เจ้าของร้าน (Owner) | มือถือ / Tablet | ตรวจงานนอกสถานที่ | อยากเห็นรูปตอนรับซื้อว่าของเป็นยังไง |

---

## 3. User Flow (UX Map)

```
┌─────────────────────────────────────────────────┐
│  FLOW A: ถ่ายรูปสินค้า                           │
├─────────────────────────────────────────────────┤
│                                                    │
│  A1. User เพิ่มสินค้าใน cart                        │
│  A2. User กดปุ่ม "📷" ที่แถวสินค้า                  │
│  A3. Bottom Sheet เปิด → เลือก:                    │
│      ├── 📷 ถ่ายรูป → เปิดกล้อง → ถ่าย → Preview   │
│      └── 🖼️ เลือกรูป → เปิด File Picker            │
│  A4. ปุ่มเปลี่ยนเป็น ✓ + แถบรูปโผล่ด้านล่าง         │
│  A5. User เพิ่มสินค้าต่อ / กดบันทึก PO              │
│  A6. ✅ Auto upload รูปทั้งหมดหลัง PO save          │
│                                                    │
├─────────────────────────────────────────────────┤
│  FLOW B: ถ่ายรูปบัตรผู้ขาย                         │
├─────────────────────────────────────────────────┤
│                                                    │
│  B1. User กด "+ ผู้ขายใหม่"                        │
│  B2. Modal เปิด → กรอกข้อมูล + กด "📸 ถ่ายบัตร"     │
│  B3. ถ่ายรูปบัตร → Preview → Confirm               │
│  B4. กด "บันทึก" → สร้าง seller + upload photo     │
│  B5. ✅ เลือก seller ใน PO โดยอัตโนมัติ             │
│                                                    │
└─────────────────────────────────────────────────┘
```

---

## 4. UX Requirements (Detailed)

### R1: Discoverability
- [ ] **R1.1** ปุ่มถ่ายรูปต้อง visible **ตลอดเวลา** แม้ cart ว่าง
- [ ] **R1.2** ใช้ FAB (Floating Action Button) รูป 📷 มุมล่างขวา
- [ ] **R1.3** มี tooltip / hint ครั้งแรกที่เห็น ("ลองถ่ายรูปสินค้าดูสิ")
- [ ] **R1.4** ปุ่มใน cart row ต้องไม่เล็กกว่า 40px (touch target WCAG)

### R2: Feedback & State
- [ ] **R2.1** แสดงจำนวนรูปที่ถ่ายแล้ว (badge บน FAB)
- [ ] **R2.2** Photo Strip — แถวรูปขนาดย่อด้านล่าง cart ก่อนบันทึก
- [ ] **R2.3** ปุ่มในแถวเปลี่ยนเป็น ✓ สีเขียว + mini thumbnail
- [ ] **R2.4** ขณะอัปโหลด → progress bar + นับ "กำลังอัปโหลด 2/5"
- [ ] **R2.5** อัปโหลดเสร็จ → success toast + gallery preview

### R3: Camera UX
- [ ] **R3.1** เปิดกล้องทันที ไม่ต้องรอ (แสดง live preview)
- [ ] **R3.2** capture button ใหญ่ ≥72px (นิ้วกดง่าย)
- [ ] **R3.3** flash animation เวลาถ่าย (feedback)
- [ ] **R3.4** Preview ก่อน confirm → "ใช้รูปนี้" / "ถ่ายใหม่"
- [ ] **R3.5** fallback: ถ้า webcam ไม่ทำงาน → เปิด file picker แทน

### R4: Tablet / Mobile
- [ ] **R4.1** Touch target ทุกปุ่ม ≥48px (WCAG 2.1)
- [ ] **R4.2** responsive grid — cart ปรับเป็น single column บน mobile
- [ ] **R4.3** camera modal ใช้ full screen (ไม่ใช่ popup เล็ก)
- [ ] **R4.4** รองรับ landscape + portrait

### R5: Seller ID Card
- [ ] **R5.1** มี visual placeholder ใน modal ("📸 ถ่ายรูปบัตรประชาชน")
- [ ] **R5.2** หลังถ่าย → แสดง thumbnail + ปุ่มลบ
- [ ] **R5.3** บังคับถ่าย? → Optional (แนะนำแต่ไม่บังคับ)

### R6: Error & Edge Cases
- [ ] **R6.1** Webcam permission denied → toast + fallback file picker
- [ ] **R6.2** ไฟล์ใหญ่เกิน 10MB → resize auto + แจ้งเตือน
- [ ] **R6.3** Upload ล้มเหลว → retry button + queue ที่เหลือ
- [ ] **R6.4** Disconnected → cache รูปไว้ + upload เมื่อ reconnect

---

## 5. Visual Design Direction

### Color Palette
```
Primary:   #2563EB (Blue - Camera/Action)
Success:   #16A34A (Green - Photo taken)
Warning:   #F59E0B (Amber - POS accent)
Surface:   #FFFFFF (Bottom sheet, modals)
Overlay:   rgba(15, 23, 42, 0.55)
```

### Component Size Guide
```
FAB Button:         56px (mobile) / 48px (desktop)
Capture Button:     72px (desktop) / 80px (tablet)
Touch Target Min:   48px (WCAG AAA)
Thumbnail:          44-48px square
Photo Strip:        64px height
Bottom Sheet:       max 480px width
```

### Icon Set
```
📷 → Camera action
✓  → Photo taken
⟳  → Retry
✕  → Cancel / Remove
🖼️ → Gallery picker
```

---

## 6. Acceptance Criteria

| # | Criteria | Priority |
|:-:|:---------|:--------:|
| 1 | User can take photo from webcam in ≤3 taps | P0 |
| 2 | Photo auto-uploads after PO save, no extra step | P0 |
| 3 | Tablet touch targets ≥48px | P0 |
| 4 | Visual feedback for every state (taken, uploading, done) | P0 |
| 5 | Fallback if camera fails → file picker | P1 |
| 6 | Badge shows pending photo count | P1 |
| 7 | Retry on upload failure | P1 |
| 8 | Seller ID card photo preview in modal | P2 |

---

## 7. Routing

```
This document → 📬 Architect (พี่ทรงศักดิ์)
                  → Review UX requirements
                  → Assign to UX Design Team (08-design)
                  → Implement in next sprint
```

**End of UX Requirements Document**
