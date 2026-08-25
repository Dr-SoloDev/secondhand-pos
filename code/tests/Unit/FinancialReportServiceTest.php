<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Unit tests for FinancialReportService::buildPeriodParams (ADD-002 Phase 1).
 *
 * buildPeriodParams เป็น pure logic — ไม่แตะ DB
 * (inject stdClass เป็น db stub เพื่อไม่ให้ constructor ไปเรียก singleton)
 * Cover: date-range mode, month mode (default), year mode, branch filter on/off,
 * raw passthrough — keys/ค่าต้องตรงกับที่ FinancialController callers ใช้เดิม
 */
class FinancialReportServiceTest extends TestCase
{
    private \FinancialReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // stdClass = fake db — pure method ไม่แตะจริง, กัน constructor ไปหา MySQL
        /** @var \FinancialReportService $service */
        $service = new \FinancialReportService(new stdClass());
        $this->service = $service;
    }

    public function testDateRangeModeFiltersBothPoAndSl(): void
    {
        // ระบุ date_from + date_to → BETWEEN filter ทั้ง PO และ SL + branch ท้าย binding
        $params = $this->service->buildPeriodParams([
            'period' => 'month', 'year' => 2026, 'month' => 6,
            'date_from' => '2026-08-01', 'date_to' => '2026-08-15',
            'branch_id' => 2,
        ]);

        $this->assertSame('DATE(po.created_at) BETWEEN ? AND ?', $params['date_filter_po']);
        $this->assertSame('DATE(sl.sale_date) BETWEEN ? AND ?', $params['date_filter_sl']);
        $this->assertSame(['2026-08-01', '2026-08-15', 2], $params['bindings_po']);
        $this->assertSame(['2026-08-01', '2026-08-15', 2], $params['bindings_sl']);
        $this->assertSame('AND po.branch_id = ?', $params['branch_filter']);
        $this->assertSame('AND sl.branch_id = ?', $params['branch_filter_sl']);
        $this->assertSame(2, $params['branch_id_raw']);
    }

    public function testMonthModeIsDefaultWhenNoDates(): void
    {
        // ไม่มี dates + period=month → YEAR()+MONTH() filter, binding [year, month]
        $params = $this->service->buildPeriodParams([
            'period' => 'month', 'year' => 2026, 'month' => 6,
            'date_from' => null, 'date_to' => null, 'branch_id' => null,
        ]);

        $this->assertSame('YEAR(po.created_at) = ? AND MONTH(po.created_at) = ?', $params['date_filter_po']);
        $this->assertSame('YEAR(sl.sale_date) = ? AND MONTH(sl.sale_date) = ?', $params['date_filter_sl']);
        $this->assertSame([2026, 6], $params['bindings_po']);
        $this->assertSame([2026, 6], $params['bindings_sl']);
        $this->assertSame('', $params['branch_filter'], 'ไม่ระบุ branch → ไม่มี branch filter');
        $this->assertNull($params['branch_id_raw']);
    }

    public function testYearModeWhenPeriodNotMonth(): void
    {
        // period != month (เช่น 'year') → YEAR() filter อย่างเดียว binding [year]
        $params = $this->service->buildPeriodParams([
            'period' => 'year', 'year' => 2025, 'month' => 12,
            'date_from' => null, 'date_to' => null, 'branch_id' => null,
        ]);

        $this->assertSame('YEAR(po.created_at) = ?', $params['date_filter_po']);
        $this->assertSame('YEAR(sl.sale_date) = ?', $params['date_filter_sl']);
        $this->assertSame([2025], $params['bindings_po']);
        $this->assertSame([2025], $params['bindings_sl']);
        $this->assertSame('year', $params['period_raw']);
        $this->assertSame(12, $params['month_raw'], 'month_raw pass-through แม้ไม่ถูกใช้ใน filter');
    }

    public function testBranchFilterAppendsBindingToBothQueries(): void
    {
        // month mode + branch → binding order: [year, month, branch] ทั้งสอง query
        $params = $this->service->buildPeriodParams([
            'period' => 'month', 'year' => 2026, 'month' => 3,
            'date_from' => null, 'date_to' => null, 'branch_id' => 7,
        ]);

        $this->assertSame([2026, 3, 7], $params['bindings_po']);
        $this->assertSame([2026, 3, 7], $params['bindings_sl']);
        $this->assertSame('AND po.branch_id = ?', $params['branch_filter']);
        $this->assertSame('AND sl.branch_id = ?', $params['branch_filter_sl']);
    }

    public function testRawValuesPassThrough(): void
    {
        // *_raw keys ต้องส่งค่า input กลับตรงเป๊ะ (callers ใช้แสดงผล/audit)
        $params = $this->service->buildPeriodParams([
            'period' => 'custom-period', 'year' => 2024, 'month' => 1,
            'date_from' => 'D1', 'date_to' => 'D2', 'branch_id' => 9,
        ]);

        $this->assertSame('custom-period', $params['period_raw']);
        $this->assertSame(2024, $params['year_raw']);
        $this->assertSame(1, $params['month_raw']);
        $this->assertSame('D1', $params['date_from_raw']);
        $this->assertSame('D2', $params['date_to_raw']);
        $this->assertSame(9, $params['branch_id_raw']);
    }

    public function testMissingInputFallsBackToDefaults(): void
    {
        // input ว่างเปล่า → default เหมือน controller เดิม: period=month, year/month จากวันนี้
        $params = $this->service->buildPeriodParams([]);

        $this->assertSame('month', $params['period_raw']);
        $this->assertSame(intval(date('Y')), $params['year_raw']);
        $this->assertSame(intval(date('n')), $params['month_raw']);
        $this->assertNull($params['date_from_raw']);
        $this->assertNull($params['date_to_raw']);
    }
}
