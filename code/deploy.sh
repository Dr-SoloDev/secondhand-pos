#!/bin/bash
set -euo pipefail

# ===== Production Deploy Script =====
# Usage: bash deploy.sh
# Must be run from code/ directory

echo "=== Production Deploy ==="

# 1. Health check: Server resources
MEM_TOTAL=$(free -m | awk '/^Mem:/{print $2}')
DISK_FREE=$(df -m . | awk 'NR==2{print $4}')
if [ "$MEM_TOTAL" -lt 2048 ]; then echo "ERROR: RAM < 2GB"; exit 1; fi
if [ "$DISK_FREE" -lt 5120 ]; then echo "ERROR: Disk < 5GB free"; exit 1; fi

# 2. Backup DB before deploy
echo "[1/5] Backup existing database..."
BACKUP_FILE="data/backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql"
mkdir -p data/backups
docker compose exec -T db mysqldump -u root -p"${MYSQL_ROOT_PASSWORD}" \
  --all-databases --single-transaction --routines --triggers > "$BACKUP_FILE" 2>/dev/null || \
  echo "WARN: No existing DB to backup (fresh deploy?)"

# 3. Pull latest code
echo "[2/5] Pull latest code..."
git pull origin main

# 4. Rebuild + restart
echo "[3/5] Rebuild containers..."
docker compose build --no-cache web
docker compose up -d --force-recreate

# 5. Run migrations
echo "[4/5] Run database migrations..."
docker compose exec -T db mysql -u root -p"${MYSQL_ROOT_PASSWORD}" pos_system \
  -e "SELECT COUNT(*) FROM schema_migrations" 2>/dev/null || \
  docker compose exec -T db sh -c \
  'for f in /docker-entrypoint-initdb.d/*.sql; do
     echo "Running $f...";
     mysql -u root -p"${MYSQL_ROOT_PASSWORD}" pos_system < "$f" 2>&1;
   done'

# 6. Health check
echo "[5/5] Health check..."
sleep 5
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://${DOMAIN}/api/index.php/auth/verify" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
  echo "Deploy successful! HTTP $HTTP_CODE"
else
  echo "Deploy failed! HTTP $HTTP_CODE — rolling back..."
  git revert HEAD --no-edit
  docker compose up -d --force-recreate
  echo "Rolled back. Please check logs."
  exit 1
fi
