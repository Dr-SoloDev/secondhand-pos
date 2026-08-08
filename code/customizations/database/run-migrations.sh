#!/bin/bash
# ============================================================
# run-migrations.sh
# Purpose: รัน migration ทั้งหมดเรียงตามลำดับ พร้อม tracking
# Usage: ./run-migrations.sh [--migrations-only] [db_user] [db_password]
# ============================================================

set -euo pipefail

MODE="init-if-empty"
if [ "${1:-}" = "--migrations-only" ]; then
    MODE="migrations-only"
    shift
fi

DB_USER="${1:-${DB_USER:-root}}"
DB_PASS="${2:-${DB_PASS:-}}"
DB_NAME="${DB_NAME:-pos_system}"
DB_HOST="${DB_HOST:-}"
DB_PORT="${DB_PORT:-3306}"

if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "❌ Invalid DB_NAME: use only letters, numbers, and underscore"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE_SQL="${BASE_SQL:-$SCRIPT_DIR/../../base-pos/database/pos_system.sql}"
MIGRATIONS_DIR="${MIGRATIONS_DIR:-$SCRIPT_DIR/migrations}"

if [ ! -f "$BASE_SQL" ] && [ -f "/var/www/html/database/pos_system.sql" ]; then
    BASE_SQL="/var/www/html/database/pos_system.sql"
fi

echo "=========================================="
echo " Secondhand POS — Database Migration"
echo "=========================================="

if ! command -v mysql &> /dev/null; then
    echo "❌ ไม่พบ mysql client"
    exit 1
fi

# Export password for all mysql commands (reduces process-listing exposure)
export MYSQL_PWD="$DB_PASS"
MYSQL_CMD=(mysql -u "$DB_USER" --default-character-set=utf8mb4)
if [ -n "$DB_HOST" ]; then
    MYSQL_CMD+=(-h "$DB_HOST" -P "$DB_PORT")
fi

runBaseSchema() {
    sed \
        -e "s/^CREATE DATABASE IF NOT EXISTS pos_system;$/CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;/" \
        -e "s/^USE pos_system;$/USE \`${DB_NAME}\`;/" \
        "$BASE_SQL" | "${MYSQL_CMD[@]}"
}

runMigration() {
    sed '/^USE pos_system;$/d' "$1" | "${MYSQL_CMD[@]}" "$DB_NAME"
}

if [ "$MODE" = "init-if-empty" ]; then
    table_count=$("${MYSQL_CMD[@]}" --silent --skip-column-names \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';")

    if [ "$table_count" -eq 0 ]; then
        if [ ! -f "$BASE_SQL" ]; then
            echo "❌ ไม่พบ base schema: $BASE_SQL"
            exit 1
        fi

        echo ""
        echo "📦 Step 1: ติดตั้ง base schema"
        runBaseSchema
        echo "   ✅ Base schema สำเร็จ"
    else
        echo ""
        echo "📦 Step 1: พบ schema เดิม ($table_count tables) — ไม่โหลด base schema ซ้ำ"
    fi
else
    echo ""
    echo "📦 Step 1: migrations-only — ไม่โหลด base schema"
fi

echo ""
echo "📋 Step 2: เตรียม schema_migrations tracking"
"${MYSQL_CMD[@]}" "$DB_NAME" <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(10)  NOT NULL PRIMARY KEY,
  filename   VARCHAR(255) NOT NULL,
  applied_at DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
echo "   ✅ schema_migrations ready"

echo ""
echo "🔧 Step 3: รัน migration (ข้ามอันที่รันแล้ว)"

# --- Pre-flight: ตรวจ permission ไฟล์ migration (กันปัญหาไฟล์อ่านไม่ได้ซ้ำ) ---
# เจอไฟล์ .sql ที่ others อ่านไม่ได้ (! -perm -o+r) เช่น mode 600
# → พยายาม chmod 644 (กันกรณีรันบน host ที่เขียนได้)
# → ถ้า chmod fail (เช่นรันใน container ที่ mount :ro) ให้ warn เท่านั้น ปล่อยให้ error handling เดิมจัดการ
broken_files=$(find "$MIGRATIONS_DIR" -maxdepth 1 -name '*.sql' ! -perm -o+r 2>/dev/null || true)

if [ -n "$broken_files" ]; then
    echo ""
    echo "⚠️ พบไฟล์ migration ที่ others อ่านไม่ได้:"
    while IFS= read -r f; do
        [ -n "$f" ] && echo "   ⚠️ $f"
    done <<< "$broken_files"
    echo "   🔧 พยายามแก้ permission เป็น 644 (กรณีรันบน host) ..."
    fixed_all=true
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        if chmod 644 "$f" 2>/dev/null; then
            echo "   ✅ $f → 644"
        else
            echo "   ⚠️ chmod ไม่สำเร็จ: $f (mount แบบ read-only?)"
            fixed_all=false
        fi
    done <<< "$broken_files"
    if [ "$fixed_all" = false ]; then
        echo "   ⚠️ ยังมีไฟล์ที่อ่านไม่ได้ — ปล่อยให้ error handling เดิมจัดการตอนรัน migration"
    fi
else
    echo "   ✅ Pre-flight: ไฟล์ migration ทั้งหมดอ่านได้ (permission ผ่าน)"
fi

for migration in "$MIGRATIONS_DIR"/*.sql; do
    [ -f "$migration" ] || continue
    filename=$(basename "$migration")
    raw_version="${filename%%_*}"

    # Validate version format
    if [[ ! "$raw_version" =~ ^[0-9]+[a-z]?$ ]]; then
        echo "   ❌ Invalid version: $raw_version (from $filename)"
        exit 1
    fi

    # Files with a letter suffix (e.g. 006p_seed_demo_data.sql) are DISTINCT
    # migrations and MUST run — keep the full version ('006p') so they are not
    # deduplicated against their numeric counterpart (006).
    # (test suite + CI depend on the demo seed from 006p)
    version="${raw_version}"

    already_run=$("${MYSQL_CMD[@]}" --silent --skip-column-names "$DB_NAME" \
        -e "SELECT COUNT(*) FROM schema_migrations WHERE version='$version';")

    if [ "$already_run" -gt 0 ]; then
        echo "   ⏭  Skip: $filename"
        continue
    fi

    echo "   → Running: $filename"
    if runMigration "$migration"; then
        "${MYSQL_CMD[@]}" "$DB_NAME" \
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
