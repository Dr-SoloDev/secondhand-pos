<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ทดสอบ ImageWatermark — ลายทแยง 45° บนรูปบัตรประชาชน
 * ข้ามทั้งชุดถ้า env ไม่มี gd/font (CI บาง runner ไม่มี gd)
 */
class ImageWatermarkTest extends TestCase
{
    private const TEST_W = 600;
    private const TEST_H = 400;

    protected function setUp(): void
    {
        if (!\ImageWatermark::available()) {
            self::markTestSkipped('gd/font not available: ' . var_export(\ImageWatermark::fontPath(), true));
        }
    }

    /** สร้างรูปพื้นขาว + เก็บ baseline jpeg */
    private function makeImage()
    {
        $im = imagecreatetruecolor(self::TEST_W, self::TEST_H);
        imagefilledrectangle($im, 0, 0, self::TEST_W - 1, self::TEST_H - 1, imagecolorallocate($im, 255, 255, 255));
        return $im;
    }

    private function toJpeg($im): string
    {
        ob_start();
        imagejpeg($im, null, 85);
        return (string)ob_get_clean();
    }

    private function countNonWhiteCenter($im): int
    {
        $count = 0;
        for ($y = (int)(self::TEST_H * 0.3); $y < (int)(self::TEST_H * 0.7); $y += 2) {
            for ($x = (int)(self::TEST_W * 0.3); $x < (int)(self::TEST_W * 0.7); $x += 2) {
                if ((imagecolorat($im, $x, $y) & 0xFFFFFF) !== 0xFFFFFF) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function testApplyKeepsDimensions(): void
    {
        $im = $this->makeImage();
        self::assertTrue(\ImageWatermark::apply($im));
        self::assertSame(self::TEST_W, imagesx($im));
        self::assertSame(self::TEST_H, imagesy($im));
        imagedestroy($im);
    }

    public function testApplyChangesBytes(): void
    {
        $im = $this->makeImage();
        $before = $this->toJpeg($im);
        self::assertTrue(\ImageWatermark::apply($im));
        $after = $this->toJpeg($im);
        self::assertNotSame($before, $after);
        imagedestroy($im);
    }

    public function testWatermarkDrawsPixelsInCenter(): void
    {
        $im = $this->makeImage();
        $beforePixels = $this->countNonWhiteCenter($im);
        self::assertTrue(\ImageWatermark::apply($im));
        $afterPixels = $this->countNonWhiteCenter($im);
        // ลายถูกวาดจริง: center region มี pixel ใหม่เยอะกว่า baseline (พื้นขาวล้วน = 0)
        self::assertSame(0, $beforePixels);
        self::assertGreaterThan(100, $afterPixels);
        imagedestroy($im);
    }

    public function testTinyImageSkippedWithoutError(): void
    {
        $im = imagecreatetruecolor(80, 50);
        imagefilledrectangle($im, 0, 0, 79, 49, imagecolorallocate($im, 255, 255, 255));
        // รูปเล็กเกินไป → ข้าม ไม่ throw
        self::assertFalse(\ImageWatermark::apply($im));
        imagedestroy($im);
    }

    public function testInvalidInputReturnsFalse(): void
    {
        self::assertFalse(\ImageWatermark::apply('not-an-image'));
        self::assertFalse(\ImageWatermark::apply(null));
    }

    public function testFontPathResolves(): void
    {
        $path = \ImageWatermark::fontPath();
        self::assertNotNull($path);
        self::assertFileExists($path);
    }
}
