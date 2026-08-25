<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for pure/logic parts of FinancialController.
 *
 * FinancialController::__construct() binds to Database singleton (real MySQL)
 * → instantiate WITHOUT constructor and exercise only methods whose behavior
 * does not depend on DB state or Response::exit paths.
 *
 * Covered:
 * - spreadsheetSafeRow: CSV/Excel formula-injection protection (=,+, -, @, BOM prefix)
 * - resolveBranchId: multi-branch role branch scoping from query string
 * - getPeriodParams: date-range vs month/year filter + binding construction
 */
class FinancialControllerLogicTest extends TestCase
{
    private \FinancialController $controller;
    private array $getBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getBackup = $_GET;
        /** @var \FinancialController $controller */
        $controller = (new ReflectionClass(\FinancialController::class))->newInstanceWithoutConstructor();
        $this->controller = $controller;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getBackup;
        parent::tearDown();
    }

    private function setUser(array $user): void
    {
        $prop = new ReflectionProperty(\Controller::class, 'user');
        $prop->setAccessible(true);
        $prop->setValue($this->controller, $user);
    }

    private function invoke(string $method, array $args = [])
    {
        $m = new ReflectionMethod($this->controller, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->controller, $args);
    }

    public function testSpreadsheetSafeRowNeutralizesFormulaPrefixCells(): void
    {
        // export CSV/Excel ต้อง escape cell ที่ขึ้นต้น = + - @ (รวม BOM/space นำหน้า)
        $row = ['=1+1|cmd', '+66812345678', '-5 บาท', '@SUM(A1:B2)', "\u{FEFF}=x", '  =evil', 'ปกติ 123', 999];
        $safe = $this->invoke('spreadsheetSafeRow', [$row]);

        $this->assertSame("'=1+1|cmd", $safe[0]);
        $this->assertSame("'+66812345678", $safe[1]);
        $this->assertSame("'-5 บาท", $safe[2]);
        $this->assertSame("'@SUM(A1:B2)", $safe[3]);
        $this->assertSame("'\u{FEFF}=x", $safe[4], 'BOM นำหน้า formula ก็ต้องถูก escape');
        $this->assertSame("'  =evil", $safe[5], 'space นำหน้า formula ก็ต้องถูก escape');
        $this->assertSame('ปกติ 123', $safe[6], 'ข้อความปกติต้องไม่ถูกแตะ');
        $this->assertSame(999, $safe[7], 'non-string ต้อง pass-through');
    }

    public function testResolveBranchIdUsesExplicitBranchForMultiBranchRoles(): void
    {
        // admin/super_manager เลือกสาขาได้จาก query string
        $_GET['branch_id'] = '3';
        $this->setUser(['role' => 'admin', 'user_id' => 1, 'branch_id' => 1]);

        $this->assertSame(3, $this->invoke('resolveBranchId'));
    }

    public function testResolveBranchIdTreatsInvalidParamAsUnfiltered(): void
    {
        // '', '0', '-5', ไม่ส่งมา → null (ดูได้ทุกสาขาสำหรับ multi-branch role)
        $this->setUser(['role' => 'super_manager', 'user_id' => 1]);

        foreach (['', '0', '-5'] as $bad) {
            $_GET['branch_id'] = $bad;
            $this->assertNull($this->invoke('resolveBranchId'), "branch_id='$bad' ต้องถูก ignore");
        }
        unset($_GET['branch_id']);
        $this->assertNull($this->invoke('resolveBranchId'));
    }

    public function testGetPeriodParamsUsesDateRangeWhenProvided(): void
    {
        // ระบุ date_from/date_to → filter BETWEEN ทั้ง PO และ SL + branch binding ท้าย array
        $_GET = ['date_from' => '2026-08-01', 'date_to' => '2026-08-15', 'branch_id' => '2'];
        $this->setUser(['role' => 'admin', 'user_id' => 1]);

        $params = $this->invoke('getPeriodParams');

        $this->assertSame('DATE(po.created_at) BETWEEN ? AND ?', $params['date_filter_po']);
        $this->assertSame('DATE(sl.sale_date) BETWEEN ? AND ?', $params['date_filter_sl']);
        $this->assertSame(['2026-08-01', '2026-08-15', 2], $params['bindings_po']);
        $this->assertSame(['2026-08-01', '2026-08-15', 2], $params['bindings_sl']);
        $this->assertSame('AND po.branch_id = ?', $params['branch_filter']);
        $this->assertSame('AND sl.branch_id = ?', $params['branch_filter_sl']);
        $this->assertSame('2026-08-01', $params['date_from_raw']);
        $this->assertSame(2, $params['branch_id_raw']);
    }

    public function testGetPeriodParamsFallsBackToMonthPeriodWithYearMonthBindings(): void
    {
        // default period=month → YEAR()+MONTH() filter, binding [year, month] ตามด้วย branch
        $_GET = ['period' => 'month', 'year' => '2026', 'month' => '6'];
        $this->setUser(['role' => 'admin', 'user_id' => 1]);

        $params = $this->invoke('getPeriodParams');

        $this->assertSame('YEAR(po.created_at) = ? AND MONTH(po.created_at) = ?', $params['date_filter_po']);
        $this->assertSame('YEAR(sl.sale_date) = ? AND MONTH(sl.sale_date) = ?', $params['date_filter_sl']);
        $this->assertSame([2026, 6], $params['bindings_po']);
        $this->assertSame([2026, 6], $params['bindings_sl']);
        $this->assertSame('', $params['branch_filter'], 'admin ไม่ระบุ branch → ไม่มี branch filter');
        $this->assertNull($params['branch_id_raw']);
        $this->assertSame('month', $params['period_raw']);
        $this->assertSame(2026, $params['year_raw']);
        $this->assertSame(6, $params['month_raw']);
    }
}
