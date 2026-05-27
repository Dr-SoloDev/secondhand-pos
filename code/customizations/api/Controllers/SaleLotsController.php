<?php
class SaleLotsController extends Controller
{
    // ดึงรายการ Sale Lots ทั้งหมด รองรับกรองตาม branch, status และช่วงวันที่
    public function index()
    {
        $filters = [
            'branch_id' => isset($_GET['branch_id']) ? intval($_GET['branch_id']) : null,
            'status'    => isset($_GET['status'])    ? $this->sanitizeInput($_GET['status'])    : null,
            'date_from' => isset($_GET['date_from']) ? $this->sanitizeInput($_GET['date_from']) : null,
            'date_to'   => isset($_GET['date_to'])   ? $this->sanitizeInput($_GET['date_to'])   : null,
        ];

        // BUG-01 FIX: ถ้าไม่ส่ง branch_id ให้ใช้ branch ของ user ที่ login
        if (!$filters['branch_id']) {
            $filters['branch_id'] = $this->user['branch_id'] ?? null;
        }
        if (!$filters['branch_id']) {
            Response::error('ต้องระบุ branch_id', 400);
        }

        $model  = new SaleLot();
        $result = $model->getAll($filters['branch_id'], $filters);
        Response::success('ดึงรายการ Sale Lots สำเร็จ', ['items' => $result]);
    }

    // ดึง Sale Lot เดียวพร้อมรายการสินค้าและข้อมูลกำไร
    public function show($id)
    {
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        $lot   = $model->getById($id);
        if (!$lot) Response::error('ไม่พบ Sale Lot', 404);

        Response::success('ดึงข้อมูล Sale Lot สำเร็จ', $lot);
    }

    // สร้าง Sale Lot ใหม่ (draft) พร้อมรายการสินค้า
    public function store()
    {
        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['branch_id', 'buyer_name', 'sale_date', 'items']);

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $cleanData = [
            'branch_id'  => intval($data['branch_id']),
            'buyer_name' => trim((string)$data['buyer_name']),
            'sale_date'  => $this->sanitizeInput($data['sale_date']),
            'status'     => $data['status'] ?? 'draft',
            'notes'      => isset($data['notes']) ? trim((string)$data['notes']) : null,
            'created_by' => $this->user['user_id'] ?? null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['category_id']) || empty($item['quantity_kg'])) {
                Response::error('แต่ละรายการต้องมี category_id และ quantity_kg', 400);
            }
            $cleanItems[] = [
                'category_id' => intval($item['category_id']),
                'quantity_kg' => floatval($item['quantity_kg']),
                'unit_price'  => floatval($item['unit_price'] ?? 0),
            ];
        }

        $cleanData['items'] = $cleanItems;

        $model = new SaleLot();
        try {
            $result = $model->create($cleanData);
            Logger::logActivity(
                $this->user['user_id'],
                'create_sale_lot',
                "Created Sale Lot: {$result['reference_no']} (฿{$result['total_amount']})"
            );
            Response::success('สร้าง Sale Lot สำเร็จ', $result);
        } catch (Exception $e) {
            Response::error('สร้าง Sale Lot ไม่สำเร็จ: ' . $e->getMessage());
        }
    }

    // อัปเดต Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function update($id)
    {
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $data = $this->getRequestData();
        $this->validateRequiredFields($data, ['buyer_name', 'sale_date', 'items']);

        if (!is_array($data['items']) || count($data['items']) === 0) {
            Response::error('ต้องระบุรายการสินค้าอย่างน้อย 1 รายการ', 400);
        }

        $cleanData = [
            'buyer_name' => trim((string)$data['buyer_name']),
            'sale_date'  => $this->sanitizeInput($data['sale_date']),
            'notes'      => isset($data['notes']) ? trim((string)$data['notes']) : null,
        ];

        $cleanItems = [];
        foreach ($data['items'] as $item) {
            if (empty($item['category_id']) || empty($item['quantity_kg'])) {
                Response::error('แต่ละรายการต้องมี category_id และ quantity_kg', 400);
            }
            $cleanItems[] = [
                'category_id' => intval($item['category_id']),
                'quantity_kg' => floatval($item['quantity_kg']),
                'unit_price'  => floatval($item['unit_price'] ?? 0),
            ];
        }

        $cleanData['items'] = $cleanItems;

        $model = new SaleLot();
        try {
            $model->update($id, $cleanData);
            Logger::logActivity(
                $this->user['user_id'],
                'update_sale_lot',
                "Updated Sale Lot ID: {$id}"
            );
            Response::success('อัปเดต Sale Lot สำเร็จ', null);
        } catch (Exception $e) {
            Response::error('อัปเดต Sale Lot ไม่สำเร็จ: ' . $e->getMessage());
        }
    }

    // ยืนยัน Sale Lot เปลี่ยนสถานะเป็น confirmed และตัดสต็อก
    public function confirm($id)
    {
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
            Response::error('ยืนยัน Sale Lot ไม่สำเร็จ: ' . $e->getMessage());
        }
    }

    // ยกเลิก Sale Lot เปลี่ยนสถานะเป็น cancelled และคืนสต็อก (BUG-05 FIX)
    public function cancel($id)
    {
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
            Response::error('ยกเลิก Sale Lot ไม่สำเร็จ: ' . $e->getMessage());
        }
    }

    // ลบ Sale Lot ได้เฉพาะสถานะ draft เท่านั้น
    public function destroy($id)
    {
        if (!$id) Response::error('ต้องระบุ Sale Lot ID', 400);

        $model = new SaleLot();
        try {
            $model->delete($id);
            Logger::logActivity(
                $this->user['user_id'],
                'delete_sale_lot',
                "Deleted Sale Lot ID: {$id}"
            );
            Response::success('ลบ Sale Lot สำเร็จ', null);
        } catch (Exception $e) {
            Response::error('ลบ Sale Lot ไม่สำเร็จ: ' . $e->getMessage());
        }
    }
}
