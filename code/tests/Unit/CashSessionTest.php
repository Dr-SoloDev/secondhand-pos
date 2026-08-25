<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Unit tests for CashSession daily cash-cycle logic (WF-06 cash position model v2).
 *
 * Critical paths covered (จาก git history — จุดที่เคย bug):
 * - เติมเงินดึงจากเซฟเท่านั้น + transfer หัก reserve จริง (กันนับเงินซ้ำที่เซฟ+ลิ้นชัก)
 * - owner_capital: เติมเกินทุนเดิม → แยก base/excess, excess = รายรับเพิ่มทุน
 * - เปิดวัน: ดึงจากเซฟก่อน ส่วนเกิน = capital injection
 * - ปิดยอด: เก็บเงินเข้าเซฟทั้งหมด (ลิ้นชักว่างตอนเช้าจะดึงใหม่)
 * - variance ต้องมีเหตุผล / เกิน threshold 100 → pending approval
 * - idempotency ของ movement insert (กันบันทึกซ้ำ/อนุมัติซ้ำ)
 * - ห้าม self-approval
 *
 * Strategy: ห้ามแก้ production code → subclass injection ผ่าน Reflection
 * (Model::$db เป็น protected และไม่มี type hint → FakeDb ใส่ตรง ๆ ได้)
 */
class CashSessionTest extends TestCase
{
    // ---------- helpers ----------

    private function makeSession(FakeCashSessionDb $db): \CashSession
    {
        $rc = new ReflectionClass(\CashSession::class);
        /** @var \CashSession $session */
        $session = $rc->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(\Model::class, 'db');
        $prop->setAccessible(true);
        $prop->setValue($session, $db);
        return $session;
    }

    private function invokePrivate(object $target, string $method, array $args = [])
    {
        $m = new ReflectionMethod($target, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($target, $args);
    }

    private function baseline(float $drawer, float $reserve): array
    {
        return [
            'id' => 5,
            'created_at' => '2026-08-01 00:00:00',
            'effective_date' => '2026-08-01',
            'drawer_balance' => $drawer,
            'reserve_balance' => $reserve,
        ];
    }

    /** Script มาตรฐาน: สาขา 1 มี position model + session เปิดอยู่ (id=10) */
    private function scriptActiveBranch(FakeCashSessionDb $db, float $drawer, float $reserve): void
    {
        $db->onFetchColumn('SELECT id FROM cash_position_baselines', 5)          // hasPositionModel
           ->onFetch('SELECT * FROM cash_sessions',                              // assertOpen
               ['id' => 10, 'branch_id' => 1, 'status' => 'open'])
           ->onFetch('SELECT * FROM cash_position_baselines',                    // getPositionBalances
               $this->baseline($drawer, $reserve))
           ->onFetchAll('balance_effect IS NOT NULL', []);                       // ไม่มี movement สะสม
    }

    // ---------- fundDrawer ----------

    public function testFundDrawerRejectsInvalidSourceType(): void
    {
        // sourceType ต้องเป็น reserve_transfer หรือ owner_capital เท่านั้น
        $db = new FakeCashSessionDb();
        $db->onFetchColumn('SELECT id FROM cash_position_baselines', 5);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('กรุณาระบุว่าเป็นเงินสำรองเดิมหรือเงินทุนใหม่');
        $session->fundDrawer(1, 500.0, 'mystery_source', 42, 'desc', 9);
    }

    public function testFundDrawerReserveTransferRejectsWhenReserveInsufficient(): void
    {
        // เติมเงินแบบ reserve_transfer ต้องดึงจากเซฟเท่านั้น — เซฟไม่พอต้อง throw
        $db = new FakeCashSessionDb();
        $this->scriptActiveBranch($db, drawer: 100.0, reserve: 200.0);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('เงินสำรองของกิจการไม่เพียงพอ');
        $session->fundDrawer(1, 300.0, 'reserve_transfer', 42, 'desc', 9);
    }

    public function testFundDrawerReserveTransferMovesMoneyFromSafeToDrawerOnly(): void
    {
        // reserve_transfer = transfer จากเซฟ → ลิ้นชัก (ไม่มี increase รวมยอดธุรกิจ)
        $db = new FakeCashSessionDb();
        $this->scriptActiveBranch($db, drawer: 100.0, reserve: 200.0);
        $session = $this->makeSession($db);

        $id = $session->fundDrawer(1, 80.0, 'reserve_transfer', 42, 'เติมเงินจากเซฟ', 9);

        $this->assertGreaterThan(0, $id);
        $movements = $db->decodedMovements();
        $this->assertCount(1, $movements);
        $m = $movements[0];
        $this->assertSame('cash_reserve_transfer', $m['type']);
        $this->assertSame('transfer', $m['effect']);
        $this->assertSame('in', $m['direction']);
        $this->assertSame('business_reserve', $m['source']);
        $this->assertSame('drawer', $m['destination']);
        $this->assertSame(80.0, $m['amount']);
        $this->assertSame(0.0, $m['excess'], 'reserve_transfer ต้องไม่นับเป็นรายรับเพิ่มทุน');
    }

    public function testFundDrawerOwnerCapitalSplitsBaseFromReserveAndExcessAsNewCapitalIncome(): void
    {
        // เติม 500 (excess 200) ขณะเซฟมี 300:
        // base 300 ดึงจากเซฟเป็น transfer (หัก reserve จริง — กันนับซ้ำ 2 ที่),
        // excess 200 เป็น increase จาก external + excess_amount 200 (รายรับเพิ่มทุน)
        $db = new FakeCashSessionDb();
        $this->scriptActiveBranch($db, drawer: 50.0, reserve: 300.0);
        $session = $this->makeSession($db);

        $id = $session->fundDrawer(1, 500.0, 'owner_capital', 42, 'Owner เติมเงิน', 9, 200.0);

        $this->assertGreaterThan(0, $id);
        $movements = $db->decodedMovements();
        $this->assertCount(2, $movements);

        $base = $movements[0];
        $this->assertSame('owner_capital_base', $base['type']);
        $this->assertSame('transfer', $base['effect'], 'base ต้องหักออกจากเซฟ (transfer) ไม่ใช่ increase');
        $this->assertSame('business_reserve', $base['source']);
        $this->assertSame('drawer', $base['destination']);
        $this->assertSame(300.0, $base['amount']);

        $excess = $movements[1];
        $this->assertSame('owner_capital_excess', $excess['type']);
        $this->assertSame('increase', $excess['effect']);
        $this->assertSame('external', $excess['source']);
        $this->assertSame(200.0, $excess['amount']);
        $this->assertSame(200.0, $excess['excess'], 'excess_amount ต้องถูกบันทึกเพื่อนับรายรับเพิ่มทุน');
    }

    public function testFundDrawerOwnerCapitalBaseBeyondReserveRecordedAsIncreaseNotRevenue(): void
    {
        // เติม 500 ไม่ระบุ excess ขณะเซฟมีแค่ 200:
        // 200 จากเซฟ (transfer) + base ส่วนที่เหลือ 300 เป็น increase "ทุนเดิม"
        // → ห้ามมี owner_capital_excess (ไม่นับรายรับซ้ำ) ← regression ของ bug MVP
        $db = new FakeCashSessionDb();
        $this->scriptActiveBranch($db, drawer: 20.0, reserve: 200.0);
        $session = $this->makeSession($db);

        $id = $session->fundDrawer(1, 500.0, 'owner_capital', 43, 'เติมทดแทนที่ใช้ไป', 9);

        $this->assertGreaterThan(0, $id);
        $movements = $db->decodedMovements();
        $this->assertCount(2, $movements);
        $this->assertSame('owner_capital_base', $movements[0]['type']);
        $this->assertSame('transfer', $movements[0]['effect']);
        $this->assertSame(200.0, $movements[0]['amount']);
        $this->assertSame('owner_capital_base_ext', $movements[1]['type']);
        $this->assertSame('increase', $movements[1]['effect']);
        $this->assertSame(300.0, $movements[1]['amount']);
        $this->assertSame(0.0, $movements[1]['excess'], 'base_ext เป็นทุนเดิม ห้ามนับเป็นรายรับเพิ่มทุน');
    }

    public function testFundDrawerLegacyBranchFallsBackToSimpleDepositMovement(): void
    {
        // สาขาที่ยังไม่เปิด position model → บันทึก cash_deposit ปกติ (legacy path)
        $db = new FakeCashSessionDb();
        $db->onFetchColumn('SELECT id FROM cash_position_baselines', null)       // hasPositionModel = false (x2)
           ->onFetch('SELECT * FROM cash_sessions',
               ['id' => 11, 'branch_id' => 1, 'status' => 'open', 'opening_actual' => 100.0]);
        $session = $this->makeSession($db);

        $id = $session->fundDrawer(1, 500.0, 'owner_capital', 44, 'เติมเงิน (legacy)', 9);

        $this->assertGreaterThan(0, $id);
        $legacy = $db->legacyMovements();
        $this->assertCount(1, $legacy);
        $this->assertSame('cash_deposit', $legacy[0]['type']);
        $this->assertSame('in', $legacy[0]['direction']);
        $this->assertSame(500.0, $legacy[0]['amount']);
    }

    // ---------- openPositionDay ----------

    public function testOpenPositionDayRejectsWhenUnfinishedSessionExists(): void
    {
        // มีรอบค้าง (pending_open/open/pending_close) → ห้ามเปิดรอบใหม่
        $db = new FakeCashSessionDb();
        $db->onFetch("status IN ('pending_open','open','pending_close')",
            ['id' => 99, 'status' => 'open']);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('สาขานี้มีรอบประจำวันที่ยังดำเนินการไม่เสร็จ');
        $this->invokePrivate($session, 'openPositionDay', [1, 700.0, null, 9]);
    }

    public function testOpenPositionDayDrawsReserveFirstAndCountsRemainderAsCapitalInjection(): void
    {
        // เช้าเปิดวันขอ 700 แต่เซฟมี 500 → ดึงเซฟ 500 เป็น transfer,
        // ส่วนเกิน 200 = เงินใหม่จาก Owner (capital injection, รายรับเพิ่มทุน)
        $db = new FakeCashSessionDb();
        $db->onFetch("status IN ('pending_open','open','pending_close')", null)
           ->onFetch('business_date=CURDATE()', null)                            // ไม่มีรอบค้างวันนี้
           ->onFetch('SELECT * FROM cash_position_baselines', $this->baseline(0.0, 500.0))
           ->onFetchAll('balance_effect IS NOT NULL', []);
        $session = $this->makeSession($db);

        /** @var array $result */
        $result = $this->invokePrivate($session, 'openPositionDay', [1, 700.0, 'เปิดวัน', 9]);

        $this->assertSame('open', $result['status']);
        $this->assertSame(500.0, $result['reserve_transfer'], 'ต้องดึงจากเซฟได้เท่าที่เซฟมี');
        $this->assertSame(200.0, $result['capital_injection'], 'ส่วนเกินเซฟ = เพิ่มทุนใหม่');
        $this->assertSame(700.0, $result['drawer_balance']);

        $movements = $db->decodedMovements();
        $this->assertCount(2, $movements);
        $this->assertSame('cash_session_open_transfer', $movements[0]['type']);
        $this->assertSame('transfer', $movements[0]['effect']);
        $this->assertSame(500.0, $movements[0]['amount']);
        $this->assertSame('owner_capital_excess', $movements[1]['type']);
        $this->assertSame('increase', $movements[1]['effect']);
        $this->assertSame(200.0, $movements[1]['excess']);
    }

    // ---------- closePositionDay ----------

    public function testClosePositionDaySweepsAllCountedCashIntoSafe(): void
    {
        // ปิดยอด: นับได้ 800 ตรงลิ้นชัก → เก็บเข้าเซฟทั้งหมด (closing_to_safe)
        // ลิ้นชักว่าง 0 เช้าวันถัดไปจะดึงจากเซฟใหม่ — ห้ามนับเงินซ้ำ 2 ตำแหน่ง
        $db = new FakeCashSessionDb();
        $db->onFetch("AND status='open'", ['id' => 10, 'branch_id' => 1, 'status' => 'open'])
           ->onFetch('SELECT * FROM cash_position_baselines', $this->baseline(800.0, 300.0))
           ->onFetchAll('balance_effect IS NOT NULL', []);
        $session = $this->makeSession($db);

        /** @var array $result */
        $result = $this->invokePrivate($session, 'closePositionDay', [1, 800.0, null, 9]);

        $this->assertSame('closed', $result['status']);
        $this->assertSame(800.0, $result['expected_cash']);
        $this->assertSame(0.0, $result['variance']);
        $this->assertSame(0.0, $result['drawer_balance'], 'หลังปิดยอดลิ้นชักต้องว่าง');
        $this->assertSame(800.0, $result['closing_transfer_amount']);

        $movements = $db->decodedMovements();
        $this->assertCount(1, $movements);
        $m = $movements[0];
        $this->assertSame('closing_to_safe', $m['type']);
        $this->assertSame('out', $m['direction']);
        $this->assertSame('transfer', $m['effect'], 'ย้ายลิ้นชัก → เซฟ ต้องเป็น transfer ไม่ใช่ decrease');
        $this->assertSame('drawer', $m['source']);
        $this->assertSame('business_reserve', $m['destination']);
        $this->assertSame(800.0, $m['amount']);
    }

    public function testClosePositionDayRequiresReasonOnVariance(): void
    {
        // นับได้ 750 แต่ระบบคาด 800 → ต่างเล็กน้อยแต่ต้องระบุเหตุผล
        $db = new FakeCashSessionDb();
        $db->onFetch("AND status='open'", ['id' => 10, 'branch_id' => 1, 'status' => 'open'])
           ->onFetch('SELECT * FROM cash_position_baselines', $this->baseline(800.0, 300.0))
           ->onFetchAll('balance_effect IS NOT NULL', []);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ยอดเงินจริงไม่ตรงยอดในลิ้นชัก');
        $this->invokePrivate($session, 'closePositionDay', [1, 750.0, null, 9]);
    }

    public function testClosePositionDayLargeVarianceRequiresApprovalBeforeClosing(): void
    {
        // ต่างเกิน threshold (100) → ต้องเป็น pending_close รออนุมัติ ไม่ปิดเอง
        $db = new FakeCashSessionDb();
        $db->onFetch("AND status='open'", ['id' => 10, 'branch_id' => 1, 'status' => 'open'])
           ->onFetch('SELECT * FROM cash_position_baselines', $this->baseline(800.0, 300.0))
           ->onFetchAll('balance_effect IS NOT NULL', []);
        $session = $this->makeSession($db);

        /** @var array $result */
        $result = $this->invokePrivate($session, 'closePositionDay', [1, 600.0, 'เหตุผลที่ยอดขาด', 9]);

        $this->assertSame('pending_close', $result['status']);
        $this->assertSame(-200.0, $result['variance']);
    }

    // ---------- approval guards ----------

    public function testApproveOpenBlocksSelfApproval(): void
    {
        // ผู้ขอเปิดยอด (id=7) ห้ามอนุมัติคำขอของตัวเอง
        $db = new FakeCashSessionDb();
        $db->onFetch('cash_sessions WHERE id=? FOR UPDATE', [
            'id' => 10, 'status' => 'pending_open',
            'opening_requested_by' => 7,
            'opening_expected' => 500.0, 'opening_actual' => 480.0, 'opening_variance' => -20.0,
        ]);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ไม่สามารถอนุมัติรายการตัวเองได้');
        $session->approveOpen(10, 7);
    }

    public function testRejectOpenRequiresReason(): void
    {
        // ปฏิเสธคำขอโดยไม่มีเหตุผล → reject ทันทีก่อนแตะ DB
        $db = new FakeCashSessionDb();
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('กรุณาระบุเหตุผลที่ปฏิเสธ');
        $session->rejectOpen(10, 2, '   ');
    }

    // ---------- idempotency & utils ----------

    public function testMovementInsertIsIdempotentPerTypeAndReference(): void
    {
        // movement_type + reference_type + reference_id ซ้ำ → คืน id เดิม ไม่ insert ใหม่
        // (กันบันทึกซ้ำเมื่อ request เดิมถูก replay/approve ซ้ำ)
        $db = new FakeCashSessionDb();
        $db->onFetchColumn('cash_movements WHERE movement_type=?', 55);
        $session = $this->makeSession($db);

        $id = $this->invokePrivate($session, 'insertPositionMovement',
            [10, 1, 'in', 'transfer', 250.0, 'business_reserve', 'drawer',
             'cash_reserve_transfer', 42, 'desc', 9]);

        $this->assertSame(55, $id);
        $this->assertCount(0, $db->decodedMovements(), 'ต้องไม่มี INSERT เกิดขึ้น');
    }

    public function testNormalizeReasonTrimsTruncatesAndNullifiesEmpty(): void
    {
        $db = new FakeCashSessionDb();
        $session = $this->makeSession($db);

        $this->assertNull($this->invokePrivate($session, 'normalizeReason', [null]));
        $this->assertNull($this->invokePrivate($session, 'normalizeReason', ['   ']));
        $this->assertSame('ok', $this->invokePrivate($session, 'normalizeReason', ['  ok  ']));

        $long = str_repeat('x', 600);
        $normalized = $this->invokePrivate($session, 'normalizeReason', [$long]);
        $this->assertSame(500, strlen($normalized));
    }
}

/**
 * Fake Database สำหรับ unit test — script ผลลัพธ์ด้วย SQL substring needle
 * (Model::$db ไม่มี type hint จึงใช้ class ธรรมดาแทน Database singleton ได้)
 */
class FakeCashSessionDb
{
    private array $fetchScript = [];
    private array $fetchAllScript = [];
    private array $fetchColumnScript = [];
    private array $insertedMovements = [];   // raw params ของ INSERT INTO cash_movements
    private string $lastPreparedSql = '';
    private int $insertIdCounter = 100;

    public function onFetch(string $needle, $result): self { $this->fetchScript[$needle][] = $result; return $this; }
    public function onFetchAll(string $needle, $result): self { $this->fetchAllScript[$needle][] = $result; return $this; }
    public function onFetchColumn(string $needle, $result): self { $this->fetchColumnScript[$needle][] = $result; return $this; }

    private function take(array &$script, string $sql)
    {
        foreach ($script as $needle => $queue) {
            if ($needle !== '' && str_contains($sql, $needle)) {
                return array_shift($script[$needle]); // queue หมด → null (default)
            }
        }
        return null;
    }

    public function fetch($query, array $params = []) { return $this->take($this->fetchScript, (string)$query); }
    public function fetchAll($query, array $params = []) { return $this->take($this->fetchAllScript, (string)$query); }
    public function fetchColumn($query, array $params = [], int $column = 0) { return $this->take($this->fetchColumnScript, (string)$query); }

    public function prepare($sql) { $this->lastPreparedSql = (string)$sql; return new stdClass(); }
    public function execute($stmt, array $params = [])
    {
        if (str_contains($this->lastPreparedSql, 'INSERT INTO cash_movements')) {
            $this->insertedMovements[] = ['sql' => $this->lastPreparedSql, 'params' => $params];
        }
        return true;
    }
    public function query($sql, array $params = [])
    {
        if (str_contains((string)$sql, 'INSERT INTO cash_movements')) {
            $this->insertedMovements[] = ['sql' => (string)$sql, 'params' => $params];
        }
        return true;
    }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }
    public function lastInsertId(): string { return (string)(++$this->insertIdCounter); }

    /** Decode params ของ position-movement INSERT เป็น assoc array */
    public function decodedMovements(): array
    {
        return array_map(static function (array $row): array {
            $p = $row['params'];
            return [
                'session_id' => (int)$p[0], 'branch_id' => (int)$p[1],
                'direction' => $p[2], 'source' => $p[3], 'destination' => $p[4], 'effect' => $p[5],
                'amount' => (float)$p[6], 'excess' => (float)$p[7], 'type' => $p[8],
                'reference_type' => $p[9], 'reference_id' => (int)$p[10],
                'description' => $p[11], 'user_id' => (int)$p[12],
            ];
        }, $this->insertedMovements);
    }

    /** Legacy recordMovement INSERT (9 columns) */
    public function legacyMovements(): array
    {
        return array_map(static function (array $row): array {
            $p = $row['params'];
            return ['type' => $p[3], 'direction' => $p[2], 'amount' => (float)$p[4]];
        }, array_values(array_filter($this->insertedMovements,
            static fn (array $r): bool => str_contains($r['sql'], '(cash_session_id,branch_id,direction,movement_type'))));
    }
}
