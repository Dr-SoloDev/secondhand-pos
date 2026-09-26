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
    const FONT_MAX_PX = 160;
    const ROTATE_DEG = 45;      // องศาแนวทแยง (imagerotate บวก = ทวนเข็ม = ลาย "/")
    const FIT_MARGIN = 0.98;    // ใช้พื้นที่รูปได้ถึง 98% (ขยายลายสุดขอบ)
    const PAD_FACTOR = 1.2;     // padding รวมแนวนอน = 1.2×font
    const LINE_FACTOR = 1.2;    // ระยะบรรทัด = 1.2×font (บีบสุดที่ยังอ่านออก)
    const PAD_BOTTOM_FACTOR = 1.0;

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

            // ---- หาฟอนต์ใหญ่สุดที่พอดีรูป (วัดจริงแบบ binary search) ----
            $font = self::maxFittingFont($w, $h, $fontPath);
            if ($font < self::FONT_MIN_PX) {
                return false; // รูปเล็กเกินไป ไม่วาด (ลายจะบังข้อมูลบัตร)
            }

            $metrics = self::measure($font, $fontPath);

            // ---- วาดบล็อกข้อความบน canvas โปร่งใส ----
            $lineH = (int)round($font * self::LINE_FACTOR);
            $blockW = $metrics['textW'] + (int)round($font * self::PAD_FACTOR);
            $blockH = $lineH * 2 + (int)round($font * self::PAD_BOTTOM_FACTOR);

            $canvas = imagecreatetruecolor($blockW, $blockH);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $blockW - 1, $blockH - 1, $transparent);
            imagealphablending($canvas, true);

            $outline = imagecolorallocatealpha($canvas, 20, 20, 20, 16);   // เส้นขอบเข้ม ~87% ทึบ
            $main = imagecolorallocatealpha($canvas, 255, 255, 255, 48);    // ขาว ~62% ทึบ

            // ขอบหนา 2px (วงใน 8 ทิศ + วงนอก 4 ทิศ) ให้อ่านชัดแม้รูปเล็ก
            $offsets = [[-1, 0], [1, 0], [0, -1], [0, 1], [-1, -1], [1, 1], [-1, 1], [1, -1],
                        [-2, 0], [2, 0], [0, -2], [0, 2]];
            foreach (self::LINES as $i => $line) {
                $bbox = imagettfbbox($font, 0, $fontPath, $line);
                $lineW = abs($bbox[2] - $bbox[0]);
                $x = (int)(($blockW - $lineW) / 2);
                $y = (int)($font * 1.1) + $i * $lineH;
                // outline: วาดสีเข้มถ่วงรอบตัวอักษรก่อน
                foreach ($offsets as $off) {
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

    /** วัดความกว้างบล็อกจริงจากฟอนต์ (ใช้บรรทัดที่กว้างสุด) */
    private static function measure(int $font, string $fontPath): array
    {
        $textW = 0;
        foreach (self::LINES as $line) {
            $bbox = imagettfbbox($font, 0, $fontPath, $line);
            $textW = max($textW, abs($bbox[2] - $bbox[0]));
        }
        $lineH = (int)round($font * self::LINE_FACTOR);
        return [
            'textW'  => $textW,
            'blockH' => $lineH * 2 + (int)round($font * self::PAD_BOTTOM_FACTOR),
        ];
    }

    /**
     * หาฟอนต์ใหญ่สุดที่บล็อกหมุน 45° แล้วยังอยู่ในรูป (รวม padding แล้ว)
     * extent แนวนอน/ตั้งของกรอบที่หมุน = blockW*cos + blockH*sin (45° เท่ากันทั้ง 2 แกน)
     */
    private static function maxFittingFont(int $w, int $h, string $fontPath): int
    {
        if (self::fitsAt(self::FONT_MAX_PX, $w, $h, $fontPath)) {
            return self::FONT_MAX_PX;
        }
        $lo = self::FONT_MIN_PX - 1; // ไม่ fit (หรือไม่ถึงขั้นต่ำ)
        $hi = self::FONT_MAX_PX;     // ไม่ fit
        while ($hi - $lo > 1) {
            $mid = (int)(($lo + $hi) / 2);
            if (self::fitsAt($mid, $w, $h, $fontPath)) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    private static function fitsAt(int $font, int $w, int $h, string $fontPath): bool
    {
        $m = self::measure($font, $fontPath);
        $blockW = $m['textW'] + (int)round($font * self::PAD_FACTOR);
        $s = sin(deg2rad(self::ROTATE_DEG));
        $c = cos(deg2rad(self::ROTATE_DEG));
        $extentX = $blockW * $c + $m['blockH'] * $s;
        $extentY = $blockW * $s + $m['blockH'] * $c;
        return $extentX <= $w * self::FIT_MARGIN && $extentY <= $h * self::FIT_MARGIN;
    }
}
