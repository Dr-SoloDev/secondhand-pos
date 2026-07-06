> **⚠️ Timeline ถูก supersede แล้ว** — Exec Council (6 ก.ค. 2569) กำหนด phased 7-week rollout แทน
> ดูแผนใหม่: [`docs/OPS-DEPLOYMENT-PLAN.md`](OPS-DEPLOYMENT-PLAN.md) | [`docs/MASTER-ACTION-TIMELINE.md`](MASTER-ACTION-TIMELINE.md)
> สถานะ: **NO-GO** จนกว่า 3 blockers จะปิด (G3 SQL, admin/admin, HTTPS)

# 📋 JOB ORDER: Infrastructure — Production Deployment

**ส่งถึง:** DevOps Engineer  
**จาก:** System Architect  
**กำหนดส่ง:** วันสิ้นสุด Day 1 (23:59 น.)  
**Handoff ให้:** Backend Security Engineer (Day 2)

---

## 1. ภารกิจหลัก (Mission Objective)

ทำให้ `docker compose up -d` บน **Ubuntu 22.04 LTS เครื่องเปล่า** ทำงานสำเร็จ 100% โดยไม่ต้องมีคนนั่งจับ — และระบบพร้อมรับ HTTPS request จาก internet

---

## 2. ก่อนเริ่ม — Check-list เตรียมความพร้อม

```
□ ได้รับ: Server credentials (SSH key, IP, user)
□ ได้รับ: Domain name + DNS A record ชี้มาแล้ว
□ Server: Ubuntu 22.04 LTS, fresh install
□ Server: ports 22, 80, 443 เปิดใน firewall แล้ว
□ ติดต่อได้ทาง SSH แล้ว
□ git clone project สำเร็จ
```

**ถ้าข้อใดข้อนึงไม่พร้อม → แจ้ง Architect ทันที อย่าเริ่ม**

---

## 3. งานที่ต้องทำ (ตามลำดับ)

### 3.1 🔧 Fix Migration Split

**ปัญหา:** ปัจจุบัน migrations อยู่ใน 2 โฟลเดอร์:
- `base-pos/database/pos_system.sql` (base schema)
- `customizations/database/migrations/*.sql` (custom)

แต่ Docker mysql-init.sh อ่าน migration จาก `/docker-entrypoint-initdb.d/migrations/` เท่านั้น — docker-compose up ครั้งแรกบน clean machine, migration runner จะไม่เจอ base schema ใน tracking

**วิธีแก้:**

1. สร้าง `code/docker/entrypoint/` directory และรวบรวม SQL files ทั้งหมดไว้ในที่เดียว:

```
code/docker/entrypoint/
├── 00-base-schema.sql          ← copy จาก base-pos/database/pos_system.sql
├── 01-base-auth.sql            ← รวบจาก migrations 001-004 ที่ base schema ต้องการ
├── 02-migration-001.sql        ← copy จาก customizations/database/migrations/
├── 03-migration-002.sql
├── ...
└── 41-migration-041.sql
```

2. แก้ `docker-compose.yml`:
```yaml
volumes:
  # ลบ volume อันเก่า 3 อันนี้:
  # - ./base-pos/database/pos_system.sql:/docker-entrypoint-initdb.d/01-base-schema.sql:ro
  # - ./customizations/database/migrations:/docker-entrypoint-initdb.d/migrations:ro
  # - ./docker/mysql-init.sh:/docker-entrypoint-initdb.d/99-run-migrations.sh:ro
  # แทนที่ด้วย:
  - ./docker/entrypoint:/docker-entrypoint-initdb.d:ro
```

3. อัปเดต `mysql-init.sh` หรือ rewrite ใหม่ให้ทำงานกับ numbered files ใน `/docker-entrypoint-initdb.d/` โดยตรง MySQL Docker image จะรัน `.sql` และ `.sh` files ตามลำดับเลขอยู่แล้ว — ถ้าไฟล์มี guard clauses (`CREATE TABLE IF NOT EXISTS`, `SELECT @col_exists`) ก็สามารถรันซ้ำได้

**Verify (ทำต่อหน้า Architect):**
```bash
# ลบ volume DB เก่า
docker compose down -v
docker compose up -d db

# รอ health check pass
docker compose exec db mysqladmin ping -u root -p${MYSQL_ROOT_PASSWORD}

# เช็คว่ามีกี่ตาราง
docker compose exec db mysql -u root -p${MYSQL_ROOT_PASSWORD} pos_system -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='pos_system';"
# EXPECTED: >= 38 (base tables + custom tables)

# เช็ค schema_migrations
docker compose exec db mysql -u root -p${MYSQL_ROOT_PASSWORD} pos_system -e \
  "SELECT COUNT(*) FROM schema_migrations;"
# EXPECTED: 40+ (001-041)
```

---

### 3.2 🖥️ Ubuntu Server Setup

**Requirements:**
- Docker + Docker Compose v2
- fail2ban (ป้องกัน SSH brute force)
- ufw (firewall)
- unattended-upgrades (security patches อัตโนมัติ)
- Timezone: Asia/Bangkok
- Swap: 2 GB (ถ้า RAM < 4GB)

**Setup script:**
```bash
# 1. System dependencies
sudo apt update && sudo apt upgrade -y
sudo apt install -y docker.io docker-compose-v2 fail2ban ufw unattended-upgrades

# 2. Docker permissions
sudo usermod -aG docker $USER

# 3. Timezone
sudo timedatectl set-timezone Asia/Bangkok

# 4. Firewall
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable

# 5. fail2ban
sudo systemctl enable fail2ban
sudo systemctl start fail2ban

# 6. unattended-upgrades
sudo dpkg-reconfigure --priority=low unattended-upgrades
```

**Verify:**
```bash
docker --version                     # EXPECTED: Docker version 24.x+
docker compose version               # EXPECTED: Docker Compose version v2.x+
sudo ufw status                      # EXPECTED: active, 22/80/443 ALLOW
sudo fail2ban-client status sshd     # EXPECTED: Status: OK, Currently banned: 0
timedatectl                          # EXPECTED: Asia/Bangkok
```

---

### 3.3 🔐 SSL/TLS — Let's Encrypt + Caddy (Reverse Proxy)

**เหตุผลที่เลือก Caddy (ไม่ใช่ Nginx):** Caddy auto SSL, auto renew, config 5 บรรทัด — ลด human error เรื่อง cert renewal

**โครงสร้าง:**
```
Internet :443 → Caddy container → web container :80
```

**สร้าง `code/docker/Caddyfile`:**
```
ร้านของฉัน.com {
    reverse_proxy web:80
}
```

**แก้ `docker-compose.yml`:** เพิ่ม service:
```yaml
caddy:
  image: caddy:2
  container_name: scrap-pos-caddy
  restart: unless-stopped
  ports:
    - "80:80"
    - "443:443"
  volumes:
    - ./docker/Caddyfile:/etc/caddy/Caddyfile:ro
    - caddy_data:/data
    - caddy_config:/config
  depends_on:
    - web

volumes:
  caddy_data:
  caddy_config:
```

**Verify:**
```bash
curl -I https://ร้านของฉัน.com/api/index.php/auth/verify
# EXPECTED: HTTP/2 200 (ไม่ใช่ redirect, ไม่ใช่ certificate warning)

curl -I http://ร้านของฉัน.com
# EXPECTED: HTTP 308 (redirect to HTTPS)
```

---

### 3.4 🔑 Production Environment (.env)

**⚠️ ห้าม commit .env, ห้ามมีใน git history**

```bash
# สร้าง secret
APP_ENV=production
JWT_SECRET=$(openssl rand -hex 32)
MYSQL_ROOT_PASSWORD=$(openssl rand -base64 24)
MYSQL_USER=posuser
MYSQL_PASSWORD=$(openssl rand -base64 24)
PMA_USER=root
PMA_PASSWORD=$(openssl rand -base64 24)

# Production-specific
ALLOWED_ORIGINS=https://ร้านของฉัน.com
JWT_EXPIRY=3600           # 1 hour (ลดจาก 24h ป้องกัน session ยาว)
```

**⚠️ ลบ phpmyadmin service ออกจาก docker-compose.yml หรือ ปิด port 8081 ใน production**
```yaml
# phpmyadmin:   ← comment ออกทั้ง service
#   image: phpmyadmin:latest
#   ...
```

**Verify:**
```bash
# เช็คว่า .env ไม่มีค่า default
grep "pos_jwt_secret_key" .env && echo "FAIL: using dev JWT" || echo "PASS"
grep "rootpass" .env && echo "FAIL: using dev root pass" || echo "PASS"

# เช็ค file permission
stat -c "%a %n" .env
# EXPECTED: 600 (owner only)
```

---

### 3.5 📁 File Permissions

```bash
# สร้าง directory
mkdir -p uploads/purchase-orders
mkdir -p data/backups

# ให้ www-data เป็นเจ้าของ
sudo chown -R www-data:www-data uploads/
sudo chown -R www-data:www-data data/

# Permission
sudo chmod 755 uploads/
sudo chmod 755 data/
sudo chmod -R 644 uploads/purchase-orders/
```

**Verify:**
```bash
docker compose exec web ls -la /var/www/html/uploads/
# EXPECTED: drwxr-xr-x www-data www-data

docker compose exec web touch /var/www/html/uploads/test-perm.txt
# EXPECTED: success (ไม่ error)
```

---

### 3.6 🚀 Production Deployment Script — `deploy.sh`

สร้างที่ `/home/drsolodev/projects/scrap-pos/code/deploy.sh`:

```bash
#!/bin/bash
set -euo pipefail

# ===== Production Deploy Script =====
# Usage: bash deploy.sh
# ต้องรันจาก code/ directory

echo "=== Production Deploy ==="

# 1. Health check: Server resources
MEM_TOTAL=$(free -m | awk '/^Mem:/{print $2}')
DISK_FREE=$(df -m . | awk 'NR==2{print $4}')
if [ "$MEM_TOTAL" -lt 2048 ]; then echo "ERROR: RAM < 2GB"; exit 1; fi
if [ "$DISK_FREE" -lt 5120 ]; then echo "ERROR: Disk < 5GB free"; exit 1; fi

# 2. Backup DB ก่อน deploy
echo "[1/5] Backup existing database..."
BACKUP_FILE="data/backups/pre-deploy-$(date +%Y%m%d-%H%M%S).sql"
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
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://ร้านของฉัน.com/api/index.php/auth/verify 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
  echo "✅ Deploy successful! HTTP $HTTP_CODE"
else
  echo "❌ Deploy failed! HTTP $HTTP_CODE — rolling back..."
  git revert HEAD --no-edit
  docker compose up -d --force-recreate
  echo "Rolled back. Please check logs."
  exit 1
fi
```

```bash
chmod +x deploy.sh
```

---

## 4. ✅ Gate Criteria — ส่งต่องาน (Handoff Checklist)

**Before calling "Done" and handing to Backend Team (Day 2), DevOps ต้อง Verify ทุกข้อ:**

```
[ ] 1. docker compose up -d บน Ubuntu clean machine ผ่าน 100%
       → docker compose ps
       → EXPECTED: ทุก container (db, web, caddy) "Up (healthy)"

[ ] 2. Database migrations ทั้งหมดถูกรัน
       → docker compose exec db mysql ... -e "SELECT COUNT(*) FROM schema_migrations"
       → EXPECTED: 41

[ ] 3. HTTPS ทำงาน
       → curl -I https://domain.com → HTTP/2 200
       → curl -I http://domain.com → HTTP 308 redirect

[ ] 4. API เรียกได้
       → curl https://domain.com/api/index.php/auth/verify → {"status":"success"}

[ ] 5. File uploads path เขียนได้
       → docker compose exec web touch /var/www/html/uploads/test.txt
       → return code 0

[ ] 6. .env ไม่มี secret default
       → grep -c "rootpass\|pos_jwt_secret_key" .env = 0

[ ] 7. phpMyAdmin ไม่เปิดใน production
       → curl -s -o /dev/null -w "%{http_code}" localhost:8081
       → EXPECTED: connection refused หรือ 000

[ ] 8. Data backup ก่อน deploy ครั้งล่าสุดมีอยู่
       → ls data/backups/pre-deploy-*.sql
       → EXPECTED: มีไฟล์ (ไม่ใช่ 0 byte)

[ ] 9. deploy.sh รันครบ流程
       → bash deploy.sh → output: ✅ Deploy successful!

[ ] 10. Error log ไม่มี WARNING/ERROR หลัง deploy 5 นาที
        → docker compose logs web --since 5m | grep -i "error\|warning\|fatal"
        → EXPECTED: 0
```

**ทั้ง 10 ข้อ = PASS → ส่งต่องานให้ Backend Team (Day 2)**  
**มี FAIL 1 ข้อ = หยุด, แก้, แล้ว verify ใหม่**

---

## 5. ⛔ สิ่งที่ DevOps ห้ามทำ

| ข้อห้าม | เพราะ |
|---------|-------|
| ❌ ห้ามแก้ PHP code ใดๆ | ไม่ใช่หน้าที่ DevOps — ส่ง Architect |
| ❌ ห้ามแก้ migration SQL logic | ไม่ใช่หน้าที่ DevOps — ส่ง Architect |
| ❌ ห้ามเปิด port 8081/phpMyAdmin | security risk สำหรับ production |
| ❌ ห้าม deploy โดยไม่มี backup | ถ้าไม่มี backup → rollback ไม่ได้ |
| ❌ ห้ามใช้ .env default value | dev secret in production = breach |
| ❌ ห้าม commit .env | secrets in git = fireable offense |

---

## 6. 📞 ถ้าติดปัญหา

1. **ลองแก้เอง 20 นาที** — ถ้าไม่หลุด →
2. **หยุด** — อย่าทำอะไรต่อ →
3. **แจ้ง Architect พร้อม:** error message, output, สิ่งที่ลองไปแล้ว

**Architect จะตัดสินใจ:** bypass, workaround, หรือ rollback

---

## 7. 🎯 Definition of Done สำหรับ DevOps (Day 1)

> **"Server พร้อม, SSL พร้อม, docker compose ขึ้น 100%, migration รันครบ, deploy script ใช้ได้, error log clean — ส่งต่องานให้ Backend Security Team ได้"**

---

*Document version: 1.0 | 2026-07-03 | System Architect*
