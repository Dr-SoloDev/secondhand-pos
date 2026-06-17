# WF-01: Photo Upload System
**Status**: SPEC — พร้อม implement  
**Date**: 2026-06-14  
**Deadline**: 2026-06-30

---

## What EXISTS (ไม่ต้องสร้าง)

| Component | File | Note |
|-----------|------|------|
| PO creation UI | `base-pos/admin/purchase-orders.html` | Form + JS ทำงานได้ |
| POST /purchase-orders | `Router.php` + `PurchaseOrdersController.php` | มี response กลับ `id`, `reference_no` |
| `purchase_order_photos` table | migration 004 | มีแล้ว ยังไม่ถูกใช้ |
| `purchase_order_items.photo_path` | migration 004 | มีแล้ว ยังไม่ถูกใช้ |
| `PurchaseOrder.php` model | line 207 | มี `photo_path` ใน response แล้ว |

## What MISSING (ต้องสร้าง)

| Component | ความสำคัญ |
|-----------|----------|
| QR code แสดงใน receipt modal | สูง |
| Mobile upload page | สูง |
| `POST /purchase-orders/:id/photos` API | สูง |
| `/uploads/` directory + PHP handler | สูง |
| Photo gallery ใน PO view | กลาง |

---

## Workflow Tree

```
[DESKTOP] สร้าง PO กด Save
        ↓
    API returns { id: 42, reference_no: "PO-240614-001" }
        ↓
    Receipt modal เปิด
        ↓ (ใหม่)
    แสดง QR Code → URL: /photo-upload.html?po=42&token=<hmac>
        ↓
[MOBILE] สแกน QR → เปิด mobile page
        ↓
    แสดงข้อมูล PO (สาขา, ผู้ขาย, รายการ)
        ↓
    กดปุ่ม "ถ่ายรูป" (camera input)
        ↓
    Preview รูป → กด "อัพโหลด"
        ↓
    POST /api/purchase-orders/42/photos (FormData)
        ↓
    PHP: validate → move → INSERT purchase_order_photos
        ↓
    ✅ "อัพโหลดสำเร็จ" → ถ่ายต่อได้
        ↓
[DESKTOP] refresh PO → เห็นรูป
```

---

## Handoff Contracts

### Contract A: QR → Mobile URL
```
URL format: https://<host>/photo-upload.html?po={id}&token={hmac}
HMAC key: JWT_SECRET (env)
HMAC input: "po:{id}:upload"
Expires: 24 hours
```

### Contract B: Mobile → API
```
POST /api/purchase-orders/{id}/photos
Content-Type: multipart/form-data
Body: { photo: File, item_id?: int }
Auth: query token (HMAC) OR Bearer JWT
Response: { success: true, photo_id: int, photo_url: string }
```

### Contract C: File Storage
```
Path: /uploads/purchase-orders/YYYY/MM/{po_id}_{uuid}.jpg
Docker volume: ./uploads:/var/www/html/uploads
Max size: 10MB
Types: image/jpeg, image/png, image/webp
Resize: ≤ 1920px (longest side) — PHP GD
```

---

## Files to Create/Edit

### 1. ใหม่: `base-pos/photo-upload.html`
- Mobile-first design (viewport, touch-friendly)
- แสดง PO info (branch, seller, items)
- `<input type="file" accept="image/*" capture="environment">` 
- Preview ก่อน upload
- Status: loading / preview / uploading / done
- ไม่ต้อง sidebar, ไม่ต้อง login form

### 2. ใหม่: `base-pos/assets/js/photo-upload.js`
- อ่าน `?po=` และ `?token=` จาก URL
- GET /api/purchase-orders/{id} — ดึงข้อมูล PO
- POST /api/purchase-orders/{id}/photos — อัพโหลด
- แสดง gallery รูปที่อัพโหลดแล้ว

### 3. แก้: `base-pos/assets/js/purchase-orders.js`
- หลัง savePurchaseOrder() สำเร็จ → generateQR(po.id)
- ใส่ QR image ใน receipt modal ที่มีอยู่แล้ว

### 4. ใหม่: `customizations/api/Controllers/PhotoUploadController.php`
```
POST /purchase-orders/{id}/photos
  - ตรวจสอบ token HMAC หรือ JWT
  - validate file (type, size)
  - resize ด้วย GD ถ้า > 1920px
  - move_uploaded_file → /uploads/...
  - INSERT INTO purchase_order_photos
  - return { photo_id, photo_url }
```

### 5. แก้: `base-pos/api/Router.php`
```php
'POST purchase-orders/photos' => [PhotoUploadController::class, 'upload'],
```
(ใช้ path แบบ `/purchase-orders/42/photos` ผ่าน segment parsing)

### 6. ใหม่: `base-pos/assets/css/components/photo-upload.css`
- Mobile-first
- Big camera button (min 56px touch target)
- Image preview thumbnail
- Upload progress

### 7. Docker: `docker-compose.yml`
```yaml
- ./uploads:/var/www/html/uploads:rw
```

---

## Security

| Risk | Mitigation |
|------|-----------|
| ใครก็ upload ได้ | HMAC token ใน URL, expire 24h |
| File type spoofing | ตรวจ MIME จาก content (finfo) ไม่จาก extension |
| Path traversal | ใช้ UUID filename เท่านั้น |
| Disk full | ตรวจ disk_free_space() ก่อน upload |
| ไฟล์ใหญ่เกิน | max 10MB check ทั้ง JS + PHP |

---

## Out of Scope (WF-01)
- ❌ Cloud storage (S3) — ใช้ local ก่อน
- ❌ Photo editing (crop/rotate)
- ❌ Multi-photo upload (drag & drop) — ทีละรูปพอ
- ❌ Video upload

---

## Test Cases

| TC | Scenario | Expected |
|----|----------|---------|
| TC-01 | Desktop save PO → QR ปรากฏใน modal | ✅ QR แสดง |
| TC-02 | สแกน QR บนมือถือ → เปิด mobile page | ✅ แสดงข้อมูล PO ถูกต้อง |
| TC-03 | กด camera → เลือกรูป → preview | ✅ รูป preview ก่อน upload |
| TC-04 | กด upload → 200 OK | ✅ รูปอยู่ใน /uploads/ + DB record |
| TC-05 | Token หมดอายุ (> 24h) | ❌ 401 Unauthorized |
| TC-06 | ไฟล์ > 10MB | ❌ error ก่อน upload |
| TC-07 | ไฟล์ไม่ใช่รูป (PDF) | ❌ rejected |
| TC-08 | Desktop reload PO → เห็นรูป | ✅ gallery แสดง |

---

## Implementation Order

```
Step 1: Docker volume + /uploads dir
Step 2: PhotoUploadController.php (API)
Step 3: Router.php (route)
Step 4: photo-upload.html + photo-upload.js (mobile page)
Step 5: QR code ใน purchase-orders.js
Step 6: photo-upload.css
Step 7: Test TC-01 → TC-08
```
