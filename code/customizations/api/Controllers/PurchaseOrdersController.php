<?php
class PurchaseOrdersController extends Controller
{
    public function getPurchaseOrders()
    {
        $this->requireAuth();

        $pagination = $this->getPaginationParams();
        $filters = [
            'branch_id' => isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null,
            'date_from' => isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null,
            'date_to' => isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : null,
            'search' => isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null,
        ];

        // SECURITY: non-admin บังคับ scope ที่ branch ของตัวเองเสมอ
        if (($this->user['role'] ?? '') !== 'admin') {
            $filters['branch_id'] = $this->user['branch_id'] ?? null;
            if (!$filters['branch_id']) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
        }

        $model = new PurchaseOrder();
        $result = $model->getPaginated($pagination['page'], $pagination['limit'], $filters);
        Response::success('Purchase orders retrieved', $result);
    }

    public function getPurchaseOrder($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('Purchase order ID is required', 400);

        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) Response::error('Purchase order not found', 404);

        // SECURITY: non-admin ดูได้เฉพาะ PO ของสาขาตัวเอง
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$po['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์เข้าถึงใบรับซื้อนี้', 403);
            }
        }

        Response::success('Purchase order retrieved', $po);
    }

    public function cancelPurchaseOrder($id = null)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) {
            $data = $this->getRequestData();
            $id = isset($data['id']) ? intval($data['id']) : 0;
        }
        if (!$id) Response::error('ต้องระบุรหัสใบรับซื้อ', 400);

        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) Response::error('ไม่พบใบรับซื้อ', 404);
        if ($po['status'] === 'cancelled') Response::error('ใบรับซื้อยกเลิกไปแล้ว', 400);

        try {
            $model->cancel($id);
            Logger::logActivity(
                $this->user['user_id'],
                'cancel_purchase_order',
                "Cancelled PO: {$po['reference_no']} (ID: {$id})"
            );
            Response::success('ยกเลิกใบรับซื้อสำเร็จ');
        } catch (Exception $e) {
            error_log('PurchaseOrder cancel failed: ' . $e->getMessage());
            Response::error('ยกเลิกใบรับซื้อไม่สำเร็จ: ' . $e->getMessage(), 500);
        }
    }

    public function createPurchaseOrder()
    {
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['branch_id', 'seller_id', 'items']);

        // SECURITY: non-admin สร้างได้เฉพาะสาขาตัวเอง
        $branchId = intval($data['branch_id']);
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || $branchId !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์สร้างใบรับซื้อในสาขานี้', 403);
            }
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $cleanData = [
            'branch_id' => intval($data['branch_id']),
            'seller_id' => intval($data['seller_id']),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'payment_status' => $data['payment_status'] ?? 'paid',
            'status' => $data['status'] ?? 'completed',
            'notes' => isset($data['notes']) ? trim((string)$data['notes']) : null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['item_name'])) {
                Response::error('แต่ละรายการต้องมีชื่อของ', 400);
            }
            $cleanItems[] = [
                'item_name' => trim((string)$item['item_name']),
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                // DEPRECATED — condition_id ไม่ใช้แล้ว ใช้ weight_deduction แทน
                'weight_deduction' => floatval($item['weight_deduction'] ?? 0),
                'quantity' => floatval($item['quantity'] ?? 1),
                'unit' => $item['unit'] ?? 'ชิ้น',
                'unit_price' => floatval($item['unit_price'] ?? 0),
                'total_price' => floatval($item['total_price'] ?? (floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0))),
                'price_tier' => !empty($item['price_tier']) ? intval($item['price_tier']) : null,
                'notes' => isset($item['notes']) ? trim((string)$item['notes']) : null,
            ];
        }

        $model = new PurchaseOrder();
        try {
            $result = $model->createWithItems($cleanData, $cleanItems, $this->user['user_id']);
            Logger::logActivity(
                $this->user['user_id'],
                'create_purchase_order',
                "Created PO: {$result['reference_no']} (฿{$result['total_amount']})"
            );
            Response::success('สร้างใบรับซื้อสำเร็จ', $result);
        } catch (Exception $e) {
            error_log('PurchaseOrder create failed: ' . $e->getMessage());
            Response::error('สร้างใบรับซื้อไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }
}
