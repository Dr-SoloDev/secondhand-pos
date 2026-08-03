# POS System Deployment to Ubuntu Server (Production)

**Last updated:** 14 กรกฎาคม 2569  
**Target:** Ubuntu 22.04 LTS + Docker Compose  
**Audience:** Deployment team (non-technical)

---

## Table of Contents
1. [Server Preparation](#server-preparation)
2. [Docker & Application Setup](#docker--application-setup)
3. [Environment Configuration](#environment-configuration)
4. [Database Initialization](#database-initialization)
5. [HTTPS/SSL Setup](#httpsssl-setup)
6. [Backup & Recovery](#backup--recovery)
7. [Monitoring & Logs](#monitoring--logs)
8. [Production Hardening](#production-hardening)
9. [Troubleshooting](#troubleshooting)

---

## Server Preparation

### System Requirements
- **OS:** Ubuntu 22.04 LTS (minimal install recommended)
- **CPU:** 2+ cores
- **RAM:** 4 GB minimum (8 GB recommended for 4 branches)
- **Disk:** 50 GB minimum (100 GB recommended for 2-year data retention)
- **Network:** Static IP + 1 Gbps connection

### Initial Setup

```bash
# 1. Update system packages
sudo apt update && sudo apt upgrade -y

# 2. Install prerequisites
sudo apt install -y \
  docker.io \
  docker-compose \
  git \
  curl \
  wget \
  htop \
  nano \
  fail2ban \
  ufw

# 3. Add current user to docker group (avoid sudo)
sudo usermod -aG docker $USER
newgrp docker

# 4. Enable Docker at boot
sudo systemctl enable docker
sudo systemctl start docker

# 5. Verify installation
docker --version
docker-compose --version
```

### Create Application Directory

```bash
# Create app directory
sudo mkdir -p /opt/scrap-pos
sudo chown $USER:$USER /opt/scrap-pos
cd /opt/scrap-pos

# Clone repository (or copy if network unavailable)
git clone https://github.com/Dr-SoloDev/secondhand-pos.git .
cd code
```

---

## Docker & Application Setup

### Directory Structure

```
/opt/scrap-pos/
├── code/
│   ├── docker-compose.yml       # Production config (see below)
│   ├── .env                     # ⚠️ SECRETS - never commit
│   ├── .env.example             # Template (safe to version)
│   ├── docker/
│   │   ├── entrypoint/
│   │   │   └── init.sql         # DB initialization
│   │   └── web/
│   │       └── Dockerfile       # PHP/Apache config
│   ├── base-pos/                # Core POS system
│   ├── customizations/          # Branch customizations
│   └── tests/
├── backups/                     # Daily DB backups
├── logs/                        # Application logs
└── config/                      # SSL certs, nginx config
```

### Production Docker Compose

Create `/opt/scrap-pos/code/docker-compose.prod.yml`:

```yaml
version: '3.8'

services:
  web:
    build: ./docker/web
    container_name: scrap-pos-web
    ports:
      - "8080:80"
    environment:
      - MYSQL_HOST=${MYSQL_HOST}
      - MYSQL_PORT=${MYSQL_PORT}
      - MYSQL_USER=${MYSQL_USER}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - JWT_SECRET=${JWT_SECRET}
      - APP_ENV=production
    volumes:
      - ./base-pos:/var/www/html/base-pos
      - ./customizations:/var/www/html/customizations
      - ./docker/web/apache.conf:/etc/apache2/sites-available/000-default.conf
      - /var/log/scrap-pos:/var/log/apache2
    depends_on:
      - db
    restart: always
    networks:
      - pos-network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:80/"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 40s

  db:
    image: mysql:8.0
    container_name: scrap-pos-db
    ports:
      - "3307:3306"
    environment:
      - MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - MYSQL_USER=${MYSQL_USER}
      - MYSQL_PASSWORD=${MYSQL_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
      - ./docker/entrypoint/init.sql:/docker-entrypoint-initdb.d/01-init.sql
      - ./customizations/database/migrations:/docker-entrypoint-initdb.d/migrations
      - /var/log/mysql:/var/log/mysql
    restart: always
    networks:
      - pos-network
    command: >
      --default-authentication-plugin=mysql_native_password
      --max_connections=100
      --log_error=/var/log/mysql/error.log
      --slow_query_log=1
      --slow_query_log_file=/var/log/mysql/slow-query.log
      --long_query_time=2

  caddy:
    image: caddy:2-alpine
    container_name: scrap-pos-caddy
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./config/Caddyfile:/etc/caddy/Caddyfile
      - caddy_data:/data
      - caddy_config:/config
    environment:
      - DOMAIN=${DOMAIN}
      - ACME_EMAIL=${ACME_EMAIL}
    depends_on:
      - web
    restart: always
    networks:
      - pos-network

volumes:
  db_data:
    driver: local
  caddy_data:
  caddy_config:

networks:
  pos-network:
    driver: bridge
```

### Start Application

```bash
# Build and start
docker-compose -f docker-compose.prod.yml up -d

# Verify all containers running
docker-compose ps

# Check logs
docker-compose logs -f web    # PHP/Apache logs
docker-compose logs -f db     # MySQL logs
```

---

## Environment Configuration

### Create Production `.env` File

```bash
# ⚠️ IMPORTANT: This file contains SECRETS
# Never commit to git, never share, restrict permissions

cp .env.example .env
chmod 600 .env  # Read/write owner only
nano .env
```

### `.env` Template (Save this as `.env.example`)

```env
# ===== MANDATORY - CHANGE BEFORE DEPLOYMENT =====

# Database
MYSQL_HOST=db
MYSQL_PORT=3306
MYSQL_DATABASE=pos_system
MYSQL_USER=pos_user
MYSQL_PASSWORD=CHANGE_ME_STRONG_PASSWORD
MYSQL_ROOT_PASSWORD=CHANGE_ME_STRONG_ROOT_PASSWORD

# JWT Secret (authentication)
# Generate: openssl rand -base64 32
JWT_SECRET=CHANGE_ME_GENERATE_NEW_SECRET

# ===== OPTIONAL - For HTTPS (Let's Encrypt) =====
APP_ENV=production
DOMAIN=pos.yourdomain.com           # Your server domain
ACME_EMAIL=admin@yourdomain.com     # For SSL cert notifications

# ===== OPTIONAL - For branch-specific configs =====
SYNC_INTERVAL=300                   # 5 minutes
BACKUP_SCHEDULE=0 2 * * *          # 2 AM daily
LOG_RETENTION_DAYS=30
```

### Secrets Generation

```bash
# Generate strong JWT_SECRET
openssl rand -base64 32

# Generate strong database passwords
openssl rand -hex 16

# Example output:
# abc123def456ghi789jkl012mno345pqr678stu=  <- JWT_SECRET
# a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6         <- DB passwords
```

### Secrets Management (Best Practices)

```bash
# 1. Store .env in secure location
sudo chown root:root /opt/scrap-pos/code/.env
sudo chmod 600 /opt/scrap-pos/code/.env

# 2. Restrict access via ACL (optional)
sudo setfacl -m u:$USER:r /opt/scrap-pos/code/.env

# 3. Backup .env separately (encrypted)
gpg --symmetric .env
# Restore: gpg --decrypt .env.gpg > .env

# 4. Rotate secrets every 90 days
# (Document in maintenance calendar)
```

---

## Database Initialization

### First-Time Setup

```bash
# 1. Check if migrations ran automatically
docker exec scrap-pos-db mysql -u pos_user -p$MYSQL_PASSWORD pos_system \
  -e "SHOW TABLES LIMIT 5;"

# Expected output: cash_sessions, products, branches, etc.

# 2. If tables missing, run migrations manually
cd /opt/scrap-pos/code/customizations/database
bash run-migrations.sh pos_user $MYSQL_PASSWORD
```

### Create Initial Admin User

```bash
# Access database
docker exec -it scrap-pos-db mysql -u pos_user -p$MYSQL_PASSWORD pos_system

# Insert admin user (run in MySQL prompt)
INSERT INTO users (username, email, password_hash, role, branch_id, is_active)
VALUES (
  'admin',
  'admin@yourdomain.com',
  '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/DSm',  -- password: admin123
  'admin',
  NULL,  -- admin has no branch restriction
  1
);

# Then run admin user reset script (below)
```

### Important: Reset Admin Password After First Login

```bash
# User MUST change password on first login
# Create /opt/scrap-pos/code/admin/force-password-change.html
# (Already implemented in cash-sessions feature)
```

---

## HTTPS/SSL Setup

### Option 1: Let's Encrypt with Caddy (Automatic)

**Prerequisites:**
- Domain name pointing to your server
- `DOMAIN` and `ACME_EMAIL` set in `.env`

```bash
# Create Caddyfile
mkdir -p /opt/scrap-pos/code/config
cat > /opt/scrap-pos/code/config/Caddyfile << 'EOF'
{$DOMAIN} {
  reverse_proxy web:80 {
    header_uri /api* X-Forwarded-Path /api
  }
  encode gzip
  log {
    output file /var/log/caddy/access.log
  }
}
EOF

# Start Caddy (included in docker-compose.prod.yml above)
docker-compose up -d caddy

# Verify SSL
curl -I https://{$DOMAIN}
# Should return: HTTP/2 200 + certificate info
```

### Option 2: Self-Signed Certificate (Internal Networks)

```bash
# Generate self-signed cert (valid 365 days)
mkdir -p /opt/scrap-pos/code/config/ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout /opt/scrap-pos/code/config/ssl/key.pem \
  -out /opt/scrap-pos/code/config/ssl/cert.pem \
  -subj "/CN=$(hostname)/O=YourCompany/C=TH"

# Update Apache config to use SSL
sudo nano /opt/scrap-pos/code/docker/web/apache.conf
# Add:
# <VirtualHost *:443>
#   SSLEngine on
#   SSLCertificateFile /etc/ssl/certs/cert.pem
#   SSLCertificateKeyFile /etc/ssl/private/key.pem
# </VirtualHost>

# Restart web container
docker-compose restart web
```

---

## Backup & Recovery

### Automated Daily Backups

Create `/opt/scrap-pos/scripts/backup-db.sh`:

```bash
#!/bin/bash
set -e

BACKUP_DIR="/opt/scrap-pos/backups"
DB_CONTAINER="scrap-pos-db"
MYSQL_USER="pos_user"
MYSQL_PASSWORD="$1"  # Pass as argument
DB_NAME="pos_system"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/pos_system_$DATE.sql.gz"

# Create backup directory if missing
mkdir -p "$BACKUP_DIR"

# Run backup
docker exec "$DB_CONTAINER" mysqldump \
  -u "$MYSQL_USER" \
  -p"$MYSQL_PASSWORD" \
  "$DB_NAME" \
  | gzip > "$BACKUP_FILE"

echo "Backup complete: $BACKUP_FILE"

# Keep only last 30 days of backups
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete
```

### Schedule with Cron

```bash
# Add to crontab (run as user who owns /opt/scrap-pos)
crontab -e

# Add line:
0 2 * * * /opt/scrap-pos/scripts/backup-db.sh $MYSQL_PASSWORD >> /opt/scrap-pos/logs/backup.log 2>&1
```

### Manual Backup

```bash
# Full database backup
docker exec scrap-pos-db mysqldump \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system > backup_$(date +%Y%m%d).sql

# Backup with test data for staging
docker exec scrap-pos-db mysqldump \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system > backup_$(date +%Y%m%d)_with_testdata.sql
```

### Restore from Backup

```bash
# Stop application
docker-compose down

# Restore database
docker-compose up -d db
sleep 10  # Wait for MySQL to start

# Import backup
docker exec scrap-pos-db mysql \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system < backup_20240714.sql

# Verify
docker exec scrap-pos-db mysql \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system -e "SELECT COUNT(*) FROM purchase_orders;"

# Start application
docker-compose up -d web
```

---

## Monitoring & Logs

### View Application Logs

```bash
# Web server (PHP/Apache)
docker-compose logs -f web

# Database (MySQL)
docker-compose logs -f db

# All containers
docker-compose logs -f

# Last 100 lines
docker-compose logs --tail 100 web
```

### Log Rotation

Create `/etc/logrotate.d/scrap-pos`:

```
/var/log/scrap-pos/* {
  daily
  rotate 30
  compress
  delaycompress
  notifempty
  create 0640 $USER $USER
  sharedscripts
  postrotate
    docker exec scrap-pos-web /usr/sbin/apache2ctl graceful > /dev/null 2>&1 || true
  endscript
}
```

### Performance Monitoring

```bash
# Container resource usage
docker stats

# Database query log
docker exec scrap-pos-db tail -f /var/log/mysql/slow-query.log

# Disk usage
df -h /opt/scrap-pos /var/lib/docker/volumes

# Memory usage
free -h
```

### Health Check Status

```bash
# Check container health
docker-compose ps

# Manual health check
curl -I http://localhost:8080/
curl -I http://localhost:8080/api/index.php/

# Database connectivity
docker exec scrap-pos-db mysql \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  -e "SELECT 1;" 2>/dev/null && echo "Database OK" || echo "Database FAILED"
```

---

## Production Hardening

### Firewall Configuration

```bash
# Enable UFW
sudo ufw enable

# Allow SSH (adjust port if using non-standard)
sudo ufw allow 22/tcp

# Allow HTTP/HTTPS
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Allow MySQL only from internal network
sudo ufw allow from 192.168.1.0/24 to any port 3307

# Deny everything else (default)
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Verify
sudo ufw status
```

### SSH Hardening

```bash
# Edit SSH config
sudo nano /etc/ssh/sshd_config

# Recommended settings:
PermitRootLogin no
PasswordAuthentication no           # Use keys only
PubkeyAuthentication yes
Protocol 2
Port 22                             # Change to non-standard if desired (e.g., 2222)

# Restart SSH
sudo systemctl restart ssh
```

### Fail2Ban (Brute Force Protection)

```bash
# Already installed, configure for custom app
sudo nano /etc/fail2ban/jail.local

# Add:
[scrap-pos]
enabled = true
port = http,https
logpath = /var/log/scrap-pos/access.log
maxretry = 5
findtime = 600
bantime = 3600

sudo systemctl restart fail2ban
```

### Regular Updates

```bash
# Enable unattended updates
sudo apt install -y unattended-upgrades

# Configure (only patch updates, no major version)
sudo nano /etc/apt/apt.conf.d/50unattended-upgrades
# Set: Unattended-Upgrade::AutoFixInterruptedDpkg "true";

# Enable security updates only
sudo dpkg-reconfigure -plow unattended-upgrades
```

---

## Troubleshooting

### Container Won't Start

```bash
# Check error logs
docker-compose logs web
docker-compose logs db

# Common issues:
# 1. Port already in use
sudo lsof -i :8080  # Find process on port 8080
sudo kill -9 <PID>

# 2. Disk space full
df -h
# Free up space: docker system prune -a

# 3. Database initialization failed
docker-compose restart db
docker-compose logs db
```

### Database Connection Failed

```bash
# Verify MySQL is running
docker exec scrap-pos-db mysql -u root -p$MYSQL_ROOT_PASSWORD -e "STATUS;"

# Check environment variables
docker exec scrap-pos-web env | grep MYSQL

# Verify network connectivity
docker exec scrap-pos-web ping db
docker exec scrap-pos-web curl http://db:3306
```

### Slow Performance

```bash
# Check container resource limits
docker stats

# Increase if needed (edit docker-compose.yml):
# deploy:
#   resources:
#     limits:
#       cpus: '1.5'
#       memory: 2G
#     reservations:
#       cpus: '1'
#       memory: 1G

# Check slow query log
docker exec scrap-pos-db tail -50 /var/log/mysql/slow-query.log

# Add indexes
docker exec -it scrap-pos-db mysql -u pos_user -p$MYSQL_PASSWORD pos_system
> ALTER TABLE purchase_orders ADD INDEX idx_business_date (business_date);
> ALTER TABLE cash_sessions ADD INDEX idx_created_at (created_at);
```

### Reset to Clean State

```bash
# ⚠️ DESTRUCTIVE - wipes all data
docker-compose down -v --remove-orphans
docker volume prune -f
rm -rf /opt/scrap-pos/code/db_data

# Start fresh
docker-compose up -d
```

---

## Maintenance Calendar

| Frequency | Task | Command |
|-----------|------|---------|
| Daily | Auto-backup DB | Cron job (see above) |
| Weekly | Verify backup integrity | Check `/opt/scrap-pos/backups/` |
| Monthly | Rotate secrets (JWT, DB passwords) | See [Secrets Generation](#secrets-generation) |
| Quarterly | Security updates | `sudo apt upgrade` |
| Yearly | SSL certificate renewal | Automatic (Let's Encrypt) |

---

## Next: Multi-Branch Distribution

See `MULTI_BRANCH_DISTRIBUTION.md` for guidance on:
- Distributing access to branch staff
- Central vs. distributed architecture
- Network topology recommendations
- Offline fallback strategies

