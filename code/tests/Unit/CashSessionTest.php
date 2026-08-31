<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Unit tests for CashSession daily cash-cycle logic.
 *
 * Simple daily drawer model:
 * - เปิดวัน = ใส่เท่าไหร่ = ลิ้นชักเท่านั้น (ไม่ยกยอดวันก่อน)
 * - ระหว่างวัน = ซื้อ = ตัดลิ้นชัก / เติมเงิน = เพิ่มลิ้นชัก
 * - ปิดยอด = นับจริง vs ยอดตามระบบ = variance, ไม่ย้ายเงิน
 * - เช้าใหม่ = เริ่มใหม่ ไม่เก็บยอดปิด
 * - variance ต้องมีเหตุผล / เกิน threshold 100 → pending approval
 * - idempotency ของ movement insert
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
               ['id' => 10, 'branch_id' => 1, 'status' => 'open', 'opening_actual' => 100.0])
           ->onFetch('SELECT * FROM cash_position_baselines',                    // getPositionBalances
               $this->baseline($drawer, $reserve))
           ->onFetchAll('balance_effect IS NOT NULL', []);                       // ไม่มี movement สะสม
    }

    // ---------- fundDrawer ----------

    public function testFundDrawerSimpleDepositAddsToDrawer(): void
    {
        // Simple model: fundDrawer แค่เพิ่มเงินเข้าลิ้นชัก
        $db = new FakeCashSessionDb();
        $this->scriptActiveBranch($db, drawer: 100.0, reserve: 200.0);
        $session = $this->makeSession($db);

        $id = $session->fundDrawer(1, 500.0, 'owner_capital', 42, 'เติมเงิน', 9);

        $this->assertGreaterThan(0, $id);
        $legacy = $db->legacyMovements();
        $this->assertCount(1, $legacy);
        $this->assertSame('cash_deposit', $legacy[0]['type']);
        $this->assertSame('in', $legacy[0]['direction']);
        $this->assertSame(500.0, $legacy[0]['amount']);
    }

    // ---------- openPositionDay ----------

    public function testOpenPositionDayRejectsSecondOpenSameDay(): void
    {
        // เปิดได้ครั้งเดียวต่อวัน — เปิดซ้ำต้องถูกปฏิเสธ ให้ใช้เติมเงินแทน (ห้ามล้าง movement)
        $db = new FakeCashSessionDb();
        $db->onFetch('business_date=CURDATE()', ['id' => 99, 'status' => 'open']);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('เปิดยอดประจำวันนี้ไปแล้ว');
        $this->invokePrivate($session, 'openPositionDay', [1, 700.0, null, 9]);
    }

    public function testOpenPositionDaySetsOpeningAmountDirectly(): void
    {
        // เปิดวัน = ใส่เท่าไหร่ = ลิ้นชักเท่านั้น ไม่ยกยอด ไม่ reserve/capital
        $db = new FakeCashSessionDb();
        $db->onFetch('business_date=CURDATE()', null)
           ->onFetchColumn('SUM(CASE', 0);
        $session = $this->makeSession($db);

        $result = $this->invokePrivate($session, 'openPositionDay', [1, 700.0, 'เปิดวัน', 9]);

        $this->assertSame('open', $result['status']);
        $this->assertSame(700.0, $result['drawer_balance']);
        // Simple model: no movement at open — opening_actual IS the drawer balance
        $movements = $db->decodedMovements();
        $this->assertCount(0, $movements);
    }

    // ---------- closePositionDay ----------

    public function testClosePositionDayRecordsVarianceWithoutTransfer(): void
    {
        // ปิดยอด: นับได้ 800 ตรงลิ้นชัก → บันทึก variance = 0 ไม่ย้ายเงิน
        $db = new FakeCashSessionDb();
        $db->onFetch("AND status='open'", ['id' => 10, 'branch_id' => 1, 'status' => 'open', 'opening_actual' => 800.0])
           ->onFetchColumn('SUM(CASE', 0);  // movementTotal = 0
        $session = $this->makeSession($db);

        $result = $this->invokePrivate($session, 'closePositionDay', [1, 800.0, null, 9]);

        $this->assertSame('closed', $result['status']);
        $this->assertSame(800.0, $result['expected_cash']);
        $this->assertSame(0.0, $result['variance']);
        // ไม่มี movement — ปิดยอดไม่ย้ายเงิน
        $movements = $db->decodedMovements();
        $this->assertCount(0, $movements);
    }

    public function testClosePositionDayRequiresReasonOnVariance(): void
    {
        $db = new FakeCashSessionDb();
        $db->onFetch("AND status='open'", ['id' => 10, 'branch_id' => 1, 'status' => 'open', 'opening_actual' => 800.0])
           ->onFetchColumn('SUM(CASE', 0);
        $session = $this->makeSession($db);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('ยอดเงินจริงไม่ตรงยอดในลิ้นชัก');
        $this->invokePrivate($session, 'closePositionDay', [1, 750.0, null, 9]);
    }

    public function testClosePositionDayLargeVarianceRequiresApprovalBeforeClosing(): void
    {
        $db = new FakeCashSessionDb();
        $db->onFetch("AND status='open'", ['id' => 10, 'branch_id' => 1, 'status' => 'open', 'opening_actual' => 800.0])
           ->onFetchColumn('SUM(CASE', 0);
        $session = $this->makeSession($db);

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

    /** Movements that are simple cash_deposit (used by fundDrawer legacy + position model) */
    public function legacyMovements(): array
    {
        return array_map(static function (array $row): array {
            $p = $row['params'];
            // 14-col position INSERT: movement_type = $p[8], direction = $p[2], amount = $p[6]
            // 9-col legacy INSERT: movement_type = $p[3], direction = $p[2], amount = $p[4]
            $isPosition = str_contains($row['sql'], 'source_location');
            if ($isPosition) {
                return ['type' => $p[8], 'direction' => $p[2], 'amount' => (float)$p[6]];
            }
            return ['type' => $p[3], 'direction' => $p[2], 'amount' => (float)$p[4]];
        }, $this->insertedMovements);
    }
}
