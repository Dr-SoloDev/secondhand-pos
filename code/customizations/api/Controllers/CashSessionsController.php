<?php
class CashSessionsController extends Controller
{
    public function index()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $filters = [];
        $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
        if (!$this->hasMultiBranchAccess()) {
            $branchId = (int)($this->user['branch_id'] ?? 0);
            if (!$branchId) Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
        }
        if ($branchId) $filters['branch_id'] = $branchId;
        if (!empty($_GET['date_from'])) $filters['date_from'] = $this->sanitizeInput($_GET['date_from']);
        if (!empty($_GET['date_to'])) $filters['date_to'] = $this->sanitizeInput($_GET['date_to']);
        Response::success('สำเร็จ', ['items' => (new CashSession())->getAll($filters)]);
    }

    public function current()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $branchId = $this->resolveBranchId($_GET['branch_id'] ?? null);
        Response::success('สำเร็จ', (new CashSession())->getCurrent($branchId));
    }

    public function open()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $branchId = $this->resolveBranchId($data['branch_id'] ?? null);
        if (!isset($data['actual_cash']) || !is_numeric($data['actual_cash'])) {
            Response::error('กรุณาระบุยอดเงินสดที่นับได้', 400);
        }
        try {
            $userId = $this->userId();
            $result = (new CashSession())->openDay(
                $branchId,
                (float)$data['actual_cash'],
                isset($data['reason']) ? (string)$data['reason'] : null,
                $userId
            );
            Logger::logActivity($userId, 'open_cash_session', "Open branch cash session branch:{$branchId} status:{$result['status']}");
            $message = $result['status'] === 'pending_open' ? 'ส่งยอดเปิดวันเพื่อรออนุมัติแล้ว' : 'เปิดยอดประจำวันแล้ว';
            Response::success($message, $result);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function close()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $branchId = $this->resolveBranchId($data['branch_id'] ?? null);
        if (!isset($data['actual_cash']) || !is_numeric($data['actual_cash'])) {
            Response::error('กรุณาระบุยอดเงินสดที่นับได้', 400);
        }
        try {
            $userId = $this->userId();
            $result = (new CashSession())->closeDay(
                $branchId,
                (float)$data['actual_cash'],
                isset($data['reason']) ? (string)$data['reason'] : null,
                $userId
            );
            Logger::logActivity($userId, 'close_cash_session', "Close branch cash session branch:{$branchId} status:{$result['status']}");
            $message = $result['status'] === 'pending_close' ? 'ส่งยอดปิดวันเพื่อรออนุมัติแล้ว' : 'ปิดยอดประจำวันแล้ว';
            Response::success($message, $result);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function approveOpen()
    {
        $this->reviewOpen(true);
    }

    public function rejectOpen()
    {
        $this->reviewOpen(false);
    }

    private function reviewOpen(bool $approve)
    {
        $this->requireAuth(['admin', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $id = (int)($data['id'] ?? 0);
        $note = isset($data['review_note']) ? (string)$data['review_note'] : null;
        if (!$id) Response::error('ไม่พบรหัสรอบประจำวัน', 400);
        try {
            $model = new CashSession();
            if ($approve) $model->approveOpen($id, $this->userId(), $note);
            else $model->rejectOpen($id, $this->userId(), (string)$note);
            Response::success($approve ? 'อนุมัติเปิดยอดแล้ว' : 'ปฏิเสธคำขอเปิดยอดแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function approveClose()
    {
        $this->reviewClose(true);
    }

    public function rejectClose()
    {
        $this->reviewClose(false);
    }

    private function reviewClose(bool $approve)
    {
        $this->requireAuth(['admin', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $id = (int)($data['id'] ?? 0);
        $note = isset($data['review_note']) ? (string)$data['review_note'] : null;
        if (!$id) Response::error('ไม่พบรหัสรอบประจำวัน', 400);
        try {
            (new CashSession())->reviewClose($id, $this->userId(), $approve, $note);
            Response::success($approve ? 'อนุมัติปิดยอดแล้ว' : 'ปฏิเสธคำขอปิดยอดแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function reopen()
    {
        $this->requireAuth(['admin']);
        $data = $this->getRequestData() ?? [];
        $id = (int)($data['id'] ?? 0);
        $reason = trim((string)($data['reason'] ?? ''));
        if (!$id || $reason === '') Response::error('กรุณาระบุรอบและเหตุผล', 400);
        try {
            (new CashSession())->reopenSameDay($id, $reason, $this->userId());
            Response::success('เปิดรอบประจำวันใหม่แล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function listDeposits()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $filters = [];
        if (!empty($_GET['status'])) $filters['status'] = $this->sanitizeInput($_GET['status']);
        if (!$this->hasMultiBranchAccess()) {
            $filters['branch_id'] = $this->resolveBranchId(null);
        } elseif (!empty($_GET['branch_id'])) {
            $filters['branch_id'] = (int)$_GET['branch_id'];
        }
        Response::success('สำเร็จ', ['items' => (new CashDepositRequest())->getAll($filters)]);
    }

    public function requestDeposit()
    {
        $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $branchId = $this->resolveBranchId($data['branch_id'] ?? null);
        try {
            $result = (new CashDepositRequest())->request(
                $branchId, (float)($data['amount'] ?? 0), (string)($data['source_name'] ?? ''),
                (string)($data['reason'] ?? ''), $this->userId()
            );
            Response::success('ส่งคำขอเติมเงินสดเพื่อรออนุมัติแล้ว', $result);
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function approveDeposit()
    {
        $this->requireAuth(['admin', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $id = (int)($data['id'] ?? 0);
        if (!$id) Response::error('ไม่พบ id', 400);
        try {
            (new CashDepositRequest())->approve($id, $this->userId(), $data['review_note'] ?? null);
            Response::success('อนุมัติและเพิ่มเงินสดเข้าลิ้นชักแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    public function rejectDeposit()
    {
        $this->requireAuth(['admin', 'super_manager']);
        $data = $this->getRequestData() ?? [];
        $id = (int)($data['id'] ?? 0);
        $note = trim((string)($data['review_note'] ?? ''));
        if (!$id || $note === '') Response::error('กรุณาระบุรายการและเหตุผล', 400);
        try {
            (new CashDepositRequest())->reject($id, $this->userId(), $note);
            Response::success('ปฏิเสธคำขอเติมเงินสดแล้ว');
        } catch (Exception $e) {
            Response::error($e->getMessage(), 400);
        }
    }

    private function resolveBranchId($requested): int
    {
        if ($this->hasMultiBranchAccess()) {
            $branchId = (int)$requested;
            if (!$branchId) Response::error('กรุณาระบุสาขา', 400);
            return $branchId;
        }
        $branchId = (int)($this->user['branch_id'] ?? 0);
        if (!$branchId) Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
        if ($requested !== null && (int)$requested !== $branchId) {
            Response::error('ไม่มีสิทธิ์จัดการยอดเงินสดของสาขาอื่น', 403);
        }
        return $branchId;
    }

    private function hasMultiBranchAccess(): bool
    {
        return in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true);
    }

    private function userId(): int
    {
        return (int)($this->user['user_id'] ?? $this->user['id']);
    }
}
