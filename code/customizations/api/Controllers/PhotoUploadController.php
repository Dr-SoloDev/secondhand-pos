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
    private const UPLOAD_BASE     = '/var/www/html/uploads/purchase-orders';
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
        $po = $db->fetch('SELECT id, status FROM purchase_orders WHERE id = ?', [$poId]);
        if (!$po) {
            Response::error('ไม่พบ Purchase Order', 404);
            return;
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
        $db->query(
            'INSERT INTO purchase_order_photos (purchase_order_id, purchase_order_item_id, photo_path, is_primary)
             VALUES (?, ?, ?, ?)',
            [$poId, $itemId ?: null, $urlPath, 0]
        );
        $photoId = $db->lastInsertId();

        Response::success('อัพโหลดสำเร็จ', [
            'photo_id'  => $photoId,
            'photo_url' => $urlPath,
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

        $db     = Database::getInstance();
        $photos = $db->fetchAll(
            'SELECT id, purchase_order_item_id, photo_path, is_primary, created_at
             FROM purchase_order_photos
             WHERE purchase_order_id = ?
             ORDER BY created_at ASC',
            [$poId]
        );

        Response::success('Photos retrieved', ['photos' => $photos]);
    }

    // -------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------

    /** ลอง authenticate ด้วย JWT Bearer token (ไม่ exit — แค่ return bool) */
    private function tryJwtAuth(): bool
    {
        // $this->user ถูก set โดย Router::checkAuth() ถ้า Bearer token valid
        return $this->user !== null;
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
