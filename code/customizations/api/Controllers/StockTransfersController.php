<?php
class StockTransfersController extends Controller
{
    public function index()
    {
        $this->requireAuth();
        $user = $this->user;
        $filters = [];
        if (!in_array(($user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $branchId = (int)($user['branch_id'] ?? 0);
            if (!$branchId) {
                Response::error('บัญชีผู้ใช้ยังไม่ได้กำหนดสาขา', 403);
                return;
            }
            $filters['branch_id'] = $branchId;
        }
        if (!empty($_GET['status'])) {
            $filters['status'] = $this->sanitizeInput($_GET['status']);
        }
        if (!empty($_GET['transfer_type'])) {
            $filters['transfer_type'] = $this->sanitizeInput($_GET['transfer_type']);
        }
        if (!empty($_GET['approval_status'])) {
            $filters['approval_status'] = $this->sanitizeInput($_GET['approval_status']);
        }
        Response::success('สำเร็จ', ['items' => (new StockTransfer())->getAll($filters)]);
    }

    public function store()
    {
        $this->requireAuth(['admin', 'manager', 'cashier', 'super_manager']);
        $user = $this->user;
        $body = $this->getRequestData() ?? [];

        $fromId = (int)($body['from_branch_id'] ?? 0);
        $toId = (int)($body['to_branch_id'] ?? 0);
        $userBranch = (int)($user['branch_id'] ?? 0);

        if (!in_array(($user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            if (!$userBranch) {
                Response::error('บัญชีผู้ใช้ยังไม่ได้กำหนดสาขา', 403);
                return;
            }
            if ($fromId && $fromId !== $userBranch) {
                Response::error('ไม่มีสิทธิ์โอนสต็อกจากสาขาอื่น', 403);
                return;
            }
            $fromId = $userBranch;
        }

        if (!$fromId || !$toId) {
            Response::error('ข้อมูลไม่ครบ', 400);
            return;
        }
        if ($fromId === $toId) {
            Response::error('ต้นทางและปลายทางต้องต่างกัน', 400);
            return;
        }

        try {
            $payload = [
                'from_branch_id' => $fromId,
                'to_branch_id' => $toId,
                'note' => isset($body['note']) ? substr(trim((string)$body['note']), 0, 255) : null,
                'transporter_name' => isset($body['transporter_name']) ? substr(trim((string)$body['transporter_name']), 0, 100) : null,
                'vehicle_plate' => isset($body['vehicle_plate']) ? substr(trim((string)$body['vehicle_plate']), 0, 20) : null,
            ];

            if (!empty($body['items']) && is_array($body['items'])) {
                $items = [];
                $seenItems = [];
                foreach (array_values($body['items']) as $index => $item) {
                    if (!is_array($item)) {
                        Response::error('ข้อมูลรายการสินค้าไม่ถูกต้อง', 400);
                        return;
                    }
                    $categoryId = (int)($item['category_id'] ?? 0);
                    $itemName = substr(trim((string)($item['item_name'] ?? '')), 0, 200);
                    $weightKg = isset($item['weight_kg']) ? (float)$item['weight_kg'] : 0;
                    if (!$categoryId || $itemName === '' || $weightKg <= 0 || !is_finite($weightKg)) {
                        Response::error('ข้อมูลรายการสินค้าไม่ครบ', 400);
                        return;
                    }
                    $itemKey = $categoryId . ':' . mb_strtolower($itemName, 'UTF-8');
                    if (isset($seenItems[$itemKey])) {
                        Response::error("รายการ {$itemName} ซ้ำ กรุณารวมเป็นรายการเดียว", 400);
                        return;
                    }
                    $seenItems[$itemKey] = true;
                    $items[] = [
                        'line_no' => $index + 1,
                        'category_id' => $categoryId,
                        'item_name' => $itemName,
                        'weight_kg' => $weightKg,
                    ];
                }
                if (empty($items)) {
                    Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
                    return;
                }
                $payload['items'] = $items;
            } else {
                $catId = (int)($body['category_id'] ?? 0);
                $itemName = substr(trim((string)($body['item_name'] ?? '')), 0, 200);
                $weight = isset($body['weight_kg']) ? (float)$body['weight_kg'] : 0;
                if (!$catId || $itemName === '' || $weight <= 0 || !is_finite($weight)) {
                    Response::error('ข้อมูลไม่ครบ', 400);
                    return;
                }
                $payload['category_id'] = $catId;
                $payload['item_name'] = $itemName;
                $payload['weight_kg'] = $weight;
            }

            $result = (new StockTransfer())->create($payload, $user['id'] ?? $user['user_id']);
            $itemCount = !empty($payload['items']) ? count($payload['items']) : 1;
            Logger::logActivity(
                $user['user_id'] ?? $user['id'],
                'create_stock_transfer',
                "โอนสต็อก from:{$fromId} to:{$toId} items:{$itemCount} ref:{$result['reference_no']}"
            );
            Response::success('สร้างใบโอนแล้ว', $result);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function confirm()
    {
        $this->requireAuth(['admin', 'manager', 'cashier', 'super_manager']);
        $user = $this->user;
        $body = $this->getRequestData() ?? [];
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            Response::error('ไม่พบ id', 400);
            return;
        }

        $receivedItems = (!empty($body['items']) && is_array($body['items'])) ? array_values($body['items']) : [];
        $receivedWeight = isset($body['received_weight_kg']) && is_numeric($body['received_weight_kg']) ? (float)$body['received_weight_kg'] : null;
        $receiveNote = isset($body['receive_note']) ? substr(trim((string)$body['receive_note']), 0, 500) : null;

        $transfer = new StockTransfer();
        $st = $transfer->findById($id);
        if (!$st) {
            Response::error('ไม่พบใบโอน', 404);
            return;
        }

        if (($st['transfer_type'] ?? 'normal') === 'reversal') {
            Response::error('ใบโอนย้อนกลับต้องผ่านเมนูอนุมัติของผู้ดูแลระบบ', 403);
            return;
        }

        if (!in_array(($user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = (int)($user['branch_id'] ?? 0);
            if (!$userBranch || (int)$st['to_branch_id'] !== $userBranch) {
                Response::error('ไม่มีสิทธิ์ตรวจรับใบโอนนี้', 403);
                return;
            }
        }

        try {
            $confirmPayload = [
                'items' => $receivedItems,
                'received_weight_kg' => $receivedWeight,
                'receive_note' => $receiveNote,
            ];
            $transfer->confirm($id, $user['id'] ?? $user['user_id'], $confirmPayload);
            Logger::logActivity(
                $user['user_id'] ?? $user['id'],
                'confirm_stock_transfer',
                "ตรวจรับใบโอนสต็อก ID:{$id} by:" . ($user['username'] ?? ($user['full_name'] ?? 'unknown'))
            );
            Response::success('ตรวจรับแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function cancel()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        $user = $this->user;
        $body = $this->getRequestData() ?? [];
        $id = (int)($body['id'] ?? 0);
        if (!$id) {
            Response::error('ไม่พบ id', 400);
            return;
        }

        $transfer = new StockTransfer();
        if (!in_array(($user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $st = $transfer->findById($id);
            if (!$st) {
                Response::error('ไม่พบใบโอน', 404);
                return;
            }
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

    public function requestReversal()
    {
        $this->requireAuth(['admin', 'manager', 'super_manager']);
        $user = $this->user;
        $body = $this->getRequestData() ?? [];
        $originalId = (int)($body['id'] ?? $body['original_transfer_id'] ?? 0);
        $reason = substr(trim((string)($body['reason'] ?? '')), 0, 500);
        $items = (!empty($body['items']) && is_array($body['items'])) ? array_values($body['items']) : [];
        if (!$originalId || $reason === '') {
            Response::error('กรุณาระบุใบโอนต้นฉบับและเหตุผล', 400);
            return;
        }

        $transfer = new StockTransfer();
        $original = $transfer->findById($originalId);
        if (!$original) {
            Response::error('ไม่พบใบโอนต้นฉบับ', 404);
            return;
        }
        if (!in_array(($user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = (int)($user['branch_id'] ?? 0);
            if (!$userBranch || (int)$original['to_branch_id'] !== $userBranch) {
                Response::error('ไม่มีสิทธิ์ขอโอนย้อนกลับจากสาขานี้', 403);
                return;
            }
        }

        try {
            $userId = (int)($user['user_id'] ?? $user['id']);
            $result = $transfer->requestReversal($originalId, $items, $reason, $userId);
            Logger::logActivity(
                $userId,
                'request_stock_transfer_reversal',
                "ขอโอนย้อนกลับ original:{$originalId} reversal:{$result['id']} reason:{$reason}"
            );
            Response::success('ส่งคำขอโอนย้อนกลับเพื่อรอผู้ดูแลระบบอนุมัติแล้ว', $result);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function approveReversal()
    {
        $this->requireAuth(['admin']);
        $user = $this->user;
        $body = $this->getRequestData() ?? [];
        $id = (int)($body['id'] ?? 0);
        $reviewNote = isset($body['review_note']) ? substr(trim((string)$body['review_note']), 0, 500) : null;
        if (!$id) {
            Response::error('ไม่พบ id', 400);
            return;
        }

        try {
            $userId = (int)($user['user_id'] ?? $user['id']);
            (new StockTransfer())->approveReversal($id, $userId, $reviewNote);
            Logger::logActivity($userId, 'approve_stock_transfer_reversal', "อนุมัติใบโอนย้อนกลับ ID:{$id}");
            Response::success('อนุมัติและโอนสต็อกย้อนกลับแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function rejectReversal()
    {
        $this->requireAuth(['admin']);
        $user = $this->user;
        $body = $this->getRequestData() ?? [];
        $id = (int)($body['id'] ?? 0);
        $reviewNote = substr(trim((string)($body['review_note'] ?? '')), 0, 500);
        if (!$id || $reviewNote === '') {
            Response::error('กรุณาระบุรายการและเหตุผลที่ปฏิเสธ', 400);
            return;
        }

        try {
            $userId = (int)($user['user_id'] ?? $user['id']);
            (new StockTransfer())->rejectReversal($id, $userId, $reviewNote);
            Logger::logActivity($userId, 'reject_stock_transfer_reversal', "ปฏิเสธคำขอโอนย้อนกลับ ID:{$id}");
            Response::success('ปฏิเสธคำขอโอนย้อนกลับแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }
}
