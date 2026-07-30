<?php
class StockTransfersController extends Controller
{
    public function index()
    {
        $this->requireAuth();
        $user = $this->user;
        $filters = [];
        if ($user['role'] !== 'admin') {
            $branchId = (int)($user['branch_id'] ?? 0);
            if (!$branchId) {
                Response::error('บัญชีผู้ใช้ยังไม่ได้กำหนดสาขา', 403);
                return;
            }
            $filters['branch_id'] = $branchId;
        }
        if (!empty($_GET['status'])) $filters['status'] = $this->sanitizeInput($_GET['status']);
        Response::success('สำเร็จ', ['items' => (new StockTransfer())->getAll($filters)]);
    }

    public function store()
    {
        $this->requireAuth(['admin', 'manager']);
        $user = $this->user;
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $fromId   = intval($body['from_branch_id'] ?? 0);
        $toId     = intval($body['to_branch_id']   ?? 0);
        $catId    = intval($body['category_id']    ?? 0);
        $itemName = substr(trim((string)($body['item_name'] ?? '')), 0, 200);
        $weight   = floatval($body['weight_kg']    ?? 0);

        if (!$fromId || !$toId || !$catId || $itemName === '' || $weight <= 0)
            { Response::error('ข้อมูลไม่ครบ', 400); return; }
        if ($fromId === $toId)
            { Response::error('ต้นทางและปลายทางต้องต่างกัน', 400); return; }

        // SECURITY: non-admin สร้างได้เฉพาะโอนจากสาขาตัวเอง
        if (($user['role'] ?? '') !== 'admin') {
            $userBranch = $user['branch_id'] ?? $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$fromId !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์โอนสต็อกจากสาขาอื่น', 403);
                return;
            }
        }

        $result = (new StockTransfer())->create([
            'from_branch_id'   => $fromId,
            'to_branch_id'     => $toId,
            'category_id'      => $catId,
            'item_name'        => $itemName,
            'weight_kg'        => $weight,
            'note'             => isset($body['note']) ? substr(trim($body['note']), 0, 255) : null,
            'transporter_name' => isset($body['transporter_name']) ? substr(trim($body['transporter_name']), 0, 100) : null,
            'vehicle_plate'    => isset($body['vehicle_plate']) ? substr(trim($body['vehicle_plate']), 0, 20) : null,
        ], $user['id'] ?? $user['user_id']);

        Logger::logActivity($user['user_id'] ?? $user['id'], 'create_stock_transfer', "โอนสต็อก from:{$fromId} to:{$toId} item:{$itemName} cat:{$catId} {$weight}kg ref:{$result['reference_no']}");
        Response::success('สร้างใบโอนแล้ว', $result);
    }

    public function confirm()
    {
        $this->requireAuth(['admin', 'manager']);
        $user = $this->user;
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id             = intval($body['id'] ?? 0);
        $receivedWeight = isset($body['received_weight_kg']) ? floatval($body['received_weight_kg']) : null;
        $receiveNote    = isset($body['receive_note']) ? substr(trim($body['receive_note']), 0, 500) : null;
        if (!$id) { Response::error('ไม่พบ id', 400); return; }
        if ($receivedWeight !== null && ($receivedWeight <= 0 || !is_finite($receivedWeight))) {
            Response::error('น้ำหนักรับต้องมากกว่า 0', 400); return;
        }

        // SECURITY: non-admin ยืนยันได้เฉพาะใบโอนที่ปลายทางเป็นสาขาตัวเอง
        if (($user['role'] ?? '') !== 'admin') {
            $st = (new StockTransfer())->findById($id);
            if (!$st) { Response::error('ไม่พบใบโอน', 404); return; }
            $userBranch = $user['branch_id'] ?? null;
            if (!$userBranch || (int)$st['to_branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์ตรวจรับใบโอนนี้', 403); return;
            }
        }

        try {
            (new StockTransfer())->confirm($id, $user['id'] ?? $user['user_id'], $receivedWeight, $receiveNote);
            Logger::logActivity($user['user_id'] ?? $user['id'], 'confirm_stock_transfer', "ตรวจรับใบโอนสต็อก ID:{$id} น้ำหนัก:" . ($receivedWeight ?? 'ตามใบ'));
            Response::success('ตรวจรับแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function cancel()
    {
        $this->requireAuth(['admin', 'manager']);
        $user = $this->user;
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = intval($body['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }

        $transfer = new StockTransfer();
        if (($user['role'] ?? '') !== 'admin') {
            $st = $transfer->findById($id);
            if (!$st) { Response::error('ไม่พบใบโอน', 404); return; }
            $userBranch = (int)($user['branch_id'] ?? 0);
            if (!$userBranch || (int)$st['from_branch_id'] !== $userBranch) {
                Response::error('ไม่มีสิทธิ์ยกเลิกใบโอนนี้', 403);
                return;
            }
        }

        try {
            $transfer->cancel($id);
            Logger::logActivity($user['user_id'] ?? $user['id'], 'cancel_stock_transfer', "ยกเลิกใบโอนสต็อก ID:{$id}");
            Response::success('ยกเลิกแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
