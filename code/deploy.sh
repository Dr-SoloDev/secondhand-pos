#!/bin/bash
set -euo pipefail

# ===== Production Deploy Script =====
# Usage: bash deploy.sh
# Must be run from code/ directory

# Source environment variables
if [ -f .env ]; then
  set -a; source .env; set +a
else
  echo "ERROR: .env file not found"; exit 1
fi

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
(
  export MYSQL_PWD="${MYSQL_ROOT_PASSWORD}"
  docker compose exec -T db mysqldump -u root \
    --all-databases --single-transaction --routines --triggers > "$BACKUP_FILE" 2>/dev/null || \
    echo "WARN: No existing DB to backup (fresh deploy?)"
  BACKUP_SIZE=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || echo 0)
  if [ "$BACKUP_SIZE" -lt 100 ]; then
    echo "WARN: Backup appears too small (${BACKUP_SIZE}B) — may be corrupt"
  fi
)

# 3. Pull latest code
echo "[2/5] Pull latest code..."
if [ -z "${CI:-}" ]; then
  read -p "Deploy latest main to production? (y/N) " -n 1 -r
  echo
  if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Deploy cancelled."
    exit 0
  fi
fi
# Stash local changes to avoid pull conflicts
git stash push -m "auto-stash-before-deploy-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || true
git pull origin main

# 4. Build + start containers
echo "[3/5] Build and start containers..."
docker compose build --no-cache web
docker compose up -d

# 5. Run migrations
echo "[4/5] Run database migrations..."
docker compose exec -T db mysql -u root pos_system \
  -e "SELECT COUNT(*) FROM schema_migrations" 2>/dev/null || \
  docker compose exec -T db sh -c \
  'for f in /docker-entrypoint-initdb.d/*.sql; do
     echo "Running $f...";
     mysql -u root pos_system < "$f" 2>&1;
   done'

# 6. Health check (wait for container health, then verify API)
echo "[5/5] Health check..."

# Wait for containers to pass their healthchecks
echo "  Waiting for containers to become healthy..."
docker compose up -d --force-recreate --wait 2>/dev/null || {
  echo "  WARN: --wait not supported or timed out, using fallback wait..."
  sleep 10
}

# Internal check via Docker network
# auth/verify returns 401 without token — that's OK, it proves PHP is working
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:80/api/index.php/auth/verify" 2>/dev/null || echo "000")

# Fallback to external if internal fails
if [ "$HTTP_CODE" = "000" ]; then
  HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://${DOMAIN}/api/index.php/auth/verify" 2>/dev/null || echo "000")
fi

# Accept 200 (authenticated) or 401 (unauthenticated — server still up)
if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "401" ]; then
  echo "Deploy successful! Server responded HTTP $HTTP_CODE"
else
  echo "Deploy failed! HTTP $HTTP_CODE — rolling back..."
  # Full rollback: revert code AND rebuild image from the reverted code
  git reset --hard HEAD@{1}
  docker compose build web
  docker compose up -d --force-recreate
  echo "Rolled back. Please check logs."
  exit 1
fi

