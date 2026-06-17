#!/bin/bash
# ============================================================
# mysql-init.sh
# รัน migration เรียงตามลำดับ พร้อม schema_migrations tracking
# (ข้ามอันที่รันแล้ว — idempotent runner)
# ============================================================
set -e

echo "=========================================="
echo " Running Secondhand POS migrations..."
echo "=========================================="

MIGRATIONS_DIR="/docker-entrypoint-initdb.d/migrations"

if [ ! -d "$MIGRATIONS_DIR" ]; then
    echo "  ⚠️  ไม่พบ migrations directory"
    exit 0
fi

# สร้างตาราง schema_migrations ถ้ายังไม่มี
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 pos_system <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(10)  NOT NULL PRIMARY KEY,
  filename   VARCHAR(255) NOT NULL,
  applied_at DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

echo "  ✅ schema_migrations table ready"
echo ""

for migration in "$MIGRATIONS_DIR"/*.sql; do
    [ -f "$migration" ] || continue
    filename=$(basename "$migration")
    version="${filename%%_*}"   # ดึงเลข เช่น "024" จาก "024_add_stock_transfers.sql"

    # ตรวจว่ารันแล้วหรือยัง
    already_run=$(mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --silent --skip-column-names \
        -e "SELECT COUNT(*) FROM pos_system.schema_migrations WHERE version='$version';" 2>/dev/null || echo "0")

    if [ "$already_run" -gt 0 ]; then
        echo "  ⏭  Skip (already applied): $filename"
        continue
    fi

    echo "  → Running: $filename"
    if mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 pos_system < "$migration"; then
        mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 pos_system \
            -e "INSERT INTO schema_migrations (version, filename) VALUES ('$version', '$filename');"
        echo "  ✅ Done: $filename"
    else
        echo "  ❌ FAILED: $filename — หยุดการ migration"
        exit 1
    fi
done

echo ""
echo "  ✅ Migrations เสร็จสมบูรณ์"
