#!/bin/bash
# เรียก canonical migration runner หลัง base schema ถูกโหลดแล้ว
set -euo pipefail

export DB_HOST=""
export DB_PORT="3306"
export DB_NAME="${MYSQL_DATABASE:-pos_system}"
export MIGRATIONS_DIR="/docker-entrypoint-initdb.d/pos-database/migrations"

export MYSQL_PWD="${MYSQL_ROOT_PASSWORD}"
for attempt in $(seq 1 30); do
    if mysqladmin -uroot ping --silent 2>/dev/null; then
        break
    fi
    if [ "$attempt" -eq 30 ]; then
        echo "ERROR: MySQL not ready after 60s"
        exit 1
    fi
    sleep 2
done

bash /docker-entrypoint-initdb.d/pos-database/run-migrations.sh \
    --migrations-only root "${MYSQL_ROOT_PASSWORD}"
