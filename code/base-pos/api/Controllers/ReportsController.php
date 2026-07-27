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

    private function resolveBranchId()
    {
        $requestedBranch = isset($_GET['branch_id']) && is_numeric($_GET['branch_id'])
            ? intval($_GET['branch_id']) : null;
        $userBranch = $this->enforceBranchScope();

        return $userBranch !== null ? $userBranch : $requestedBranch;
    }

    public function getDashboardStats()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();

        $reportService = new ReportService();
        $stats = $reportService->getDashboardStats($branchId);

        Response::success('Dashboard statistics retrieved', $stats);
    }

    public function getSalesChart()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSalesChartData($period, $branchId);

        Response::success('Sales chart data retrieved', $chartData);
    }

    public function getPurchaseChart()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getPurchaseChartData($period, $branchId);

        Response::success('Purchase chart data retrieved', $chartData);
    }

    public function getRecentPurchases()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $purchases = $reportService->getRecentPurchases($limit, $branchId);

        Response::success('Recent purchases retrieved', $purchases);
    }

    public function getRecentSales()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $sales = $reportService->getRecentSales($limit, $branchId);

        Response::success('Recent sales retrieved', $sales);
    }

    public function getSalesReport()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSalesReport($dateFrom, $dateTo, $groupBy, $branchId);

        Response::success('Sales report data retrieved', $reportData);
    }

    public function getProductSales()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;

        $reportService = new ReportService();
        $reportData = $reportService->getProductSalesReport($dateFrom, $dateTo, $categoryId, $limit, $branchId);

        Response::success('Product sales report retrieved', $reportData);
    }

    public function getInventoryReport()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $stockStatus = isset($_GET['stock_status']) ? $this->sanitizeInput($_GET['stock_status']) : null;

        $reportService = new ReportService();
        $reportData = $reportService->getInventoryReport($categoryId, $stockStatus, $branchId);

        Response::success('Inventory report retrieved', $reportData);
    }

    public function getCashierPerformance()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        try {
            $reportService = new ReportService();
            $reportData = $reportService->getCashierPerformanceReport(
                $_GET['date_from'] ?? date('Y-m-01'),
                $_GET['date_to'] ?? date('Y-m-d'),
                isset($_GET['user_id']) ? intval($_GET['user_id']) : null,
                $branchId
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
        $branchId = $this->resolveBranchId();
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $lots = $reportService->getRecentSaleLots($limit, $branchId);

        Response::success('Recent sale lots retrieved', $lots);
    }

    public function getPurchaseReport()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getPurchaseReport($dateFrom, $dateTo, $groupBy, $branchId);

        Response::success('Purchase report data retrieved', $reportData);
    }

    public function getSaleLotReport()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSaleLotReport($dateFrom, $dateTo, $groupBy, $branchId);

        Response::success('Sale lot report data retrieved', $reportData);
    }

    public function getSaleLotChart()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSaleLotChartData($period, $branchId);

        Response::success('Sale lot chart data retrieved', $chartData);
    }

    public function getTaxReport()
    {
        $this->requireAuth();
        $branchId = $this->resolveBranchId();
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'daily';

        $reportService = new ReportService();
        $reportData = $reportService->getTaxReport($dateFrom, $dateTo, $period, $branchId);

        Response::success('Tax report data retrieved', $reportData);
    }
}
