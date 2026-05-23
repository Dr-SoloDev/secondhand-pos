#!/bin/bash
# ============================================================
# mysql-init.sh
# รัน migration ทั้งหมดเรียงตามลำดับ หลังจาก base schema ติดตั้งเสร็จ
# ============================================================
set -e

echo "=========================================="
echo " Running Secondhand POS migrations..."
echo "=========================================="

MIGRATIONS_DIR="/docker-entrypoint-initdb.d/migrations"

if [ -d "$MIGRATIONS_DIR" ]; then
    for migration in "$MIGRATIONS_DIR"/*.sql; do
        if [ -f "$migration" ]; then
            filename=$(basename "$migration")
            echo "  → Running: $filename"
            mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --default-character-set=utf8mb4 pos_system < "$migration"
        fi
    done
    echo "  ✅ Migrations เสร็จสมบูรณ์"
else
    echo "  ⚠️  ไม่พบ migrations directory"
fi
