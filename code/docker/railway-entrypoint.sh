#!/bin/bash
set -e

echo "=== Scrap POS startup ==="

# Wait for MySQL
echo "Waiting for MySQL at $DB_HOST..."
for i in $(seq 1 30); do
    if mysqladmin ping -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" --silent 2>/dev/null; then
        echo "MySQL ready"
        break
    fi
    [ "$i" -eq 30 ] && echo "ERROR: MySQL not ready after 60s" && exit 1
    sleep 2
done

# Initialize DB schema if empty
TABLE_COUNT=$(mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    -se "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt "5" ]; then
    echo "Loading base schema..."
    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        < /var/www/html/database/pos_system.sql
    echo "Running migrations..."
    for f in $(ls /var/www/customizations/database/migrations/*.sql 2>/dev/null | sort); do
        echo "  $f"
        mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f" 2>/dev/null || true
    done
    echo "DB initialized"
fi

echo "Starting Apache..."
exec apache2-foreground
