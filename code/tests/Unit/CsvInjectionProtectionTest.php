<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for CSV/Excel formula-injection protection (Response).
 *
 * ที่มา: Response::csv() เดิมใช้ regex /^[=+\-@]/ — ไม่จัดการ whitespace/BOM
 * นำหน้า ทำให้ cell แบบ " =cmd" หรือ "\u{FEFF}=1+1|cmd" หลุด escape
 * → attacker ที่ควบคุมข้อมูล (เช่น ชื่อผู้ขาย, หมายเหตุ) รัน formula
 * ตอนไฟล์ export เปิดใน Excel/Google Sheets ได้
 *
 * Fix: regex /^[\s\x{FEFF}]*[=+\-@]/u — เหมือน spreadsheetSafeRow เดิม
 * ของ FinancialController และย้ายมาเป็น shared helper ที่
 * Response::spreadsheetSafeRow() (single source of truth)
 */
class CsvInjectionProtectionTest extends TestCase
{
    public function testNeutralizesFormulaPrefixCells(): void
    {
        // cell ขึ้นต้น = + - @ ต้องถูก prefix apostrophe ทั้งหมด
        $row = ['=1+1|cmd', '+66812345678', '-5 บาท', '@SUM(A1:B2)'];
        $safe = \Response::spreadsheetSafeRow($row);

        $this->assertSame("'=1+1|cmd", $safe[0]);
        $this->assertSame("'+66812345678", $safe[1]);
        $this->assertSame("'-5 บาท", $safe[2]);
        $this->assertSame("'@SUM(A1:B2)", $safe[3]);
    }

    public function testNeutralizesWhitespaceAndBomPrefixedFormulas(): void
    {
        // ← regression หลัก: space/tab/BOM นำหน้า formula ต้องไม่หลุด
        $row = ["\u{FEFF}=x", '  =evil', "\t+cmd", "\u{00A0}-5"];
        $safe = \Response::spreadsheetSafeRow($row);

        $this->assertSame("'\u{FEFF}=x", $safe[0], 'BOM นำหน้า formula ก็ต้องถูก escape');
        $this->assertSame("'  =evil", $safe[1], 'space นำหน้า formula ก็ต้องถูก escape');
        $this->assertSame("'\t+cmd", $safe[2], 'tab นำหน้า formula ก็ต้องถูก escape');
        $this->assertSame("'\u{00A0}-5", $safe[3], 'NBSP นำหน้า formula ก็ต้องถูก escape');
    }

    public function testLeavesNormalTextAndNonStringsUntouched(): void
    {
        // ข้อความปกติ/ตัวเลข ต้อง pass-through ไม่ถูกแตะ
        $row = ['ปกติ 123', 'abc=def ไม่ใช่ formula เพราะไม่ใช่ตัวเริ่ม', 999, 12.5, null];
        $safe = \Response::spreadsheetSafeRow($row);

        $this->assertSame('ปกติ 123', $safe[0]);
        $this->assertSame('abc=def ไม่ใช่ formula เพราะไม่ใช่ตัวเริ่ม', $safe[1], 'formula กลาง string ไม่ต้อง escape');
        $this->assertSame(999, $safe[2]);
        $this->assertSame(12.5, $safe[3]);
        $this->assertNull($safe[4]);
    }

    public function testPreservesKeyAssociation(): void
    {
        // assoc row (export ใช้ key => value) ต้องคง key เดิม
        $safe = \Response::spreadsheetSafeRow(['name' => '=HYPERLINK("http://evil")', 'qty' => 3]);

        $this->assertSame("'=HYPERLINK(\"http://evil\")", $safe['name']);
        $this->assertSame(3, $safe['qty']);
    }
}
