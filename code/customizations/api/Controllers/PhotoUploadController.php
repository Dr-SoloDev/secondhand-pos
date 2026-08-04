<?php
/**
 * PhotoUploadController
 * WF-01: รับ upload รูปสินค้าจากมือถือ ผ่าน QR handoff
 *
 * Endpoints:
 *   POST /api/purchase-orders/{id}/photos  — อัพโหลดรูป
 *   GET  /api/purchase-orders/{id}/photos  — ดูรูปทั้งหมดของ PO
 */
class PhotoUploadController extends Controller
{
    // ขีดจำกัด
    private const MAX_SIZE_BYTES  = 10 * 1024 * 1024; // 10 MB
    private const MAX_DIMENSION   = 1920;              // px (longest side)
    private const ALLOWED_MIMES   = ['image/jpeg', 'image/png', 'image/webp'];
    private const UPLOAD_BASE     = UPLOAD_DIR . '/purchase-orders';
    private const URL_BASE        = '/uploads/purchase-orders';

    // -------------------------------------------------------
    // POST /api/purchase-orders/{id}/photos
    // Auth: HMAC token (QR flow) OR Bearer JWT (staff flow)
    // -------------------------------------------------------
    public function upload($poId)
    {
        $poId = intval($poId);
        if ($poId <= 0) {
            Response::error('Invalid purchase order ID', 400);
            return;
        }

        // ตรวจ auth: JWT ก่อน ถ้าไม่มี ลอง HMAC token
        $authed = $this->tryJwtAuth() || $this->tryHmacToken($poId);
        if (!$authed) {
            Response::error('Unauthorized — token ไม่ถูกต้องหรือหมดอายุ', 401);
            return;
        }

        // ตรวจว่า PO มีอยู่จริง
        $db = Database::getInstance();
        $po = $db->fetch('SELECT id, branch_id, status FROM purchase_orders WHERE id = ?', [$poId]);
        if (!$po) {
            Response::error('ไม่พบ Purchase Order', 404);
            return;
        }
        if ($this->user !== null) {
            $this->assertBranchAccess((int)$po['branch_id']);
        }

        // ตรวจ file upload
        if (empty($_FILES['photo'])) {
            Response::error('ไม่พบไฟล์รูป (field: photo)', 400);
            return;
        }

        $file  = $_FILES['photo'];
        $error = $this->validateFile($file);
        if ($error) {
            Response::error($error, 422);
            return;
        }

        // สร้าง path
        $year  = date('Y');
        $month = date('m');
        $dir   = self::UPLOAD_BASE . "/{$year}/{$month}";
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            Response::error('ไม่สามารถสร้าง directory ได้', 500);
            return;
        }

        // ตรวจ disk space (เหลืออย่างน้อย 100 MB)
        if (disk_free_space(self::UPLOAD_BASE) < 100 * 1024 * 1024) {
            Response::error('พื้นที่จัดเก็บเต็ม กรุณาติดต่อผู้ดูแลระบบ', 507);
            return;
        }

        $uuid     = $poId . '_' . bin2hex(random_bytes(8));
        $ext      = 'jpg'; // บันทึกเป็น JPEG เสมอ
        $filename = "{$uuid}.{$ext}";
        $destPath = "{$dir}/{$filename}";
        $urlPath  = self::URL_BASE . "/{$year}/{$month}/{$filename}";

        // resize และบันทึก
        if (!$this->saveResized($file['tmp_name'], $destPath)) {
            Response::error('ไม่สามารถบันทึกไฟล์รูปได้', 500);
            return;
        }

        // บันทึก DB
        $itemId = isset($_POST['item_id']) ? intval($_POST['item_id']) : null;
        if ($itemId) {
            $validItem = $db->fetchColumn(
                'SELECT 1 FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?',
                [$itemId, $poId]
            );
            if (!$validItem) {
                @unlink($destPath);
                Response::error('รายการสินค้าไม่อยู่ใน Purchase Order นี้', 422);
                return;
            }
        }
        $db->query(
            'INSERT INTO purchase_order_photos (purchase_order_id, purchase_order_item_id, photo_path, is_primary)
             VALUES (?, ?, ?, ?)',
            [$poId, $itemId ?: null, $urlPath, 0]
        );
        $photoId = $db->lastInsertId();

        Response::success('อัพโหลดสำเร็จ', [
            'photo_id'  => $photoId,
            'photo_url' => $this->protectedPhotoUrl((int)$photoId),
        ]);
    }

    // -------------------------------------------------------
    // GET /api/purchase-orders/photo-token?id={po_id}
    // Auth: JWT (staff บน desktop) — สร้าง HMAC token ส่งกลับ
    // -------------------------------------------------------
    public function photoToken($poId)
    {
        $this->requireAuth();
        $poId = intval($poId);
        if ($poId <= 0) {
            Response::error('Invalid purchase order ID', 400);
            return;
        }
        $po = Database::getInstance()->fetch(
            'SELECT id, branch_id FROM purchase_orders WHERE id = ?',
            [$poId]
        );
        if (!$po) {
            Response::error('ไม่พบ Purchase Order', 404);
            return;
        }
        $this->assertBranchAccess((int)$po['branch_id']);
        $t = self::makeToken($poId);
        Response::success('Token created', $t);
    }

    // -------------------------------------------------------
    // GET /api/purchase-orders/{id}/photos
    // -------------------------------------------------------
    public function list($poId)
    {
        $poId = intval($poId);

        $authed = $this->tryJwtAuth() || $this->tryHmacToken($poId);
        if (!$authed) {
            Response::error('Unauthorized', 401);
            return;
        }

        $db = Database::getInstance();
        $po = $db->fetch('SELECT id, branch_id FROM purchase_orders WHERE id = ?', [$poId]);
        if (!$po) {
            Response::error('ไม่พบ Purchase Order', 404);
            return;
        }
        if ($this->user !== null) {
            $this->assertBranchAccess((int)$po['branch_id']);
        }
        $photos = $db->fetchAll(
            'SELECT id, purchase_order_item_id, photo_path, is_primary, created_at
             FROM purchase_order_photos
             WHERE purchase_order_id = ?
             ORDER BY created_at ASC',
            [$poId]
        );

        foreach ($photos as &$photo) {
            $photo['photo_path'] = $this->protectedPhotoUrl((int)$photo['id']);
        }
        unset($photo);

        Response::success('Photos retrieved', ['photos' => $photos]);
    }

    public function view($photoId)
    {
        $photoId = intval($photoId);
        $photo = Database::getInstance()->fetch(
            "SELECT pop.photo_path, po.id AS purchase_order_id, po.branch_id
             FROM purchase_order_photos pop
             INNER JOIN purchase_orders po ON po.id = pop.purchase_order_id
             WHERE pop.id = ?",
            [$photoId]
        );
        if (!$photo) {
            Response::error('ไม่พบรูปภาพ', 404);
            return;
        }
        $this->authorizeFileAccess((int)$photo['purchase_order_id'], (int)$photo['branch_id']);
        $this->sendImage($photo['photo_path']);
    }

    public function viewItemPhoto($itemId)
    {
        $itemId = intval($itemId);
        $item = Database::getInstance()->fetch(
            "SELECT poi.photo_path, po.id AS purchase_order_id, po.branch_id
             FROM purchase_order_items poi
             INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id
             WHERE poi.id = ?",
            [$itemId]
        );
        if (!$item || empty($item['photo_path'])) {
            Response::error('ไม่พบรูปภาพ', 404);
            return;
        }
        $this->authorizeFileAccess((int)$item['purchase_order_id'], (int)$item['branch_id']);
        $this->sendImage($item['photo_path']);
    }

    // -------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------

    /** ลอง authenticate ด้วย JWT Bearer token หรือ httpOnly cookie */
    private function tryJwtAuth(): bool
    {
        if ($this->user !== null) return true;
        $token = $_COOKIE['posToken'] ?? '';
        if (empty($token)) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
            }
        }
        if (empty($token)) return false;
        $decoded = TokenService::validate($token);
        if (!$decoded) {
            return false;
        }

        $currentUser = Database::getInstance()->fetch(
            'SELECT id, username, role, branch_id, status, auth_version FROM users WHERE id = ?',
            [(int)($decoded['user_id'] ?? 0)]
        );
        if (!$currentUser || $currentUser['status'] !== 'active') {
            return false;
        }
        if ((int)($decoded['auth_version'] ?? 1) !== (int)($currentUser['auth_version'] ?? 1)) {
            return false;
        }

        $this->user = array_merge($decoded, [
            'user_id' => (int)$currentUser['id'],
            'username' => $currentUser['username'],
            'role' => $currentUser['role'],
            'branch_id' => $currentUser['branch_id'] !== null ? (int)$currentUser['branch_id'] : null,
        ]);
        return true;
    }

    /** ลอง authenticate ด้วย HMAC token ใน query string */
    private function tryHmacToken(int $poId): bool
    {
        $token   = $_GET['token'] ?? '';
        $expires = $_GET['expires'] ?? '';
        if (!$token || !$expires) {
            return false;
        }

        if (time() > intval($expires)) {
            return false; // หมดอายุ
        }

        $secret   = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        $expected = hash_hmac('sha256', "po:{$poId}:upload:{$expires}", $secret);

        return hash_equals($expected, $token);
    }

    /** สร้าง HMAC token (ใช้ generate QR URL) */
    public static function makeToken(int $poId): array
    {
        $expires = time() + 86400; // 24 ชั่วโมง
        $secret  = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? '';
        $token   = hash_hmac('sha256', "po:{$poId}:upload:{$expires}", $secret);
        return ['token' => $token, 'expires' => $expires];
    }

    private function authorizeFileAccess(int $poId, int $branchId): void
    {
        if ($this->tryJwtAuth()) {
            $this->assertBranchAccess($branchId);
            return;
        }
        if (!$this->tryHmacToken($poId)) {
            Response::error('Unauthorized', 401);
        }
    }

    private function assertBranchAccess(int $branchId): void
    {
        $role = $this->user['role'] ?? '';
        if (in_array($role, ['admin', 'super_manager'], true)) {
            return;
        }
        if (empty($this->user['branch_id']) || (int)$this->user['branch_id'] !== $branchId) {
            Response::error('ไม่มีสิทธิ์เข้าถึงรูปของสาขานี้', 403);
        }
    }

    private function protectedPhotoUrl(int $photoId): string
    {
        $base = rtrim(BASE_PATH, '/') . '/index.php/purchase-orders/photo-file?id=' . $photoId;
        if (!empty($_GET['token']) && !empty($_GET['expires'])) {
            $base .= '&token=' . rawurlencode((string)$_GET['token'])
                  . '&expires=' . rawurlencode((string)$_GET['expires']);
        }
        return $base;
    }

    private function sendImage(string $storedPath): void
    {
        $prefix = '/uploads/purchase-orders/';
        if (strpos($storedPath, $prefix) !== 0) {
            Response::error('Invalid image path', 404);
        }

        $baseDir = realpath(self::UPLOAD_BASE);
        $filePath = realpath(UPLOAD_DIR . substr($storedPath, strlen('/uploads')));
        if (!$baseDir || !$filePath || strpos($filePath, $baseDir . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
            Response::error('ไม่พบไฟล์รูปภาพ', 404);
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    /** validate ไฟล์ — คืน error string หรือ null ถ้าผ่าน */
    private function validateFile(array $file): ?string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกินที่ server อนุญาต',
                UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกินที่ form อนุญาต',
                UPLOAD_ERR_PARTIAL    => 'อัพโหลดไม่สมบูรณ์',
                UPLOAD_ERR_NO_FILE    => 'ไม่พบไฟล์',
                UPLOAD_ERR_NO_TMP_DIR => 'ไม่มี tmp directory',
                UPLOAD_ERR_CANT_WRITE => 'เขียนไฟล์ไม่ได้',
            ];
            return $msgs[$file['error']] ?? 'Upload error: ' . $file['error'];
        }

        if ($file['size'] > self::MAX_SIZE_BYTES) {
            return 'ไฟล์ใหญ่เกิน 10 MB';
        }

        // ตรวจ MIME จาก content จริง (ไม่เชื่อ extension)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return "ไฟล์ประเภท {$mime} ไม่รองรับ (รองรับ: JPEG, PNG, WebP)";
        }

        return null;
    }

    /** resize ถ้าใหญ่เกิน MAX_DIMENSION แล้วบันทึกเป็น JPEG */
    private function saveResized(string $srcPath, string $destPath): bool
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $srcPath);
        finfo_close($finfo);

        $src = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($srcPath),
            'image/png'  => imagecreatefrompng($srcPath),
            'image/webp' => imagecreatefromwebp($srcPath),
            default      => false,
        };

        if (!$src) {
            return false;
        }

        $w = imagesx($src);
        $h = imagesy($src);

        // resize ถ้าเกิน
        if ($w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION) {
            if ($w >= $h) {
                $newW = self::MAX_DIMENSION;
                $newH = intval($h * self::MAX_DIMENSION / $w);
            } else {
                $newH = self::MAX_DIMENSION;
                $newW = intval($w * self::MAX_DIMENSION / $h);
            }
            $dst = imagecreatetruecolor($newW, $newH);
            // รักษา alpha สำหรับ PNG
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        $ok = imagejpeg($src, $destPath, 85); // quality 85
        imagedestroy($src);
        return $ok;
    }
}
