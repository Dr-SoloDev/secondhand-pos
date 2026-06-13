<?php
class SalesController extends Controller
{
    public function createSale()
    {
        $this->requireAuth(['admin', 'manager', 'cashier']);

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['items']);

        if (empty($data['items'])) {
            Response::error('Sale must have at least one item', 400);
        }

        // Process sale
        $saleModel = new Sale();

        try {
            $saleData = [
                'customer_id' => $data['customer_id'] ?? 1, // Default to walk-in customer
                'user_id' => $this->user['user_id'],
                'payment_method' => $data['payment_method'] ?? 'cash',
                'notes' => $data['notes'] ?? '',
                'discount_amount' => $data['discount_amount'] ?? 0,
                'tax_amount' => $data['tax_amount'] ?? 0
            ];

            $saleId = $saleModel->create($saleData, $data['items']);

            // Get sale details for receipt
            $sale = $saleModel->getDetailsForReceipt($saleId);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'create_sale',
                "Created sale: {$sale['reference_no']}"
            );

            Response::success('Sale created successfully', $sale);
        } catch (Exception $e) {
            error_log('Sale creation failed: ' . $e->getMessage());
            Response::error('Sale creation failed', 500);
        }
    }

    public function getSales()
    {
        $this->requireAuth();
        // Get pagination params
        $pagination = $this->getPaginationParams();

        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null;
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : null;
        $customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : null;
        $paymentStatus = isset($_GET['payment_status']) ? $this->sanitizeInput($_GET['payment_status']) : null;
        $paymentMethod = isset($_GET['payment_method']) ? $this->sanitizeInput($_GET['payment_method']) : null;
        $search = isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null;

        // Get sales
        $saleModel = new Sale();
        $result = $saleModel->getSalesWithPagination(
            $pagination['page'],
            $pagination['limit'],
            $dateFrom,
            $dateTo,
            $customerId,
            $paymentStatus,
            $paymentMethod,
            $search
        );

        Response::success('Sales retrieved', $result);
    }

    /**
     * @param $id
     */
    public function getSaleDetails($id)
    {
        $this->requireAuth();
        if (!$id) {
            Response::error('Sale ID is required', 400);
        }

        $saleModel = new Sale();
        $sale = $saleModel->getDetailedSale($id);

        if (!$sale) {
            Response::error('Sale not found', 404);
        }

        Response::success('Sale details retrieved', $sale);
    }

    public function voidSale()
    {
        // Check permissions
        $this->requireAuth(['admin', 'manager']);

        // Get and validate request data
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['id', 'reason']);

        $saleId = intval($data['id']);
        $reason = $this->sanitizeInput($data['reason']);

        // Process void
        $saleModel = new Sale();

        try {
            $sale = $saleModel->findById($saleId);
            if (!$sale) {
                throw new Exception('Sale not found');
            }

            if ($sale['payment_status'] === 'voided') {
                throw new Exception('Sale is already voided');
            }

            $saleModel->voidSale($saleId, $reason, $this->user['user_id']);

            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'void_sale',
                "Voided sale ID: {$saleId}, Reason: {$reason}"
            );

            Response::success('Sale voided successfully');
        } catch (Exception $e) {
            error_log('Sale void failed: ' . $e->getMessage());
            Response::error('Failed to void sale', 500);
        }
    }

    public function exportSales()
    {
        $this->requireAuth(['admin', 'manager']);

        // Get filter params
        $dateFrom = isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null;
        $dateTo = isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : null;
        $paymentStatus = isset($_GET['payment_status']) ? $this->sanitizeInput($_GET['payment_status']) : null;
        $paymentMethod = isset($_GET['payment_method']) ? $this->sanitizeInput($_GET['payment_method']) : null;
        $search = isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null;

        // Get sales for export
        $saleModel = new Sale();
        $sales = $saleModel->getSalesForExport(
            $dateFrom,
            $dateTo,
            $paymentStatus,
            $paymentMethod,
            $search
        );

        // Prepare CSV data
        $csvData = [];

        // Header row
        $csvData[] = [
            'Reference #',
            'Date',
            'Customer',
            'Items',
            'Subtotal',
            'Discount',
            'Tax',
            'Total',
            'Payment Method',
            'Status',
            'Cashier',
            'Notes'
        ];

        // Data rows
        foreach ($sales as $sale) {
            $csvData[] = [
                $sale['reference_no'],
                $sale['created_at'],
                $sale['customer_name'] ?: 'Walk-in Customer',
                $sale['item_count'],
                $sale['total_amount'],
                $sale['discount_amount'],
                $sale['tax_amount'],
                $sale['grand_total'],
                $sale['payment_method'],
                $sale['payment_status'],
                $sale['user_name'],
                $sale['notes']
            ];
        }

        // Log activity
        Logger::logActivity(
            $this->user['user_id'],
            'export_sales',
            "Exported sales data"
        );

        // Send as CSV
        Response::csv($csvData, 'sales_export_'.date('Y-m-d').'.csv');
    }
}
