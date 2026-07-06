<?php
class SellersController extends Controller
{
    /**
     * GET /api/sellers - ดึงรายการผู้ขายทั้งหมด
     */
    public function getSellers()
    {
        $this->requireAuth();
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
        $this->requireAuth();
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
        $this->requireAuth();
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

        // Stamp PDPA consent timestamp on first consent
        if (!empty($data['pdpa_consent'])) {
            $data['pdpa_consented_at'] = date('Y-m-d H:i:s');
        }
        unset($data['pdpa_consent']);

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

        // Only stamp pdpa_consented_at once — if consent given and not yet recorded
        if (!empty($data['pdpa_consent'])) {
            $current = $sellerModel->getById($id);
            if ($current && $current['pdpa_consented_at'] === null) {
                $data['pdpa_consented_at'] = date('Y-m-d H:i:s');
            }
        }
        unset($data['pdpa_consent']);

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
     * GET /api/sellers/data-center?id=X
     * ส่งข้อมูลทุกอย่างของผู้ขายกลับในครั้งเดียว
     * - seller details (รวม id_card_photo, pdpa, blacklist)
     * - all POs (ทุกรวม status)
     * - items ของแต่ละ PO
     * - photos (item photos) ของแต่ละ PO
     * - สรุปยอดรวม
     */
    public function getSellerDataCenter()
    {
        $this->requireAuth();
        $id = intval($_GET['id'] ?? 0);
        if (!$id) { Response::error('ไม่พบ id', 400); return; }

        $db = Database::getInstance();

        // 1. Seller details
        $sellerModel = new Seller();
        $seller = $sellerModel->getById($id);
        if (!$seller) {
            Response::error('ไม่พบผู้ขายนี้', 404);
            return;
        }

        // 2. All POs (ทุกสถานะ) พร้อมสาขา + พนักงาน
        $pos = $db->fetchAll(
            "SELECT po.*,
                    b.name AS branch_name,
                    b.code AS branch_code,
                    u.full_name AS processed_by_name
             FROM purchase_orders po
             LEFT JOIN branches b ON po.branch_id = b.id
             LEFT JOIN users u ON po.user_id = u.id
             WHERE po.seller_id = ?
             ORDER BY po.created_at DESC",
            [$id]
        );

        // 3. Items + Photos สำหรับทุก PO ของผู้ขาย
        $allItems = $db->fetchAll(
            "SELECT poi.*,
                    po.reference_no AS po_ref,
                    po.id AS po_id,
                    c.name AS category_name
             FROM purchase_order_items poi
             JOIN purchase_orders po ON poi.purchase_order_id = po.id
             LEFT JOIN categories c ON poi.category_id = c.id
             WHERE po.seller_id = ?
             ORDER BY po.created_at DESC, poi.id ASC",
            [$id]
        );

        $allPhotos = $db->fetchAll(
            "SELECT pop.*,
                    po.reference_no AS po_ref
             FROM purchase_order_photos pop
             JOIN purchase_orders po ON pop.purchase_order_id = po.id
             WHERE po.seller_id = ?
             ORDER BY pop.created_at ASC",
            [$id]
        );

        // 4. จัดกลุ่ม items และ photos ตาม PO id
        $itemsByPo = [];
        foreach ($allItems as $item) {
            $poId = $item['po_id'];
            if (!isset($itemsByPo[$poId])) $itemsByPo[$poId] = [];
            $itemsByPo[$poId][] = [
                'id'              => $item['id'],
                'item_name'       => $item['item_name'],
                'quantity'        => $item['quantity'],
                'weight_deduction'=> $item['weight_deduction'],
                'unit'            => $item['unit'],
                'unit_price'      => $item['unit_price'],
                'total_price'     => $item['total_price'],
                'category_name'   => $item['category_name'] ?? '',
                'photo_path'      => $item['photo_path'] ?? null,
                'notes'           => $item['notes'] ?? '',
            ];
        }

        $photosByPo = [];
        foreach ($allPhotos as $photo) {
            $poId = $photo['purchase_order_id'];
            if (!isset($photosByPo[$poId])) $photosByPo[$poId] = [];
            $photosByPo[$poId][] = [
                'id'         => $photo['id'],
                'photo_path' => $photo['photo_path'],
                'is_primary' => $photo['is_primary'],
            ];
        }

        // 5. ประกอบ POs
        $transactions = [];
        $totalPos = 0;
        $totalAmount = 0;
        $totalItemsSold = 0;
        $firstTransaction = null;
        $lastTransaction = null;

        foreach ($pos as $po) {
            $poId = $po['id'];
            $amount = floatval($po['total_amount']);

            $transactions[] = [
                'id'               => $poId,
                'reference_no'     => $po['reference_no'],
                'status'           => $po['status'],
                'payment_method'   => $po['payment_method'],
                'total_amount'     => $amount,
                'total_items'      => intval($po['total_items']),
                'branch_name'      => $po['branch_name'] ?? '',
                'processed_by'     => $po['processed_by_name'] ?? '',
                'notes'            => $po['notes'] ?? '',
                'created_at'       => $po['created_at'],
                'items'            => $itemsByPo[$poId] ?? [],
                'photos'           => $photosByPo[$poId] ?? [],
            ];

            $totalPos++;
            $totalAmount += $amount;
            $totalItemsSold += intval($po['total_items']);
            if ($firstTransaction === null || $po['created_at'] < $firstTransaction) {
                $firstTransaction = $po['created_at'];
            }
            if ($lastTransaction === null || $po['created_at'] > $lastTransaction) {
                $lastTransaction = $po['created_at'];
            }
        }

        // 6. Response
        Response::success('สำเร็จ', [
            'seller' => [
                'id'                 => $seller['id'],
                'full_name'          => $seller['full_name'],
                'id_card'            => $seller['id_card'] ?? '',
                'phone'              => $seller['phone'] ?? '',
                'address'            => $seller['address'] ?? '',
                'vehicle_plate'      => $seller['vehicle_plate'] ?? '',
                'id_card_photo'      => $seller['id_card_photo'] ?? '',
                'pdpa_consented_at'  => $seller['pdpa_consented_at'] ?? null,
                'is_blacklisted'     => $seller['is_blacklisted'] ?? 0,
                'blacklist_reason'   => $seller['blacklist_reason'] ?? '',
                'notes'              => $seller['notes'] ?? '',
                'total_transactions' => $seller['total_transactions'] ?? 0,
                'total_amount'       => $seller['total_amount'] ?? 0,
                'last_transaction_at'=> $seller['last_transaction_at'] ?? null,
                'created_at'         => $seller['created_at'] ?? null,
            ],
            'transactions' => $transactions,
            'summary' => [
                'total_pos'         => $totalPos,
                'total_amount'      => $totalAmount,
                'total_items_sold'  => $totalItemsSold,
                'first_transaction' => $firstTransaction,
                'last_transaction'  => $lastTransaction,
            ],
        ]);
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

    /**
     * POST /api/sellers/photo?id=X - อัปโหลดรูปบัตรประชาชน
     */
    public function uploadPhoto($id = null)
    {
        $this->requireAuth();

        if (!$id) {
            $data = $this->getRequestData();
            $id = $data['id'] ?? ($_GET['id'] ?? null);
        }

        $id = intval($id);
        if ($id <= 0) {
            Response::error('กรุณาระบุ ID ผู้ขาย', 400);
        }

        // ตรวจว่าผู้ขายมีอยู่จริง
        $sellerModel = new Seller();
        $seller = $sellerModel->getById($id);
        if (!$seller) {
            Response::error('ไม่พบผู้ขายนี้', 404);
        }

        // ตรวจ file upload
        if (empty($_FILES['photo'])) {
            Response::error('ไม่พบไฟล์รูป (field: photo)', 400);
        }

        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('เกิดข้อผิดพลาดในการอัปโหลด', 400);
        }

        // จำกัดขนาด 10MB
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            Response::error('ไฟล์ใหญ่เกิน 10 MB', 422);
        }

        // ตรวจ MIME
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes, true)) {
            Response::error('รองรับเฉพาะไฟล์ JPEG, PNG, WebP', 422);
        }

        // สร้าง path
        $uploadBase = '/var/www/html/uploads/sellers';
        $year = date('Y');
        $month = date('m');
        $dir = "{$uploadBase}/{$year}/{$month}";

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            Response::error('ไม่สามารถสร้าง目录จัดเก็บได้', 500);
        }

        // ตรวจสอบพื้นที่ว่าง
        if (disk_free_space($uploadBase) < 50 * 1024 * 1024) {
            Response::error('พื้นที่จัดเก็บเต็ม กรุณาติดต่อผู้ดูแลระบบ', 507);
        }

        // สร้างชื่อไฟล์
        $uuid = $id . '_' . bin2hex(random_bytes(8));
        $filename = "{$uuid}.jpg";
        $destPath = "{$dir}/{$filename}";
        $urlPath = "/uploads/sellers/{$year}/{$month}/{$filename}";

        // resize และบันทึก (reuse logic จาก PhotoUploadController)
        $this->saveResizedImage($file['tmp_name'], $destPath);

        // ลบรูปเก่าถ้ามี
        if (!empty($seller['id_card_photo'])) {
            $oldPath = $_SERVER['DOCUMENT_ROOT'] . $seller['id_card_photo'];
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // อัปเดต DB
        $db = Database::getInstance();
        $db->query('UPDATE sellers SET id_card_photo = ? WHERE id = ?', [$urlPath, $id]);

        Logger::logActivity(
            $this->user['user_id'],
            'upload_seller_photo',
            "อัปโหลดรูปบัตรผู้ขาย ID: {$id}"
        );

        Response::success('อัปโหลดรูปบัตรสำเร็จ', [
            'photo_url' => $urlPath,
            'seller_id' => $id,
        ]);
    }

    /**
     * resize รูปภาพและบันทึกเป็น JPEG (max 1920px)
     */
    private function saveResizedImage(string $srcPath, string $destPath): bool
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $srcPath);
        finfo_close($finfo);

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($srcPath),
            'image/png'  => imagecreatefrompng($srcPath),
            'image/webp' => imagecreatefromwebp($srcPath),
            default      => false,
        };

        if (!$src) {
            // fallback: copy ตรง
            return copy($srcPath, $destPath);
        }

        $maxDim = 1920;
        $w = imagesx($src);
        $h = imagesy($src);

        if ($w > $maxDim || $h > $maxDim) {
            if ($w >= $h) {
                $newW = $maxDim;
                $newH = intval($h * $maxDim / $w);
            } else {
                $newH = $maxDim;
                $newW = intval($w * $maxDim / $h);
            }
            $dst = imagecreatetruecolor($newW, $newH);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        $ok = imagejpeg($src, $destPath, 85);
        imagedestroy($src);
        return $ok;
    }
}
