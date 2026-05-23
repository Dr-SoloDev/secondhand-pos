#!/bin/bash
# ============================================================
# run-migrations.sh
# Purpose: รัน migration ทั้งหมดเรียงตามลำดับ
# Usage: ./run-migrations.sh [db_user] [db_password]
# ============================================================

set -e

DB_USER="${1:-root}"
DB_PASS="${2:-}"
DB_NAME="pos_system"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_SQL="$SCRIPT_DIR/../../base-pos/database/pos_system.sql"
MIGRATIONS_DIR="$SCRIPT_DIR/migrations"

echo "=========================================="
echo " Secondhand POS — Database Migration"
echo "=========================================="

# ตรวจสอบว่ามี mysql client
if ! command -v mysql &> /dev/null; then
    echo "❌ ไม่พบ mysql client กรุณาติดตั้ง MySQL/MariaDB ก่อน"
    exit 1
fi

# Build mysql command
if [ -z "$DB_PASS" ]; then
    MYSQL_CMD="mysql -u $DB_USER"
else
    MYSQL_CMD="mysql -u $DB_USER -p$DB_PASS"
fi

# Step 1: รัน base schema
echo ""
echo "📦 Step 1: ติดตั้ง base schema (goragodwiriya/pos-system)"
$MYSQL_CMD < "$BASE_SQL"
echo "   ✅ Base schema สำเร็จ"

# Step 2: รัน migration ทั้งหมดเรียงตามลำดับ
echo ""
echo "🔧 Step 2: รัน migration สำหรับ secondhand customization"
for migration in "$MIGRATIONS_DIR"/*.sql; do
    filename=$(basename "$migration")
    echo "   → Running: $filename"
    $MYSQL_CMD < "$migration"
done

echo ""
echo "=========================================="
echo " ✅ Migration เสร็จสมบูรณ์"
echo "=========================================="
echo ""
echo " Database: $DB_NAME"
echo " ตารางใหม่: branches, sellers, item_conditions,"
echo "          purchase_orders, purchase_order_items,"
echo "          purchase_order_photos"
echo ""
