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
        // SECURITY: non-admin บังคับ branch ของตัวเอง
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
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSalesChartData($period);

        Response::success('Sales chart data retrieved', $chartData);
    }

    public function getPurchaseChart()
    {
        $this->requireAuth();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getPurchaseChartData($period);

        Response::success('Purchase chart data retrieved', $chartData);
    }

    public function getRecentPurchases()
    {
        $this->requireAuth();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $purchases = $reportService->getRecentPurchases($limit);

        Response::success('Recent purchases retrieved', $purchases);
    }

    public function getRecentSales()
    {
        $this->requireAuth();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $sales = $reportService->getRecentSales($limit);

        Response::success('Recent sales retrieved', $sales);
    }

    public function getSalesReport()
    {
        $this->requireAuth();
        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSalesReport($dateFrom, $dateTo, $groupBy);

        Response::success('Sales report data retrieved', $reportData);
    }

    public function getProductSales()
    {
        $this->requireAuth();
        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

        $reportService = new ReportService();
        $reportData = $reportService->getProductSalesReport($dateFrom, $dateTo, $categoryId, $limit);

        Response::success('Product sales report retrieved', $reportData);
    }

    public function getInventoryReport()
    {
        $this->requireAuth();
        // Get filter params
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $stockStatus = isset($_GET['stock_status']) ? $this->sanitizeInput($_GET['stock_status']) : null;

        $reportService = new ReportService();
        $reportData = $reportService->getInventoryReport($categoryId, $stockStatus);

        Response::success('Inventory report retrieved', $reportData);
    }

    public function getCashierPerformance()
    {
        $this->requireAuth();
        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

        $reportService = new ReportService();
        $reportData = $reportService->getCashierPerformanceReport($dateFrom, $dateTo, $userId);

        Response::success('Cashier performance data retrieved', $reportData);
    }

    public function getRecentSaleLots()
    {
        $this->requireAuth();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $lots = $reportService->getRecentSaleLots($limit);

        Response::success('Recent sale lots retrieved', $lots);
    }

    public function getPurchaseReport()
    {
        $this->requireAuth();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getPurchaseReport($dateFrom, $dateTo, $groupBy);

        Response::success('Purchase report data retrieved', $reportData);
    }

    public function getSaleLotReport()
    {
        $this->requireAuth();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSaleLotReport($dateFrom, $dateTo, $groupBy);

        Response::success('Sale lot report data retrieved', $reportData);
    }

    public function getSaleLotChart()
    {
        $this->requireAuth();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSaleLotChartData($period);

        Response::success('Sale lot chart data retrieved', $chartData);
    }

    public function getTaxReport()
    {
        $this->requireAuth();
        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'daily';

        $reportService = new ReportService();
        $reportData = $reportService->getTaxReport($dateFrom, $dateTo, $period);

        Response::success('Tax report data retrieved', $reportData);
    }
}
