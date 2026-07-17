<?php
class ReportsController extends Controller
{
    /**
     * SECURITY: Non-admin บังคับ scope ที่ branch ของตัวเองเสมอ
     */
    private function enforceBranchScope()
    {
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
            return $userBranch;
        }
        return null; // admin: no restriction
    }

    public function getDashboardStats()
    {
        $this->requireAuth();
        $branchId = isset($_GET['branch_id']) && is_numeric($_GET['branch_id'])
            ? intval($_GET['branch_id']) : null;
        $userBranch = $this->enforceBranchScope();
        if ($userBranch !== null) {
            $branchId = $userBranch;
        }

        $reportService = new ReportService();
        $stats = $reportService->getDashboardStats($branchId);

        Response::success('Dashboard statistics retrieved', $stats);
    }

    public function getSalesChart()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSalesChartData($period, $userBranch);

        Response::success('Sales chart data retrieved', $chartData);
    }

    public function getPurchaseChart()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getPurchaseChartData($period, $userBranch);

        Response::success('Purchase chart data retrieved', $chartData);
    }

    public function getRecentPurchases()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $purchases = $reportService->getRecentPurchases($limit, $userBranch);

        Response::success('Recent purchases retrieved', $purchases);
    }

    public function getRecentSales()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $sales = $reportService->getRecentSales($limit, $userBranch);

        Response::success('Recent sales retrieved', $sales);
    }

    public function getSalesReport()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSalesReport($dateFrom, $dateTo, $groupBy, $userBranch);

        Response::success('Sales report data retrieved', $reportData);
    }

    public function getProductSales()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

        $reportService = new ReportService();
        $reportData = $reportService->getProductSalesReport($dateFrom, $dateTo, $categoryId, $limit, $userBranch);

        Response::success('Product sales report retrieved', $reportData);
    }

    public function getInventoryReport()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $stockStatus = isset($_GET['stock_status']) ? $this->sanitizeInput($_GET['stock_status']) : null;

        $reportService = new ReportService();
        $reportData = $reportService->getInventoryReport($categoryId, $stockStatus, $userBranch);

        Response::success('Inventory report retrieved', $reportData);
    }

    public function getCashierPerformance()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        try {
            $reportService = new ReportService();
            $reportData = $reportService->getCashierPerformanceReport(
                $_GET['date_from'] ?? date('Y-m-01'),
                $_GET['date_to'] ?? date('Y-m-d'),
                isset($_GET['user_id']) ? intval($_GET['user_id']) : null,
                $userBranch
            );
            Response::success('Cashier performance data retrieved', $reportData);
        } catch (\Throwable $e) {
            Response::success('Cashier performance not available', [
                'cashiers' => [],
                'filters' => ['date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')]
            ]);
        }
    }

    public function getRecentSaleLots()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $lots = $reportService->getRecentSaleLots($limit, $userBranch);

        Response::success('Recent sale lots retrieved', $lots);
    }

    public function getPurchaseReport()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getPurchaseReport($dateFrom, $dateTo, $groupBy, $userBranch);

        Response::success('Purchase report data retrieved', $reportData);
    }

    public function getSaleLotReport()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSaleLotReport($dateFrom, $dateTo, $groupBy, $userBranch);

        Response::success('Sale lot report data retrieved', $reportData);
    }

    public function getSaleLotChart()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSaleLotChartData($period, $userBranch);

        Response::success('Sale lot chart data retrieved', $chartData);
    }

    public function getTaxReport()
    {
        $this->requireAuth();
        $userBranch = $this->enforceBranchScope();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'daily';

        $reportService = new ReportService();
        $reportData = $reportService->getTaxReport($dateFrom, $dateTo, $period, $userBranch);

        Response::success('Tax report data retrieved', $reportData);
    }
}
