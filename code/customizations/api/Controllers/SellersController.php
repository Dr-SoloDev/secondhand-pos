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
        foreach ($sellers as &$seller) {
            $seller = $this->withProtectedSellerPhoto($seller);
        }
        unset($seller);
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

        Logger::logActivity($this->user['user_id'], 'view_seller_detail', "Viewed seller ID: {$id}", [
            'actor' => $this->user,
            'module' => 'sellers',
            'entity_type' => 'seller',
            'entity_id' => $id,
        ]);
        Response::success('ดึงข้อมูลผู้ขายสำเร็จ', $this->withProtectedSellerPhoto($seller));
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

        $includeBlacklisted = isset($_GET['include_blacklisted'])
                              && $_GET['include_blacklisted'] === 'true';

        $sellerModel = new Seller();
        $results = $sellerModel->search($keyword, $includeBlacklisted);
        foreach ($results as &$seller) {
            $seller = $this->withProtectedSellerPhoto($seller);
        }
        unset($seller);

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
        $data['full_name'] = $this->sanitizeInput($data['full_name'], 150);

        // Validate เลขบัตรประชาชน (ถ้ามี)
        if (!empty($data['id_card'])) {
            $idCard = preg_replace('/[^0-9]/', '', $data['id_card']);
            if (strlen($idCard) !== 13) {
                Response::error('เลขบัตรประชาชนต้องเป็น 13 หลัก', 400);
            }
            $data['id_card'] = $idCard;
        }

        // Cashier may assign the seller tier as part of the purchase workflow.
        if (isset($data['tier_level'])) {
            $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
            $tierLevel = (int)$data['tier_level'];
            if ($tierLevel < 1 || $tierLevel > 3) {
                Response::error('ระดับราคาพิเศษไม่ถูกต้อง (1-3)', 400);
            }
            $data['tier_level'] = $tierLevel;
        }

        if (!in_array(($this->user['role'] ?? ''), ['admin', 'manager', 'super_manager'], true)
            && !empty($data['is_blacklisted'])) {
            Response::error('ไม่มีสิทธิ์ขึ้นบัญชีดำผู้ขาย', 403);
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
                "Created seller ID: {$sellerId}",
                [
                    'actor' => $this->user,
                    'module' => 'sellers',
                    'entity_type' => 'seller',
                    'entity_id' => $sellerId,
                    'after' => $sellerModel->getById($sellerId),
                ]
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
        $current = $sellerModel->getById($id);
        if (!$current) {
            Response::error('ไม่พบผู้ขายนี้', 404);
        }

        if (!in_array(($this->user['role'] ?? ''), ['admin', 'manager', 'super_manager'], true)) {
            $blacklistChanged = isset($data['is_blacklisted'])
                && (int)$data['is_blacklisted'] !== (int)($current['is_blacklisted'] ?? 0);
            $reasonChanged = array_key_exists('blacklist_reason', $data)
                && trim((string)$data['blacklist_reason']) !== trim((string)($current['blacklist_reason'] ?? ''));
            if ($blacklistChanged || $reasonChanged) {
                Response::error('ไม่มีสิทธิ์เปลี่ยนสถานะบัญชีดำผู้ขาย', 403);
            }
            unset($data['is_blacklisted'], $data['blacklist_reason'], $data['blacklisted_at'], $data['blacklisted_by']);
        }

        // Only stamp pdpa_consented_at once — if consent given and not yet recorded
        if (!empty($data['pdpa_consent'])) {
            if ($current && $current['pdpa_consented_at'] === null) {
                $data['pdpa_consented_at'] = date('Y-m-d H:i:s');
            }
        }
        unset($data['pdpa_consent']);

        // Cashier may change tier_level; blacklist remains manager/admin-only.
        if (isset($data['tier_level']) && (int)$data['tier_level'] !== (int)($current['tier_level'] ?? 1)) {
            $this->requireAuth(['admin', 'cashier', 'manager', 'super_manager']);
            $newTier = (int)$data['tier_level'];
            if ($newTier < 1 || $newTier > 3) {
                Response::error('ระดับราคาพิเศษไม่ถูกต้อง (1-3)', 400);
            }
            $tierLabels = [1 => 'บิล 1 (ทั่วไป)', 2 => 'บิล 2', 3 => 'บิล 3'];
            $oldLabel = $tierLabels[(int)($current['tier_level'] ?? 1)];
            $newLabel = $tierLabels[$newTier];
        }

        try {
            $sellerModel->update($id, $data);

            // Audit log — tier change
            $tierChanged = isset($data['tier_level']) && (int)$data['tier_level'] !== (int)($current['tier_level'] ?? 1);
            if ($tierChanged && isset($oldLabel, $newLabel)) {
                Logger::logActivity(
                    $this->user['user_id'],
                    'update_seller_tier',
                    "Changed seller tier ID: {$id}: {$oldLabel} to {$newLabel}",
                    [
                        'actor' => $this->user,
                        'module' => 'sellers',
                        'entity_type' => 'seller',
                        'entity_id' => $id,
                        'before' => ['tier_level' => $current['tier_level'] ?? 1],
                        'after' => ['tier_level' => $newTier],
                    ]
                );
            }

            Logger::logActivity(
                $this->user['user_id'],
                'update_seller',
                "Updated seller ID: {$id}",
                [
                    'actor' => $this->user,
                    'module' => 'sellers',
                    'entity_type' => 'seller',
                    'entity_id' => $id,
                    'before' => $current,
                    'after' => $sellerModel->getById($id),
                ]
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
                'photo_path'      => !empty($item['photo_path'])
                    ? $this->protectedItemPhotoUrl((int)$item['id'])
                    : null,
                'notes'           => $item['notes'] ?? '',
            ];
        }

        $photosByPo = [];
        $photosByItem = [];
        foreach ($allPhotos as $photo) {
            $poId = $photo['purchase_order_id'];
            $itemId = $photo['purchase_order_item_id'];

            // Group by PO (for gallery)
            if (!isset($photosByPo[$poId])) $photosByPo[$poId] = [];
            $photosByPo[$poId][] = [
                'id'         => $photo['id'],
                'photo_path' => $this->protectedPurchasePhotoUrl((int)$photo['id']),
                'is_primary' => $photo['is_primary'],
                'item_id'    => $itemId,
            ];

            // Group by Item (for inline thumbnail)
            if ($itemId) {
                if (!isset($photosByItem[$itemId])) $photosByItem[$itemId] = [];
                $photosByItem[$itemId][] = [
                    'id'         => $photo['id'],
                    'photo_path' => $this->protectedPurchasePhotoUrl((int)$photo['id']),
                    'is_primary' => $photo['is_primary'],
                ];
            }
        }

        // 4.5. tier_level fallback
        $tierLevel = isset($seller['tier_level']) ? (int)$seller['tier_level'] : 1;
        if ($tierLevel < 1 || $tierLevel > 3) $tierLevel = 1;

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

            $poItems = $itemsByPo[$poId] ?? [];
            // Attach item-level photos to each item
            foreach ($poItems as &$item) {
                $itemId = $item['id'];
                $item['photos'] = $photosByItem[$itemId] ?? [];
            }
            unset($item);

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
                'items'            => $poItems,
                'photos'           => $photosByPo[$poId] ?? [],
            ];

            // Visit count and amount include only completed purchase orders.
            if ($po['status'] === 'completed') {
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
        }

        Logger::logActivity($this->user['user_id'], 'view_seller_data_center', "Viewed seller data center ID: {$id}", [
            'actor' => $this->user,
            'module' => 'sellers',
            'entity_type' => 'seller',
            'entity_id' => $id,
        ]);

        // 6. Response
        Response::success('สำเร็จ', [
            'seller' => [
                'id'                 => $seller['id'],
                'full_name'          => $seller['full_name'],
                'id_card'            => $seller['id_card'] ?? '',
                'phone'              => $seller['phone'] ?? '',
                'address'            => $seller['address'] ?? '',
                'vehicle_plate'      => $seller['vehicle_plate'] ?? '',
                'vehicle_type'       => $seller['vehicle_type'] ?? '',
                'id_card_photo'      => !empty($seller['id_card_photo'])
                    ? $this->protectedSellerPhotoUrl((int)$seller['id'])
                    : '',
                'pdpa_consented_at'  => $seller['pdpa_consented_at'] ?? null,
                'is_blacklisted'     => $seller['is_blacklisted'] ?? 0,
                'blacklist_reason'   => $seller['blacklist_reason'] ?? '',
                'notes'              => $seller['notes'] ?? '',
                'tier_level'         => $tierLevel,
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
        $this->requireAuth(['admin', 'manager', 'super_manager']);

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
                "Blacklisted seller ID: {$id}",
                [
                    'actor' => $this->user,
                    'module' => 'sellers',
                    'entity_type' => 'seller',
                    'entity_id' => $id,
                    'reason' => $reason,
                    'after' => $sellerModel->getById($id),
                ]
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
        $this->requireAuth(['admin', 'manager', 'super_manager']);

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
                "Removed seller blacklist ID: {$id}",
                [
                    'actor' => $this->user,
                    'module' => 'sellers',
                    'entity_type' => 'seller',
                    'entity_id' => $id,
                    'after' => $sellerModel->getById($id),
                ]
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
        $uploadBase = UPLOAD_DIR . '/sellers';
        $year = date('Y');
        $month = date('m');
        $dir = "{$uploadBase}/{$year}/{$month}";

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            Response::error('ไม่สามารถสร้าง目录จัดเก็บได้', 500);
        }

        // ตรวจสอบพื้นที่ว่าง
        if (disk_free_space(UPLOAD_DIR) < 50 * 1024 * 1024) {
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
            "Uploaded seller ID card photo ID: {$id}",
            [
                'actor' => $this->user,
                'module' => 'sellers',
                'entity_type' => 'seller',
                'entity_id' => $id,
                'after' => ['id_card_photo' => $urlPath],
            ]
        );

        Response::success('อัปโหลดรูปบัตรสำเร็จ', [
            'photo_url' => $this->protectedSellerPhotoUrl($id),
            'seller_id' => $id,
        ]);
    }

    public function viewPhoto($id = null)
    {
        $this->requireAuth(['admin', 'manager', 'super_manager', 'cashier']);
        $id = intval($id ?? ($_GET['id'] ?? 0));
        $seller = (new Seller())->getById($id);
        if (!$seller || empty($seller['id_card_photo'])) {
            Response::error('ไม่พบรูปบัตรผู้ขาย', 404);
        }

        $storedPath = (string)$seller['id_card_photo'];
        $prefix = '/uploads/sellers/';
        if (strpos($storedPath, $prefix) !== 0) {
            Response::error('Invalid image path', 404);
        }
        $baseDir = realpath(UPLOAD_DIR . '/sellers');
        $filePath = realpath(UPLOAD_DIR . substr($storedPath, strlen('/uploads')));
        if (!$baseDir || !$filePath || strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
            Response::error('ไม่พบไฟล์รูปบัตรผู้ขาย', 404);
        }

        Logger::logActivity($this->user['user_id'], 'view_seller_id_card_photo', "Viewed seller ID card photo ID: {$id}", [
            'actor' => $this->user,
            'module' => 'sellers',
            'entity_type' => 'seller',
            'entity_id' => $id,
        ]);

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
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

    private function withProtectedSellerPhoto(array $seller): array
    {
        if (!empty($seller['id_card_photo']) && !empty($seller['id'])) {
            $seller['id_card_photo'] = $this->protectedSellerPhotoUrl((int)$seller['id']);
        }
        return $seller;
    }

    private function protectedSellerPhotoUrl(int $sellerId): string
    {
        return rtrim(BASE_PATH, '/') . '/index.php/sellers/photo-view?id=' . $sellerId;
    }

    private function protectedPurchasePhotoUrl(int $photoId): string
    {
        return rtrim(BASE_PATH, '/') . '/index.php/purchase-orders/photo-file?id=' . $photoId;
    }

    private function protectedItemPhotoUrl(int $itemId): string
    {
        return rtrim(BASE_PATH, '/') . '/index.php/purchase-orders/item-photo-file?id=' . $itemId;
    }
}
