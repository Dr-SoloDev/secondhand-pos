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

    public function getDashboardStats($branchId = null)
    {
        // WF-04: optional branch filter
        $bWhere = $branchId ? " AND branch_id = ?" : "";
        $bWhereSL = $branchId ? " AND branch_id = ?" : "";

        // Today's purchase total (รับซื้อวันนี้)
        $todayPurchases = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_amount), 0) as total
              FROM purchase_orders
              WHERE DATE(created_at) = CURDATE()
              AND status = 'completed'
              AND source_type = 'manual'" . $bWhere,
            $branchId ? [$branchId] : []
        );

        // Today's purchase orders count (ใบรับซื้อวันนี้)
        $todayPO = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM purchase_orders
              WHERE DATE(created_at) = CURDATE()
              AND status = 'completed'
              AND source_type = 'manual'" . $bWhere,
            $branchId ? [$branchId] : []
        );

        // Total sellers (ผู้ขายทั้งหมด — ไม่กรองตาม branch)
        $totalSellers = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM sellers WHERE is_blacklisted = 0"
        );

        // Pending purchase orders (ใบรับซื้อรอตรวจสอบ)
        $pendingPO = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM purchase_orders
              WHERE status = 'draft' AND source_type = 'manual'" . $bWhere,
            $branchId ? [$branchId] : []
        );

        // Also keep sales stats for reference
        $todaySales = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(grand_total), 0) as total
              FROM sales
              WHERE DATE(created_at) = CURDATE()
              AND payment_status != 'voided'" . $bWhere,
            $branchId ? [$branchId] : []
        );

        // Low stock count from branch_stock, the scrap inventory source of truth.
        $lowStockBranch = $branchId ? ' AND bs.branch_id = ?' : '';
        $lowStockCount = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM branch_stock bs
              INNER JOIN categories c ON c.id = bs.category_id
              WHERE ((c.alert_threshold IS NOT NULL AND bs.stock_kg <= c.alert_threshold)
                 OR (c.alert_threshold IS NULL AND bs.stock_kg <= 0))" . $lowStockBranch,
            $branchId ? [$branchId] : []
        );

        // ── Branch Stock (scrap inventory) stats ──
        $bWhereBS = $branchId ? " WHERE branch_id = ?" : "";
        $bsParams = $branchId ? [$branchId] : [];

        $branchStockSummary = $this->db->fetch(
            "SELECT
                COUNT(*) as total_items,
                COALESCE(SUM(stock_kg), 0) as total_kg,
                COALESCE(SUM(stock_kg * unit_price), 0) as total_value,
                SUM(CASE WHEN stock_kg <= 0 THEN 1 ELSE 0 END) as zero_stock_count
            FROM branch_stock" . $bWhereBS,
            $bsParams
        );

        // Sale lot stats
        $todaySaleLotAmount = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_amount), 0)
              FROM sale_lots
              WHERE DATE(sale_date) = CURDATE()
              AND status = 'confirmed'" . $bWhereSL,
            $branchId ? [$branchId] : []
        );

        $monthSaleLotProfit = $this->db->fetchColumn(
            "SELECT COALESCE(SUM(total_amount - total_cost), 0)
              FROM sale_lots
              WHERE MONTH(sale_date) = MONTH(CURDATE())
              AND YEAR(sale_date) = YEAR(CURDATE())
              AND status = 'confirmed'" . $bWhereSL,
            $branchId ? [$branchId] : []
        );

        $pendingSaleLots = $this->db->fetchColumn(
            "SELECT COUNT(*)
              FROM sale_lots
              WHERE status = 'draft'" . $bWhereSL,
            $branchId ? [$branchId] : []
        );

        return [
            'today_purchases' => floatval($todayPurchases),
            'today_po_count' => intval($todayPO),
            'total_sellers' => intval($totalSellers),
            'pending_po' => intval($pendingPO),
            'today_sales' => floatval($todaySales),
            'low_stock_count' => intval($lowStockCount),
            'today_salelot_amount' => floatval($todaySaleLotAmount),
            'month_salelot_profit' => floatval($monthSaleLotProfit),
            'pending_salelots' => intval($pendingSaleLots),
            // Branch Stock (scrap inventory) metrics
            'branch_stock_total_items' => intval($branchStockSummary['total_items']),
            'branch_stock_total_kg' => floatval($branchStockSummary['total_kg']),
            'branch_stock_total_value' => floatval($branchStockSummary['total_value']),
            'branch_stock_zero_count' => intval($branchStockSummary['zero_stock_count'])
        ];
    }

    public function getRecentPurchases($limit = 10, $branchId = null)
    {
        $branchFilter = $branchId ? 'AND po.branch_id = ?' : '';
        $params = [];
        if ($branchId) {
            $params[] = $branchId;  // branch_id first (WHERE clause)
        }
        $params[] = $limit;  // limit last (LIMIT clause)

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
            WHERE po.status = 'completed' AND po.source_type = 'manual' $branchFilter
            ORDER BY po.created_at DESC
            LIMIT ?",
            $params
        );
    }

    public function getPurchaseChartData($period = 'week', $branchId = null)
    {
        $labels = [];
        $purchaseData = [];

        $branchFilter = $branchId ? 'AND branch_id = ?' : '';

        switch ($period) {
            case 'week':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE(created_at) as po_date,
                        COALESCE(SUM(total_amount), 0) as total
                    FROM purchase_orders
                    WHERE
                        created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                        AND status = 'completed'
                        AND source_type = 'manual'
                        $branchFilter
                    GROUP BY DATE(created_at)
                    ORDER BY po_date ASC",
                    $branchId ? [$branchId] : []
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
                        AND status = 'completed'
                        AND source_type = 'manual'
                        $branchFilter
                    GROUP BY DATE(created_at)
                    ORDER BY po_date ASC",
                    $branchId ? [$branchId] : []
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
                        AND status = 'completed'
                        AND source_type = 'manual'
                        $branchFilter
                    GROUP BY po_month
                    ORDER BY po_month ASC",
                    $branchId ? [$branchId] : []
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
    public function getSalesChartData($period = 'week', $branchId = null)
    {
        $labels = [];
        $salesData = [];

        $branchFilter = $branchId ? 'AND branch_id = ?' : '';

        switch ($period) {
            case 'week':
                $result = $this->db->fetchAll(
                    "SELECT
                DATE(created_at) as sale_date,
                COALESCE(SUM(grand_total), 0) as total
            FROM sales
            WHERE
                created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                AND payment_status != 'voided'
                $branchFilter
            GROUP BY DATE(created_at)
            ORDER BY sale_date ASC",
                    $branchId ? [$branchId] : []
                );

                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = $date;
                    $salesData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['sale_date'], $labels);
                    if ($dateIndex !== false) {
                        $salesData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            case 'month':
                $result = $this->db->fetchAll(
                    "SELECT
                DATE(created_at) as sale_date,
                COALESCE(SUM(grand_total), 0) as total
            FROM sales
            WHERE
                MONTH(created_at) = MONTH(CURDATE())
                AND YEAR(created_at) = YEAR(CURDATE())
                AND payment_status != 'voided'
                $branchFilter
            GROUP BY DATE(created_at)
            ORDER BY sale_date ASC",
                    $branchId ? [$branchId] : []
                );

                $daysInMonth = date('t');
                for ($i = 1; $i <= $daysInMonth; $i++) {
                    $date = date('Y-m-').sprintf('%02d', $i);
                    $labels[] = $date;
                    $salesData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['sale_date'], $labels);
                    if ($dateIndex !== false) {
                        $salesData[$dateIndex] = floatval($row['total']);
                    }
                }
                break;

            case 'year':
                $result = $this->db->fetchAll(
                    "SELECT
                DATE_FORMAT(created_at, '%Y-%m-01') as sale_month,
                COALESCE(SUM(grand_total), 0) as total
            FROM sales
            WHERE
                YEAR(created_at) = YEAR(CURDATE())
                AND payment_status != 'voided'
                $branchFilter
            GROUP BY sale_month
            ORDER BY sale_month ASC",
                    $branchId ? [$branchId] : []
                );

                for ($i = 1; $i <= 12; $i++) {
                    $month = date('Y-').sprintf('%02d', $i).'-01';
                    $labels[] = $month;
                    $salesData[] = 0;
                }

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
    public function getRecentSales($limit = 10, $branchId = null)
    {
        $branchFilter = $branchId ? 'AND s.branch_id = ?' : '';
        $params = [];
        if ($branchId) {
            $params[] = $branchId;  // branch_id first (WHERE clause)
        }
        $params[] = $limit;  // limit last (LIMIT clause)

        return $this->db->fetchAll(
            "SELECT
        s.*,
        c.name as customer_name,
        (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
    FROM sales s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE 1=1 $branchFilter
    ORDER BY s.created_at DESC
    LIMIT ?",
            $params
        );
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $groupBy
     */
    public function getSalesReport($dateFrom, $dateTo, $groupBy = 'day', $branchId = null)
    {
        $branchFilter = $branchId ? 'AND s.branch_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($branchId) {
            $params[] = $branchId;
        }

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
        $branchFilter
    GROUP BY {$groupFormat}
    ORDER BY {$labelFormat} ASC",
            $params
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
                'group_by' => $groupBy,
                'branch_id' => $branchId
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
    public function getProductSalesReport($dateFrom, $dateTo, $categoryId = null, $limit = 20, $branchId = null)
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

        if ($branchId) {
            $conditions[] = "s.branch_id = ?";
            $params[] = $branchId;
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
    public function getInventoryReport($categoryId = null, $stockStatus = null, $branchId = null)
    {
        // ── Part 1: Retail Products (from `products` table) ──
        $prodConditions = ["p.status = 'active'"];
        $prodParams = [];

        if ($categoryId) {
            $prodConditions[] = "p.category_id = ?";
            $prodParams[] = $categoryId;
        }

        if ($stockStatus) {
            switch ($stockStatus) {
                case 'low':
                    $prodConditions[] = "p.quantity <= p.low_stock_threshold AND p.quantity > 0";
                    break;
                case 'out':
                    $prodConditions[] = "p.quantity <= 0";
                    break;
            }
        }

        if ($branchId) {
            $prodConditions[] = "p.branch_id = ?";
            $prodParams[] = $branchId;
        }

        $prodWhere = " WHERE " . implode(' AND ', $prodConditions);

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
            $prodWhere
            ORDER BY p.name ASC",
            $prodParams
        );

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

        // ── Part 2: Scrap / Branch Stock (from `branch_stock` table) ──
        $bsConditions = [];
        $bsParams = [];

        if ($categoryId) {
            $bsConditions[] = "bs.category_id = ?";
            $bsParams[] = $categoryId;
        }

        if ($stockStatus) {
            switch ($stockStatus) {
                case 'low':
                    // branch_stock has no threshold column; treat "low" as stock_kg <= 0
                    $bsConditions[] = "bs.stock_kg <= 0";
                    break;
                case 'out':
                    $bsConditions[] = "bs.stock_kg <= 0";
                    break;
            }
        }

        if ($branchId) {
            $bsConditions[] = "bs.branch_id = ?";
            $bsParams[] = $branchId;
        }

        $bsWhere = count($bsConditions) ? " WHERE " . implode(' AND ', $bsConditions) : "";

        $branchStock = $this->db->fetchAll(
            "SELECT
                bs.id,
                bs.branch_id,
                b.name as branch_name,
                bs.category_id,
                c.name as category_name,
                bs.item_name,
                bs.stock_kg,
                bs.unit_price,
                (bs.stock_kg * bs.unit_price) as inventory_value,
                bs.last_updated
            FROM branch_stock bs
            LEFT JOIN categories c ON bs.category_id = c.id
            LEFT JOIN branches b ON bs.branch_id = b.id
            $bsWhere
            ORDER BY c.name ASC, bs.item_name ASC",
            $bsParams
        );

        $bsTotalItems = count($branchStock);
        $bsTotalKg = 0;
        $bsTotalValue = 0;
        $bsZeroStockCount = 0;

        foreach ($branchStock as $item) {
            $bsTotalKg += $item['stock_kg'];
            $bsTotalValue += $item['inventory_value'];
            if ($item['stock_kg'] <= 0) {
                $bsZeroStockCount++;
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
            ],
            // New: Branch Stock (scrap inventory) section
            'branch_stock_inventory' => $branchStock,
            'branch_stock_totals' => [
                'total_items' => $bsTotalItems,
                'total_kg' => $bsTotalKg,
                'total_value' => $bsTotalValue,
                'zero_stock_count' => $bsZeroStockCount
            ]
        ];
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $userId
     */
    public function getCashierPerformanceReport($dateFrom, $dateTo, $userId = null, $branchId = null)
    {
        $branchFilterS2 = $branchId ? 'AND s2.branch_id = ?' : '';
        $branchFilterDirect = $branchId ? 'AND branch_id = ?' : '';
        $branchFilterJoin = $branchId ? 'AND s.branch_id = ?' : '';

        $queryParams = [];

        // items_sold subquery
        $queryParams[] = $dateFrom;
        $queryParams[] = $dateTo;
        if ($branchId) {
            $queryParams[] = $branchId;
        }

        // cancelled_orders subquery
        $queryParams[] = $dateFrom;
        $queryParams[] = $dateTo;
        if ($branchId) {
            $queryParams[] = $branchId;
        }

        // main sales join
        $queryParams[] = $dateFrom;
        $queryParams[] = $dateTo;
        if ($branchId) {
            $queryParams[] = $branchId;
        }

        if ($userId) {
            $queryParams[] = $userId;
        }

        $userFilter = $userId ? 'AND u.id = ?' : '';

        $cashiers = $this->db->fetchAll(
            "SELECT
        u.id, u.username, u.full_name,
        COUNT(DISTINCT s.id) as order_count,
        (SELECT SUM(si.quantity) FROM sale_items si
        JOIN sales s2 ON si.sale_id = s2.id
        WHERE s2.user_id = u.id
        AND DATE(s2.created_at) BETWEEN ? AND ?
        AND s2.payment_status != 'voided'
        $branchFilterS2) as items_sold,
        COALESCE(SUM(s.grand_total), 0) as total_sales,
        (SELECT COUNT(*) FROM sales
        WHERE user_id = u.id
        AND payment_status = 'voided'
        AND DATE(created_at) BETWEEN ? AND ?
        $branchFilterDirect) as cancelled_orders
    FROM users u
    LEFT JOIN sales s ON u.id = s.user_id AND DATE(s.created_at) BETWEEN ? AND ?
        AND s.payment_status != 'voided'
        $branchFilterJoin
    WHERE u.role IN ('admin', 'manager', 'cashier')
        $userFilter
    GROUP BY u.id, u.username, u.full_name
    ORDER BY total_sales DESC",
            $queryParams
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
                'user_id' => $userId,
                'branch_id' => $branchId
            ]
        ];
    }

    public function getRecentSaleLots($limit = 10, $branchId = null)
    {
        $branchFilter = $branchId ? 'AND sl.branch_id = ?' : '';
        $params = [];
        if ($branchId) {
            $params[] = $branchId;  // branch_id first (WHERE clause)
        }
        $params[] = $limit;  // limit last (LIMIT clause)

        return $this->db->fetchAll(
            "SELECT
                sl.*,
                b.name as branch_name,
                u.full_name as created_by_name,
                (sl.total_amount - sl.total_cost) as profit
            FROM sale_lots sl
            LEFT JOIN branches b ON sl.branch_id = b.id
            LEFT JOIN users u ON sl.created_by = u.id
            WHERE 1=1 $branchFilter
            ORDER BY sl.created_at DESC
            LIMIT ?",
            $params
        );
    }

    public function getPurchaseReport($dateFrom, $dateTo, $groupBy = 'day', $branchId = null)
    {
        $branchFilter = $branchId ? 'AND po.branch_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($branchId) {
            $params[] = $branchId;
        }

        switch ($groupBy) {
            case 'day':
                $groupFormat = 'DATE(po.created_at)';
                $labelFormat = 'DATE(po.created_at)';
                break;
            case 'month':
                $groupFormat = "DATE_FORMAT(po.created_at, '%Y-%m')";
                $labelFormat = "DATE_FORMAT(po.created_at, '%Y-%m')";
                break;
            case 'year':
                $groupFormat = 'YEAR(po.created_at)';
                $labelFormat = 'YEAR(po.created_at)';
                break;
            default:
                throw new Exception('Invalid grouping');
        }

        $reportData = $this->db->fetchAll(
            "SELECT
                {$labelFormat} as label,
                COUNT(*) as order_count,
                COALESCE(SUM(po.total_amount), 0) as total_amount,
                COALESCE(AVG(po.total_amount), 0) as avg_amount
            FROM purchase_orders po
            WHERE
                DATE(po.created_at) BETWEEN ? AND ?
                AND po.status = 'completed'
                AND po.source_type = 'manual'
                $branchFilter
            GROUP BY {$groupFormat}
            ORDER BY {$labelFormat} ASC",
            $params
        );

        $totalOrders = 0;
        $totalAmount = 0;

        foreach ($reportData as $row) {
            $totalOrders += $row['order_count'];
            $totalAmount += $row['total_amount'];
        }

        return [
            'report_data' => $reportData,
            'totals' => [
                'total_orders' => $totalOrders,
                'total_amount' => $totalAmount
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by' => $groupBy,
                'branch_id' => $branchId
            ]
        ];
    }

    public function getPurchaseItemReport($dateFrom, $dateTo, $branchId = null)
    {
        $branchFilter = $branchId ? 'AND po.branch_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($branchId) {
            $params[] = $branchId;
        }

        $items = $this->db->fetchAll(
            "SELECT
                DATE(po.created_at) AS purchase_date,
                poi.item_name,
                poi.category_id,
                COALESCE(c.name, 'ไม่ระบุหมวด') AS category_name,
                COALESCE(poi.unit, 'ชิ้น') AS unit,
                COUNT(DISTINCT po.id) AS bill_count,
                COUNT(poi.id) AS line_count,
                COALESCE(SUM(poi.quantity), 0) AS total_quantity,
                COALESCE(SUM(poi.weight_deduction), 0) AS total_deduction,
                COALESCE(SUM(
                    GREATEST(
                        COALESCE(poi.net_quantity, 0),
                        0
                    )
                ), 0) AS net_quantity,
                COALESCE(SUM(poi.total_price), 0) AS total_amount,
                CASE
                    WHEN SUM(
                        GREATEST(
                            COALESCE(poi.net_quantity, 0),
                            0
                        )
                    ) > 0
                    THEN SUM(poi.total_price) / SUM(
                        GREATEST(
                            COALESCE(poi.net_quantity, 0),
                            0
                        )
                    )
                    ELSE 0
                END AS weighted_avg_unit_price
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             LEFT JOIN categories c ON c.id = poi.category_id
             WHERE po.status = 'completed'
               AND po.source_type = 'manual'
               AND DATE(po.created_at) BETWEEN ? AND ?
               $branchFilter
             GROUP BY
                DATE(po.created_at),
                poi.category_id,
                c.name,
                poi.item_name,
                poi.unit
             ORDER BY purchase_date DESC, poi.item_name ASC, poi.unit ASC",
            $params
        );

        $totals = $this->db->fetch(
            "SELECT
                COUNT(DISTINCT po.id) AS total_bills,
                COUNT(poi.id) AS total_rows,
                COALESCE(SUM(
                    GREATEST(
                        COALESCE(poi.net_quantity, 0),
                        0
                    )
                ), 0) AS total_net_quantity,
                COALESCE(SUM(poi.total_price), 0) AS total_amount
             FROM purchase_order_items poi
             JOIN purchase_orders po ON po.id = poi.purchase_order_id
             WHERE po.status = 'completed'
               AND po.source_type = 'manual'
               AND DATE(po.created_at) BETWEEN ? AND ?
               $branchFilter",
            $params
        );

        return [
            'items' => $items ?: [],
            'totals' => [
                'total_bills' => intval($totals['total_bills'] ?? 0),
                'total_rows' => intval($totals['total_rows'] ?? 0),
                'total_net_quantity' => floatval($totals['total_net_quantity'] ?? 0),
                'total_amount' => floatval($totals['total_amount'] ?? 0),
            ],
        ];
    }

    public function getSaleLotReport($dateFrom, $dateTo, $groupBy = 'day', $branchId = null)
    {
        $branchFilter = $branchId ? 'AND sl.branch_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($branchId) {
            $params[] = $branchId;
        }

        switch ($groupBy) {
            case 'day':
                $groupFormat = 'DATE(sl.sale_date)';
                $labelFormat = 'DATE(sl.sale_date)';
                break;
            case 'month':
                $groupFormat = "DATE_FORMAT(sl.sale_date, '%Y-%m')";
                $labelFormat = "DATE_FORMAT(sl.sale_date, '%Y-%m')";
                break;
            case 'year':
                $groupFormat = 'YEAR(sl.sale_date)';
                $labelFormat = 'YEAR(sl.sale_date)';
                break;
            default:
                throw new Exception('Invalid grouping');
        }

        $reportData = $this->db->fetchAll(
            "SELECT
                {$labelFormat} as label,
                COUNT(*) as lot_count,
                COALESCE(SUM(sl.total_amount), 0) as total_amount,
                COALESCE(SUM(sl.total_cost), 0) as total_cost,
                COALESCE(SUM(sl.total_amount - sl.total_cost), 0) as profit
            FROM sale_lots sl
            WHERE
                DATE(sl.sale_date) BETWEEN ? AND ?
                AND sl.status = 'confirmed'
                $branchFilter
            GROUP BY {$groupFormat}
            ORDER BY {$labelFormat} ASC",
            $params
        );

        $totalLots = 0;
        $totalAmount = 0;
        $totalCost = 0;
        $totalProfit = 0;

        foreach ($reportData as $row) {
            $totalLots += $row['lot_count'];
            $totalAmount += $row['total_amount'];
            $totalCost += $row['total_cost'];
            $totalProfit += $row['profit'];
        }

        return [
            'report_data' => $reportData,
            'totals' => [
                'total_lots' => $totalLots,
                'total_amount' => $totalAmount,
                'total_cost' => $totalCost,
                'total_profit' => $totalProfit
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'group_by' => $groupBy,
                'branch_id' => $branchId
            ]
        ];
    }

    public function getSaleLotChartData($period = 'week', $branchId = null)
    {
        $labels = [];
        $amountData = [];
        $profitData = [];

        $branchFilter = $branchId ? 'AND branch_id = ?' : '';

        switch ($period) {
            case 'week':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE(sale_date) as sl_date,
                        COALESCE(SUM(total_amount), 0) as total,
                        COALESCE(SUM(total_amount - total_cost), 0) as profit
                    FROM sale_lots
                    WHERE
                        sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                        AND status = 'confirmed'
                        $branchFilter
                    GROUP BY DATE(sale_date)
                    ORDER BY sl_date ASC",
                    $branchId ? [$branchId] : []
                );

                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $labels[] = $date;
                    $amountData[] = 0;
                    $profitData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['sl_date'], $labels);
                    if ($dateIndex !== false) {
                        $amountData[$dateIndex] = floatval($row['total']);
                        $profitData[$dateIndex] = floatval($row['profit']);
                    }
                }
                break;

            case 'month':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE(sale_date) as sl_date,
                        COALESCE(SUM(total_amount), 0) as total,
                        COALESCE(SUM(total_amount - total_cost), 0) as profit
                    FROM sale_lots
                    WHERE
                        MONTH(sale_date) = MONTH(CURDATE())
                        AND YEAR(sale_date) = YEAR(CURDATE())
                        AND status = 'confirmed'
                        $branchFilter
                    GROUP BY DATE(sale_date)
                    ORDER BY sl_date ASC",
                    $branchId ? [$branchId] : []
                );

                $daysInMonth = date('t');
                for ($i = 1; $i <= $daysInMonth; $i++) {
                    $date = date('Y-m-').sprintf('%02d', $i);
                    $labels[] = $date;
                    $amountData[] = 0;
                    $profitData[] = 0;
                }

                foreach ($result as $row) {
                    $dateIndex = array_search($row['sl_date'], $labels);
                    if ($dateIndex !== false) {
                        $amountData[$dateIndex] = floatval($row['total']);
                        $profitData[$dateIndex] = floatval($row['profit']);
                    }
                }
                break;

            case 'year':
                $result = $this->db->fetchAll(
                    "SELECT
                        DATE_FORMAT(sale_date, '%Y-%m-01') as sl_month,
                        COALESCE(SUM(total_amount), 0) as total,
                        COALESCE(SUM(total_amount - total_cost), 0) as profit
                    FROM sale_lots
                    WHERE
                        YEAR(sale_date) = YEAR(CURDATE())
                        AND status = 'confirmed'
                        $branchFilter
                    GROUP BY sl_month
                    ORDER BY sl_month ASC",
                    $branchId ? [$branchId] : []
                );

                for ($i = 1; $i <= 12; $i++) {
                    $month = date('Y-').sprintf('%02d', $i).'-01';
                    $labels[] = $month;
                    $amountData[] = 0;
                    $profitData[] = 0;
                }

                foreach ($result as $row) {
                    $monthIndex = array_search($row['sl_month'], $labels);
                    if ($monthIndex !== false) {
                        $amountData[$monthIndex] = floatval($row['total']);
                        $profitData[$monthIndex] = floatval($row['profit']);
                    }
                }
                break;
        }

        return [
            'labels' => $labels,
            'amounts' => $amountData,
            'profits' => $profitData
        ];
    }

    /**
     * @param $dateFrom
     * @param $dateTo
     * @param $period
     */
    public function getTaxReport($dateFrom, $dateTo, $period = 'daily', $branchId = null)
    {
        $branchFilter = $branchId ? 'AND branch_id = ?' : '';
        $params = [$dateFrom, $dateTo];
        if ($branchId) {
            $params[] = $branchId;
        }

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
        $branchFilter
    GROUP BY {$groupFormat}
    ORDER BY MIN(created_at) ASC",
    $params
        );

        // Calculate totals
        $totalTaxableSales = 0;
        $totalTaxCollected = 0;

        foreach ($taxData as &$periodRow) {
            $periodRow['taxable_sales'] = floatval($periodRow['taxable_sales']);
            $periodRow['tax_collected'] = floatval($periodRow['tax_collected']);

            $totalTaxableSales += $periodRow['taxable_sales'];
            $totalTaxCollected += $periodRow['tax_collected'];
        }
        unset($periodRow);

        return [
            'periods' => $taxData,
            'totals' => [
                'taxable_sales' => $totalTaxableSales,
                'tax_collected' => $totalTaxCollected
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'period' => $period,
                'branch_id' => $branchId
            ]
        ];
    }
}
