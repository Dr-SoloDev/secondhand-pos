<?php
class PurchaseOrdersController extends Controller
{
    public function getPurchaseOrders()
    {
        $pagination = $this->getPaginationParams();
        $filters = [
            'branch_id' => isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null,
            'date_from' => isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null,
            'date_to' => isset($_GET['date_to']) ? $this->sanitizeInput($_GET['date_to']) : null,
            'search' => isset($_GET['search']) ? $this->sanitizeInput($_GET['search']) : null,
        ];
        $model = new PurchaseOrder();
        $result = $model->getPaginated($pagination['page'], $pagination['limit'], $filters);
        Response::success('Purchase orders retrieved', $result);
    }

    public function getPurchaseOrder($id)
    {
        if (!$id) Response::error('Purchase order ID is required', 400);
        $model = new PurchaseOrder();
        $po = $model->getById($id);
        if (!$po) Response::error('Purchase order not found', 404);
        Response::success('Purchase order retrieved', $po);
    }

    public function createPurchaseOrder()
    {
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['branch_id', 'seller_id', 'items']);

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $cleanData = [
            'branch_id' => intval($data['branch_id']),
            'seller_id' => intval($data['seller_id']),
            'payment_method' => $data['payment_method'] ?? 'cash',
            'payment_status' => $data['payment_status'] ?? 'paid',
            'status' => $data['status'] ?? 'completed',
            'notes' => isset($data['notes']) ? htmlspecialchars(trim($data['notes'])) : null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['item_name']) || empty($item['condition_id'])) {
                Response::error('แต่ละรายการต้องมีชื่อของและสภาพ', 400);
            }
            $cleanItems[] = [
                'item_name' => htmlspecialchars(trim($item['item_name'])),
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                'condition_id' => intval($item['condition_id']),
                'quantity' => floatval($item['quantity'] ?? 1),
                'unit' => $item['unit'] ?? 'ชิ้น',
                'unit_price' => floatval($item['unit_price'] ?? 0),
                'total_price' => floatval($item['total_price'] ?? (floatval($item['quantity'] ?? 1) * floatval($item['unit_price'] ?? 0))),
                'notes' => isset($item['notes']) ? htmlspecialchars(trim($item['notes'])) : null,
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
            Response::error('สร้างใบรับซื้อไม่สำเร็จ: ' . $e->getMessage());
        }
    }
}
