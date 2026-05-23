  <?php
class ReportService
{
    /**
     * @var mixed
     */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getDashboardStats()
    {
        // Today's purchase total (รับซื้อวันนี้)
        $todayPurchases = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_amount), 0) as total
              FROM purchase_orders
              WHERE DATE(created_at) = CURDATE()
              AND status != 'cancelled'"
        );

        // Today's purchase orders count (ใบรับซื้อวันนี้)
        $todayPO = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM purchase_orders
              WHERE DATE(created_at) = CURDATE()
              AND status != 'cancelled'"
        );

        // Total sellers (ผู้ขายทั้งหมด)
        $totalSellers = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM sellers WHERE is_blacklisted = 0"
        );

        // Pending purchase orders (ใบรับซื้อรอตรวจสอบ)
        $pendingPO = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM purchase_orders
              WHERE status = 'draft'"
        );

        // Also keep sales stats for reference
        $todaySales = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(grand_total), 0) as total
              FROM sales
              WHERE DATE(created_at) = CURDATE()
              AND payment_status != 'voided'"
        );

        // Low stock count
        $lowStockCount = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM products
              WHERE quantity <= low_stock_threshold
              AND status = 'active'"
        );

        return [
            'today_purchases' => floatval($todayPurchases),
            'today_po_count' => intval($todayPO),
            'total_sellers' => intval($totalSellers),
            'pending_po' => intval($pendingPO),
            'today_sales' => floatval($todaySales),
            'low_stock_count' => intval($lowStockCount)
        ];
    }

    public function getRecentPurchases($limit = 10)
    {
        return $this->db->fetchAll(
            "SELECT
                po.*,
                s.full_name as seller_name,
                u.full_name as user_name,
                b.name as branch_name
            FROM purchase_orders po
            LEFT JOIN sellers s ON po.seller_id = s.id
            LEFT JOIN users u ON po.user_id = u.id
            LEFT JOIN branches b ON po.branch_id = b.id
            ORDER BY po.created_at DESC
            LIMIT ?",
            [$limit]
        );
    }

    public function getPurchaseChartData($period = 'week')
    {
        $labels = [];
        $purchaseData = [];

        switch ($period) {
            case 'week':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE(created_at) as po_date,
                        COALESCE(SUM(total_amount), 0) as total
                    FROM purchase_orders
                    WHERE
                        created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                        AND status != 'cancelled'
                    GROUP BY DATE(created_at)
                    ORDER BY po_date ASC"
                );

                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = $date;
                    $purchaseData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['po_date'], $labels);
                    if ($dateIndex !== false) {
                        $purchaseData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            case 'month':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE(created_at) as po_date,
                        COALESCE(SUM(total_amount), 0) as total
                    FROM purchase_orders
                    WHERE
                        MONTH(created_at) = MONTH(CURDATE())
                        AND YEAR(created_at) = YEAR(CURDATE())
                        AND status != 'cancelled'
                    GROUP BY DATE(created_at)
                    ORDER BY po_date ASC"
                );

                $daysInMonth = date('t');
                for ($i = 1; $i <= $daysInMonth; $i++) {
                    $date = date('Y-m-').sprintf('%02d', $i);
                    $labels[] = $date;
                    $purchaseData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['po_date'], $labels);
                    if ($dateIndex !== false) {
                        $purchaseData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            case 'year':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE_FORMAT(created_at, '%Y-%m-01') as po_month,
                        COALESCE(SUM(total_amount), 0) as total
                    FROM purchase_orders
                    WHERE
                        YEAR(created_at) = YEAR(CURDATE())
                        AND status != 'cancelled'
                    GROUP BY po_month
                    ORDER BY po_month ASC"
                );

                for ($i = 1; $i <= 12; $i++) {
                    $month = date('Y-').sprintf('%02d', $i).'-01';
                    $labels[] = $month;
                    $purchaseData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['po_month'], $labels);
                    if ($dateIndex !== false) {
                        $purchaseData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;
        }

        return [
            'labels' => $labels,
            'purchases' => $purchaseData
        ];
    }

    /**
     * @param $period
     */
    public function getSalesChartData($period = 'week')
    {
        $labels = [];
        $salesData = [];

        switch ($period) {
            case 'week':
                // Get sales for the last 7 days
                $result = $this->db->fetchAll(
                    "SELECT
                DATE(created_at) as sale_date,
                COALESCE(SUM(grand_total), 0) as total
            FROM sales
            WHERE
                created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                AND payment_status != 'voided'
            GROUP BY DATE(created_at)
            ORDER BY sale_date ASC"
                );

                // Create an array for the last 7 days
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = $date;
                    $salesData[] = 0; // Default to 0
                }

                // Fill in actual data
                foreach ($result as $row) {
                    $dateIndex = array_search($row['sale_date'], $labels);
                    if ($dateIndex !== false) {
                        $salesData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            case 'month':
                // Get sales for the current month by day
                $result = $this->db->fetchAll(
                    "SELECT
                DATE(created_at) as sale_date,
                COALESCE(SUM(grand_total), 0) as total
            FROM sales
            WHERE
                MONTH(created_at) = MONTH(CURDATE())
                AND YEAR(created_at) = YEAR(CURDATE())
                AND payment_status != 'voided'
            GROUP BY DATE(created_at)
            ORDER BY sale_date ASC"
                );

                // Create an array for each day of the current month
                $daysInMonth = date('t');
                for ($i = 1; $i <= $daysInMonth; $i++) {
                    $date = date('Y-m-').sprintf('%02d', $i);
                    $labels[] = $date;
                    $salesData[] = 0; // Default to 0
                }

                // Fill in actual data
                foreach ($result as $row) {
                    $dateIndex = array_search($row['sale_date'], $labels);
                    if ($dateIndex !== false) {
                        $salesData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            case 'year':
                // Get sales for each month of the current year
                $result = $this->db->fetchAll(
                    "SELECT
                DATE_FORMAT(created_at, '%Y-%m-01') as sale_month,
                COALESCE(SUM(grand_total), 0) as total
            FROM sales
            WHERE
                YEAR(created_at) = YEAR(CURDATE())
                AND payment_status != 'voided'
            GROUP BY sale_month
            ORDER BY sale_month ASC"
                );

                // Create an array for each month of the year
                for ($i = 1; $i <= 12; $i++) {
                    $month = date('Y-').sprintf('%02d', $i).'-01';
                    $labels[] = $month;
                    $salesData[] = 0; // Default to 0
                }

                // Fill in actual data
                foreach ($result as $row) {
                    $dateIndex = array_search($row['sale_month'], $labels);
                    if ($dateIndex !== false) {
                        $salesData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            default:
                throw new Exception('Invalid period');
        }

        return [
            'labels' => $labels,
            'sales' => $salesData
        ];
    }

    /**
     * @param $limit
     * @return mixed
     */
    public function getRecentSales($limit = 10)
    {
        return $this->db->fetchAll(
            "SELECT
        s.*,
        c.name as customer_name,
        (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    ORDER BY s.created_at DESC
    LIMIT ?",
            [$limit]
        );
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $groupBy
     */
    public function getSalesReport($dateFrom, $dateTo, $groupBy = 'day')
    {
        // Determine SQL grouping based on groupBy parameter
        switch ($groupBy) {
            case 'day':
                $groupFormat = 'DATE(s.created_at)';
                $labelFormat = 'DATE(s.created_at)';
                break;
            case 'month':
                $groupFormat = "DATE_FORMAT(s.created_at, '%Y-%m')";
                $labelFormat = "DATE_FORMAT(s.created_at, '%Y-%m')";
                break;
            case 'year':
                $groupFormat = 'YEAR(s.created_at)';
                $labelFormat = 'YEAR(s.created_at)';
                break;
            default:
                throw new Exception('Invalid grouping');
        }

        // Get report data
        $reportData = $this->db->fetchAll(
            "SELECT
        {$labelFormat} as label,
        COUNT(*) as order_count,
        COALESCE(SUM(s.total_amount), 0) as total_amount,
        COALESCE(SUM(s.discount_amount), 0) as discount_amount,
        COALESCE(SUM(s.tax_amount), 0) as tax_amount,
        COALESCE(SUM(s.grand_total), 0) as grand_total
    FROM sales s
    WHERE
        DATE(s.created_at) BETWEEN ? AND ?
        AND s.payment_status != 'voided'
    GROUP BY {$groupFormat}
    ORDER BY {$labelFormat} ASC",
            [$dateFrom, $dateTo]
        );

        // Calculate totals
        $totalOrders = 0;
        $totalSales = 0;
        $totalDiscounts = 0;
        $totalTax = 0;
        $totalGrand = 0;

        foreach ($reportData as $row) {
            $totalOrders += $row['order_count'];
            $totalSales += $row['total_amount'];
            $totalDiscounts += $row['discount_amount'];
            $totalTax += $row['tax_amount'];
            $totalGrand += $row['grand_total'];
        }

        return [
            'report_data' => $reportData,
            'totals' => [
                'total_orders' => $totalOrders,
                'total_sales' => $totalSales,
                'total_discounts' => $totalDiscounts,
                'total_tax' => $totalTax,
                'total_grand' => $totalGrand
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by' => $groupBy
            ]
        ];
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $categoryId
     * @param null $limit
     * @return mixed
     */
    public function getProductSalesReport($dateFrom, $dateTo, $categoryId = null, $limit = 20)
    {
        $conditions = [
            "DATE(s.created_at) BETWEEN ? AND ?",
            "s.payment_status != 'voided'"
        ];
        $params = [$dateFrom, $dateTo];

        if ($categoryId) {
            $conditions[] = "p.category_id = ?";
            $params[] = $categoryId;
        }

        $whereClause = " WHERE ".implode(' AND ', $conditions);
        $limitClause = $limit ? " LIMIT ?" : "";

        if ($limit) {
            $params[] = $limit;
        }

        return $this->db->fetchAll(
            "SELECT
        p.id,
        p.sku,
        p.name,
        c.name as category_name,
        COUNT(DISTINCT si.sale_id) as order_count,
        SUM(si.quantity) as quantity_sold,
        COALESCE(SUM(si.total), 0) as total_sales
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN sale_items si ON p.id = si.product_id
    LEFT JOIN sales s ON si.sale_id = s.id
    $whereClause
    GROUP BY p.id, p.sku, p.name, c.name
    ORDER BY total_sales DESC
    $limitClause",
            $params
        );
    }

    /**
     * @param $categoryId
     * @param null $stockStatus
     */
    public function getInventoryReport($categoryId = null, $stockStatus = null)
    {
        $conditions = ["p.status = 'active'"];
        $params = [];

        if ($categoryId) {
            $conditions[] = "p.category_id = ?";
            $params[] = $categoryId;
        }

        if ($stockStatus) {
            switch ($stockStatus) {
                case 'low':
                    $conditions[] = "p.quantity <= p.low_stock_threshold AND p.quantity > 0";
                    break;
                case 'out':
                    $conditions[] = "p.quantity <= 0";
                    break;
            }
        }

        $whereClause = " WHERE ".implode(' AND ', $conditions);

        $inventory = $this->db->fetchAll(
            "SELECT
        p.id,
        p.sku,
        p.name,
        p.quantity,
        p.low_stock_threshold,
        c.name as category_name,
        p.price,
        p.cost,
        (p.quantity * p.cost) as inventory_value
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    $whereClause
    ORDER BY p.name ASC",
            $params
        );

        // Calculate totals
        $totalItems = count($inventory);
        $totalQuantity = 0;
        $totalValue = 0;
        $lowStockCount = 0;
        $outOfStockCount = 0;

        foreach ($inventory as $item) {
            $totalQuantity += $item['quantity'];
            $totalValue += $item['inventory_value'];

            if ($item['quantity'] <= 0) {
                $outOfStockCount++;
            } elseif ($item['quantity'] <= $item['low_stock_threshold']) {
                $lowStockCount++;
            }
        }

        return [
            'inventory' => $inventory,
            'totals' => [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'total_value' => $totalValue,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount
            ]
        ];
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $userId
     */
    public function getCashierPerformanceReport($dateFrom, $dateTo, $userId = null)
    {
        $conditions = ["DATE(s.created_at) BETWEEN ? AND ?"];
        $params = [$dateFrom, $dateTo];

        if ($userId) {
            $conditions[] = "u.id = ?";
            $params[] = $userId;
        }

        $whereClause = !empty($conditions) ? " WHERE ".implode(' AND ', $conditions) : "";

        $cashiers = $this->db->fetchAll(
            "SELECT
        u.id, u.username, u.full_name,
        COUNT(DISTINCT s.id) as order_count,
        (SELECT SUM(si.quantity) FROM sale_items si
        JOIN sales s2 ON si.sale_id = s2.id
        WHERE s2.user_id = u.id
        AND DATE(s2.created_at) BETWEEN ? AND ?
        AND s2.payment_status != 'voided') as items_sold,
        SUM(CASE WHEN s.payment_status != 'voided' THEN s.grand_total ELSE 0 END) as total_sales,
        (SELECT COUNT(*) FROM sales
        WHERE user_id = u.id
        AND payment_status = 'voided'
        AND DATE(created_at) BETWEEN ? AND ?) as cancelled_orders
    FROM users u
    LEFT JOIN sales s ON u.id = s.user_id AND DATE(s.created_at) BETWEEN ? AND ?
    WHERE u.role IN ('admin', 'manager', 'cashier')
    GROUP BY u.id, u.username, u.full_name
    ORDER BY total_sales DESC",
            array_merge([$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo], $params)
        );

        // Ensure proper data types
        foreach ($cashiers as &$cashier) {
            $cashier['order_count'] = intval($cashier['order_count']);
            $cashier['items_sold'] = intval($cashier['items_sold'] ?? 0);
            $cashier['total_sales'] = floatval($cashier['total_sales'] ?? 0);
            $cashier['cancelled_orders'] = intval($cashier['cancelled_orders']);
        }

        return [
            'cashiers' => $cashiers,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'user_id' => $userId
            ]
        ];
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $period
     */
    public function getTaxReport($dateFrom, $dateTo, $period = 'daily')
    {
        // Determine SQL grouping based on period
        switch ($period) {
            case 'daily':
                $groupFormat = 'DATE(created_at)';
                $periodFormat = 'DATE(created_at)';
                break;
            case 'monthly':
                $groupFormat = "DATE_FORMAT(created_at, '%Y-%m')";
                $periodFormat = "DATE_FORMAT(created_at, '%Y-%m')";
                break;
            case 'quarterly':
                $groupFormat = "CONCAT(YEAR(created_at), '-Q', QUARTER(created_at))";
                $periodFormat = "CONCAT(YEAR(created_at), '-Q', QUARTER(created_at))";
                break;
            default:
                throw new Exception('Invalid period');
        }

        // Query for tax data by period
        $taxData = $this->db->fetchAll(
            "SELECT
        {$periodFormat} as period,
        SUM(total_amount - discount_amount) as taxable_sales,
        SUM(tax_amount) as tax_collected
    FROM sales
    WHERE
        DATE(created_at) BETWEEN ? AND ?
        AND payment_status != 'voided'
    GROUP BY {$groupFormat}
    ORDER BY MIN(created_at) ASC",
            [$dateFrom, $dateTo]
        );

        // Calculate totals
        $totalTaxableSales = 0;
        $totalTaxCollected = 0;

        foreach ($taxData as &$period) {
            $period['taxable_sales'] = floatval($period['taxable_sales']);
            $period['tax_collected'] = floatval($period['tax_collected']);

            $totalTaxableSales += $period['taxable_sales'];
            $totalTaxCollected += $period['tax_collected'];
        }

        return [
            'periods' => $taxData,
            'totals' => [
                'taxable_sales' => $totalTaxableSales,
                'tax_collected' => $totalTaxCollected
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'period' => $period
            ]
        ];
    }
}