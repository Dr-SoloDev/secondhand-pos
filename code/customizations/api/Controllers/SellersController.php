<?php
class SellersController extends Controller
{
    /**
     * GET /api/sellers - ดึงรายการผู้ขายทั้งหมด
     */
    public function getSellers()
    {
        $sellerModel = new Seller();
        $includeBlacklisted = isset($_GET['include_blacklisted']) && $_GET['include_blacklisted'] === 'true';
        
        $sellers = $sellerModel->getAll($includeBlacklisted);
        Response::success('ดึงข้อมูลผู้ขายสำเร็จ', $sellers);
    }

    /**
     * GET /api/sellers/seller?id=X - ดึงข้อมูลผู้ขายตาม ID
     */
    public function getSeller()
    {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            Response::error('กรุณาระบุ ID ผู้ขาย', 400);
        }

        $sellerModel = new Seller();
        $seller = $sellerModel->getById($id);

        if (!$seller) {
            Response::error('ไม่พบผู้ขายนี้', 404);
        }

        Response::success('ดึงข้อมูลผู้ขายสำเร็จ', $seller);
    }

    /**
     * GET /api/sellers/search?q=keyword - ค้นหาผู้ขาย
     */
    public function searchSellers()
    {
        $keyword = $_GET['q'] ?? '';
        
        if (empty($keyword)) {
            Response::error('กรุณาระบุคำค้นหา', 400);
        }

        $sellerModel = new Seller();
        $results = $sellerModel->search($keyword);

        Response::success('ค้นหาสำเร็จ', $results);
    }

    /**
     * POST /api/sellers - เพิ่มผู้ขายใหม่
     */
    public function createSeller()
    {
        $this->requireAuth(); // ต้อง login

        $data = $this->getRequestData();

        // ต้องระบุชื่อ-นามสกุล
        if (empty($data['full_name'])) {
            Response::error('กรุณาระบุชื่อ-นามสกุล', 400);
        }

        // Validate เลขบัตรประชาชน (ถ้ามี)
        if (!empty($data['id_card'])) {
            $idCard = preg_replace('/[^0-9]/', '', $data['id_card']);
            if (strlen($idCard) !== 13) {
                Response::error('เลขบัตรประชาชนต้องเป็น 13 หลัก', 400);
            }
            $data['id_card'] = $idCard;
        }

        $sellerModel = new Seller();
        
        try {
            $sellerId = $sellerModel->create($data);
            
            // Log activity
            Logger::logActivity(
                $this->user['user_id'],
                'create_seller',
                "เพิ่มผู้ขาย: {$data['full_name']}"
            );

            Response::success('เพิ่มผู้ขายสำเร็จ', ['id' => $sellerId]);
        } catch (Exception $e) {
            error_log('Seller create failed: ' . $e->getMessage());
            Response::error($e->getMessage(), 400);
        }
    }

    /**
     * PUT /api/sellers/seller - แก้ไขข้อมูลผู้ขาย
     */
    public function updateSeller($id = null)
    {
        $this->requireAuth();

        if (!$id) {
            $data = $this->getRequestData();
            $id = $data['id'] ?? null;
        } else {
            $data = $this->getRequestData();
        }

        if (!$id) {
            Response::error('กรุณาระบุ ID ผู้ขาย', 400);
        }

        // Validate เลขบัตรประชาชน (ถ้ามี)
        if (!empty($data['id_card'])) {
            $idCard = preg_replace('/[^0-9]/', '', $data['id_card']);
            if (strlen($idCard) !== 13) {
                Response::error('เลขบัตรประชาชนต้องเป็น 13 หลัก', 400);
            }
            $data['id_card'] = $idCard;
        }

        $sellerModel = new Seller();

        try {
            $sellerModel->update($id, $data);

            Logger::logActivity(
                $this->user['user_id'],
                'update_seller',
                "แก้ไขผู้ขาย ID: {$id}"
            );

            Response::success('แก้ไขข้อมูลผู้ขายสำเร็จ');
        } catch (Exception $e) {
            error_log('Seller update failed: ' . $e->getMessage());
            Response::error($e->getMessage(), 400);
        }
    }

    public function getSellerHistory()
    {
        $this->requireAuth();
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }

        $db = Database::getInstance();
        $items = $db->fetchAll(
            "SELECT po.reference_no, po.created_at, po.total_amount, po.total_items,
                    b.name AS branch_name
             FROM purchase_orders po
             LEFT JOIN branches b ON b.id = po.branch_id
             WHERE po.seller_id = ? AND po.status = 'completed'
             ORDER BY po.created_at DESC LIMIT 50",
            [$id]
        );
        Response::success('สำเร็จ', ['items' => $items ?: []]);
    }

    /**
     * POST /api/sellers/blacklist - Blacklist ผู้ขาย
     */
    public function blacklistSeller()
    {
        $this->requireAuth(['admin', 'manager']); // เฉพาะ admin/manager

        $data = $this->getRequestData();
        $id = $data['id'] ?? null;
        $reason = $data['reason'] ?? null;

        if (!$id) {
            Response::error('กรุณาระบุ ID ผู้ขาย', 400);
        }

        $sellerModel = new Seller();

        try {
            $sellerModel->blacklist($id, $reason);

            Logger::logActivity(
                $this->user['user_id'],
                'blacklist_seller',
                "Blacklist ผู้ขาย ID: {$id} เหตุผล: {$reason}"
            );

            Response::success('Blacklist ผู้ขายสำเร็จ');
        } catch (Exception $e) {
            error_log('Seller blacklist failed: ' . $e->getMessage());
            Response::error('ไม่สามารถ Blacklist ผู้ขายได้', 400);
        }
    }

    /**
     * POST /api/sellers/unblacklist - ยกเลิก Blacklist
     */
    public function unblacklistSeller()
    {
        $this->requireAuth(['admin', 'manager']);

        $data = $this->getRequestData();
        $id = $data['id'] ?? null;

        if (!$id) {
            Response::error('กรุณาระบุ ID ผู้ขาย', 400);
        }

        $sellerModel = new Seller();

        try {
            $sellerModel->unblacklist($id);

            Logger::logActivity(
                $this->user['user_id'],
                'unblacklist_seller',
                "ยกเลิก Blacklist ผู้ขาย ID: {$id}"
            );

            Response::success('ยกเลิก Blacklist สำเร็จ');
        } catch (Exception $e) {
            error_log('Seller unblacklist failed: ' . $e->getMessage());
            Response::error('ไม่สามารถยกเลิก Blacklist ได้', 400);
        }
    }
}
