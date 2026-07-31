<?php
class AdjustmentDocumentsController extends Controller
{
    public function index()
    {
        $this->requireAuth(['admin']);
        $filters = [];
        if (!empty($_GET['branch_id'])) $filters['branch_id'] = (int)$_GET['branch_id'];
        if (!empty($_GET['adjustment_type'])) $filters['adjustment_type'] = $this->sanitizeInput($_GET['adjustment_type']);
        if (!empty($_GET['date_from'])) $filters['date_from'] = $this->sanitizeInput($_GET['date_from']);
        if (!empty($_GET['date_to'])) $filters['date_to'] = $this->sanitizeInput($_GET['date_to']);
        Response::success('สำเร็จ', ['items' => (new AdjustmentDocument())->getAll($filters)]);
    }

    public function create()
    {
        $this->requireAuth(['admin']);
        $data = $this->getRequestData() ?? [];
        $type = (string)($data['adjustment_type'] ?? '');
        $userId = (int)($this->user['user_id'] ?? $this->user['id']);
        $model = new AdjustmentDocument();

        try {
            if ($type === 'purchase_order_cancellation') {
                $target = trim((string)($data['target_reference'] ?? $data['purchase_order_id'] ?? ''));
                if ($target === '') Response::error('กรุณาระบุเลขที่ใบรับซื้อ', 400);
                $result = $model->cancelHistoricalPurchaseOrder($target, (string)($data['reason'] ?? ''), $userId);
            } elseif ($type === 'sale_lot_revenue_correction') {
                $target = trim((string)($data['target_reference'] ?? $data['sale_lot_id'] ?? ''));
                if ($target === '' || !isset($data['amount'])) Response::error('กรุณาระบุ Lot และยอดรายรับใหม่', 400);
                $result = $model->correctSaleLotRevenue(
                    $target, (float)$data['amount'], (string)($data['payment_method'] ?? ''),
                    (string)($data['effective_date'] ?? ''), (string)($data['reason'] ?? ''),
                    isset($data['note']) ? (string)$data['note'] : null, $userId
                );
            } elseif ($type === 'historical_expense') {
                $branchId = (int)($data['branch_id'] ?? 0);
                if ($branchId <= 0) {
                    Response::error('กรุณาระบุสาขาสำหรับบันทึกรายจ่ายย้อนหลัง', 400);
                    return;
                }

                $branchModel = new Branch();
                $branch = $branchModel->findById($branchId);
                if (!$branch) {
                    Response::error('ไม่พบสาขานี้ในระบบ', 404);
                    return;
                }

                $result = $model->createHistoricalExpense([
                    'branch_id' => $branchId,
                    'expense_date' => (string)($data['effective_date'] ?? ''),
                    'category' => (string)($data['category'] ?? ''),
                    'amount' => (float)($data['amount'] ?? 0),
                    'payment_method' => (string)($data['payment_method'] ?? ''),
                    'beneficiary_name' => (string)($data['beneficiary_name'] ?? ''),
                    'reason' => (string)($data['reason'] ?? ''),
                    'note' => isset($data['note']) ? (string)$data['note'] : null,
                ], $userId);
            } else {
                Response::error('ประเภทเอกสารปรับปรุงไม่ถูกต้อง', 400);
                return;
            }

            Logger::logActivity($userId, 'create_adjustment_document', "Created {$type} {$result['reference_no']}");
            Response::success('บันทึกเอกสารปรับปรุงแล้ว', $result);
        } catch (Exception $e) {
            error_log('Adjustment document failed: ' . $e->getMessage());
            $message = trim($e->getMessage());
            if ($message !== '' && stripos($message, 'SQLSTATE') === false
                && preg_match('/[\x{0E00}-\x{0E7F}]/u', $message)) {
                Response::error(substr($message, 0, 500), 400);
            }
            Response::error('บันทึกเอกสารปรับปรุงไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }
}
