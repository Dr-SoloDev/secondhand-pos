# ADD-002: Service Layer Extraction

| Metadata | Value |
|:---------|:------|
| **Status** | **Proposed** |
| **Author** | พี่ทรงศักดิ์ (Architect) |
| **Approved by** | CEO (เทอโบ) |
| **Date** | 2026-08-25 |
| **Depends on** | Test suite @ fc3c72f (CashSession 15, FinancialControllerLogic 5, CsvInjectionProtection 4 — PHPUnit 11, run via docker) |

---

## 1. Context

### 1.1 ปัญหาที่เจอ

**Problem 1: Fat Models — business logic + SQL ปนกันจนแยก test ไม่ได้**

| File | บรรทัด | Test coverage | ปัญหาหลัก |
|:-----|-------:|:--------------|:----------|
| `Models/StockTransfer.php` | 981 | ❌ ไม่มี | `confirm()` เป็น transactional block ~220 บรรทัด (line 169–391) ผสม validation + SQL + stock movement |
| `Models/CashSession.php` | 913 | ✅ 15 cases | `fundDrawer()` มี owner_capital base/excess split logic (pure) ฝังกับ INSERT chain |
| `Models/SaleLot.php` | 907 | ❌ ไม่มี (โดยตรง) | FIFO allocation loop เป็น pure math แต่ฝังใน method เดียวกับ `FOR UPDATE` query |
| `Controllers/FinancialController.php` | 853 | ⚠️ logic เท่านั้น (5 cases) | Report SQL + aggregation + Response + export format อยู่รวมกัน |

**Problem 2: HTTP layer ของ `FinancialController` test ไม่ได้**
- `resolveBranchId()` / `resolveWriteBranchId()` / `assertExpenseBranchAccess()` เรียก `Response::error(..., 403/400)` ซึ่ง `exit()` **กลาง flow** — PHPUnit process ตายทันที
- ทุก report method hardcode `Database::getInstance()` — แทน mock ไม่ได้
- `exportCsv()` / `exportExcel()` echo + `exit` ตรง ๆ — integration test ต้อง spawn process

**Problem 3: Duplicate report logic (~150 บรรทัด)**
- `exportCsv()` และ `exportExcel()` มี data-gathering section **เหมือนกันแทบทั้งหมด** (purchases / salelots / expenses / summary datasets)
- `summary()` endpoint กับ summary branch ของ exports มี query set เดียวกันอีกชุด — แก้ที่ไหนหลุดอีกที่ = bug รอเกิด

**Problem 4: Singleton coupling ทำ refactor มีความเสี่ยงสูง**
- `Model` base (`Core/Model.php`) constructor: `$this->db = Database::getInstance()` — ทุก Model ผูก singleton ตั้งแต่ birth
- การเพิ่ม test ทับ logic เดิมโดยไม่แยก dependency จะได้ test ที่ fragile (ต้องแตะ real DB ทุกครั้ง)

### 1.2 Precedent ที่มีอยู่

- `Services/SellerIdCipher.php` — service class แรกใน `customizations/api/Services/` (static, crypto-only) พิสูจน์แล้วว่า autoload รองรับ: `base-pos/api/autoload.php` scan `$customizations.'/Services/'` อยู่แล้ว → **class ใหม่ใช้ได้ทันที ไม่ต้องแก้ autoloader**
- ⚠️ แต่ SellerIdCipher เป็น static — เหมาะกับ pure crypto ไม่เหมาะกับ service ที่มี DB dependency → service ใหม่จะใช้ instance + constructor injection (ดู §4.2)

### 1.3 Safety Net ที่มี

```
code/tests/Unit/CashSessionTest.php            — 15 cases (movement/open/close/review paths)
code/tests/Unit/FinancialControllerLogicTest.php — 5 cases
code/tests/Unit/CsvInjectionProtectionTest.php   — 4 cases (Response::spreadsheetSafeRow)
```

→ Refactor "เร็วหลัง test" ตามคำสั่ง CEO: phase แรกต้องเลือกไฟล์ที่มี test คุ้มครองก่อนเสมอ

---

## 2. Goals / Non-goals

### Goals
1. แยก business logic ออกจาก HTTP layer (`Response::*`, `$_GET`, `php://input`, `exit`) และ persistence layer (SQL) ให้ unit-test ได้โดยไม่แตะ DB
2. ทำให้ `FinancialController` รายงานทุกตัว reuse dataset เดียวกัน (kill duplication ~150 บรรทัด)
3. เพิ่ม unit test coverage ให้ FIFO cost, cash movement rules, stock transfer normalization — logic ที่ผิดแล้วเจ็บที่สุด
4. Refactor แบบ incremental — ทุก phase จบแล้ว suite ต้องเขียว + deploy ได้

### Non-goals (ชัดเจน — ห้ามหลุด scope)
- ❌ **ไม่แตะ database schema** — ไม่มี migration ใหม่ (ADD-001 จัดการ schema แล้ว)
- ❌ **ไม่ refactor UI / frontend JS** — HTTP contract (routes, request/response shape) ห้ามเปลี่ยน
- ❌ **ไม่แนะนำ framework / DI container** — PHP กึ่ง legacy, constructor injection ธรรมดาพอ
- ❌ ไม่แตะ `SellerIdCipher` (static อยู่ยง — crypto pure อยู่แล้ว)
- ❌ ไม่ migrate ทั้ง repo — เฉพาะ 4 fat files ที่ระบุ

---

## 3. Decision

### 3.1 แยก 3 layers ภายใน customizations/api

```
┌──────────────────────────────────────────────────────┐
│ Controllers  — auth, $_GET/body parsing,             │
│                Response::success/error, HTTP only    │
├──────────────────────────────────────────────────────┤
│ Services     — business rules, validation,           │
│                orchestration, dataset building       │
│                (throw Exception — ไม่แตะ Response)   │
├──────────────────────────────────────────────────────┤
│ Models       — SQL + transaction boundaries          │
│                (คง role เดิม แต่บางเบาลง)            │
└──────────────────────────────────────────────────────┘
```

**Contract สำคัญ:** Service ห้ามเรียก `Response::*` และห้าม `exit()` — สื่อสารความผิดพลาดด้วย `Exception` เท่านั้น Controller เป็นคนเดียวที่ catch → map เป็น `Response::error($e->getMessage(), 400)`. นี่คือกุญแจไขปัญหา "test ไม่ได้เพราะ exit กลาง flow"

### 3.2 Service classes ที่เสนอ

| Service | Extract จาก | Responsibility | ลักษณะ |
|:--------|:------------|:---------------|:-------|
| `FinancialReportService` | `FinancialController` (853 ln) | สร้าง period params (จาก input array — ไม่แตะ `$_GET`), build+run report queries, aggregate summary/trend/comparison/top-sellers, produce export datasets (CSV/Excel share) | stateless, DB-injected |
| `CashMovementService` | `CashSession` (913 ln) | movement validation (direction/amount finite), drawer-sufficiency rule, position-model routing, owner_capital base/excess split decision | rule-heavy |
| `FifoAllocationService` | `SaleLot` (907 ln) | **pure** allocation math: FIFO walk (`take = min(available, remaining)`), weighted-avg, provisional estimate, insufficient-stock check — รับ array of rows คืน cost | 100% pure, ไม่มี DB |
| `StockTransferService` | `StockTransfer` (981 ln) | item normalization/validation (`normalizeCreateItems`, `normalizeConfirmItems`, received-weight assertion), reversal validation — ส่วน transactional คงอยู่ใน Model | validation-heavy |

> หมายเหตุ: ตั้งใจ**ไม่**แตกเป็น micro-service จำนวนมาก — 4 ตัวนี้ map ตรงกับ bounded logic 4 ก้อน ไม่เกินความจำเป็น (no architecture astronautics)

### 3.3 Dependency Injection — lightweight constructor injection

`Model::$db` ไม่มี type hint (`@var mixed`) และ `Database::getInstance()` ถูกเรียกใน constructor → subclass/service ทำ pattern เดียวกันได้:

```php
// Services/CashMovementService.php
class CashMovementService
{
    private $db;

    public function __construct($db = null)
    {
        // production: ไม่ส่งอะไรมา → ใช้ singleton เดิม (backward compatible)
        // test: inject fake/stub ได้ทันที
        $this->db = $db ?: Database::getInstance();
    }
}
```

- **ไม่มี DI container** — caller ที่มีอยู่ (`new CashSession()`) ทำงานเหมือนเดิมทุกจุด
- Model ที่ต้องการ inject ภายหลัง override constructor แบบเดียวกันได้ (ไม่ต้องแก้ `Core/Model.php`)
- Service ↔ Model wiring: Service รับ `Database` และ instantiate Model ที่ต้องใช้เอง (composition ธรรมดา)

### 3.4 Backward Compatibility

| มุม | การันตี |
|:----|:--------|
| HTTP contract | routes, method names, JSON shape, status codes — **ไม่เปลี่ยน** Controller คง signature เดิม เพียง delegate ภายใน |
| Admin JS | ไม่แตะไฟล์ JS เลย — response payload ต้อง byte-compatible (ตรวจด้วย manual smoke ต่อ endpoint) |
| Error semantics | ข้อความ Exception เดิม (เช่น `'เงินสดในลิ้นชักไม่เพียงพอ...'`) ต้อง flow ผ่าน `Response::error` แบบเดิม — copy ข้อความตรง ๆ ห้ามแต่ง |
| Public Model API | `CashSession::recordMovement()` ฯลฯ ยังเรียกได้จากทุก caller เดิม — ภายใน delegate ไป Service |

---

## 4. Technical Design

### 4.1 ตัวอย่าง Before/After — FinancialController

**Before (ปัจจุบัน):**
```php
public function summary()
{
    $this->requireReportAuth();
    $params = $this->getPeriodParams();      // อ่าน $_GET ตรง ๆ
    $db = Database::getInstance();           // singleton hard-wired
    // ... 6 queries + aggregation inline ...
    Response::success('สำเร็จ', [...]);      // HTTP ปน business logic
}
```

**After:**
```php
public function summary()
{
    $this->requireReportAuth();
    try {
        $data = (new FinancialReportService())
            ->getSummary($this->periodInput());   // periodInput(): $_GET → plain array (HTTP เท่านั้น)
        Response::success('สำเร็จ', $data);
    } catch (Exception $e) {
        Response::error($e->getMessage(), 400);
    }
}
```

### 4.2 ตัวอย่าง — FifoAllocationService (pure, test ได้โดยไม่มี DB)

```php
// Services/FifoAllocationService.php
class FifoAllocationService
{
    /** @param array $rows [{net_quantity, unit_price, consumed_qty}, ...] เรียง FIFO แล้ว */
    public function allocateFifo(array $rows, float $quantityKg): float
    {
        $remaining = $quantityKg;
        $totalCost = 0.0;
        foreach ($rows as $row) {
            if ($remaining <= 0) break;
            $available = (float)$row['net_quantity'] - (float)$row['consumed_qty'];
            $take      = min($available, $remaining);
            $totalCost += $take * (float)$row['unit_price'];
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new InvalidArgumentException("สต็อกไม่เพียงพอ (ขาด {$remaining} กก.)");
        }
        return $totalCost;
    }

    public function weightedAverage(array $aggRow, float $quantityKg): float { /* pure เช่นกัน */ }
}
```

`SaleLot::calculateFifoCost()` เดิมยังอยู่ (public API คงเดิม) — ภายในกลายเป็น: fetch rows → `return (new FifoAllocationService())->allocateFifo($rows, $quantity_kg);`

### 4.3 ตัวอย่าง — CashMovementService (rule extraction จาก `recordMovement`/`fundDrawer`)

```php
// pure decision — test ได้ทันที
public function resolveOwnerCapitalSplit(float $amount, float $excessRequested, float $reserveBalance): array
{
    // returns ['from_reserve' => f, 'base_external' => f, 'excess' => f]
    // logic เดิมจาก fundDrawer() lines 637–664 — move as-is, ห้ามแต่ง
}

public function assertMovementValid(string $direction, float $amount): void
{
    if (!in_array($direction, ['in', 'out'], true) || !is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException('ข้อมูลรายการเงินสดไม่ถูกต้อง');   // ข้อความเดิม
    }
}
```

`CashSession::recordMovement()` คง signature เดิม → เรียก service validate/split → คง INSERT + transaction ไว้ที่ Model

---

## 5. Migration Path — 4 Phases (refactor เร็วหลัง test)

> หลักการ: **ทุก phase ขึ้นกับ safety net ที่มีอยู่ก่อน — phase ไหน coverage บางที่สุด ทำทีหลังสุด**

### Phase 1 — FinancialReportService (test net: 5 + 4 cases + duplication payoff สูงสุด) — ~2.5 วัน

| Day | งาน |
|:----|:----|
| 1 | สร้าง `FinancialReportService` skeleton + ย้าย `getPeriodParams()` เป็น `buildPeriodParams(array $input)` (pure — test เพิ่มได้ทันที) |
| 2 | ย้าย summary/lotRevenues/purchaseByCategory/monthlyTrend/branchComparison/topSellers/topBuyers datasets → Controller เหลือ parse+respond |
| 3 | Unify `exportCsv`/`exportExcel` ให้ใช้ dataset เดียวกัน (kill ~150 บรรทัด duplicate) + เพิ่ม unit test params/dataset builders + run suite |

### Phase 2 — CashMovementService (test net: 15 cases แน่นสุด) — ~2 วัน

| Day | งาน |
|:----|:----|
| 1 | Extract validation + `resolveOwnerCapitalSplit()` + drawer-sufficiency rule → 15 tests เดิมต้องเขียว (regression proof) |
| 2 | เพิ่ม unit test สำหรับ split edge cases (excess > amount, reserve บางส่วน, excess=0) |

### Phase 3 — FifoAllocationService (extract pure ก่อน → test ตามหลังได้ฟรี) — ~2 วัน

| Day | งาน |
|:----|:----|
| 1 | Extract allocation loops จาก `calculateFifoCost`/`calculateProvisionalCost`/`calculateWeightedAvgCost` — SaleLot delegates |
| 2 | เขียน unit test FIFO (partial-lot, exact-fit, insufficient → exception, zero-qty) + weighted avg |

### Phase 4 — StockTransferService (coverage = 0 → ทำท้ายสุด ระวังสุด) — ~3 วัน

| Day | งาน |
|:----|:----|
| 1 | Extract `normalizeCreateItems` + `normalizeConfirmItems` + `assertReceivedWeightWithinOrder` (pure validation) — **ไม่แตะ `confirm()` transactional core ใน step นี้** |
| 2 | เขียน unit test normalization ให้แน่น (item_name NULL, weight overflow, reversal items mismatch) |
| 3 | เมื่อ test คุม validation แล้ว ค่อยพิจารณา extract orchestration ออกจาก `confirm()` — ถ้า risk สูงเกิน ให้หยุดที่ step 2 (explicit stop-loss point) |

**Total: ~9.5 วัน (≈ 2 sprints)**

### Dependencies
- Phase 1 → อิสระ, Phase 2 → อิสระ (ทำ parallel ได้ถ้ามีคน)
- Phase 3 ก่อน Phase 4 แนะนำ (Phase 4 reuse บทเรียนเรื่อง pure-extraction)
- ทุก phase: `docker` run PHPUnit suite ต้องเขียวก่อน commit

---

## 6. Testing Strategy

| Level | สิ่งที่ test | วิธี |
|:------|:-------------|:-----|
| Unit — pure services | `FifoAllocationService`, `buildPeriodParams()`, `resolveOwnerCapitalSplit()`, transfer normalizers | constructor-less / stub db — เร็วมาก, เพิ่มจำนวน case ได้อิสระ |
| Unit — DB-injected services | `FinancialReportService`, `CashMovementService` | inject fake `$db` (hand-rolled stub ที่ implement `fetch/fetchAll/fetchColumn`) — **ไม่แนะนำ mocking framework ใหม่** ตาม Non-goals |
| Regression (มีอยู่) | 15 + 5 + 4 cases เดิม | ต้องเขียวทุก phase — เป็น migration correctness proof |
| Integration/manual | HTTP endpoints จริง (headers, CSV BOM, Excel mime) | smoke ต่อ phase ผ่าน docker — จงใจ**ไม่**ทำ HTTP integration test ใน scope นี้ (exit()/header() ยังเป็นข้อจำกัดของ PHP classic SAPI) |

**Coverage gate:** ต่อยอด `workers/auto_qa_gate.py --threshold` mindset ของ SoloCorp — target บวมขึ้นจาก baseline หลัง Phase 3

---

## 7. Risk Assessment

### Risk 1: Behavior drift ระหว่างย้ายโค้ด
- **ปัญหา:** copy-paste-refactor อาจเปลี่ยน message/status/rounding โดยไม่ตั้งใจ
- **Mitigation:** กฎ "move as-is ก่อน เสมอ" — commit แรกของทุก extraction ต้องเป็น pure move; ห้ามแก้ logic พร้อมกัน 2 อย่างใน commit เดียว
- **Detection:** regression suite เดิม + diff review บังคับ

### Risk 2: `Response::error()` exit-semantics เปลี่ยน
- **ปัญหา:** เดิม `Response::error()` ใน controller private helper `exit` ทันที — ถ้าแปลงเป็น Exception แล้ว flow เดิมมี code ต่อจากจุด error จะรันเพิ่ม
- **Mitigation:** audit ทุกจุดที่เรียก `Response::error` ใน 4 ไฟล์ — ทุกจุดใน `FinancialController` อยู่ใน path ที่ `return` ตามทันทีหรือเป็น terminal อยู่แล้ว (ยืนยันจากการอ่านโค้ด line 29–34, 306–330) — แต่ reviewer ต้อง re-check อีกชั้น

### Risk 3: StockTransfer::confirm() แตกแล้วพัง (220 บรรทัด, 0 test)
- **ปัญหา:** transactional core ผูก branch_stock dual-write (ADD-001) — พัง = stock เพี้ยน
- **Mitigation:** Phase 4 มี **stop-loss**: ทำแค่ pure validation extraction + tests; orchestration extraction เป็น optional — ถ้า test ไม่คุมพอ ไม่ต้องทำ
- **Rollback:** `git revert` ต่อ phase (แต่ละ phase ≤ 3 วัน = revert ได้ไม่เจ็บ)

### Risk 4: Service instantiation กระจัดกระจาย (`new XService()` ทุก method)
- **ปัญหา:** ไม่มี container → จุด new กระจาย อาจสร้าง object ซ้ำ
- **Mitigation:** ยอมรับ — object เหล่านี้ stateless และถูกสร้าง per-request อยู่แล้ว cost ต่ำมาก; ห้ามเพิ่ม registry/singleton ใหม่ (Non-goals)

---

## 8. Consequences

### ✅ ข้อดี
1. Business logic unit-test ได้จริง — FIFO, cash rules, transfer validation ไม่ต้องมี MySQL
2. Kill duplication ~150 บรรทัดใน FinancialController exports — แก้ที่เดียว effect ทุก format
3. `exit()`-กลาง-flow หายจาก business paths → อนาคตต่อยอด integration test ได้
4. Autoload-ready — `customizations/api/Services/` ถูก scan อยู่แล้ว ศูนย์ config change
5. ทุก phase deploy ได้ ไม่มี big-bang cutover

### ⚠️ ข้อเสีย / Trade-offs
1. Indirection เพิ่ม — อ่าน `summary()` แล้วต้อง jump ไป service (ยอมรับเพื่อ testability)
2. Constructor injection แบบ default-null ยัง permit hidden singleton path (`$db ?: Database::getInstance()`) — purist อาจไม่พอใจ แต่จำเป็นเพื่อ backward compat
3. Models ยังผูก singleton ใน constructor — แก้เฉพาะตัวที่จำเป็น ไม่ใช่ทั้งระบบ (scope control)
4. ~9.5 วันที่ไม่ได้ ship feature ใหม่ — ชดเชยด้วยความเร็วในการเพิ่ม feature รายงานต่อจากนี้

---

## 9. Appendix: Source Files

| File | Path |
|:-----|:-----|
| FinancialController.php | `code/customizations/api/Controllers/FinancialController.php` |
| CashSession.php | `code/customizations/api/Models/CashSession.php` |
| SaleLot.php | `code/customizations/api/Models/SaleLot.php` |
| StockTransfer.php | `code/customizations/api/Models/StockTransfer.php` |
| SellerIdCipher.php (precedent) | `code/customizations/api/Services/SellerIdCipher.php` |
| Model.php (base) | `code/base-pos/api/Core/Model.php` |
| autoload.php | `code/base-pos/api/autoload.php` |
| Existing tests | `code/tests/Unit/{CashSession,FinancialControllerLogic,CsvInjectionProtection}Test.php` |
| Related ADR | `decisions/ADD-001-branch-stock-redesign.md` |
