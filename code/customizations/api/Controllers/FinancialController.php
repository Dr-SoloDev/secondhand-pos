<?php
class FinancialController extends Controller
{
    private const REPORT_ROLES = ['admin', 'manager', 'super_manager'];
    private const MULTI_BRANCH_ROLES = ['admin', 'super_manager'];

    private function requireReportAuth()
    {
        $this->requireAuth(self::REPORT_ROLES);
    }

    private function hasMultiBranchAccess()
    {
        return in_array(($this->user['role'] ?? ''), self::MULTI_BRANCH_ROLES, true);
    }

    private function resolveBranchId()
    {
        $branchId = isset($_GET['branch_id']) && $_GET['branch_id'] !== ''
            ? intval($_GET['branch_id'])
            : null;
        if ($branchId !== null && $branchId <= 0) {
            $branchId = null;
        }

        if (!$this->hasMultiBranchAccess()) {
            $branchId = intval($this->user['branch_id'] ?? 0) ?: null;
            if (!$branchId) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
        }

        return $branchId;
    }

    // ดึง summary cards: total_purchase, total_revenue, total_expenses, total_kg, total_lots
    public function summary()
    {
        $this->requireReportAuth();
        $params = $this->getPeriodParams();

        $db = Database::getInstance();

        // รายจ่ายรับซื้อ (purchase_orders ที่ completed)
        $purchase = $db->fetch(
            "SELECT COALESCE(SUM(po.total_amount), 0) AS total_purchase
             FROM purchase_orders po
             WHERE po.status = 'completed'
               AND po.source_type = 'manual'
               AND {$params['date_filter_po']}
               {$params['branch_filter']}",
            $params['bindings_po']
        );

        // น้ำหนักรับซื้อรวม
        $kgRow = $db->fetch(
            "SELECT COALESCE(SUM(poi.net_quantity), 0) AS total_kg
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             WHERE po.status = 'completed'
               AND po.source_type = 'manual'
               AND {$params['date_filter_po']}
               {$params['branch_filter']}",
            $params['bindings_po']
        );

        // รายรับขาย Lot (actual_revenue ที่กรอกแล้ว)
        $revenue = $db->fetch(
            "SELECT
               COALESCE(SUM(sl.actual_revenue), 0) AS total_revenue,
               COUNT(sl.id) AS total_lots
             FROM sale_lots sl
             WHERE sl.status = 'confirmed'
               AND sl.actual_revenue IS NOT NULL
               AND {$params['date_filter_sl']}
               {$params['branch_filter_sl']}",
            $params['bindings_sl']
        );

        // ค่าใช้จ่ายจาก expenses JSON ใน sale_lots (transport_cost + lot-level expenses)
        $lotExpRow = $db->fetch(
            "SELECT
               COALESCE(SUM(sl.transport_cost), 0) AS total_transport,
               COALESCE(SUM(
                  (SELECT COALESCE(SUM(CAST(jt.amount AS DECIMAL(12,2))), 0)
                   FROM JSON_TABLE(sl.expenses, '\$[*]' COLUMNS (amount VARCHAR(20) PATH '\$.amount')) jt)
               ), 0) AS total_lot_expenses
             FROM sale_lots sl
             WHERE sl.status = 'confirmed'
               AND {$params['date_filter_sl']}
               {$params['branch_filter_sl']}",
            $params['bindings_sl']
        );

        // ค่าใช้จ่ายประจำร้าน (business_expenses)
        $bizExpenses = (new BusinessExpense())->sumByPeriod(
            $params['branch_id_raw'],
            $params['period_raw'],
            $params['year_raw'],
            $params['month_raw']
        );

        $totalTransport  = floatval($lotExpRow['total_transport'] ?? 0);
        $totalLotExpenses = floatval($lotExpRow['total_lot_expenses'] ?? 0);
        $totalLotExpenses += $totalTransport;

        Response::success('สำเร็จ', [
            'total_purchase'    => $purchase['total_purchase'] ?? 0,
            'total_kg'          => $kgRow['total_kg'] ?? 0,
            'total_revenue'     => $revenue['total_revenue'] ?? 0,
            'total_lots'        => $revenue['total_lots'] ?? 0,
            'total_expenses'    => $totalLotExpenses,
            'total_lot_expenses'=> $totalLotExpenses,
            'total_biz_expenses'=> floatval($bizExpenses),
            'filters'           => [
                'period'   => $params['period_raw'],
                'year'     => $params['year_raw'],
                'month'    => $params['month_raw'],
                'date_from'=> $params['date_from_raw'],
                'date_to'  => $params['date_to_raw'],
                'branch_id'=> $params['branch_id_raw'],
            ],
        ]);
    }

    // ดึงรายการ Lot ที่มี actual_revenue (สำหรับตารางรายละเอียด)
    public function lotRevenues()
    {
        $this->requireReportAuth();
        $params = $this->getPeriodParams();
        $db = Database::getInstance();

        $items = $db->fetchAll(
            "SELECT sl.id, sl.reference_no, sl.branch_id, sl.sale_date,
                    sl.total_cost, sl.actual_revenue, sl.actual_revenue_note,
                    sl.actual_revenue_date, sl.expenses
             FROM sale_lots sl
             WHERE sl.status = 'confirmed'
               AND {$params['date_filter_sl']}
               {$params['branch_filter_sl']}
             ORDER BY sl.actual_revenue_date DESC, sl.sale_date DESC",
            $params['bindings_sl']
        );

        Response::success('สำเร็จ', ['items' => $items ?: []]);
    }

    // ดึงรายจ่ายรับซื้อแยกตามหมวดหมู่
    public function purchaseByCategory()
    {
        $this->requireReportAuth();
        $params = $this->getPeriodParams();
        $db = Database::getInstance();

        $items = $db->fetchAll(
            "SELECT
               COALESCE(c.name, poi.item_name, 'ไม่ระบุหมวด') AS category_name,
               COALESCE(SUM(poi.net_quantity), 0) AS total_kg,
               COUNT(poi.id) AS total_items,
               COALESCE(SUM(poi.total_price), 0) AS total_amount
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             LEFT JOIN categories c ON c.id = poi.category_id
             WHERE po.status = 'completed'
               AND po.source_type = 'manual'
               AND {$params['date_filter_po']}
               {$params['branch_filter']}
             GROUP BY poi.category_id, c.name, poi.item_name
             ORDER BY total_amount DESC",
            $params['bindings_po']
        );

        Response::success('สำเร็จ', ['items' => $items ?: []]);
    }

    // ดึงรายการ business expenses
    public function listExpenses()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $params   = $this->getPeriodParams();
        $model    = new BusinessExpense();
        $items    = $model->listByPeriod(
            $params['branch_id_raw'],
            $_GET['period'] ?? 'month',
            intval($_GET['year'] ?? date('Y')),
            intval($_GET['month'] ?? date('n'))
        );
        Response::success('สำเร็จ', ['items' => $items]);
    }

    // เพิ่ม business expense
    public function createExpense()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $branchId = $this->resolveWriteBranchId($body['branch_id'] ?? null);
        $amount   = floatval($body['amount'] ?? 0);
        if (!$branchId || $amount <= 0 || !is_finite($amount) || empty($body['expense_date'])
            || empty($body['category']) || empty(trim((string)($body['beneficiary_name'] ?? '')))) {
            Response::error('ข้อมูลไม่ครบ', 400);
            return;
        }
        try {
            $userId = (int)($this->user['user_id'] ?? $this->user['id']);
            $id = (new BusinessExpense())->createRequest([
                'branch_id' => $branchId,
                'expense_date' => $body['expense_date'],
                'category' => substr(trim($body['category']), 0, 50),
                'amount' => $amount,
                'payment_method' => $body['payment_method'] ?? 'cash',
                'beneficiary_name' => substr(trim((string)$body['beneficiary_name']), 0, 200),
                'note' => isset($body['note']) ? substr(trim($body['note']), 0, 255) : null,
            ], $userId);
            Logger::logActivity($userId, 'request_business_expense', "ขอเบิกค่าใช้จ่าย branch:{$branchId} {$body['category']} {$amount}บาท");
            Response::success('ส่งคำขอรายจ่ายเพื่อรออนุมัติแล้ว', ['id' => $id, 'status' => 'pending']);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    // ลบ business expense
    public function deleteExpense()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }
        try {
            $userId = (int)($this->user['user_id'] ?? $this->user['id']);
            (new BusinessExpense())->cancelRequest($id, $userId);
            Logger::logActivity($userId, 'cancel_business_expense_request', "ยกเลิกคำขอค่าใช้จ่าย ID:{$id}");
            Response::success('ยกเลิกคำขอแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function approveExpense()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        $body = $this->getRequestData() ?? [];
        $id = (int)($body['id'] ?? 0);
        if (!$id) Response::error('ไม่พบ id', 400);
        try {
            $model = new BusinessExpense();
            $expense = $model->findById($id);
            if (!$expense) Response::error('ไม่พบคำขอรายจ่าย', 404);
            $this->assertExpenseBranchAccess((int)$expense['branch_id']);
            $userId = (int)($this->user['user_id'] ?? $this->user['id']);
            $allowSelfApproval = ($this->user['role'] ?? '') === 'admin';
            $model->approve(
                $id, $userId, (string)($this->user['role'] ?? ''), $body['review_note'] ?? null, $allowSelfApproval
            );
            $selfApprovalNote = $allowSelfApproval && (int)($expense['requested_by'] ?? 0) === $userId
                ? ' self_approved:1'
                : '';
            Logger::logActivity($userId, 'approve_business_expense', "อนุมัติค่าใช้จ่าย ID:{$id}" . $selfApprovalNote);
            Response::success('อนุมัติและบันทึกการจ่ายแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function rejectExpense()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        $body = $this->getRequestData() ?? [];
        $id = (int)($body['id'] ?? 0);
        $note = trim((string)($body['review_note'] ?? ''));
        if (!$id || $note === '') Response::error('กรุณาระบุรายการและเหตุผล', 400);
        try {
            $model = new BusinessExpense();
            $expense = $model->findById($id);
            if (!$expense) Response::error('ไม่พบคำขอรายจ่าย', 404);
            $this->assertExpenseBranchAccess((int)$expense['branch_id']);
            $userId = (int)($this->user['user_id'] ?? $this->user['id']);
            $model->reject($id, $userId, $note);
            Logger::logActivity($userId, 'reject_business_expense', "ปฏิเสธค่าใช้จ่าย ID:{$id}");
            Response::success('ปฏิเสธคำขอแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    private function resolveWriteBranchId($requested): int
    {
        if ($this->hasMultiBranchAccess()) {
            $branchId = (int)$requested;
            if (!$branchId) Response::error('กรุณาระบุสาขา', 400);
            return $branchId;
        }
        $branchId = (int)($this->user['branch_id'] ?? 0);
        if (!$branchId) Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
        if ($requested !== null && (int)$requested !== $branchId) {
            Response::error('ไม่มีสิทธิ์บันทึกรายจ่ายของสาขาอื่น', 403);
        }
        return $branchId;
    }

    private function assertExpenseBranchAccess(int $branchId): void
    {
        if ($this->hasMultiBranchAccess()) {
            return;
        }
        $userBranch = (int)($this->user['branch_id'] ?? 0);
        if (!$userBranch || $userBranch !== $branchId) {
            Response::error('ไม่มีสิทธิ์อนุมัติรายจ่ายของสาขาอื่น', 403);
        }
    }

    // สร้าง date filter params จาก GET
    private function getPeriodParams()
    {
        $period   = $_GET['period']    ?? 'month';
        $year     = intval($_GET['year']     ?? date('Y'));
        $month    = intval($_GET['month']    ?? date('n'));
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null;
        $dateTo   = isset($_GET['date_to'])   ? $this->sanitizeInput($_GET['date_to'])   : null;
        $branchId = $this->resolveBranchId();

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

    // GET /api/financial/export?period=month&year=2026&month=6&branch_id=&type=purchases|salelots|expenses|summary
    public function exportCsv()
    {
        $this->requireReportAuth();
        $params = $this->getPeriodParams();
        $type   = $_GET['type'] ?? 'summary';
        $db     = Database::getInstance();

        $rows = [];
        $headers = [];

        if ($type === 'purchases') {
            $headers = ['เลขที่บิล','วันที่','สาขา','ผู้ขาย','เลขบัตร','ยอด (บาท)','สถานะ'];
            $sql = "SELECT po.reference_no, po.created_at, b.name AS branch_name,
                           s.full_name AS seller_name, s.id_card, s.id_card_encrypted,
                           po.total_amount, po.status
                    FROM purchase_orders po
                    LEFT JOIN branches b ON b.id = po.branch_id
                    LEFT JOIN sellers s ON s.id = po.seller_id
                    WHERE po.status = 'completed'
                      AND po.source_type = 'manual'
                      AND {$params['date_filter_po']}
                      {$params['branch_filter']}
                    ORDER BY po.created_at DESC";
            $raw = $db->fetchAll($sql, $params['bindings_po']);
            foreach ($raw as $r) {
                $sellerIdCard = !empty($r['id_card_encrypted'])
                    ? SellerIdCipher::decrypt($r['id_card_encrypted'])
                    : $r['id_card'];
                $rows[] = [$r['reference_no'], $r['created_at'], $r['branch_name'],
                           $r['seller_name'], $sellerIdCard, $r['total_amount'], 'สำเร็จ'];
            }

        } elseif ($type === 'salelots') {
            $headers = ['เลขที่ Lot','วันที่','สาขา','ต้นทุน (บาท)','รายรับจริง (บาท)','กำไร (บาท)','สถานะ'];
            $sql = "SELECT sl.reference_no, sl.sale_date, b.name AS branch_name,
                           sl.total_cost, sl.actual_revenue, sl.status
                    FROM sale_lots sl
                    LEFT JOIN branches b ON b.id = sl.branch_id
                    WHERE sl.status = 'confirmed'
                      AND {$params['date_filter_sl']}
                      {$params['branch_filter_sl']}
                    ORDER BY sl.sale_date DESC";
            $raw = $db->fetchAll($sql, $params['bindings_sl']);
            foreach ($raw as $r) {
                $profit = floatval($r['actual_revenue'] ?? 0) - floatval($r['total_cost'] ?? 0);
                $rows[] = [$r['reference_no'], $r['sale_date'], $r['branch_name'],
                           $r['total_cost'], $r['actual_revenue'] ?? '', number_format($profit, 2), 'ยืนยันแล้ว'];
            }

        } elseif ($type === 'expenses') {
            $headers = ['วันที่','สาขา','ประเภท','จำนวน (บาท)','หมายเหตุ'];
            $model = new BusinessExpense();
            $raw = $model->listByPeriod(
                $params['branch_id_raw'], $params['period_raw'], $params['year_raw'], $params['month_raw']
            );
            foreach ($raw as $r) {
                $rows[] = [$r['expense_date'], $r['branch_name'], $r['category'], $r['amount'], $r['note'] ?? ''];
            }

        } else { // summary
            $headers = ['รายการ','จำนวน (บาท)'];
            $pYear = $params['year_raw']; $pMonth = $params['month_raw'];
            $pPeriod = $params['period_raw']; $pBranch = $params['branch_id_raw'];
            $poWhere = $pPeriod === 'month'
                ? "YEAR(created_at)=? AND MONTH(created_at)=?" : "YEAR(created_at)=?";
            $poBind = $pPeriod === 'month' ? [$pYear, $pMonth] : [$pYear];
            if ($pBranch) { $poWhere .= " AND branch_id=?"; $poBind[] = $pBranch; }
            $slWhere = $pPeriod === 'month'
                ? "YEAR(sale_date)=? AND MONTH(sale_date)=?" : "YEAR(sale_date)=?";
            $slBind = $pPeriod === 'month' ? [$pYear, $pMonth] : [$pYear];
            if ($pBranch) { $slWhere .= " AND branch_id=?"; $slBind[] = $pBranch; }
            $po = $db->fetch("SELECT COALESCE(SUM(total_amount),0) AS v FROM purchase_orders WHERE status='completed' AND source_type='manual' AND $poWhere", $poBind);
            $sl = $db->fetch("SELECT COALESCE(SUM(actual_revenue),0) AS v FROM sale_lots WHERE status='confirmed' AND actual_revenue IS NOT NULL AND $slWhere", $slBind);
            $biz = (new BusinessExpense())->sumByPeriod($pBranch, $pPeriod, $pYear, $pMonth);

            // Transport cost + lot-level expenses (matching summary() endpoint)
            $lotExp = $db->fetch(
                "SELECT
                   COALESCE(SUM(sl.transport_cost), 0) AS transport,
                   COALESCE(SUM(
                      (SELECT COALESCE(SUM(CAST(jt.amount AS DECIMAL(12,2))), 0)
                       FROM JSON_TABLE(sl.expenses, '\$[*]' COLUMNS (amount VARCHAR(20) PATH '\$.amount')) jt)
                   ), 0) AS lot_expenses
                 FROM sale_lots sl
                 WHERE sl.status = 'confirmed' AND $slWhere",
                $slBind
            );
            $totalLotExp = floatval($lotExp['transport'] ?? 0) + floatval($lotExp['lot_expenses'] ?? 0);

            $purchase = floatval($po['v'] ?? 0);
            $revenue  = floatval($sl['v'] ?? 0);
            $bizVal   = floatval($biz);
            $profit   = $revenue - $purchase - $totalLotExp - $bizVal;
            $rows = [
                ['รายจ่ายรับซื้อของ', number_format($purchase, 2)],
                ['รายรับขาย Lot',      number_format($revenue, 2)],
                ['ค่าใช้จ่าย Lot (รถ/อื่น)', number_format($totalLotExp, 2)],
                ['ค่าใช้จ่ายประจำร้าน', number_format($bizVal, 2)],
                ['กำไร/ขาดทุนสุทธิ',  number_format($profit, 2)],
            ];
        }

        $filename = "export_{$type}_{$params['year_raw']}";
        if ($params['period_raw'] === 'month') $filename .= "_{$params['month_raw']}";
        if ($params['branch_id_raw']) $filename .= "_branch{$params['branch_id_raw']}";
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) fputcsv($out, $this->spreadsheetSafeRow($row));
        fclose($out);
        exit;
    }

    /**
     * GET /api/financial/monthly-trend?year=2026
     * รายได้-รายจ่าย 12 เดือนย้อนหลัง
     */
    public function monthlyTrend()
    {
        $this->requireReportAuth();
        $db = Database::getInstance();

        $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
        $branchId = $this->resolveBranchId();

        // รายได้ขาย Lot รายเดือน
        $revenueSql = "SELECT
            MONTH(sl.sale_date) AS month,
            COALESCE(SUM(sl.actual_revenue), 0) AS total_revenue
        FROM sale_lots sl
        WHERE sl.status = 'confirmed'
          AND YEAR(sl.sale_date) = ?
          AND sl.actual_revenue IS NOT NULL
          " . ($branchId ? "AND sl.branch_id = ?" : "") . "
        GROUP BY MONTH(sl.sale_date)
        ORDER BY month ASC";
        $revenueRows = $db->fetchAll($revenueSql, array_merge([$year], $branchId ? [$branchId] : []));

        // รายจ่ายรับซื้อรายเดือน
        $purchaseSql = "SELECT
            MONTH(po.created_at) AS month,
            COALESCE(SUM(po.total_amount), 0) AS total_purchase
        FROM purchase_orders po
        WHERE po.status = 'completed'
          AND po.source_type = 'manual'
          AND YEAR(po.created_at) = ?
          " . ($branchId ? "AND po.branch_id = ?" : "") . "
        GROUP BY MONTH(po.created_at)
        ORDER BY month ASC";
        $purchaseRows = $db->fetchAll($purchaseSql, array_merge([$year], $branchId ? [$branchId] : []));

        // ค่าใช้จ่ายรายเดือน
        $bizExpSql = "SELECT
            MONTH(expense_date) AS month,
            COALESCE(SUM(amount), 0) AS total_expense
        FROM business_expenses
        WHERE YEAR(expense_date) = ?
          AND status = 'approved'
          " . ($branchId ? "AND branch_id = ?" : "") . "
        GROUP BY MONTH(expense_date)
        ORDER BY month ASC";
        $bizExpRows = $db->fetchAll($bizExpSql, array_merge([$year], $branchId ? [$branchId] : []));

        $months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        $revenueMap = [];
        foreach ($revenueRows as $r) $revenueMap[intval($r['month'])] = floatval($r['total_revenue']);
        $purchaseMap = [];
        foreach ($purchaseRows as $r) $purchaseMap[intval($r['month'])] = floatval($r['total_purchase']);
        $bizExpMap = [];
        foreach ($bizExpRows as $r) $bizExpMap[intval($r['month'])] = floatval($r['total_expense']);

        $result = [];
        for ($m = 1; $m <= 12; $m++) {
            $revenue = $revenueMap[$m] ?? 0;
            $purchase = $purchaseMap[$m] ?? 0;
            $bizExp = $bizExpMap[$m] ?? 0;
            $lotExp = 0; // transport cost approximated from summary
            $profit = $revenue - $purchase - $lotExp - $bizExp;
            $result[] = [
                'month'    => $m,
                'month_th' => $months[$m - 1],
                'revenue'  => $revenue,
                'purchase' => $purchase,
                'biz_exp'  => $bizExp,
                'profit'   => $profit,
            ];
        }

        Response::success('สำเร็จ', ['trend' => $result]);
    }

    /**
     * GET /api/financial/branch-comparison?year=2026&period=month&month=6
     * เปรียบเทียบรายรับรายจ่ายแต่ละสาขา
     */
    public function branchComparison()
    {
        $this->requireReportAuth();
        $db = Database::getInstance();

        $params = $this->getPeriodParams();
        $branchWhere = '';
        $branchBindings = [];
        if ($params['branch_id_raw']) {
            $branchWhere = 'WHERE b.id = ?';
            $branchBindings[] = $params['branch_id_raw'];
        }

        // รายรับรายจ่ายแต่ละสาขา
        $rows = $db->fetchAll(
            "SELECT
                b.id AS branch_id,
                b.name AS branch_name,
                COALESCE(revenue.total_revenue, 0) AS revenue,
                COALESCE(purchase.total_purchase, 0) AS purchase
            FROM branches b
            LEFT JOIN (
                SELECT sl.branch_id, COALESCE(SUM(sl.actual_revenue), 0) AS total_revenue
                FROM sale_lots sl
                WHERE sl.status = 'confirmed'
                  AND sl.actual_revenue IS NOT NULL
                  AND {$params['date_filter_sl']}
                  {$params['branch_filter_sl']}
                GROUP BY sl.branch_id
            ) revenue ON revenue.branch_id = b.id
            LEFT JOIN (
                SELECT po.branch_id, COALESCE(SUM(po.total_amount), 0) AS total_purchase
                FROM purchase_orders po
                WHERE po.status = 'completed'
                  AND po.source_type = 'manual'
                  AND {$params['date_filter_po']}
                  {$params['branch_filter']}
                GROUP BY po.branch_id
            ) purchase ON purchase.branch_id = b.id
            {$branchWhere}
            ORDER BY b.id ASC",
            array_merge($params['bindings_sl'], $params['bindings_po'], $branchBindings)
        );

        $result = [];
        foreach ($rows as $r) {
            $revenue = floatval($r['revenue'] ?? 0);
            $purchase = floatval($r['purchase'] ?? 0);
            $result[] = [
                'branch_id' => $r['branch_id'],
                'branch_name' => $r['branch_name'],
                'revenue' => $revenue,
                'purchase' => $purchase,
                'profit' => $revenue - $purchase,
            ];
        }

        Response::success('สำเร็จ', ['comparison' => $result]);
    }

    /**
     * GET /api/financial/top-sellers?year=2026&period=month&month=6&branch_id=&limit=5
     */
    public function topSellers()
    {
        $this->requireReportAuth();
        $db = Database::getInstance();

        $limit = min(intval($_GET['limit'] ?? 5), 20);
        $params = $this->getPeriodParams();

        $rows = $db->fetchAll(
            "SELECT
                s.full_name AS seller_name,
                COUNT(po.id) AS bill_count,
                COALESCE(SUM(po.total_amount), 0) AS total_amount
            FROM sellers s
            JOIN purchase_orders po ON po.seller_id = s.id
            WHERE po.status = 'completed'
              AND po.source_type = 'manual'
              AND {$params['date_filter_po']}
              {$params['branch_filter']}
            GROUP BY s.id, s.full_name
            ORDER BY total_amount DESC
            LIMIT ?",
            array_merge($params['bindings_po'], [$limit])
        );

        Response::success('สำเร็จ', ['items' => $rows ?: []]);
    }

    /**
     * GET /api/financial/top-buyers?year=2026&period=month&month=6&branch_id=&limit=5
     */
    public function topBuyers()
    {
        $this->requireReportAuth();
        $db = Database::getInstance();

        $limit = min(intval($_GET['limit'] ?? 5), 20);
        $params = $this->getPeriodParams();

        $rows = $db->fetchAll(
            "SELECT
                sl.buyer_name,
                COUNT(sl.id) AS lot_count,
                COALESCE(SUM(sl.actual_revenue), 0) AS total_revenue
            FROM sale_lots sl
            WHERE sl.status = 'confirmed'
              AND sl.actual_revenue IS NOT NULL
              AND {$params['date_filter_sl']}
              {$params['branch_filter_sl']}
            GROUP BY sl.buyer_name
            ORDER BY total_revenue DESC
            LIMIT ?",
            array_merge($params['bindings_sl'], [$limit])
        );

        Response::success('สำเร็จ', ['items' => $rows ?: []]);
    }

    /**
     * GET /api/financial/export-excel?period=month&year=2026&month=6&branch_id=&type=purchases|salelots|expenses|summary
     * ส่งออกเป็น Excel (HTML table)
     */
    public function exportExcel()
    {
        $this->requireReportAuth();
        $params = $this->getPeriodParams();
        $type   = $_GET['type'] ?? 'summary';
        $db     = Database::getInstance();

        $rows = [];
        $headers = [];

        if ($type === 'purchases') {
            $headers = ['เลขที่บิล','วันที่','สาขา','ผู้ขาย','เลขบัตร','ยอด (บาท)','สถานะ'];
            $sql = "SELECT po.reference_no, po.created_at, b.name AS branch_name,
                           s.full_name AS seller_name, s.id_card, s.id_card_encrypted,
                           po.total_amount, po.status
                    FROM purchase_orders po
                    LEFT JOIN branches b ON b.id = po.branch_id
                    LEFT JOIN sellers s ON s.id = po.seller_id
                    WHERE po.status = 'completed'
                      AND po.source_type = 'manual'
                      AND {$params['date_filter_po']}
                      {$params['branch_filter']}
                    ORDER BY po.created_at DESC";
            $raw = $db->fetchAll($sql, $params['bindings_po']);
            foreach ($raw as $r) {
                $sellerIdCard = !empty($r['id_card_encrypted'])
                    ? SellerIdCipher::decrypt($r['id_card_encrypted'])
                    : $r['id_card'];
                $rows[] = [$r['reference_no'], $r['created_at'], $r['branch_name'],
                           $r['seller_name'], $sellerIdCard, $r['total_amount'], 'สำเร็จ'];
            }

        } elseif ($type === 'salelots') {
            $headers = ['เลขที่ Lot','วันที่','สาขา','ต้นทุน (บาท)','รายรับจริง (บาท)','กำไร (บาท)','สถานะ'];
            $sql = "SELECT sl.reference_no, sl.sale_date, b.name AS branch_name,
                           sl.total_cost, sl.actual_revenue, sl.status
                    FROM sale_lots sl
                    LEFT JOIN branches b ON b.id = sl.branch_id
                    WHERE sl.status = 'confirmed'
                      AND {$params['date_filter_sl']}
                      {$params['branch_filter_sl']}
                    ORDER BY sl.sale_date DESC";
            $raw = $db->fetchAll($sql, $params['bindings_sl']);
            foreach ($raw as $r) {
                $profit = floatval($r['actual_revenue'] ?? 0) - floatval($r['total_cost'] ?? 0);
                $rows[] = [$r['reference_no'], $r['sale_date'], $r['branch_name'],
                           $r['total_cost'], $r['actual_revenue'] ?? '', number_format($profit, 2), 'ยืนยันแล้ว'];
            }

        } elseif ($type === 'expenses') {
            $headers = ['วันที่','สาขา','ประเภท','จำนวน (บาท)','หมายเหตุ'];
            $model = new BusinessExpense();
            $raw = $model->listByPeriod(
                $params['branch_id_raw'], $params['period_raw'], $params['year_raw'], $params['month_raw']
            );
            foreach ($raw as $r) {
                $rows[] = [$r['expense_date'], $r['branch_name'], $r['category'], $r['amount'], $r['note'] ?? ''];
            }

        } else { // summary
            $headers = ['รายการ','จำนวน (บาท)'];
            $pYear = $params['year_raw']; $pMonth = $params['month_raw'];
            $pPeriod = $params['period_raw']; $pBranch = $params['branch_id_raw'];
            $poWhere = $pPeriod === 'month'
                ? "YEAR(created_at)=? AND MONTH(created_at)=?" : "YEAR(created_at)=?";
            $poBind = $pPeriod === 'month' ? [$pYear, $pMonth] : [$pYear];
            if ($pBranch) { $poWhere .= " AND branch_id=?"; $poBind[] = $pBranch; }
            $slWhere = $pPeriod === 'month'
                ? "YEAR(sale_date)=? AND MONTH(sale_date)=?" : "YEAR(sale_date)=?";
            $slBind = $pPeriod === 'month' ? [$pYear, $pMonth] : [$pYear];
            if ($pBranch) { $slWhere .= " AND branch_id=?"; $slBind[] = $pBranch; }
            $po = $db->fetch("SELECT COALESCE(SUM(total_amount),0) AS v FROM purchase_orders WHERE status='completed' AND source_type='manual' AND $poWhere", $poBind);
            $sl = $db->fetch("SELECT COALESCE(SUM(actual_revenue),0) AS v FROM sale_lots WHERE status='confirmed' AND actual_revenue IS NOT NULL AND $slWhere", $slBind);
            $biz = (new BusinessExpense())->sumByPeriod($pBranch, $pPeriod, $pYear, $pMonth);

            // Transport cost + lot-level expenses (matching summary() endpoint)
            $lotExp = $db->fetch(
                "SELECT
                   COALESCE(SUM(sl.transport_cost), 0) AS transport,
                   COALESCE(SUM(
                      (SELECT COALESCE(SUM(CAST(jt.amount AS DECIMAL(12,2))), 0)
                       FROM JSON_TABLE(sl.expenses, '\$[*]' COLUMNS (amount VARCHAR(20) PATH '\$.amount')) jt)
                   ), 0) AS lot_expenses
                 FROM sale_lots sl
                 WHERE sl.status = 'confirmed' AND $slWhere",
                $slBind
            );
            $totalLotExp = floatval($lotExp['transport'] ?? 0) + floatval($lotExp['lot_expenses'] ?? 0);

            $purchase = floatval($po['v'] ?? 0);
            $revenue  = floatval($sl['v'] ?? 0);
            $bizVal   = floatval($biz);
            $profit   = $revenue - $purchase - $totalLotExp - $bizVal;
            $rows = [
                ['รายจ่ายรับซื้อของ', number_format($purchase, 2)],
                ['รายรับขาย Lot',      number_format($revenue, 2)],
                ['ค่าใช้จ่าย Lot (รถ/อื่น)', number_format($totalLotExp, 2)],
                ['ค่าใช้จ่ายประจำร้าน', number_format($bizVal, 2)],
                ['กำไร/ขาดทุนสุทธิ',  number_format($profit, 2)],
            ];
        }

        $filename = "export_{$type}_{$params['year_raw']}";
        if ($params['period_raw'] === 'month') $filename .= "_{$params['month_raw']}";
        if ($params['branch_id_raw']) $filename .= "_branch{$params['branch_id_raw']}";
        $filename .= '.xls';

        $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        $html .= '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>รายงาน</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head><body>';
        $html .= '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:12px;">';
        $html .= '<thead><tr style="background-color:#d97706;color:white;">';
        foreach ($headers as $h) $html .= "<th>{$h}</th>";
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($this->spreadsheetSafeRow($row) as $cell) {
                $html .= '<td>' . htmlspecialchars((string)$cell, ENT_QUOTES, 'UTF-8') . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        header('Content-Type: application/vnd.ms-excel');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        echo $html;
        exit;
    }

    private function spreadsheetSafeRow(array $row)
    {
        return array_map(function ($cell) {
            if (!is_string($cell)) {
                return $cell;
            }
            if (preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $cell)) {
                return "'" . $cell;
            }
            return $cell;
        }, $row);
    }
}
