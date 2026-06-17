<?php
class ReportsController extends Controller
{
    public function getDashboardStats()
    {
        // WF-04: รับ branch_id เพื่อกรองตามสาขา (null = รวมทุกสาขา)
        $branchId = isset($_GET['branch_id']) && is_numeric($_GET['branch_id'])
            ? intval($_GET['branch_id']) : null;

        $reportService = new ReportService();
        $stats = $reportService->getDashboardStats($branchId);

        Response::success('Dashboard statistics retrieved', $stats);
    }

    public function getSalesChart()
    {
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSalesChartData($period);

        Response::success('Sales chart data retrieved', $chartData);
    }

    public function getPurchaseChart()
    {
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getPurchaseChartData($period);

        Response::success('Purchase chart data retrieved', $chartData);
    }

    public function getRecentPurchases()
    {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $purchases = $reportService->getRecentPurchases($limit);

        Response::success('Recent purchases retrieved', $purchases);
    }

    public function getRecentSales()
    {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $sales = $reportService->getRecentSales($limit);

        Response::success('Recent sales retrieved', $sales);
    }

    public function getSalesReport()
    {
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
        // Get filter params
        $categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : null;
        $stockStatus = isset($_GET['stock_status']) ? $this->sanitizeInput($_GET['stock_status']) : null;

        $reportService = new ReportService();
        $reportData = $reportService->getInventoryReport($categoryId, $stockStatus);

        Response::success('Inventory report retrieved', $reportData);
    }

    public function getCashierPerformance()
    {
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
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;

        $reportService = new ReportService();
        $lots = $reportService->getRecentSaleLots($limit);

        Response::success('Recent sale lots retrieved', $lots);
    }

    public function getPurchaseReport()
    {
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getPurchaseReport($dateFrom, $dateTo, $groupBy);

        Response::success('Purchase report data retrieved', $reportData);
    }

    public function getSaleLotReport()
    {
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $groupBy = isset($_GET['group_by']) ? $this->sanitizeInput($_GET['group_by']) : 'day';

        $reportService = new ReportService();
        $reportData = $reportService->getSaleLotReport($dateFrom, $dateTo, $groupBy);

        Response::success('Sale lot report data retrieved', $reportData);
    }

    public function getSaleLotChart()
    {
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'week';

        $reportService = new ReportService();
        $chartData = $reportService->getSaleLotChartData($period);

        Response::success('Sale lot chart data retrieved', $chartData);
    }

    public function getTaxReport()
    {
        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : date('Y-m-01');
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : date('Y-m-d');
        $period = isset($_GET['period']) ? $this->sanitizeInput($_GET['period']) : 'daily';

        $reportService = new ReportService();
        $reportData = $reportService->getTaxReport($dateFrom, $dateTo, $period);

        Response::success('Tax report data retrieved', $reportData);
    }
}
