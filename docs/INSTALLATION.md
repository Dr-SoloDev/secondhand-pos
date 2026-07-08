# Installation Guide — Secondhand POS

**คู่มือการติดตั้งระบบจัดการร้านรับซื้อของเก่า 4 สาขา**

> Version: 1.0 | Last Updated: 2026-07-07

---

## 1. Prerequisites

| Software | Version | Purpose |
|----------|---------|---------|
| Docker | 24+ | Container runtime |
| Docker Compose | 2.20+ | Container orchestration |
| Git | 2.30+ | Version control |
| MySQL client (optional) | 8.0 | Direct DB access |

**Hardware minimum** (per server):
- 2 CPU cores
- 2GB RAM
- 20GB SSD
- Internet connection (for initial setup)

---

## 2. Initial Setup

### 2.1 Clone Repository

```bash
git clone <repository-url> scrap-pos
cd scrap-pos/code
```

### 2.2 Configure Environment

```bash
cp .env.example .env
```

Required variables in `.env`:

| Variable | Description | Example |
|----------|-------------|---------|
| `JWT_SECRET` | JWT signing key (change in production!) | `your-secure-random-string` |
| `MYSQL_ROOT_PASSWORD` | MySQL root password | `rootpass` |
| `MYSQL_USER` | Application DB user | `posuser` |
| `MYSQL_PASSWORD` | Application DB password | `pospass` |
| `MYSQL_DATABASE` | Database name | `pos_system` |

Production-only variables:

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_ENV` | Set to `production` in production | `production` |
| `DOMAIN` | Your domain for Caddy | `pos.yourdomain.com` |
| `ACME_EMAIL` | Email for Let's Encrypt | `admin@yourdomain.com` |
| `ALLOWED_ORIGINS` | CORS origins (comma separated) | `https://pos.yourdomain.com` |

### 2.3 Start Containers

```bash
docker compose up -d
```

This starts:
- **scrap-pos-web** — Apache + PHP 8.2 on port 8080
- **scrap-pos-db** — MySQL 8.0 on port 3307

First start imports base schema + runs all 48 migrations automatically.

### 2.4 Verify Installation

```bash
# Check containers are running
docker ps

# Test API
curl http://localhost:8080/api/index.php/branches

# Open browser
open http://localhost:8080/admin/
```

### 2.5 Default Login

| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `admin` |

**Change password immediately after first login.**

---

## 3. Database

### 3.1 Access via CLI

```bash
docker exec -it scrap-pos-db mysql -u root -prootpass pos_system
```

### 3.2 Backup

```bash
docker exec scrap-pos-db mysqldump -u root -prootpass pos_system > backup-$(date +%Y%m%d).sql
```

### 3.3 Restore

```bash
cat backup-20260707.sql | docker exec -i scrap-pos-db mysql -u root -prootpass pos_system
```

### 3.4 Running Migrations

Migrations are stored in `customizations/database/migrations/` and run automatically on first container start. To run manually:

```bash
# If host has mysql client:
bash customizations/database/run-migrations.sh root rootpass

# Or via docker:
docker exec -i scrap-pos-db mysql -u root -prootpass pos_system < customizations/database/migrations/048_add_sale_lots_created_at_index.sql
```

---

## 4. Production Deployment

### 4.1 Docker Stack

In production, `docker-compose.yml` uses **Caddy** instead of phpMyAdmin:
- Caddy automatically provisions Let's Encrypt TLS certificates
- phpMyAdmin is commented out

```bash
echo "APP_ENV=production" >> .env
echo "DOMAIN=pos.yourdomain.com" >> .env
echo "ACME_EMAIL=admin@yourdomain.com" >> .env
docker compose up -d
```

### 4.2 Deploy Script

A deploy script is provided at `code/deploy.sh`:

```bash
bash deploy.sh
```

Flow:
1. Backup database
2. `git pull`
3. Rebuild containers
4. Run migrations
5. Health check (3 retries, 10s interval)
6. Rollback on failure

### 4.3 Health Check

Docker Compose includes a health check for the web container:
- URL: `http://localhost/api/index.php/branches`
- Interval: 30s
- Retries: 3

### 4.4 SSL/HTTPS

Caddy handles TLS automatically via Let's Encrypt. Required env vars:
- `DOMAIN` — your domain
- `ACME_EMAIL` — email for certificate notifications

---

## 5. Development

### 5.1 Full Reset

```bash
docker compose down -v && docker compose up -d --build
```

This deletes all data and starts fresh.

### 5.2 Rebuild Specific Container

```bash
docker compose up -d --build web
```

### 5.3 View Logs

```bash
docker compose logs -f web
docker compose logs -f db
```

### 5.4 Run Tests

```bash
cd tests/api
bash run.sh
```

Tests cover: auth, branches, sellers, POs, sale lots, FIFO flow, catalog, inventory — **84 assertions**.

---

## 6. Troubleshooting

| Problem | Cause | Solution |
|---------|-------|----------|
| Port 8080 in use | Another service | Change `WEB_PORT` in `.env` |
| DB connection refused | DB not ready | Wait 30s, `docker compose restart web` |
| Login fails | Wrong JWT_SECRET | Regenerate JWT_SECRET in `.env` |
| "Too many login attempts" | Rate limiting | Wait 15 min or `TRUNCATE login_attempts;` in DB |
| Migration error | Duplicate version | Check `schema_migrations` table |
| Caddy cert error | DNS not propagated | Wait 5 min, check `DOMAIN` in `.env` |

---

## 7. Container Reference

| Service | Image | Ports | Volumes | Health Check |
|---------|-------|-------|---------|-------------|
| web | `php:8.2-apache` | 80 (internal) | `./base-pos:/var/www/html`, `./customizations:/var/www/custom` | `/branches` 30s |
| db | `mysql:8.0` | 3306 (internal) | `./mysql-data:/var/lib/mysql` | MySQL native |
| caddy | `caddy:2` | 443 (external) | `Caddyfile` | Caddy native |

---

## 8. File Structure

```
code/
├── base-pos/                      # Core system (read-only)
│   ├── api/                       # PHP backend
│   │   ├── Router.php             # Route registry
│   │   ├── index.php              # Entry point
│   │   ├── config.php             # DB config from env
│   │   ├── autoload.php           # PSR-4 autoloader
│   │   └── Core/                  # Database, Auth, Response, Logger
│   ├── admin/                     # Admin UI pages
│   └── assets/                    # CSS, JS
│
├── customizations/                # Custom overlay (edit here)
│   ├── api/
│   │   ├── Controllers/           # 11 controllers
│   │   ├── Models/                # 10 models
│   │   └── Services/              # ReportService.php
│   └── database/
│       ├── migrations/            # 48 SQL files
│       └── run-migrations.sh      # Migration runner
│
├── tests/api/                     # API tests (bash/curl)
├── deploy.sh                      # Production deploy script
├── docker-compose.yml             # Docker Compose config
└── Dockerfile                     # PHP 8.2 Apache image
```
