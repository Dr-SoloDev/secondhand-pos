> **⚠️ Timeline ถูก supersede แล้ว** — ดูแผนใหม่: [`docs/OPS-DEPLOYMENT-PLAN.md`](OPS-DEPLOYMENT-PLAN.md)
> สถานะ: **NO-GO** จนกว่า 3 blockers จะปิด (G3 SQL, admin/admin, HTTPS)

# 📋 JOB ORDER: Backend Security Engineer — Day 2

**ส่งถึง:** Backend Security Engineer  
**จาก:** System Architect  
**กำหนดส่ง:** วันสิ้นสุด Day 2 (23:59 น.)  
**รับต่อจาก:** DevOps Engineer (Day 1)  
**Handoff ให้:** PHP Full-stack (Day 3)

---

## Pre-condition (ต้องได้จาก DevOps ก่อนเริ่ม)

```
□ Server เปิด, SSL พร้อม, docker compose up healthy
□ deploy.sh รันผ่าน
□ .env production secret ทั้งหมด
□ Error log clean
□ มือถืองานไม่เจอ issue ค้างจาก Day 1
```

**ถ้าข้อไหนยังไม่ผ่าน → หยุด, แจ้ง Architect, อย่าเริ่ม**

---

## ภารกิจหลัก

แก้ **4 จุด** ที่เป็นความเสี่ยง REAL สำหรับ Production — ไม่ใช่ nice-to-have:

| Priority | Task | เวลา | ความเสี่ยงถ้าไม่ทำ |
|----------|------|------|--------------------|
| P0 | Fix error message leak | 30 นาที | Internal path / SQL leak สู่ user |
| P0 | Cart state protection | 2-4 ชม. | เสียข้อมูล cart+รูป เมื่อ token expire |
| P0 | Idempotency — PO + Sale Lot | 4 ชม. | PO/Lot ซ้ำ → เงินผิด |
| P1 | Rate limit verification | 30 นาที | (ยืนยันว่าของที่มีอยู่ทำงาน) |

---

## Task 1: Fix Error Message Leak (30 นาที)

### ไฟล์ที่แก้: `code/base-pos/api/index.php`

**ปัจจุบัน (อันตราย):**
```php
} catch (\Throwable $e) {
    Response::error($e->getMessage(), 500);
}
```

**เปลี่ยนเป็น:**
```php
} catch (\Throwable $e) {
    error_log("[FATAL] " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    Response::error('Internal server error. Please contact administrator.', 500);
}
```

### Verify:
```bash
curl https://domain.com/api/index.php/nonexistent-endpoint
# EXPECTED: {"status":"error","message":"Internal server error. Please contact administrator."}
# NOT EXPECTED: stack trace, SQL fragment, file path

docker compose logs web | grep "\[FATAL\]"
# EXPECTED: มี log จริง (เฉพาะใน log server, ไม่ leak ออกไป)
```

---

## Task 2: Token Refresh + Cart State Protection (2-4 ชม.)

### ตัวเลือก A (แนะนำสำหรับ 5 วัน): sessionStorage Cart Persist

ใช้เวลาน้อยกว่า, safety สูงกว่า, ไม่ต้องออกแบบ refresh token rotator ที่อาจมี bug

### ไฟล์ที่แก้: `code/base-pos/assets/js/common.js`

**เพิ่มฟังก์ชัน:**
```javascript
// === Cart State Protection ===
// ป้องกันข้อมูลหายเมื่อ token expire

function saveCartState(cartState) {
  sessionStorage.setItem('cart_backup', JSON.stringify(cartState));
  sessionStorage.setItem('cart_backup_time', Date.now());
}

function restoreCartState() {
  const saved = sessionStorage.getItem('cart_backup');
  const savedTime = sessionStorage.getItem('cart_backup_time');
  if (saved && savedTime && (Date.now() - parseInt(savedTime) < 30 * 60 * 1000)) {
    try {
      return JSON.parse(saved);
    } catch (e) {
      clearCartState();
      return null;
    }
  }
  return null;
}

function clearCartState() {
  sessionStorage.removeItem('cart_backup');
  sessionStorage.removeItem('cart_backup_time');
}
```

**แก้ `apiRequest()` ให้ intercept 401:**
```javascript
async function apiRequest(endpoint, method = 'GET', data = null) {
  try {
    // ... existing fetch logic ...

    if (res.status === 401) {
      // Save cart state before redirect
      const cartData = window.getCurrentCartState?.();
      if (cartData) saveCartState(cartData);

      // Clear auth
      localStorage.removeItem('posUser');
      document.cookie = 'posToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
      document.cookie = 'posUser=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';

      window.location.href = '../index.html?expired=1';
      return { status: 'error', message: 'Session expired' };
    }
    // ...
  }
}
```

### ไฟล์ที่แก้: `code/base-pos/assets/js/purchase-orders.js`

**เพิ่มฟังก์ชัน export สำหรับ common.js:**
```javascript
// === Cart State Export (สำหรับ common.js save ก่อน 401) ===
window.getCurrentCartState = function() {
  if (cart.length === 0 && !selectedSeller) return null;
  return {
    branch_id: document.getElementById('branchSelect')?.value,
    seller: selectedSeller,
    items: cart,
    tier: globalTier,
    hasSignature: !!pendingSignatureDataUrl,
  };
};
```

**เพิ่มฟังก์ชัน restore ตอน init:**
```javascript
function restoreCartFromBackup() {
  const saved = restoreCartState();
  if (!saved) return;

  const confirmed = confirm(
    '⚠️ พบข้อมูลที่ยังไม่ได้บันทึกจาก session ก่อนหน้า\n' +
    `(${saved.items?.length || 0} รายการ, ผู้ขาย: ${saved.seller?.full_name || 'ไม่มี'})\n\n` +
    'ต้องการกู้คืนข้อมูลหรือไม่?'
  );

  if (!confirmed) {
    clearCartState();
    return;
  }

  // Restore branch
  if (saved.branch_id) {
    document.getElementById('branchSelect').value = saved.branch_id;
  }

  // Restore seller
  if (saved.seller) {
    selectSeller(saved.seller);
    // selectSeller ภายในเรียก doSelectSeller
    doSelectSeller(saved.seller);
  }

  // Restore cart items
  if (saved.items?.length) {
    cart = saved.items;
    renderCart();
  }

  // Restore tier
  if (saved.tier?.level) {
    globalTier.level = saved.tier.level;
    const btns = document.querySelectorAll('.global-tier-btn');
    if (btns[saved.tier.level - 1]) {
      setTierActive(btns[saved.tier.level - 1], saved.tier.level);
    }
  }

  clearCartState();
  showNotification('กู้คืนข้อมูลสำเร็จ', 'success');
}
```

เรียก `restoreCartFromBackup()` ใน `DOMContentLoaded` หลังจาก load branches/categories เพื่อให้ dropdown พร้อม

### Verify:
```
[ ] สร้าง cart 5 รายการ, เลือก seller → force token expire (รอ 24h หรือลบ cookie)
    → เรียก API → 401 → save → redirect login
    → login สำเร็จ → กลับมาที่ purchase-orders.html
    → popup "พบข้อมูลที่ยังไม่ได้บันทึก" → กู้คืน
    → cart, seller, tier กลับมาเหมือนเดิม

[ ] sessionStorage.clear() → cart ไม่ถูกกู้คืน (ถูกต้อง)

[ ] กู้คืนหลังจาก 30 นาที → cart ถูกลบ (ถูกต้อง — security)
```

---

## Task 3: Idempotency Key — PO + Sale Lot (4 ชม.)

### 3.1 Migration ใหม่

**ไฟล์:** `code/customizations/database/migrations/041_create_idempotency_keys.sql`

```sql
-- Migration 041: Idempotency keys table for preventing duplicate financial transactions
CREATE TABLE IF NOT EXISTS idempotency_keys (
    idempotency_key VARCHAR(64) PRIMARY KEY,
    endpoint VARCHAR(100) NOT NULL,
    response_json TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 Helper Functions

**ไฟล์ใหม่:** `code/customizations/api/Helpers/Idempotency.php` (สร้าง directory ถ้ายังไม่มี)

```php
<?php
class Idempotency
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function check($key, $endpoint)
    {
        $existing = $this->db->fetch(
            "SELECT response_json FROM idempotency_keys
             WHERE idempotency_key = ? AND endpoint = ?
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            [$key, $endpoint]
        );
        if ($existing) {
            $data = json_decode($existing['response_json'], true);
            Response::success($data, 'Duplicate request (idempotent)');
            exit;
        }
    }

    public function save($key, $endpoint, $responseData)
    {
        $this->db->insert('idempotency_keys', [
            'idempotency_key' => $key,
            'endpoint' => $endpoint,
            'response_json' => json_encode($responseData),
        ]);
    }

    public function cleanup()
    {
        // ลบ keys ที่อายุเกิน 24 ชม. (เรียกเป็นครั้งคราว หรือ cron)
        $this->db->execute(
            $this->db->prepare(
                "DELETE FROM idempotency_keys WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            ),
            []
        );
    }
}
```

### 3.3 Client-side: ส่ง idempotency_key

**ไฟล์:** `code/base-pos/assets/js/purchase-orders.js`

ก่อนส่ง payload, เพิ่ม:
```javascript
const idempotencyKey = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);

const payload = {
    // ... existing fields ...
    idempotency_key: idempotencyKey,
};
```

**ไฟล์:** `code/base-pos/assets/js/sale-lots.js`

ทำแบบเดียวกันใน `saveLot()` function

### 3.4 Server-side: ตรวจสอบ

**ไฟล์:** `code/customizations/api/Controllers/PurchaseOrdersController.php`

ใน `createPurchaseOrder()` method — หลังจาก `getRequestData()` และก่อน business logic:
```php
$data = $this->getRequestData();
$idempotencyKey = $data['idempotency_key'] ?? null;
if ($idempotencyKey) {
    $idemp = new Idempotency();
    $idemp->check($idempotencyKey, 'purchase-orders');
}

// ... existing business logic (validate, insert PO, etc.) ...

if ($idempotencyKey) {
    $idemp->save($idempotencyKey, 'purchase-orders', ['id' => $poId, 'reference_no' => $refNo]);
}
```

**ไฟล์:** `code/customizations/api/Controllers/SaleLotsController.php`

ใน `store()` method — ทำแบบเดียวกัน:
```php
$data = $this->getRequestData();
$idempotencyKey = $data['idempotency_key'] ?? null;
if ($idempotencyKey) {
    $idemp = new Idempotency();
    $idemp->check($idempotencyKey, 'sale-lots');
}

// ... existing business logic ...

if ($idempotencyKey) {
    $idemp->save($idempotencyKey, 'sale-lots', ['id' => $lotId, 'reference_no' => $refNo]);
}
```

### 3.5 Autoload Helper

**ไฟล์:** `code/base-pos/api/autoload.php` — เพิ่ม path สำหรับ Helpers:
```php
// Add Helpers directory
$helperPath = __DIR__ . '/../../customizations/api/Helpers';
if (is_dir($helperPath)) {
    set_include_path(get_include_path() . PATH_SEPARATOR . $helperPath);
}
```

หรือใช้ require_once ใน Controller โดยตรง (ง่ายกว่า):
```php
require_once __DIR__ . '/../../customizations/api/Helpers/Idempotency.php';
```

### Verify:
```bash
# ทดสอบ idempotency PO
KEY="test-key-$(date +%s)"

# ครั้งแรก → 201 Created
curl -s -X POST https://domain.com/api/index.php/purchase-orders \
  -H "Content-Type: application/json" \
  -d '{"branch_id":1,"seller_id":1,"items":[...],"idempotency_key":"'$KEY'"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])"
# EXPECTED: success

# ครั้งที่สอง (key เดียวกัน) → 200 OK (idempotent) ไม่สร้าง PO ซ้ำ
curl -s -X POST https://domain.com/api/index.php/purchase-orders \
  -H "Content-Type: application/json" \
  -d '{"branch_id":1,"seller_id":1,"items":[...],"idempotency_key":"'$KEY'"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['status'])"
# EXPECTED: success (idempotent)

# ตรวจสอบว่า PO มีแค่ 1 ใบ
curl -s https://domain.com/api/index.php/purchase-orders?limit=100 \
  | python3 -c "import sys,json; print(len(json.load(sys.stdin)['data']['items']))"
# EXPECTED: 1 (not 2)
```

---

## Task 4: Rate Limit Verification (30 นาที)

Rate limiting มีอยู่แล้วใน `AuthController` (5 attempts / 15 min / IP) — แค่ verify ว่าทำงานจริง

### Test script:
```bash
#!/bin/bash
echo "=== Rate Limit Test ==="
for i in $(seq 1 6); do
  RESP=$(curl -s -X POST https://domain.com/api/index.php/auth/login \
    -H "Content-Type: application/json" \
    -d "{\"username\":\"admin\",\"password\":\"wrong$i\"}")
  STATUS=$(echo "$RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('status','?'))")
  echo "Attempt $i: $STATUS"
done
```

### Expected Output:
```
Attempt 1: error
Attempt 2: error
Attempt 3: error
Attempt 4: error
Attempt 5: error
Attempt 6: error       ← แต่ message ต้องเป็น "Too many login attempts"
```

### Verify:
```bash
# ตรวจสอบตาราง login_attempts
docker compose exec db mysql -u root -p${MYSQL_ROOT_PASSWORD} pos_system \
  -e "SELECT COUNT(*) FROM login_attempts WHERE attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);"

# ถ้า rate limit ไม่ทำงาน → STOP! แจ้ง Architect ก่อนทำ Task อื่น
```

---

## ✅ Gate Criteria — ส่งต่องาน (Handoff to Day 3)

```
[ ] 1. Error message leak fixed
     → curl /nonexistent-endpoint → {"status":"error","message":"Internal server error..."}
     → ไม่มี stack trace / SQL / path ใน response
     → error_log() มี log จริง (เฉพาะ log server)

[ ] 2. Cart state protection
     → สร้าง cart → force 401 → login → กู้คืน cart
     → sessionStorage cart_backup มีข้อมูลหลังจาก last API call
     → 30 นาทีผ่านไป → cart ถูกลบ (auto expire)

[ ] 3. Idempotency — PO
     → ส่ง key เดียวกัน 2 ครั้ง → PO 1 ใบ
     → ส่ง key ต่างกัน → PO 2 ใบ
     → ตรวจสอบ idempotency_keys: มี record

[ ] 4. Idempotency — Sale Lot
     → ส่ง key เดียวกัน 2 ครั้ง → Lot 1 อัน
     → ส่ง key ต่างกัน → Lot 2 อัน

[ ] 5. Migration 041
     → รันแล้ว, ตาราง idempotency_keys มีอยู่
     → docker compose exec db ... -e "DESCRIBE idempotency_keys" → PASS

[ ] 6. Rate limit
     → 6 ครั้ง login ผิด → blocked
     → 15 นาที → reset (wait or check lockout_expires)

[ ] 7. ไม่มี Fatal Error ใน log
     → docker compose logs web --since 2h | grep -c "FATAL|ERROR" ต้อง <= 5
     (ยอมรับ warning เกี่ยวกับ static resource ถ้าเกิดจาก browser prefetch)
```

**FAIL 1 ข้อ = แก้ก่อนส่งต่องาน**

---

## ⛔ สิ่งที่ Backend Security ห้ามทำ

| ข้อห้าม | เพราะ |
|---------|-------|
| ❌ ห้ามเปลี่ยน JWT expiry โดยไม่แจ้ง Architect | ส่งผลต่อ UX ทั้งระบบ |
| ❌ ห้ามลบ migration เดิม | migration idempotent, ไม่ต้องลบ |
| ❌ ห้ามแก้ DB schema ใน migration ที่มีอยู่แล้ว | migration ใหม่เท่านั้น |
| ❌ ห้ามเพิ่ม library/dependency | PHP Vanilla = ไม่มี Composer |
| ❌ ห้าม deploy โดยไม่แจ้ง Architect | production risk |
| ❌ ห้ามเปิด endpoint ใหม่โดยไม่ register route | Router.php ต้องมี route |
| ❌ ห้ามแก้คอนฟิก server (nginx, php.ini, docker) | ส่ง DevOps (Day 1) |

---

## 📞 ถ้าติดปัญหา

1. **ลองแก้เอง 30 นาที** — ถ้าไม่หลุด →
2. **หยุด** — อย่าทำอะไรต่อ →
3. **แจ้ง Architect พร้อม:** error message, file path, สิ่งที่ลองไปแล้ว, docker compose logs

---

## 🎯 Definition of Done สำหรับ Backend Security (Day 2)

> **"Server ปลอดภัย, ไม่มี error leak, ไม่มีทางเสียข้อมูล cart, ไม่มีทางสร้าง PO/Lot ซ้ำ, rate limit ทำงาน — ส่งต่องานให้ PHP Full-stack Team ได้"**

---

*Document version: 1.0 | 2026-07-03 | System Architect*
