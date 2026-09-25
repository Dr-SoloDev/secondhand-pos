<?php
/**
 * ImageWatermark — วาดข้อความลายทแยง 45° บนรูปบัตรประชาชน (bake ลงไฟล์ตอนอัปโหลด)
 *
 * วัตถุประสงค์:
 * 1. กันนำรูปบัตรไปใช้ซ้ำผิดวัตถุประสงค์ (ไฟล์หลุดนอกระบบยังมีลาย)
 * 2. แสดงความยินยอม PDPA ของผู้ขายบนตัวรูปเอง
 *
 * ลาย = บล็อกข้อความ 2 บรรทัด (หมุน 45°) วางกึ่งกลางรูป
 * Fail-open: ถ้า gd/font ไม่พร้อม → คืน false (ไม่ให้การอัปโหลดล้มเหลว)
 */
class ImageWatermark
{
    /** ข้อความลาย — ห้ามแก้ไขข้อความนอกเหนือจาก Owner สั่ง */
    const LINES = [
        'ใช้สำหรับซื้อขายของเก่าเท่านั้น ผู้ขายรับรองว่ามิใช่ของขโมย',
        'ผู้ขายตกลงยินยอมให้เก็บข้อมูลเอกสารนี้ไว้เพื่อยืนยันด้วยความสมัครใจ',
    ];

    const FONT_MIN_PX = 10;
    const FONT_MAX_PX = 72;
    const ROTATE_DEG = 45;      // องศาแนวทแยง (imagerotate บวก = ทวนเข็ม = ลาย "/")

    /** ตรวจว่า runtime พร้อมวาดลายไหม (gd + imagettftext + ไฟล์ฟอนต์) */
    public static function available(): bool
    {
        return function_exists('imagettftext') && self::fontPath() !== null;
    }

    /** resolve พาธฟอนต์: env ก่อน แล้วค่อย fallback (docker image / repo / host) */
    public static function fontPath(): ?string
    {
        static $resolved = false;
        static $path = null;
        if ($resolved) return $path;
        $resolved = true;

        $candidates = [];
        $env = getenv('IDCARD_WATERMARK_FONT');
        if (is_string($env) && trim($env) !== '') {
            $candidates[] = trim($env);
        }
        $candidates[] = '/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf';
        // จาก customizations/api/Services → code/docker/fonts
        $candidates[] = dirname(__DIR__, 3) . '/docker/fonts/NotoSansThai-Regular.ttf';
        $candidates[] = '/usr/share/fonts/truetype/tlwg/Loma.ttf';

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                $path = $candidate;
                return $path;
            }
        }
        return null;
    }

    /**
     * วาดลายลงบน GD image (truecolor, ผ่าน resize แล้ว) กลางรูป
     * @param resource|\GdImage $im
     * @return bool true = วาดสำเร็จ, false = ข้าม/ล้มเหลว (ไม่ throw)
     */
    public static function apply($im): bool
    {
        try {
            if (!is_resource($im) && !(is_object($im) && $im instanceof GdImage)) {
                return false;
            }
            if (!self::available()) {
                error_log('[Watermark] skipped: gd/font unavailable');
                return false;
            }

            $w = imagesx($im);
            $h = imagesy($im);
            if ($w < 120 || $h < 80) {
                return false; // รูปเล็กเกินไป ไม่วาด (ลายจะบังข้อมูลบัตร)
            }

            $fontPath = self::fontPath();
            $diag = sqrt($w * $w + $h * $h);
            $longChars = mb_strlen(self::LINES[1], 'UTF-8');

            // ---- หา font size ที่พอดี (ประมาณก่อน แล้ววัดจริงย่อจน fit) ----
            $font = (int)floor(min(0.035 * $w, $diag / (0.55 * max($longChars, 1))));
            $font = max(self::FONT_MIN_PX, min(self::FONT_MAX_PX, $font));

            $metrics = self::measure($font, $fontPath);
            $tries = 0;
            while ($tries++ < 40 && $font > self::FONT_MIN_PX
                && !self::blockFits($metrics['textW'], $metrics['blockH'], $w, $h)) {
                $font = max(self::FONT_MIN_PX, (int)floor($font * 0.9));
                $metrics = self::measure($font, $fontPath);
            }

            // ---- วาดบล็อกข้อความบน canvas โปร่งใส ----
            $lineH = (int)round($font * 1.3);
            $blockW = $metrics['textW'] + $font * 2;   // padding รอบขอบ
            $blockH = $lineH * 2 + (int)round($font * 1.2);

            $canvas = imagecreatetruecolor($blockW, $blockH);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $blockW - 1, $blockH - 1, $transparent);
            imagealphablending($canvas, true);

            $outline = imagecolorallocatealpha($canvas, 20, 20, 20, 32);   // เส้นขอบเข้ม ~75% ทึบ
            $main = imagecolorallocatealpha($canvas, 255, 255, 255, 64);   // ขาว ~50% ทึบ

            foreach (self::LINES as $i => $line) {
                $bbox = imagettfbbox($font, 0, $fontPath, $line);
                $lineW = abs($bbox[2] - $bbox[0]);
                $x = (int)(($blockW - $lineW) / 2);
                $y = (int)($font * 1.1) + $i * $lineH;
                // outline: วาดสีเข้มถ่วง 8 ทิศก่อน (1px)
                foreach ([[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, 1], [-1, 1], [1, -1]] as $off) {
                    imagettftext($canvas, $font, 0, $x + $off[0], $y + $off[1], $outline, $fontPath, $line);
                }
                imagettftext($canvas, $font, 0, $x, $y, $main, $fontPath, $line);
            }

            // ---- หมุน 45° แล้ววางกลางรูป ----
            $rotated = imagerotate($canvas, self::ROTATE_DEG, $transparent);
            imagedestroy($canvas);
            if ($rotated === false) {
                return false;
            }

            $rw = imagesx($rotated);
            $rh = imagesy($rotated);
            $dx = (int)(($w - $rw) / 2);
            $dy = (int)(($h - $rh) / 2);

            imagealphablending($im, true);          // src-over merge (alpha ของลายถูก blend)
            imagecopy($im, $rotated, $dx, $dy, 0, 0, $rw, $rh);
            imagedestroy($rotated);
            return true;
        } catch (Throwable $e) {
            error_log('[Watermark] apply failed: ' . $e->getMessage());
            return false;
        }
    }

    /** วัดความกว้างบล็อกจริงจากฟอนต์ */
    private static function measure(int $font, string $fontPath): array
    {
        $bbox = imagettfbbox($font, 0, $fontPath, self::LINES[1]);
        $textW = abs($bbox[2] - $bbox[0]);
        $lineH = (int)round($font * 1.3);
        return [
            'textW'  => $textW,
            'blockH' => $lineH * 2 + (int)round($font * 1.2),
        ];
    }

    /**
     * บล็อกหมุน 45° แล้วยังอยู่ในรูปไหม (เผื่อ margin 5%)
     * extent = textW*cos45 + blockH*sin45 ทั้งแกนนอน/ตั้ง (cos45 = sin45)
     */
    private static function blockFits(int $textW, int $blockH, int $w, int $h): bool
    {
        $s = sin(deg2rad(self::ROTATE_DEG));
        $c = cos(deg2rad(self::ROTATE_DEG));
        $extentX = $textW * $c + $blockH * $s;
        $extentY = $textW * $s + $blockH * $c;
        return $extentX <= $w * 0.95 && $extentY <= $h * 0.95;
    }
}
