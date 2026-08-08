#!/bin/bash
set -e

echo "=== Scrap POS startup ==="

# Wait for MySQL
echo "Waiting for MySQL at $DB_HOST:${DB_PORT:-3306}..."
for i in $(seq 1 30); do
    if mysqladmin ping -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" --silent 2>/dev/null; then
        echo "MySQL ready"
        break
    fi
    [ "$i" -eq 30 ] && echo "ERROR: MySQL not ready after 60s" && exit 1
    sleep 2
done

# Initialize an empty database, then apply every pending tracked migration.
# Any migration failure must stop startup so an incompatible release is never served.
echo "Applying database migrations..."
bash /var/www/customizations/database/run-migrations.sh "$DB_USER" "$DB_PASS"
echo "Database migrations complete"

echo "Starting Apache..."
exec apache2-foreground
