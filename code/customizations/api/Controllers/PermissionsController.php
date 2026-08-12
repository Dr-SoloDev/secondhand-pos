<?php
class PermissionsController extends Controller
{
    public function getPermissions()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $role = (string)($this->user['role'] ?? '');
        $isAdmin = in_array($role, ['admin', 'super_manager'], true); // TEST-MODE: super_manager = admin (ชั่วคราว)
        $isManager = in_array($role, ['manager', 'super_manager', 'admin'], true);
        $isMultiBranch = in_array($role, ['super_manager', 'admin'], true);
        $canManageUsers = in_array($role, ['super_manager', 'admin'], true);

        $pages = [
            'index.html' => true,
            'purchase-orders.html' => true,
            'catalog.html' => true,
            'sellers.html' => true,
            'seller-history.html' => true,
            'inventory.html' => true,
            'sale-lots.html' => $isManager,
            'sales.html' => $isManager,
            'reports.html' => true,
            'cash-sessions.html' => true,
            'employees.html' => $isManager,
            'expenses.html' => true,
            'financial-summary.html' => $isManager,
            'stock-transfers.html' => true,
            'price-board.html' => true,
            'price-tiers.html' => $isAdmin,
            'branches.html' => $isAdmin,
            'users.html' => $canManageUsers,
            'audit-log.html' => $isAdmin,
            'settings.html' => $isManager,
            'print-receipt.html' => true,
        ];

        Response::success('Permissions retrieved', [
            'role' => $role,
            'branch_id' => $this->user['branch_id'] ?? null,
            'multi_branch' => $isMultiBranch,
            'pages' => $pages,
            'actions' => [
                'purchase_orders' => [
                    'read' => true, 'create' => true, 'request_cancel' => true,
                    'review_cancel' => $isManager, 'self_approve' => $isAdmin,
                ],
                'catalog' => [
                    'read' => true, 'create' => true, 'update' => true,
                    'manage_categories' => $isManager, 'delete' => $isAdmin,
                ],
                'sellers' => [
                    'read' => true, 'create' => true, 'update' => true, 'set_tier' => true,
                    'blacklist' => $isManager,
                ],
                'inventory' => ['read' => true, 'direct_adjust' => $isAdmin],
                'sale_lots' => ['read' => $isManager, 'manage' => $isManager],
                'employees' => [
                    'read' => $isManager, 'manage' => $isManager,
                    'delete' => in_array($role, ['super_manager', 'admin'], true),
                ],
                'expenses' => ['read' => true, 'create' => true, 'review' => $isManager],
                'reports' => ['operational' => true, 'full' => $isManager],
                'stock_transfers' => ['read' => true, 'create' => true, 'receive' => true, 'review' => $isManager],
                'price_board' => ['read' => true, 'edit' => $isAdmin],
                'cash_sessions' => [
                    'read' => true, 'open_close' => true, 'review' => $isManager, 'reopen' => $isAdmin,
                ],
                'adjustments' => ['manage' => $isAdmin],
                'users' => ['read' => $canManageUsers, 'manage_lower_roles' => $canManageUsers, 'manage_elevated_roles' => $isAdmin],
                'settings' => ['branch' => $isManager, 'global' => $isAdmin, 'backup' => $isAdmin],
                'audit' => ['read' => $isAdmin, 'export' => $isAdmin],
            ],
        ]);
    }
}
