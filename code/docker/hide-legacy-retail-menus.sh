#!/bin/bash
# ============================================================
# hide-legacy-retail-menus.sh
# ซ่อนเมนู base-pos เดิมที่ไม่เข้ากับธุรกิจรับซื้อของเก่า:
#   - "ขายหน้าร้าน" (../pos/index.html, index.html ใน /pos)
#   - "ประวัติการขาย" (sales.html)
#
# ⚠️ รันหลังจาก Dr.solodev / เจ้าของ ตัดสินใจแล้วเท่านั้น
# ⚠️ backup: backups/pre-demo-*.sql มีอยู่แล้ว / git commit ก่อนรัน
#
# Usage: bash docker/hide-legacy-retail-menus.sh
# ============================================================
set -e
cd "$(dirname "${BASH_SOURCE[0]}")/.."   # → code/

echo "ซ่อนเมนู 'ขายหน้าร้าน' + 'ประวัติการขาย' ในทุกหน้า..."

# comment out <li> ที่ลิงก์ไป pos/index.html และ sales.html
# ใช้ perl เพื่อ match ทั้งบรรทัด <li>...</li> ที่มี href เหล่านี้
for f in base-pos/admin/*.html base-pos/pos/*.html; do
  [ -f "$f" ] || continue
  perl -0777 -i -pe 's{(<li>\s*<a href="[^"]*(?:pos/index\.html|(?<!_)sales\.html)"[^>]*>.*?</a>\s*</li>)}{<!-- legacy retail menu hidden: $1 -->}gs' "$f"
done

echo "✅ เสร็จ — ตรวจสอบ: grep -rl 'ขายหน้าร้าน' base-pos/admin/*.html"
echo "↩️  ย้อนกลับ: git checkout base-pos/admin base-pos/pos"
