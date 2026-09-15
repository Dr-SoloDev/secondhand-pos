#!/bin/bash
set -euo pipefail

# ===== Production Deploy Script =====
# Usage: bash deploy.sh
# Must be run from code/ directory

# SAFETY: Only deploy from a clean checkout of main.
# Local changes must be committed and pushed to origin/main before deploy.
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "ERROR: Not inside a git repository"; exit 1
fi

if [ "$(git rev-parse --abbrev-ref HEAD)" != "main" ]; then
  echo "ERROR: Deploy is allowed only from the 'main' branch"; exit 1
fi

if [ -n "$(git status --short)" ]; then
  echo "ERROR: Working tree is not clean. Commit/push first, or run 'git stash' manually."
  echo "       Uncommitted changes will NOT be deployed by this script."
  git status --short
  exit 1
fi

LOCAL_HEAD=$(git rev-parse HEAD)
REMOTE_HEAD=$(git rev-parse origin/main 2>/dev/null || echo "")
if [ -z "$REMOTE_HEAD" ]; then
  echo "ERROR: Cannot read origin/main"; exit 1
fi
if [ "$LOCAL_HEAD" != "$REMOTE_HEAD" ]; then
  echo "ERROR: Local main is not in sync with origin/main. Run 'git pull' / 'git push' first."
  exit 1
fi

# Source environment variables
if [ -f .env ]; then
  set -a; source .env; set +a
else
  echo "ERROR: .env file not found"; exit 1
fi

echo "=== Production Deploy ==="
echo "Deploying commit: ${LOCAL_HEAD}"

# 1. Health check: Server resources
MEM_TOTAL=$(free -m | awk '/^Mem:/{print $2}')
DISK_FREE=$(df -m . | awk 'NR==2{print $4}')
if [ "$MEM_TOTAL" -lt 2048 ]; then echo "ERROR: RAM < 2GB"; exit 1; fi
if [ "$DISK_FREE" -lt 5120 ]; then echo "ERROR: Disk < 5GB free"; exit 1; fi

# 2. Backup DB before deploy
echo "[1/5] Backup existing database..."
BACKUP_FILE="data/backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql"
mkdir -p data/backups
if [ "$(docker compose ps --status running --services db 2>/dev/null || true)" = "db" ]; then
  if ! docker compose exec -T db sh -lc \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -u root --all-databases --single-transaction --routines --triggers' \
    > "$BACKUP_FILE"; then
    echo "ERROR: Database backup failed; deploy stopped"
    exit 1
  fi

  BACKUP_SIZE=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || echo 0)
  if [ "$BACKUP_SIZE" -lt 100 ]; then
    echo "ERROR: Backup appears too small (${BACKUP_SIZE}B); deploy stopped"
    exit 1
  fi
  echo "  Backup ready: $BACKUP_FILE (${BACKUP_SIZE} bytes)"
else
  echo "  No running DB container; skipping backup for fresh deploy"
fi

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
# SAFETY: never stash local changes automatically.
# Clean checkout was already enforced above.
git pull origin main

# 4. Build + start containers
echo "[3/5] Build and start containers..."
docker compose build --no-cache web
docker compose up -d

# 5. Run migrations
echo "[4/5] Run database migrations..."
docker compose exec -T db bash /docker-entrypoint-initdb.d/99-run-migrations.sh

# 6. Health check (wait for container health, then verify API)
echo "[5/5] Health check..."

# Wait for containers to pass their healthchecks
echo "  Waiting for containers to become healthy..."
docker compose up -d --force-recreate --wait 2>/dev/null || {
  echo "  WARN: --wait not supported or timed out, using fallback wait..."
  sleep 10
}

# Internal check inside the web container.  Apache listens on port 80 inside
# the container; the host publishes it on 8080 (not localhost:80).
# auth/verify returns 401 without token — that's OK, it proves PHP is working.
if ! HTTP_CODE=$(docker compose exec -T web sh -lc \
  'curl -s -o /dev/null -w "%{http_code}" "http://localhost:80/api/index.php/auth/verify"' \
  2>/dev/null); then
  HTTP_CODE="000"
fi

# Fallback to the published host port if the container check fails.
if [ "$HTTP_CODE" = "000" ]; then
  if ! HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:8080/api/index.php/auth/verify" 2>/dev/null); then
    HTTP_CODE="000"
  fi
fi

# Final fallback to the configured public HTTPS endpoint.
if [ "$HTTP_CODE" = "000" ]; then
  if ! HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://${DOMAIN}/api/index.php/auth/verify" 2>/dev/null); then
    HTTP_CODE="000"
  fi
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
