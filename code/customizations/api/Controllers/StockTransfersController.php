<?php
class StockTransfersController extends Controller
{
    public function index()
    {
        $this->requireAuth();
        $user = $this->user;
        $filters = [];
        if ($user['role'] !== 'admin') {
            $filters['from_branch_id'] = $user['branch_id'];
        }
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];
        Response::success('สำเร็จ', ['items' => (new StockTransfer())->getAll($filters)]);
    }

    public function store()
    {
        $user = $this->requireAuth();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $fromId   = intval($body['from_branch_id'] ?? 0);
        $toId     = intval($body['to_branch_id']   ?? 0);
        $catId    = intval($body['category_id']    ?? 0);
        $weight   = floatval($body['weight_kg']    ?? 0);

        if (!$fromId || !$toId || !$catId || $weight <= 0)
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
            'from_branch_id' => $fromId,
            'to_branch_id'   => $toId,
            'category_id'    => $catId,
            'weight_kg'      => $weight,
            'note'           => isset($body['note']) ? substr(trim($body['note']), 0, 255) : null,
        ], $user['id'] ?? $user['user_id']);

        Response::success('สร้างใบโอนแล้ว', $result);
    }

    public function confirm()
    {
        $user = $this->requireAuth(['admin', 'manager']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = intval($body['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }
        try {
            (new StockTransfer())->confirm($id, $user['id'] ?? $user['user_id']);
            Response::success('ยืนยันแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function cancel()
    {
        $this->requireAuth(['admin', 'manager']);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $id   = intval($body['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }
        (new StockTransfer())->cancel($id);
        Response::success('ยกเลิกแล้ว');
    }
}
