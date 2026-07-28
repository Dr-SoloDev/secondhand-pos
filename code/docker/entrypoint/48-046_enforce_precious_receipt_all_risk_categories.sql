-- Migration 046: กำหนด requires_precious_receipt สำหรับทุกหมวดโลหะมีค่าตาม ม.357
-- ทองแดง, โลหะมีค่า, ทองเหลือง, อลูมิเนียม, สายไฟ → ต้องเซ็นรับรอง
-- เศษเหล็ก, เครื่องใช้ไฟฟ้า, แบตเตอรี่, มือถือ → ไม่บังคับ (PDPA)

UPDATE categories SET requires_precious_receipt = 1
WHERE name LIKE '%ทองแดง%'
   OR name LIKE '%โลหะมีค่า%'
   OR name LIKE '%ทองเหลือง%'
   OR name LIKE '%อลูมิเนียม%'
   OR name LIKE '%สายไฟ%'
   OR name LIKE '%สายทองแดง%';

UPDATE categories SET requires_precious_receipt = 0
WHERE name LIKE '%เศษเหล็ก%'
   OR name LIKE '%เครื่องใช้ไฟฟ้า%'
   OR name LIKE '%แบตเตอรี่%'
   OR name LIKE '%มือถือ%'
   OR name LIKE '%อุปกรณ์%';
