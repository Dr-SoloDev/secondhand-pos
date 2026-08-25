<?php
/**
 * FinancialReportService — ADD-002 Phase 1 / Day 1
 *
 * ย้าย logic การสร้าง period params ออกจาก FinancialController (move-as-is)
 * - รับ input เป็น plain array (ไม่แตะ $_GET / Response::* / exit)
 * - Controller เป็นคนอ่าน HTTP ($_GET, user role, sanitize) แล้วส่งเข้ามา
 * - Constructor injection แบบ lightweight ตาม ADD-002 §3.3:
 *   production ไม่ส่ง db → singleton เดิม, test inject fake/stub ได้ทันที
 */
class FinancialReportService
{
    /**
     * @var mixed Database handle — nullable, resolve แบบ lazy ผ่าน db()
     *            (pure methods เช่น buildPeriodParams ไม่แตะ DB เลย)
     */
    private $db;

    /**
     * @param mixed $db Database instance หรือ null (→ lazy singleton)
     */
    public function __construct($db = null)
    {
        $this->db = $db;
    }

    /**
     * Lazy database access — production: singleton เดิม, test: inject stub
     * (defer การ connect จนถึง query แรก — pure path ไม่โดน)
     */
    private function db()
    {
        if ($this->db === null) {
            $this->db = Database::getInstance();
        }
        return $this->db;
    }

    /**
     * สร้าง period params สำหรับ report queries — move as-is จาก
     * FinancialController::getPeriodParams() lines 342–382 (logic เดิมทุก branch)
     *
     * @param array $input {
     *   period: string ('month'|'year'), year: int, month: int,
     *   date_from: ?string, date_to: ?string, branch_id: ?int (resolve จาก role แล้วโดย Controller)
     * }
     * @return array keys เดิมทุกตัวที่ callers/tests คาดหวัง
     */
    public function buildPeriodParams(array $input)
    {
        $period   = $input['period'] ?? 'month';
        $year     = intval($input['year'] ?? date('Y'));
        $month    = intval($input['month'] ?? date('n'));
        $dateFrom = $input['date_from'] ?? null;
        $dateTo   = $input['date_to'] ?? null;
        $branchId = $input['branch_id'] ?? null;

        // date filter สำหรับ purchase_orders (created_at)
        if ($dateFrom && $dateTo) {
            // ช่วงวันที่เจาะจง (รายงานตามวันที่)
            $dateFilterPO = "DATE(po.created_at) BETWEEN ? AND ?";
            $bindingsPO   = [$dateFrom, $dateTo];
            $dateFilterSL = "DATE(sl.sale_date) BETWEEN ? AND ?";
            $bindingsSL   = [$dateFrom, $dateTo];
        } elseif ($period === 'month') {
            $dateFilterPO = "YEAR(po.created_at) = ? AND MONTH(po.created_at) = ?";
            $bindingsPO   = [$year, $month];
            $dateFilterSL = "YEAR(sl.sale_date) = ? AND MONTH(sl.sale_date) = ?";
            $bindingsSL   = [$year, $month];
        } else {
            $dateFilterPO = "YEAR(po.created_at) = ?";
            $bindingsPO   = [$year];
            $dateFilterSL = "YEAR(sl.sale_date) = ?";
            $bindingsSL   = [$year];
        }

        // branch filter
        $branchFilter = '';
        if ($branchId) {
            $branchFilter  = "AND po.branch_id = ?";
            $bindingsPO[]  = $branchId;
            $bindingsSL[]  = $branchId;
        }

        return [
            'date_filter_po'  => $dateFilterPO,
            'date_filter_sl'  => $dateFilterSL,
            'branch_filter'   => $branchFilter,
            'branch_filter_sl'=> $branchId ? "AND sl.branch_id = ?" : '',
            'bindings_po'     => $bindingsPO,
            'bindings_sl'     => $bindingsSL,
            'branch_id_raw'   => $branchId,
            'period_raw'      => $period,
            'year_raw'        => $year,
            'month_raw'       => $month,
            'date_from_raw'   => $dateFrom,
            'date_to_raw'     => $dateTo,
        ];
    }
}
