<?php
class Router
{
    /**
     * @var array
     */
    private $routes = [];
    /**
     * @var mixed
     */
    private $user = null;

    public function __construct()
    {
        // Get base path
        $basePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__);
        define('BASE_PATH', $basePath);

        // Check authentication
        $this->checkAuth();
    }

    private function checkAuth()
    {
        // Public routes - no authentication required
        $publicRoutes = [
            'auth/login' => true,
            'auth/verify' => true
        ];

        // Get request URI and method
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (strpos($requestUri, BASE_PATH) === 0) {
            $requestUri = substr($requestUri, strlen(BASE_PATH));
        }
        $requestUri = str_replace('index.php', '', $requestUri);

        // Parse the URI
        $uriParts = explode('/', trim($requestUri, '/'));
        $module = $uriParts[0] ?? '';
        $action = $uriParts[1] ?? '';

        if (!isset($publicRoutes["$module/$action"])) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';

            if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
                Response::error('Authentication required', 401);
                exit;
            }

            $token = substr($authHeader, 7);
            $decoded = TokenService::validate($token);

            if (!$decoded) {
                Response::error('Invalid or expired token', 401);
                exit;
            }

            // Store user data
            $this->user = $decoded;
        }
    }

    public function registerRoutes()
    {
        // Auth routes
        $this->routes[] = ['route' => 'auth/login', 'controller' => 'AuthController', 'method' => 'login'];
        $this->routes[] = ['route' => 'auth/verify', 'controller' => 'AuthController', 'method' => 'verify'];

        // Inventory routes
        $this->routes[] = ['route' => 'inventory/categories', 'controller' => 'InventoryController', 'method' => 'getCategories', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/categories', 'controller' => 'InventoryController', 'method' => 'createCategory', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'inventory/set-threshold', 'controller' => 'InventoryController', 'method' => 'setThreshold', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'inventory/stock-alerts', 'controller' => 'InventoryController', 'method' => 'getStockAlerts', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/category', 'controller' => 'InventoryController', 'method' => 'getCategory', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/category', 'controller' => 'InventoryController', 'method' => 'updateCategory', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'inventory/category', 'controller' => 'InventoryController', 'method' => 'deleteCategory', 'verb' => 'DELETE'];

        // Product routes
        $this->routes[] = ['route' => 'inventory/products', 'controller' => 'InventoryController', 'method' => 'getProducts', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/products', 'controller' => 'InventoryController', 'method' => 'createProduct', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'inventory/product', 'controller' => 'InventoryController', 'method' => 'getProduct', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/product', 'controller' => 'InventoryController', 'method' => 'updateProduct', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'inventory/product', 'controller' => 'InventoryController', 'method' => 'deleteProduct', 'verb' => 'DELETE'];
        $this->routes[] = ['route' => 'inventory/low-stock', 'controller' => 'InventoryController', 'method' => 'getLowStock', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/transactions', 'controller' => 'InventoryController', 'method' => 'getTransactions', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'inventory/transactions', 'controller' => 'InventoryController', 'method' => 'createTransaction', 'verb' => 'POST'];

        // Sales routes
        $this->routes[] = ['route' => 'sales/create', 'controller' => 'SalesController', 'method' => 'createSale', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sales/list', 'controller' => 'SalesController', 'method' => 'getSales', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sales/details', 'controller' => 'SalesController', 'method' => 'getSaleDetails', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sales/void', 'controller' => 'SalesController', 'method' => 'voidSale', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sales/export', 'controller' => 'SalesController', 'method' => 'exportSales', 'verb' => 'GET'];

        // Reports routes
        $this->routes[] = ['route' => 'reports/dashboard-stats', 'controller' => 'ReportsController', 'method' => 'getDashboardStats', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/sales-chart', 'controller' => 'ReportsController', 'method' => 'getSalesChart', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/recent-sales', 'controller' => 'ReportsController', 'method' => 'getRecentSales', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/purchase-chart', 'controller' => 'ReportsController', 'method' => 'getPurchaseChart', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/recent-purchases', 'controller' => 'ReportsController', 'method' => 'getRecentPurchases', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/recent-sale-lots', 'controller' => 'ReportsController', 'method' => 'getRecentSaleLots', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/sales-report', 'controller' => 'ReportsController', 'method' => 'getSalesReport', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/product-sales', 'controller' => 'ReportsController', 'method' => 'getProductSales', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/inventory-report', 'controller' => 'ReportsController', 'method' => 'getInventoryReport', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/cashier-performance', 'controller' => 'ReportsController', 'method' => 'getCashierPerformance', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/tax-report', 'controller' => 'ReportsController', 'method' => 'getTaxReport', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/purchase-report', 'controller' => 'ReportsController', 'method' => 'getPurchaseReport', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/sale-lot-report', 'controller' => 'ReportsController', 'method' => 'getSaleLotReport', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'reports/sale-lot-chart', 'controller' => 'ReportsController', 'method' => 'getSaleLotChart', 'verb' => 'GET'];

        // Users routes
        $this->routes[] = ['route' => 'users/all', 'controller' => 'UsersController', 'method' => 'getAllUsers', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'users', 'controller' => 'UsersController', 'method' => 'createUser', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'users/user', 'controller' => 'UsersController', 'method' => 'getUser', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'users/user', 'controller' => 'UsersController', 'method' => 'updateUser', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'users/user', 'controller' => 'UsersController', 'method' => 'deleteUser', 'verb' => 'DELETE'];
        $this->routes[] = ['route' => 'users/change-password', 'controller' => 'UsersController', 'method' => 'changePassword', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'users/activity-log', 'controller' => 'UsersController', 'method' => 'getActivityLog', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'users/profile', 'controller' => 'UsersController', 'method' => 'getProfile', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'users/profile', 'controller' => 'UsersController', 'method' => 'updateProfile', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'users/change-own-password', 'controller' => 'UsersController', 'method' => 'changeOwnPassword', 'verb' => 'POST'];

        // Settings routes
        $this->routes[] = ['route' => 'settings/store', 'controller' => 'SettingsController', 'method' => 'getStoreSettings', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'settings/store', 'controller' => 'SettingsController', 'method' => 'saveStoreSettings', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'settings/system', 'controller' => 'SettingsController', 'method' => 'getSystemSettings', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'settings/system', 'controller' => 'SettingsController', 'method' => 'saveSystemSettings', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'settings/backup/create', 'controller' => 'SettingsController', 'method' => 'createBackup', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'settings/backup/restore', 'controller' => 'SettingsController', 'method' => 'restoreBackup', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'settings/backup/history', 'controller' => 'SettingsController', 'method' => 'getBackupHistory', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'settings/backup/download', 'controller' => 'SettingsController', 'method' => 'downloadBackup', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'settings/backup/delete', 'controller' => 'SettingsController', 'method' => 'deleteBackup', 'verb' => 'POST'];

        // Customers routes
        $this->routes[] = ['route' => 'customers', 'controller' => 'CustomersController', 'method' => 'getCustomers', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'customers', 'controller' => 'CustomersController', 'method' => 'createCustomer', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'customers/customer', 'controller' => 'CustomersController', 'method' => 'getCustomer', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'customers/customer', 'controller' => 'CustomersController', 'method' => 'updateCustomer', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'customers/customer', 'controller' => 'CustomersController', 'method' => 'deleteCustomer', 'verb' => 'DELETE'];

        // Branches routes
        $this->routes[] = ['route' => 'branches', 'controller' => 'BranchesController', 'method' => 'getBranches', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'branches', 'controller' => 'BranchesController', 'method' => 'createBranch', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'branches/active', 'controller' => 'BranchesController', 'method' => 'getActiveBranches', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'branches/summary', 'controller' => 'BranchesController', 'method' => 'getBranchSummary', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'branches/branch', 'controller' => 'BranchesController', 'method' => 'getBranch', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'branches/branch', 'controller' => 'BranchesController', 'method' => 'updateBranch', 'verb' => 'PUT'];

        // Sellers routes
        $this->routes[] = ['route' => 'sellers', 'controller' => 'SellersController', 'method' => 'getSellers', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sellers', 'controller' => 'SellersController', 'method' => 'createSeller', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sellers/search', 'controller' => 'SellersController', 'method' => 'searchSellers', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sellers/seller', 'controller' => 'SellersController', 'method' => 'getSeller', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sellers/seller', 'controller' => 'SellersController', 'method' => 'updateSeller', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'sellers/blacklist', 'controller' => 'SellersController', 'method' => 'blacklistSeller', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sellers/unblacklist', 'controller' => 'SellersController', 'method' => 'unblacklistSeller', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sellers/history', 'controller' => 'SellersController', 'method' => 'getSellerHistory', 'verb' => 'GET'];

        // Purchase Orders routes
        $this->routes[] = ['route' => 'purchase-orders', 'controller' => 'PurchaseOrdersController', 'method' => 'getPurchaseOrders', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'purchase-orders', 'controller' => 'PurchaseOrdersController', 'method' => 'createPurchaseOrder', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'purchase-orders/order', 'controller' => 'PurchaseOrdersController', 'method' => 'getPurchaseOrder', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'purchase-orders/cancel', 'controller' => 'PurchaseOrdersController', 'method' => 'cancelPurchaseOrder', 'verb' => 'POST'];

        // Item Conditions routes
        $this->routes[] = ['route' => 'item-conditions', 'controller' => 'ItemConditionsController', 'method' => 'getConditions', 'verb' => 'GET'];

        // Price Tiers routes
        $this->routes[] = ['route' => 'price-tiers', 'controller' => 'PriceTiersController', 'method' => 'getPriceTiers', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'price-tiers', 'controller' => 'PriceTiersController', 'method' => 'createCatalogItem', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'price-tiers/category', 'controller' => 'PriceTiersController', 'method' => 'updatePriceTiers', 'verb' => 'PUT'];

        // Purchase Item Catalog routes
        $this->routes[] = ['route' => 'purchase-catalog', 'controller' => 'PurchaseItemCatalogController', 'method' => 'getCatalog', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'purchase-catalog', 'controller' => 'PurchaseItemCatalogController', 'method' => 'createItem', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'purchase-catalog/search', 'controller' => 'PurchaseItemCatalogController', 'method' => 'searchCatalog', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'purchase-catalog/item', 'controller' => 'PurchaseItemCatalogController', 'method' => 'getItem', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'purchase-catalog/item', 'controller' => 'PurchaseItemCatalogController', 'method' => 'updateItem', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'purchase-catalog/item', 'controller' => 'PurchaseItemCatalogController', 'method' => 'deleteItem', 'verb' => 'DELETE'];
        $this->routes[] = ['route' => 'purchase-catalog/update-category', 'controller' => 'PurchaseItemCatalogController', 'method' => 'updateCategory', 'verb' => 'POST'];

        // Sale Lots routes
        $this->routes[] = ['route' => 'sale-lots', 'controller' => 'SaleLotsController', 'method' => 'index', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sale-lots', 'controller' => 'SaleLotsController', 'method' => 'store', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sale-lots/sale-lot', 'controller' => 'SaleLotsController', 'method' => 'show', 'verb' => 'GET'];
        $this->routes[] = ['route' => 'sale-lots/sale-lot', 'controller' => 'SaleLotsController', 'method' => 'update', 'verb' => 'PUT'];
        $this->routes[] = ['route' => 'sale-lots/sale-lot', 'controller' => 'SaleLotsController', 'method' => 'destroy', 'verb' => 'DELETE'];
        $this->routes[] = ['route' => 'sale-lots/confirm', 'controller' => 'SaleLotsController', 'method' => 'confirm', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sale-lots/cancel', 'controller' => 'SaleLotsController', 'method' => 'cancel', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'sale-lots/record-revenue', 'controller' => 'SaleLotsController', 'method' => 'recordRevenue', 'verb' => 'POST'];

        // Financial Summary (admin only)
        $this->routes[] = ['route' => 'financial/summary',              'controller' => 'FinancialController', 'method' => 'summary',             'verb' => 'GET'];
        $this->routes[] = ['route' => 'financial/lot-revenues',         'controller' => 'FinancialController', 'method' => 'lotRevenues',          'verb' => 'GET'];
        $this->routes[] = ['route' => 'financial/purchase-by-category', 'controller' => 'FinancialController', 'method' => 'purchaseByCategory',   'verb' => 'GET'];
        $this->routes[] = ['route' => 'financial/expenses',             'controller' => 'FinancialController', 'method' => 'listExpenses',         'verb' => 'GET'];
        $this->routes[] = ['route' => 'financial/expenses',             'controller' => 'FinancialController', 'method' => 'createExpense',        'verb' => 'POST'];
        $this->routes[] = ['route' => 'financial/expenses',             'controller' => 'FinancialController', 'method' => 'deleteExpense',        'verb' => 'DELETE'];
        $this->routes[] = ['route' => 'financial/export',               'controller' => 'FinancialController', 'method' => 'exportCsv',            'verb' => 'GET'];
        $this->routes[] = ['route' => 'purchase-catalog/price-board',   'controller' => 'PurchaseItemCatalogController', 'method' => 'getPriceBoard', 'verb' => 'GET'];
        // Stock Transfers
        $this->routes[] = ['route' => 'stock-transfers',         'controller' => 'StockTransfersController', 'method' => 'index',   'verb' => 'GET'];
        $this->routes[] = ['route' => 'stock-transfers',         'controller' => 'StockTransfersController', 'method' => 'store',   'verb' => 'POST'];
        $this->routes[] = ['route' => 'stock-transfers/confirm', 'controller' => 'StockTransfersController', 'method' => 'confirm', 'verb' => 'POST'];
        $this->routes[] = ['route' => 'stock-transfers/cancel',  'controller' => 'StockTransfersController', 'method' => 'cancel',  'verb' => 'POST'];
    }

    public function dispatch()
    {
        // Get request URI and method
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];
        if (strpos($requestUri, BASE_PATH) === 0) {
            $requestUri = substr($requestUri, strlen(BASE_PATH));
        }
        $requestUri = str_replace('index.php', '', $requestUri);

        // Parse the URI
        $routeKey = trim($requestUri, '/');

        // Find matching route with correct HTTP verb
        $matchedRoute = null;
        foreach ($this->routes as $config) {
            if ($config['route'] === $routeKey &&
                (!isset($config['verb']) || $config['verb'] === $requestMethod)) {
                $matchedRoute = $config;
                break;
            }
        }

        if (!$matchedRoute) {
            Response::error('Endpoint not found or method not allowed', 404);
            exit;
        }

        // Get ID from query string or URL path
        $uriParts = explode('/', trim($requestUri, '/'));
        $pathId = $uriParts[2] ?? null;
        $queryId = isset($_GET['id']) ? $_GET['id'] : null;
        $id = $pathId ?? $queryId;

        // Convert ID to integer if numeric
        if ($id !== null && is_numeric($id)) {
            $id = intval($id);
        }

        // Instantiate controller and execute method
        $controllerName = $matchedRoute['controller'];
        $methodName = $matchedRoute['method'];

        if (!class_exists($controllerName)) {
            Response::error("Controller not found", 500);
            exit;
        }

        $controller = new $controllerName($this->user);

        if (!method_exists($controller, $methodName)) {
            Response::error("Method not found in controller", 500);
            exit;
        }

        $controller->$methodName($id);
    }
}
