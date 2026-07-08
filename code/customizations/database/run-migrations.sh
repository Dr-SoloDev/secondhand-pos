#!/bin/bash
# ============================================================
# run-migrations.sh
# Purpose: รัน migration ทั้งหมดเรียงตามลำดับ พร้อม tracking
# Usage: ./run-migrations.sh [db_user] [db_password]
# ============================================================

set -euo pipefail

DB_USER="${1:-root}"
DB_PASS="${2:-}"
DB_NAME="pos_system"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_SQL="$SCRIPT_DIR/../../base-pos/database/pos_system.sql"
MIGRATIONS_DIR="$SCRIPT_DIR/migrations"

echo "=========================================="
echo " Secondhand POS — Database Migration"
echo "=========================================="

if ! command -v mysql &> /dev/null; then
    echo "❌ ไม่พบ mysql client"
    exit 1
fi

# Export password for all mysql commands (reduces process-listing exposure)
export MYSQL_PWD="$DB_PASS"
MYSQL_CMD="mysql -u $DB_USER --default-character-set=utf8mb4"

echo ""
echo "📦 Step 1: ติดตั้ง base schema"
$MYSQL_CMD < "$BASE_SQL"
echo "   ✅ Base schema สำเร็จ"

echo ""
echo "📋 Step 2: เตรียม schema_migrations tracking"
$MYSQL_CMD $DB_NAME <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(10)  NOT NULL PRIMARY KEY,
  filename   VARCHAR(255) NOT NULL,
  applied_at DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
echo "   ✅ schema_migrations ready"

echo ""
echo "🔧 Step 3: รัน migration (ข้ามอันที่รันแล้ว)"

for migration in "$MIGRATIONS_DIR"/*.sql; do
    [ -f "$migration" ] || continue
    filename=$(basename "$migration")
    version="${filename%%_*}"

    # Validate version format
    if [[ ! "$version" =~ ^[0-9]+[a-z]?$ ]]; then
        echo "   ❌ Invalid version: $version (from $filename)"
        exit 1
    fi

    already_run=$($MYSQL_CMD --silent --skip-column-names $DB_NAME \
        -e "SELECT COUNT(*) FROM schema_migrations WHERE version='$version';" 2>/dev/null || echo "0")

    if [ "$already_run" -gt 0 ]; then
        echo "   ⏭  Skip: $filename"
        continue
    fi

    echo "   → Running: $filename"
    if $MYSQL_CMD $DB_NAME < "$migration"; then
        $MYSQL_CMD $DB_NAME \
            -e "INSERT INTO schema_migrations (version, filename) VALUES ('$version', '$filename');"
        echo "   ✅ Done: $filename"
    else
        echo "   ❌ FAILED: $filename"
        exit 1
    fi
done

echo ""
echo "=========================================="
echo " ✅ Migration เสร็จสมบูรณ์"
echo "=========================================="
