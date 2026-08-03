# Multi-Branch Distribution Strategy (4 Branches in Surin)

**Last updated:** 14 กรกฎาคม 2569  
**Scenario:** 1 Central Server (Ubuntu) + 4 Branch Offices (Surin Province)  
**Architecture:** Central database with distributed client access

---

## Table of Contents
1. [Architecture Overview](#architecture-overview)
2. [Network Topology](#network-topology)
3. [Branch Access Methods](#branch-access-methods)
4. [Sync & Data Consistency](#sync--data-consistency)
5. [Offline Fallback](#offline-fallback-strategy)
6. [Security Considerations](#security-considerations)
7. [Setup Checklist](#setup-checklist)

---

## Architecture Overview

### Model: Centralized Database + Local Clients

```
┌─────────────────────────────────────────────────────────┐
│           CENTRAL SERVER (Ubuntu, 1 machine)             │
│   IP: 192.168.1.10 (or public domain)                   │
│   ┌───────────────────────────────────────────────────┐ │
│   │  Docker Compose                                   │ │
│   │  ├─ Web (PHP/Apache) :80,:443                     │ │
│   │  └─ MySQL Database (pos_system)                   │ │
│   └───────────────────────────────────────────────────┘ │
│   Daily Backups: /opt/scrap-pos/backups/               │
└─────────────────────────────────────────────────────────┘
            ↑           ↑           ↑           ↑
      HTTPS/SSL    HTTPS/SSL   HTTPS/SSL   HTTPS/SSL
            ↓           ↓           ↓           ↓
┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  BRANCH 1    │ │  BRANCH 2    │ │  BRANCH 3    │ │  BRANCH 4    │
│ Surin North  │ │ Surin Central│ │ Surin South  │ │ Surin East   │
│ Browser/UI   │ │ Browser/UI   │ │ Browser/UI   │ │ Browser/UI   │
│ Tablets      │ │ Tablets      │ │ Tablets      │ │ Tablets      │
│ (Desktop)    │ │ (Desktop)    │ │ (Desktop)    │ │ (Desktop)    │
└──────────────┘ └──────────────┘ └──────────────┘ └──────────────┘
```

### Key Principles

| Aspect | Design |
|--------|--------|
| **Database** | Single MySQL on central server (no replication) |
| **Business Logic** | All in PHP backend (centralized) |
| **User Interface** | Stateless web UI (browsers) |
| **Auth** | JWT tokens issued by central server |
| **Data Sync** | Real-time via HTTPS API (no batch/queue) |
| **Failure Mode** | Branch can work offline temporarily (see below) |

### Advantages
✅ Single source of truth (no merge conflicts)  
✅ Easier to backup & audit  
✅ No complex replication setup  
✅ Real-time visibility across all branches  

### Tradeoffs
⚠️ Central server is single point of failure  
⚠️ Requires good internet at branches  
⚠️ Slower for high-latency connections  

---

## Network Topology

### Scenario 1: Internal Network (LAN)

**When:** All branches connected via private company network / VPN

```
┌─────────────────────────────────────────────────────────┐
│                    Company VPN / Network                 │
│                   (e.g., 192.168.0.0/24)                 │
│                                                           │
│  Central Server          Branch 1      Branch 2  Branch 3
│  192.168.1.10           192.168.1.11   ...
│  (Private IP)           (Private IP)
└─────────────────────────────────────────────────────────┘
```

**Setup:**
```bash
# Central server: Bind to private IP (not 127.0.0.1)
docker-compose edit  # Change ports to:
# ports:
#   - "192.168.1.10:80:80"
#   - "192.168.1.10:3306:3306"  # Only if branches need direct DB access

# Branch client: Access via private IP
# In browser: http://192.168.1.10/admin/
```

**Security:**
- Firewall restricts port 3306 to internal network only
- UFW rule: `sudo ufw allow from 192.168.1.0/24 to any port 3306`

---

### Scenario 2: Internet / Public Domain

**When:** Branches access via internet / branches at remote locations

```
┌─────────────────────────┐
│   Central Server        │
│ pos.yourdomain.com      │
│   (Public Domain)       │
└──────────┬──────────────┘
           │
    ┌──────┴──────┬──────────┬──────────┐
    │      │      │          │          │
  Branch  Branch  Branch    Branch   (Mobile?)
    1      2       3         4
  (WiFi) (WiFi)  (WiFi)   (WiFi)
```

**Setup:**
```bash
# Domain DNS points to central server public IP
# Let's Encrypt automatically provisions HTTPS
# Branches access: https://pos.yourdomain.com/admin/

# .env on central server:
DOMAIN=pos.yourdomain.com
ACME_EMAIL=admin@yourdomain.com
APP_ENV=production

# Caddy handles SSL automatically
docker-compose up -d caddy
```

**Security:**
- HTTPS enforced (automatic redirects)
- Firewall allows 80, 443 from anywhere
- MySQL port 3306 NOT exposed to internet

---

### Scenario 3: Hybrid (Recommended for Thailand)

**When:** Branches connect via company WiFi + occasional remote access

```
Central Server (Private IP 192.168.1.10)
  + Public DNS (pos.yourdomain.com) for remote access
  
Branches:
  - Usually via private network (fast, no quota)
  - Fallback to public domain if network unavailable
```

**Setup in .env:**
```env
# Can access via BOTH
# Internal:  http://192.168.1.10/
# External:  https://pos.yourdomain.com/

# Configure Caddy for both:
DOMAIN=pos.yourdomain.com
INTERNAL_IP=192.168.1.10
```

---

## Branch Access Methods

### Method 1: Web Browser (Recommended)

**Supported on:**
- Desktop computers
- Tablets (iPad, Android)
- Smartphones (secondary)

**Setup:**
```bash
# 1. Each branch: Open browser
# Internal:  http://192.168.1.10/admin/
# External:  https://pos.yourdomain.com/admin/

# 2. Login with branch credentials
username: cashier@branch1
password: (created in user setup)

# 3. App loads → user sees only their branch data
# (enforced server-side via JWT branch_id)
```

**Pros:**
✅ No installation needed  
✅ Works on any device with browser  
✅ Easy to update (push to server)  

**Cons:**
❌ Requires internet  
❌ Slow on poor WiFi  

---

### Method 2: Mobile Progressive Web App (PWA)

**Status:** Not implemented yet (future feature)

**When ready:**
```bash
# Users open browser, tap "Add to Home Screen"
# App runs like native app, syncs in background
# Can work offline temporarily (service worker)
```

---

## Sync & Data Consistency

### Real-Time Sync (Current Implementation)

**Flow:**
```
Branch 1 cashier → (HTTPS POST) → Central API
                    ↓
              Process & Save (MySQL)
                    ↓
              Return Response (JSON)
                    ↓
Branch 1 browser ← Show Success/Error
```

**Latency:**
- Internal network: ~50-100ms
- Public internet: ~200-500ms (acceptable)

**Consistency:**
- ACID transactions ensure no data loss
- Row-level locking (InnoDB) prevents race conditions

### Dealing with Temporary Internet Outage

**Current behavior:**
- If connection drops, user sees error
- Data NOT saved
- User must retry when online

**Recommended:**
```javascript
// Optional future enhancement (not implemented):
// Cache failed requests locally, retry when online
// Store in localStorage:
// {
//   "pending_sale_lot_123": {
//     "action": "save",
//     "payload": {...},
//     "timestamp": 1234567890
//   }
// }

// On reconnect, replay queued actions
```

---

## Offline Fallback Strategy

### Scenario: Central Server Unavailable (Rare)

**Problem:** Branch staff cannot record purchases/sales

**Solution: Offline Ledger**

```bash
# 1. Each branch keeps printed ledger + backup laptop
# 2. Transactions recorded manually:
#    - Purchase Order: seller name, items, price
#    - Sale Lot: batch number, items, buyer
#    - Cash session: opening balance, closing balance

# 3. When server back online:
#    - User manually enters missing transactions
#    - Or imports from backup export
#    - System detects duplicates via idempotency keys
```

**Recommended Process:**

```bash
# Before shift, print current status:
# - Active purchase orders
# - Pending approvals
# - Previous day summary

# Each branch has:
# 1. Printed work list (2-3 pages/day)
# 2. Physical ledger (bound notebook)
# 3. USB backup laptop with cached data

# If server down:
#   - Record all transactions on paper + USB
#   - Screenshot forms if possible
#   - Continue next business day when online
```

### Data Recovery After Outage

```bash
# When server is back up:
docker-compose logs db  # Check no corruption

# If database corrupted:
# 1. Stop application
docker-compose down

# 2. Restore from most recent backup
docker-compose up -d db
docker exec scrap-pos-db mysql -u pos_user -p$MYSQL_PASSWORD \
  pos_system < /opt/scrap-pos/backups/pos_system_20240714_020000.sql.gz

# 3. Verify integrity
docker exec scrap-pos-db mysql -u pos_user -p$MYSQL_PASSWORD \
  pos_system -e "SELECT COUNT(*) FROM purchase_orders;"

# 4. Restart application
docker-compose up -d web caddy

# 5. Notify branches of any data loss (none if backup recent)
```

---

## Security Considerations

### Threat Model

| Threat | Impact | Mitigation |
|--------|--------|-----------|
| **Branch staff steals/loses credentials** | Attacker gains access to POS | Change password immediately, disable account |
| **Server hacked** | Attacker accesses all branch data | Regular security updates, strong passwords, 2FA (future) |
| **Network intercept (MITM)** | Attacker reads user/transaction data | Always use HTTPS, pin SSL cert (optional) |
| **Insider: admin abuse** | Admin fraudulently approves transactions | Audit log all actions, require dual approval for high-value |
| **Malware on branch device** | Credential theft, data leakage | Keep devices updated, antivirus, regular scans |

---

### Implementation Checklist

**Firewalling:**
```bash
# Central server: UFW rules
sudo ufw allow from 192.168.1.0/24 to any port 80,443    # Branches
sudo ufw allow from 192.168.1.0/24 to any port 3306      # Optional: direct DB (not recommended)
sudo ufw default deny incoming
```

**HTTPS/SSL:**
```bash
# All branches MUST use HTTPS (automatic with Caddy + Let's Encrypt)
# Never allow HTTP in production

# Optional: Pin SSL certificate on branch devices
# (prevents rogue SSL from compromise)
```

**Authentication:**
```bash
# JWT token includes branch_id
# Server enforces: user can only access their branch
# See USER_ROLES_SETUP.md for role definitions
```

**Audit Logging:**
```bash
# All transactions logged with:
# - User who performed action
# - Timestamp (server time, not client)
# - What changed (from → to)
# - IP address (if using public domain)

# View logs:
docker exec scrap-pos-db mysql -u pos_user -p$MYSQL_PASSWORD \
  pos_system -e "SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 20;"
```

**Data Encryption at Rest (Optional):**
```bash
# If customer data requires encryption:
# 1. Use MySQL 8.0+ Transparent Data Encryption (TDE)
# 2. Encrypt disk volume: LUKS/dm-crypt
# 3. Document customer consent (PDPA compliance)
```

---

## Setup Checklist

### Before First Branch Connects

- [ ] Central server deployed (see DEPLOYMENT_UBUNTU_SERVER.md)
- [ ] Database initialized with all migrations
- [ ] HTTPS working (curl -I https://pos.yourdomain.com/)
- [ ] Admin account created + password changed
- [ ] Firewall configured (ports 80, 443 open; 3306 restricted)
- [ ] Daily backups scheduled and verified
- [ ] Branch user accounts created (one per branch)
- [ ] Branch staff trained on login/logout

### During Branch Onboarding

- [ ] Print network topology diagram for branch office wall
- [ ] Test connection from branch device
  ```bash
  # From branch device (browser or terminal):
  curl -I https://pos.yourdomain.com/
  # Should return: HTTP/2 200 + certificate details
  ```
- [ ] Staff login test: Create test purchase order, verify appears in admin
- [ ] Speed test: Measure round-trip latency acceptable (< 500ms)
- [ ] Offline procedures briefing: What to do if server down

### Monthly Maintenance

- [ ] Verify all 4 branches can connect
- [ ] Check backup integrity (restore test to staging DB)
- [ ] Review firewall logs for suspicious access
- [ ] Update Docker images & OS packages
- [ ] Test failover procedures (if using redundancy)

---

## Example Deployment for 4 Branches

### Step 1: Central Server (1 machine)

```bash
# Ubuntu 22.04 LTS server
# IP: 192.168.1.10 (internal) or pos.yourdomain.com (public)

cd /opt/scrap-pos/code

# Start application
docker-compose -f docker-compose.prod.yml up -d

# Verify
docker-compose ps
curl http://localhost:80/admin/  # Should load UI
```

### Step 2: Create Branch Users

```bash
# Access admin panel (see USER_ROLES_SETUP.md)
# Create 4 users:
# - Branch 1 Manager (role: manager, branch_id: 1)
# - Branch 2 Manager (role: manager, branch_id: 2)
# - Branch 3 Manager (role: manager, branch_id: 3)
# - Branch 4 Manager (role: manager, branch_id: 4)

# Each branch can then create additional cashier accounts
```

### Step 3: Branch 1 First Login

```bash
# Device: Tablet or Desktop at Branch 1
# Network: Connect to company WiFi (or use public domain)

# Open browser:
# - Internal: http://192.168.1.10/admin/
# - Public:  https://pos.yourdomain.com/admin/

# Login as Branch 1 user
# Should see:
# - Only Branch 1 purchase orders
# - Only Branch 1 cash sessions
# - Only Branch 1 employees
# ✅ Admin can see all branches
```

### Step 4: Repeat for Branches 2, 3, 4

```bash
# Same process, different user credentials
# Each branch completely isolated at UI level
# (enforced server-side, not UI)
```

---

## Network Diagram (Detailed)

```
┌─────────────────────────────────────────────────────────────────────┐
│                       CENTRAL UBUNTU SERVER                         │
│                    (Owned by Head Manager/Admin)                    │
│                                                                     │
│  IP: 192.168.1.10 (LAN) / pos.yourdomain.com (Internet)           │
│                                                                     │
│  ┌────────────────────────────────────────────────────────────┐   │
│  │ Docker Containers                                          │   │
│  │                                                            │   │
│  │  Web Container      Database Container                    │   │
│  │  - PHP/Apache      - MySQL 8.0                           │   │
│  │  - Port 80/443     - Port 3306 (internal only)           │   │
│  │  - Routes traffic  - Stores all data                     │   │
│  │  - JWT auth        - Backups daily to:                   │   │
│  │  - CORS enabled      /opt/scrap-pos/backups/            │   │
│  │                                                            │   │
│  └────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  ┌────────────────────────────────────────────────────────────┐   │
│  │ Caddy Reverse Proxy (Optional, for HTTPS)                │   │
│  │ - Auto SSL via Let's Encrypt                             │   │
│  │ - Redirects HTTP → HTTPS                                 │   │
│  │ - Port 80, 443 (public)                                  │   │
│  └────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  Backups: Stored locally + recommend USB external drive backup    │
│  Logs: /var/log/scrap-pos/ + Docker logs                         │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
                    ↑                ↑               ↑
                HTTPS               HTTPS          HTTPS
                (Port 443)          (Port 443)     (Port 443)
                    │                │               │
    ┌───────────────┘                │               └──────────────┐
    │                                │                              │
    ↓                                ↓                              ↓
┌────────────────┐         ┌────────────────┐         ┌────────────────┐
│  BRANCH 1      │         │  BRANCH 2      │         │  BRANCH 3-4    │
│ Surin North    │         │ Surin Central  │         │ Surin South    │
│                │         │                │         │ Surin East     │
│ Users:         │         │ Users:         │         │ Users:         │
│ - Manager 1    │         │ - Manager 2    │         │ - Manager 3    │
│ - Cashier 1a   │         │ - Cashier 2a   │         │ - Cashier 3a   │
│ - Cashier 1b   │         │ - Cashier 2b   │         │ - Cashier 3b   │
│                │         │                │         │                │
│ Devices:       │         │ Devices:       │         │ Devices:       │
│ - Tablet #1    │         │ - Tablet #2    │         │ - Tablet #3    │
│ - Desktop #1   │         │ - Desktop #2   │         │ - Desktop #3   │
│                │         │                │         │                │
│ Each device    │         │ Each device    │         │ Each device    │
│ sees ONLY      │         │ sees ONLY      │         │ sees ONLY      │
│ Branch 1 data  │         │ Branch 2 data  │         │ Branch 3/4 data│
└────────────────┘         └────────────────┘         └────────────────┘
```

---

## Next Steps

1. **Deploy central server** → Follow DEPLOYMENT_UBUNTU_SERVER.md
2. **Create branch users** → Follow USER_ROLES_SETUP.md
3. **Test from each branch** → See step 3 above
4. **Train staff** → Use DATA_SECURITY_GUIDE.md + CASH_SESSIONS_QUICK_GUIDE.md
5. **Monitor performance** → Check docker stats, slow query log

