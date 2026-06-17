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

        // SECURITY: non-admin บังคับ scope ที่ branch ของตัวเองเสมอ — ห้าม query สาขาอื่น
        if (($this->user['role'] ?? '') !== 'admin') {
            $filters['branch_id'] = $this->user['branch_id'] ?? null;
            if (!$filters['branch_id']) {
                Response::error('ไม่มีสาขาที่ผูกกับผู้ใช้นี้', 403);
            }
        }

        $model  = new SaleLot();
        $result = $model->getAll($filters['branch_id'], $filters);
        Response::success('ดึงรายการ Sale Lots สำเร็จ', ['items' => $result]);
    }

    // ดึง Sale Lot เดียวพร้อมรายการสินค้าและข้อมูลกำไร
    public function show($id)
    {
        $this->requireAuth();
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);

        // SECURITY: non-admin ดูได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์เข้าถึง Sale Lot นี้', 403);
            }
        }

        Response::success('ดึงข้อมูล Sale Lot สำเร็จ', $lot);
    }

    // สร้าง Sale Lot ใหม่ — confirmed ทันที (บันทึกจากบิลที่ขายไปแล้ว)
    public function store()
    {
        $this->requireAuth(['admin', 'manager']);
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['branch_id', 'buyer_name', 'sale_date', 'items']);

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $cleanData = [
            'branch_id'  => intval($data['branch_id']),
            'buyer_name' => trim((string)$data['buyer_name']),
            'sale_date'  => $this->sanitizeInput($data['sale_date']),
            'status'     => 'confirmed',
            'notes'      => isset($data['notes']) ? trim((string)$data['notes']) : null,
            'expenses'   => $data['expenses'] ?? null,
            'created_by' => $this->user['user_id'] ?? null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['quantity_kg'])) {
                Response::error('แต่ละรายการต้องมีน้ำหนัก', 400);
            }
            $cleanItems[] = [
                'catalog_id'  => !empty($item['catalog_id']) ? intval($item['catalog_id']) : null,
                'item_name'   => !empty($item['item_name']) ? trim((string)$item['item_name']) : 'สินค้า',
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                'quantity_kg' => floatval($item['quantity_kg']),
                'unit_price'  => floatval($item['unit_price'] ?? 0),
            ];
        }

        $cleanData['items'] = $cleanItems;

        $model = new SaleLot();
        try {
            $result = $model->create($cleanData);
            $model->deductStock($result['id']);
            Logger::logActivity(
                $this->user['user_id'],
                'create_sale_lot',
                "Created Sale Lot: {$result['reference_no']} (฿{$result['total_amount']})"
            );
            Response::success('สร้าง Sale Lot สำเร็จ', $result);
        } catch (Exception $e) {
            error_log('SaleLot store failed: ' . $e->getMessage());
            Response::error('สร้าง Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    // อัปเดต Sale Lot: draft → update ปกติ, confirmed → restore+update+deduct stock ใหม่
    public function update($id)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);

        // SECURITY: non-admin แก้ได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (($this->user['role'] ?? '') !== 'admin') {
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

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['quantity_kg'])) Response::error('แต่ละรายการต้องมีน้ำหนัก', 400);
            $cleanItems[] = [
                'catalog_id'  => !empty($item['catalog_id']) ? intval($item['catalog_id']) : null,
                'item_name'   => !empty($item['item_name']) ? trim((string)$item['item_name']) : 'สินค้า',
                'category_id' => !empty($item['category_id']) ? intval($item['category_id']) : null,
                'quantity_kg' => floatval($item['quantity_kg']),
                'unit_price'  => floatval($item['unit_price'] ?? 0),
            ];
        }

        $cleanData = [
            'buyer_name'  => trim((string)$data['buyer_name']),
            'sale_date'   => $this->sanitizeInput($data['sale_date']),
            'notes'       => isset($data['notes']) ? trim((string)$data['notes']) : null,
            'expenses'    => $data['expenses'] ?? null,
            'items'       => $cleanItems,
            'updated_by'  => $this->user['user_id'] ?? null,
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
        $this->requireAuth(['admin', 'manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
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
            Response::error('ยืนยัน Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    // ยกเลิก Sale Lot เปลี่ยนสถานะเป็น cancelled และคืนสต็อก (BUG-05 FIX)
    public function cancel($id)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
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
            Response::error('ยกเลิก Sale Lot ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง', 500);
        }
    }

    // บันทึกรายรับจริงจากบิลศูนย์รับซื้อ (เฉพาะ lot ที่ confirmed แล้ว)
    public function recordRevenue($id)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $data = $this->getRequestData();
        if (!isset($data['actual_revenue']) || !is_numeric($data['actual_revenue'])) {
            Response::error('ต้องระบุยอดรายรับจริง (actual_revenue)', 400);
        }

        $actualRevenue = floatval($data['actual_revenue']);
        if ($actualRevenue < 0) { Response::error('ยอดรายรับต้องไม่ติดลบ', 400); return; }

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);
        if ($lot['status'] !== 'confirmed') Response::error('บันทึกรายรับได้เฉพาะ Lot ที่ยืนยันแล้ว', 400);

        // SECURITY: non-admin บันทึกได้เฉพาะสาขาตัวเอง
        if (($this->user['role'] ?? '') !== 'admin') {
            $userBranch = $this->user['branch_id'] ?? null;
            if (!$userBranch || (int)$lot['branch_id'] !== (int)$userBranch) {
                Response::error('ไม่มีสิทธิ์เข้าถึง Sale Lot นี้', 403);
            }
        }

        $cleanData = [
            'actual_revenue'      => $actualRevenue,
            'actual_revenue_note' => isset($data['actual_revenue_note']) ? trim((string)$data['actual_revenue_note']) : null,
            'actual_revenue_date' => isset($data['actual_revenue_date']) ? $this->sanitizeInput($data['actual_revenue_date']) : date('Y-m-d'),
        ];

        try {
            $model->recordRevenue($id, $cleanData);
            Logger::logActivity(
                $this->user['user_id'],
                'record_revenue',
                "Recorded revenue ฿{$actualRevenue} for Sale Lot ID: {$id}"
            );
            Response::success('บันทึกรายรับสำเร็จ', null);
        } catch (Exception $e) {
            error_log('SaleLot recordRevenue failed: ' . $e->getMessage());
            Response::error('บันทึกรายรับไม่สำเร็จ กรุณาลองใหม่', 500);
        }
    }

    // ลบ Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function destroy($id)
    {
        $this->requireAuth(['admin', 'manager']);
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();

        // SECURITY: non-admin ลบได้เฉพาะ Sale Lot ของสาขาตัวเอง
        if (($this->user['role'] ?? '') !== 'admin') {
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
}
