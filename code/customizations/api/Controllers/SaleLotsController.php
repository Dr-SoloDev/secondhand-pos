<?php
class SaleLotsController extends Controller
{
    // ดึงรายการ Sale Lots ทั้งหมด รองรับกรองตาม branch, status และช่วงวันที่
    public function index()
    {
        $this->requireAuth();

        $filters = [
            'branch_id' => isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null,
            'status'    => isset($_GET['status'])    ? $this->sanitizeInput($_GET['status'])    : null,
            'date_from' => isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null,
            'date_to'   => isset($_GET['date_to'])   ? $this->sanitizeInput($_GET['date_to'])   : null,
        ];

        // Read policy: admin/super_manager may read all branches or filter by branch_id.
        // Other roles remain forced to their own branch.
        $filters['branch_id'] = $this->resolveReadBranchId();

        $model  = new SaleLot();
        $result = $model->getAll($filters['branch_id'], $filters);
        Response::success('ดึงรายการ Sale Lots สำเร็จ', ['items' => $result, 'filters' => $filters]);
    }

    // ดึง Sale Lot เดียวพร้อมรายการสินค้าและข้อมูลกำไร
    public function show($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);

        $this->assertReadBranchAccess($lot['branch_id'] ?? null, 'ไม่มีสิทธิ์เข้าถึง Sale Lot นี้');

        Response::success('ดึงข้อมูล Sale Lot สำเร็จ', $lot);
    }

    // สร้าง Sale Lot ใหม่เป็น draft เท่านั้น ยังไม่ตัดสต็อกจนกว่าจะ confirm
    public function store()
    {
        $this->requireAuth(['admin', 'super_manager']);
        $data = $this->getRequestData();

        // Idempotency check — ป้องกัน Sale Lot ซ้ำ
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $idemp = new Idempotency();
            try {
                $idemp->acquire($idempotencyKey, 'sale-lots');
                $idemp->check($idempotencyKey, 'sale-lots');
            } catch (Exception $e) {
                Response::error($e->getMessage(), 409);
            }
        }

        // SECURITY: non-admin บังคับ scope ที่ branch ของตัวเอง — ห้ามสร้าง Sale Lot ให้สาขาอื่น
        if (!in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$data['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์สร้าง Sale Lot สำหรับสาขานี้', 403);
            }
        }

        $this->validateRequiredFields($data, ['branch_id', 'buyer_name', 'sale_date', 'items']);

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $transportCost = $this->nonNegativeAmount($data['transport_cost'] ?? 0, 'ค่าขนส่ง');
        $expenses = $this->normalizeExpenses($data['expenses'] ?? null);

        $cleanData = [
            'branch_id'      => intval($data['branch_id']),
            'buyer_name'     => trim((string)$data['buyer_name']),
            'sale_date'      => $this->sanitizeInput($data['sale_date']),
            'status'         => 'draft',
            'notes'          => isset($data['notes']) ? trim((string)$data['notes']) : null,
            'transport_cost' => $transportCost,
            'expenses'       => $expenses,
            'created_by'     => $this->user['user_id'] ?? null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['quantity_kg'])) {
                Response::error('แต่ละรายการต้องมีน้ำหนัก', 400);
            }
            if (empty($item['category_id'])) {
                Response::error('แต่ละรายการต้องเลือกหมวดหมู่', 400);
            }
            $itemName = trim((string)($item['item_name'] ?? ''));
            if ($itemName === '') {
                Response::error('แต่ละรายการต้องมีชื่อสินค้า', 400);
            }
            $quantity = (float)$item['quantity_kg'];
            $unitPrice = (float)($item['unit_price'] ?? 0);
            if (!is_finite($quantity) || $quantity <= 0) {
                Response::error('น้ำหนักขายต้องมากกว่า 0', 422);
            }
            if (!is_finite($unitPrice) || $unitPrice < 0) {
                Response::error('ราคาขายต่อหน่วยต้องไม่น้อยกว่า 0', 422);
            }
            $cleanItems[] = [
                'catalog_id'  => !empty($item['catalog_id']) ? intval($item['catalog_id']) : null,
                'item_name'   => $itemName,
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                'quantity_kg' => $quantity,
                'unit_price'  => $unitPrice,
            ];
        }

        $cleanData['items'] = $cleanItems;

        $model = new SaleLot();
        try {
            $result = $model->create($cleanData);
            if ($idempotencyKey) {
                $idemp->save($idempotencyKey, 'sale-lots', ['id' => $result['id'], 'reference_no' => $result['reference_no']]);
                $idemp->release();
            }

            Logger::logActivity(
                $this->user['user_id'],
                'create_sale_lot',
                "Created Sale Lot: {$result['reference_no']} (฿{$result['total_amount']})"
            );
            Response::success('บันทึก Sale Lot แบบร่างสำเร็จ', $result);
        } catch (Exception $e) {
            if ($idempotencyKey && isset($idemp)) {
                $idemp->release();
            }
            error_log('SaleLot store failed: ' . $e->getMessage());
            Response::error($e->getMessage() ?: 'สร้าง Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 400);
        }
    }

    // อัปเดต Sale Lot ได้เฉพาะ draft; confirmed ต้องยกเลิกหรือสร้าง Lot ใหม่
    public function update($id)
    {
        $this->requireAuth(['admin', 'super_manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);

        // SECURITY: non-admin แก้ได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (!in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์แก้ไข Sale Lot นี้', 403);
            }
        }

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['buyer_name', 'sale_date', 'items']);

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $transportCost = $this->nonNegativeAmount($data['transport_cost'] ?? 0, 'ค่าขนส่ง');
        $expenses = $this->normalizeExpenses($data['expenses'] ?? null);

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['quantity_kg'])) Response::error('แต่ละรายการต้องมีน้ำหนัก', 400);
            if (empty($item['category_id'])) Response::error('แต่ละรายการต้องเลือกหมวดหมู่', 400);
            $itemName = trim((string)($item['item_name'] ?? ''));
            if ($itemName === '') Response::error('แต่ละรายการต้องมีชื่อสินค้า', 400);
            $quantity = (float)$item['quantity_kg'];
            $unitPrice = (float)($item['unit_price'] ?? 0);
            if (!is_finite($quantity) || $quantity <= 0) Response::error('น้ำหนักขายต้องมากกว่า 0', 422);
            if (!is_finite($unitPrice) || $unitPrice < 0) Response::error('ราคาขายต่อหน่วยต้องไม่น้อยกว่า 0', 422);
            $cleanItems[] = [
                'catalog_id'  => !empty($item['catalog_id']) ? intval($item['catalog_id']) : null,
                'item_name'   => $itemName,
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                'quantity_kg' => $quantity,
                'unit_price'  => $unitPrice,
            ];
        }

        $cleanData = [
            'buyer_name'     => trim((string)$data['buyer_name']),
            'sale_date'      => $this->sanitizeInput($data['sale_date']),
            'notes'          => isset($data['notes']) ? trim((string)$data['notes']) : null,
            'transport_cost' => $transportCost,
            'expenses'       => $expenses,
            'items'          => $cleanItems,
            'updated_by'     => $this->user['user_id'] ?? null,
        ];

        try {
            if ($lot['status'] === 'confirmed') {
                $model->updateConfirmed($id, $cleanData);
            } else {
                $model->update($id, $cleanData);
            }
            $userName = $this->user['username'] ?? $this->user['user_id'];
            Logger::logActivity(
                $this->user['user_id'],
                'update_sale_lot',
                "Updated Sale Lot ID: {$id} (status: {$lot['status']}) by {$userName}"
            );
            Response::success('อัปเดต Sale Lot สำเร็จ', null);
        } catch (Exception $e) {
            error_log('SaleLot update failed: ' . $e->getMessage());
            Response::error($e->getMessage() ?: 'อัปเดต Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    // ยืนยัน Sale Lot เปลี่ยนสถานะเป็น confirmed และตัดสต็อก
    public function confirm($id)
    {
        $this->requireAuth(['admin', 'super_manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);
        if ($lot['status'] !== 'draft') {
            Response::error('ยืนยัน Lot ได้เฉพาะ Lot ที่ยังไม่ยืนยันเท่านั้น', 400);
        }

        // SECURITY: non-admin ยืนยันได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (!in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์ยืนยัน Sale Lot นี้', 403);
            }
        }

        try {
            $model->updateStatus($id, 'confirmed');
            Logger::logActivity(
                $this->user['user_id'],
                'confirm_sale_lot',
                "Confirmed Sale Lot ID: {$id}"
            );
            Response::success('ยืนยัน Sale Lot สำเร็จ', null);
        } catch (Exception $e) {
            error_log('SaleLot confirm failed: ' . $e->getMessage());
            Response::error($e->getMessage() ?: 'ยืนยัน Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 400);
        }
    }

    // ยกเลิก Sale Lot เปลี่ยนสถานะเป็น cancelled และคืนสต็อก (BUG-05 FIX)
    public function cancel($id)
    {
        $this->requireAuth(['admin', 'super_manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);
        if ($lot['actual_revenue'] !== null) {
            Response::error('Lot ที่บันทึกรายรับแล้วต้องแก้ไขด้วยเอกสารปรับปรุง', 400);
        }

        // SECURITY: non-admin ยกเลิกได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (!in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์ยกเลิก Sale Lot นี้', 403);
            }
        }

        try {
            $model->updateStatus($id, 'cancelled');
            Logger::logActivity(
                $this->user['user_id'],
                'cancel_sale_lot',
                "Cancelled Sale Lot ID: {$id}"
            );
            Response::success('ยกเลิก Sale Lot สำเร็จ', null);
        } catch (Exception $e) {
            error_log('SaleLot cancel failed: ' . $e->getMessage());
            Response::error($e->getMessage() ?: 'ยกเลิก Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 400);
        }
    }

    // บันทึกรายรับจริงจากบิลศูนย์รับซื้อ (เฉพาะ lot ที่ confirmed แล้ว)
    public function recordRevenue($id)
    {
        $this->requireAuth(['admin', 'super_manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $data = $this->getRequestData();
        if (!isset($data['actual_revenue']) || !is_numeric($data['actual_revenue'])) {
            Response::error('ต้องระบุยอดรายรับจริง (actual_revenue)', 400);
        }

        $actualRevenue = floatval($data['actual_revenue']);
        if ($actualRevenue < 0) { Response::error('ยอดรายรับต้องไม่ติดลบ', 400); return; }
        $paymentMethod = $data['payment_method'] ?? null;
        if (!in_array($paymentMethod, ['cash', 'bank_transfer'], true)) {
            Response::error('กรุณาระบุวิธีรับเงินสดหรือเงินโอน', 400);
        }

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);
        if ($lot['status'] !== 'confirmed') Response::error('บันทึกรายรับได้เฉพาะ Lot ที่ยืนยันแล้ว', 400);

        // SECURITY: non-admin บันทึกได้เฉพาะสาขาตัวเอง
        if (!in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์เข้าถึง Sale Lot นี้', 403);
            }
        }

        $cleanData = [
            'actual_revenue'      => $actualRevenue,
            'actual_revenue_note' => isset($data['actual_revenue_note']) ? trim((string)$data['actual_revenue_note']) : null,
            'actual_revenue_date' => isset($data['actual_revenue_date']) ? $this->sanitizeInput($data['actual_revenue_date']) : date('Y-m-d'),
            'payment_method'      => $paymentMethod,
        ];

        try {
            $model->recordRevenue($id, $cleanData, (int)($this->user['user_id'] ?? $this->user['id']));
            Logger::logActivity(
                $this->user['user_id'],
                'record_revenue',
                "Recorded revenue ฿{$actualRevenue} for Sale Lot ID: {$id}"
            );
            Response::success('บันทึกรายรับสำเร็จ', null);
        } catch (Exception $e) {
            error_log('SaleLot recordRevenue failed: ' . $e->getMessage());
            Response::error($e->getMessage() ?: 'บันทึกรายรับไม่สำเร็จ กรุณาลองใหม่', 400);
        }
    }

    // ลบ Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function destroy($id)
    {
        $this->requireAuth(['admin', 'super_manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();

        // SECURITY: non-admin ลบได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (!in_array(($this->user['role'] ?? ''), ['admin', 'super_manager'], true)) {
            $lot = $model->getById($id);
            if (!$lot) Response::error('ไม่พบ Sale Lot', 404);
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์ลบ Sale Lot นี้', 403);
            }
        }

        try {
            $model->delete($id);
            Logger::logActivity(
                $this->user['user_id'],
                'delete_sale_lot',
                "Deleted Sale Lot ID: {$id}"
            );
            Response::success('ลบ Sale Lot สำเร็จ', null);
        } catch (Exception $e) {
            error_log('SaleLot destroy failed: ' . $e->getMessage());
            Response::error('ลบ Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    private function resolveReadBranchId()
    {
        $requestedBranch = null;
        $branchProvided = isset($_GET['branch_id']) && $_GET['branch_id'] !== '';

        if ($branchProvided) {
            if (!is_numeric($_GET['branch_id']) || intval($_GET['branch_id']) < 0) {
                Response::error('branch_id ไม่ถูกต้อง', 400);
            }
            $requestedBranch = intval($_GET['branch_id']);
            if ($requestedBranch === 0) {
                $requestedBranch = null;
            }
        }

        $role = $this->user['role'] ?? '';
        if (in_array($role, ['admin', 'super_manager'], true)) {
            return $requestedBranch;
        }

        $userBranch = intval($this->user['branch_id'] ?? 0);
        if (!$userBranch) {
            Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
        }

        return $userBranch;
    }

    private function assertReadBranchAccess($branchId, $message)
    {
        $role = $this->user['role'] ?? '';
        if (in_array($role, ['admin', 'super_manager'], true)) {
            return;
        }

        $userBranch = intval($this->user['branch_id'] ?? 0);
        if (!$userBranch || (int)$branchId !== $userBranch) {
            Response::error($message, 403);
        }
    }

    private function nonNegativeAmount($value, $label)
    {
        $amount = (float)$value;
        if (!is_finite($amount) || $amount < 0) {
            Response::error("{$label}ต้องไม่น้อยกว่า 0", 422);
        }
        return $amount;
    }

    private function normalizeExpenses($expenses)
    {
        if ($expenses === null || $expenses === '') {
            return null;
        }
        if (!is_array($expenses)) {
            Response::error('รูปแบบค่าใช้จ่ายไม่ถูกต้อง', 422);
        }

        $clean = [];
        foreach ($expenses as $expense) {
            if (!is_array($expense)) {
                Response::error('รูปแบบค่าใช้จ่ายไม่ถูกต้อง', 422);
            }
            $description = trim((string)($expense['description'] ?? ''));
            if ($description === '') {
                Response::error('กรุณาระบุรายละเอียดค่าใช้จ่าย', 422);
            }
            $clean[] = [
                'description' => $this->sanitizeInput($description, 255),
                'amount' => $this->nonNegativeAmount($expense['amount'] ?? 0, 'ค่าใช้จ่าย'),
            ];
        }
        return $clean ?: null;
    }
}
