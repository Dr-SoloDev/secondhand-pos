<?php
class FinancialController extends Controller
{
    // ดึง summary cards: total_purchase, total_revenue, total_expenses, total_kg, total_lots
    public function summary()
    {
        $this->requireAuth(['admin']);
        $params = $this->getPeriodParams();

        $db = Database::getInstance();

        // รายจ่ายรับซื้อ (purchase_orders ที่ completed)
        $purchase = $db->fetch(
            "SELECT COALESCE(SUM(po.total_amount), 0) AS total_purchase
             FROM purchase_orders po
             WHERE po.status = 'completed'
               AND {$params['date_filter_po']}
               {$params['branch_filter']}",
            $params['bindings_po']
        );

        // น้ำหนักรับซื้อรวม
        $kgRow = $db->fetch(
            "SELECT COALESCE(SUM(poi.quantity), 0) AS total_kg
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             WHERE po.status = 'completed'
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

        // ค่าใช้จ่ายจาก expenses JSON ใน sale_lots
        $expRow = $db->fetch(
            "SELECT COALESCE(SUM(
               (SELECT COALESCE(SUM(CAST(jt.amount AS DECIMAL(12,2))), 0)
                FROM JSON_TABLE(sl.expenses, '\$[*]' COLUMNS (amount VARCHAR(20) PATH '\$.amount')) jt)
             ), 0) AS total_expenses
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

        Response::success('สำเร็จ', [
            'total_purchase'    => $purchase['total_purchase'] ?? 0,
            'total_kg'          => $kgRow['total_kg'] ?? 0,
            'total_revenue'     => $revenue['total_revenue'] ?? 0,
            'total_lots'        => $revenue['total_lots'] ?? 0,
            'total_expenses'    => floatval($expRow['total_expenses'] ?? 0),
            'total_biz_expenses'=> floatval($bizExpenses),
        ]);
    }

    // ดึงรายการ Lot ที่มี actual_revenue (สำหรับตารางรายละเอียด)
    public function lotRevenues()
    {
        $this->requireAuth(['admin']);
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
        $this->requireAuth(['admin']);
        $params = $this->getPeriodParams();
        $db = Database::getInstance();

        $items = $db->fetchAll(
            "SELECT
               COALESCE(c.name, poi.item_name, 'ไม่ระบุหมวด') AS category_name,
               COALESCE(SUM(poi.quantity), 0) AS total_kg,
               COUNT(poi.id) AS total_items,
               COALESCE(SUM(poi.total_price), 0) AS total_amount
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             LEFT JOIN categories c ON c.id = poi.category_id
             WHERE po.status = 'completed'
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
        $this->requireAuth(['admin']);
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
        $user = $this->requireAuth(['admin']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $branchId = intval($body['branch_id'] ?? 0);
        $amount   = floatval($body['amount'] ?? 0);
        if (!$branchId || $amount <= 0 || empty($body['expense_date']) || empty($body['category'])) {
            Response::error('ข้อมูลไม่ครบ', 400);
            return;
        }
        $model = new BusinessExpense();
        $id = $model->create([
            'branch_id'    => $branchId,
            'expense_date' => $body['expense_date'],
            'category'     => substr(trim($body['category']), 0, 50),
            'amount'       => $amount,
            'note'         => isset($body['note']) ? substr(trim($body['note']), 0, 255) : null,
            'created_by'   => $user['id'] ?? null,
        ]);
        Response::success('บันทึกแล้ว', ['id' => intval($id)]);
    }

    // ลบ business expense
    public function deleteExpense()
    {
        $this->requireAuth(['admin']);
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }
        (new BusinessExpense())->delete($id);
        Response::success('ลบแล้ว');
    }

    // สร้าง date filter params จาก GET
    private function getPeriodParams()
    {
        $period   = $_GET['period']    ?? 'month';
        $year     = intval($_GET['year']     ?? date('Y'));
        $month    = intval($_GET['month']    ?? date('n'));
        $branchId = isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null;

        // date filter สำหรับ purchase_orders (created_at)
        if ($period === 'month') {
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
        ];
    }

    // GET /api/financial/export?period=month&year=2026&month=6&branch_id=&type=purchases|salelots|expenses|summary
    public function exportCsv()
    {
        $this->requireAuth(['admin']);
        $params = $this->getPeriodParams();
        $type   = $_GET['type'] ?? 'summary';
        $db     = Database::getInstance();

        $rows = [];
        $headers = [];

        if ($type === 'purchases') {
            $headers = ['เลขที่บิล','วันที่','สาขา','ผู้ขาย','เลขบัตร','ยอด (บาท)','สถานะ'];
            $sql = "SELECT po.reference_no, po.created_at, b.name AS branch_name,
                           s.full_name AS seller_name, s.id_card, po.total_amount, po.status
                    FROM purchase_orders po
                    LEFT JOIN branches b ON b.id = po.branch_id
                    LEFT JOIN sellers s ON s.id = po.seller_id
                    WHERE po.status = 'completed'
                      AND {$params['date_filter_po']}
                      {$params['branch_filter']}
                    ORDER BY po.created_at DESC";
            $raw = $db->fetchAll($sql, $params['bindings_po']);
            foreach ($raw as $r) {
                $rows[] = [$r['reference_no'], $r['created_at'], $r['branch_name'],
                           $r['seller_name'], $r['id_card'], $r['total_amount'], 'สำเร็จ'];
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
            $po = $db->fetch("SELECT COALESCE(SUM(total_amount),0) AS v FROM purchase_orders WHERE status='completed' AND $poWhere", $poBind);
            $sl = $db->fetch("SELECT COALESCE(SUM(actual_revenue),0) AS v FROM sale_lots WHERE status='confirmed' AND actual_revenue IS NOT NULL AND $slWhere", $slBind);
            $biz = (new BusinessExpense())->sumByPeriod($pBranch, $pPeriod, $pYear, $pMonth);
            $purchase = floatval($po['v'] ?? 0);
            $revenue  = floatval($sl['v'] ?? 0);
            $profit   = $revenue - $purchase - floatval($biz);
            $rows = [
                ['รายจ่ายรับซื้อของ', number_format($purchase, 2)],
                ['รายรับขาย Lot',      number_format($revenue, 2)],
                ['ค่าใช้จ่ายประจำร้าน', number_format(floatval($biz), 2)],
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
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }
}
