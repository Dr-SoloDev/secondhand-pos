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

### Architecture: Central Server + 4 Remote Branches

**Real-World Setup for Scrap POS:**
- Central Server at Headquarters (Surin)
- Branch 1, 2, 3 at different locations (100+ km apart)
- All branches have internet connectivity (WiFi, Mobile, ISP)
- **Branches cannot access each other directly** (separate networks)

```
┌────────────────────────────────────────────────────────┐
│         UBUNTU SERVER (Headquarters)                   │
│   pos.yourdomain.com (Public Domain + IP)              │
│                                                         │
│  Docker Compose                                         │
│  ├─ Web (PHP/Apache) :80,:443                         │
│  ├─ MySQL Database                                     │
│  └─ Caddy HTTPS proxy                                  │
└────────────────┬───────────────────────────────────────┘
                 │
        HTTPS Encrypted (TLS 1.2+)
        Internet Connection (Port 443)
                 │
    ┌────────────┼────────────┬──────────────┐
    │            │            │              │
    ↓            ↓            ↓              ↓
┌────────────┐ ┌────────────┐ ┌────────────┐ ┌────────────┐
│  BRANCH 1  │ │  BRANCH 2  │ │  BRANCH 3  │ │ HQ OFFICE  │
│ (Remote)   │ │ (Remote)   │ │ (Remote)   │ │ (Local)    │
│ Internet   │ │ Internet   │ │ Internet   │ │ Internet   │
│ ├ WiFi     │ │ ├ Mobile   │ │ ├ ISP      │ │ ├ WiFi     │
│ └ Devices  │ │ └ Devices  │ │ └ Devices  │ │ └ Devices  │
│            │ │            │ │            │ │            │
│ Staff →    │ │ Staff →    │ │ Staff →    │ │ Staff →    │
│ Browser    │ │ Browser    │ │ Browser    │ │ Browser    │
│            │ │            │ │            │ │            │
│ URL:       │ │ URL:       │ │ URL:       │ │ URL:       │
│ https://   │ │ https://   │ │ https://   │ │ https://   │
│ pos.your   │ │ pos.your   │ │ pos.your   │ │ pos.your   │
│ domain.com │ │ domain.com │ │ domain.com │ │ domain.com │
│ /admin/    │ │ /admin/    │ │ /admin/    │ │ /admin/    │
└────────────┘ └────────────┘ └────────────┘ └────────────┘
```

**Setup Steps:**

1. **Get Public IP from ISP** (required)
   ```bash
   # Contact your internet service provider (ISP):
   "ฉันต้องการ public IP สำหรับเซิร์ฟเวอร์"
   
   # ISP will provide: 203.150.xxx.xxx (static or dynamic)
   # If dynamic IP: Use DNS dynamic update service
   ```

2. **Register Public Domain** (required, ~200฿/year)
   ```bash
   # Choose registrar:
   # - Namecheap.com
   # - GoDaddy.com
   # - Thai .co.th registrar
   
   # Register: pos.yourdomain.com (or scrapposhq.com)
   ```

3. **Point DNS to Server**
   ```bash
   # In domain registrar's control panel:
   # Add A Record:
   #   Name: pos
   #   Type: A
   #   Value: <your-public-ip>  (e.g., 203.150.xxx.xxx)
   #   TTL: 3600
   #
   # Wait 24-48 hours for DNS propagation
   
   # Verify from any computer:
   nslookup pos.yourdomain.com
   # Should return your server's IP
   ```

4. **Configure Central Server**
   ```bash
   cd /opt/scrap-pos/code
   
   # Edit .env:
   DOMAIN=pos.yourdomain.com
   ACME_EMAIL=admin@yourdomain.com
   APP_ENV=production
   
   # Start with Caddy (automatic HTTPS):
   docker-compose -f docker-compose.prod.yml up -d
   ```

5. **All Branches Access**
   ```
   From any device with internet:
   https://pos.yourdomain.com/admin/
   
   ✅ HTTPS encrypted (TLS 1.2+)
   ✅ Certificate auto-renewed (Let's Encrypt)
   ✅ No per-branch configuration
   ```

**Why This Works:**
- ✅ Simple: One domain for all branches
- ✅ Secure: HTTPS enforced, encrypted traffic
- ✅ Scalable: Add branches anytime
- ✅ Cheap: Domain ~200฿/year, SSL free
- ✅ Reliable: Automatic certificate renewal

**Security:**
- ✅ HTTPS enforced (automatic HTTP → HTTPS redirect)
- ✅ Firewall allows: 80 (HTTP), 443 (HTTPS)
- ✅ Firewall blocks: 3306 (MySQL), 22 (SSH restricted)
- ✅ Certificates auto-renewed every 90 days
- ✅ All traffic encrypted between branch and server
- ✅ JWT authentication validates every API call

---

## Branch Access Methods

### Method 1: Web Browser (Recommended) ⭐

**Supported on:**
- Desktop computers
- Tablets (iPad, Android)
- Smartphones (secondary)

**Setup (All branches use same URL):**

```bash
# 1. Each branch: Open web browser
# URL: https://pos.yourdomain.com/admin/
#      (same for HQ, Branch 1, 2, 3)

# 2. Login with branch credentials
username: cashier_branch1
password: (set in USER_ROLES_SETUP.md)

# 3. App loads → user sees only their branch data
# (enforced server-side via JWT branch_id)

# 4. Logout at end of shift
# Session expires after 8 hours
```

**Requirements:**
- ✅ Internet connection (WiFi, Mobile, ISP)
- ✅ Web browser (Chrome, Firefox, Safari)
- ✅ No installation needed

**Network Behavior:**
```
Branch 1 staff → (WiFi/Mobile) → Internet → HTTPS → Server
                                   ↑
                         Encrypted tunnel
                         Port 443 (HTTPS)
```

**Speed:**
- Local network: ~50-100ms response
- Remote branch: ~200-500ms response
- Acceptable for POS operations ✅

**Pros:**
✅ No installation needed  
✅ Works on any device with browser  
✅ Automatic updates (push to server)  
✅ No per-branch configuration  
✅ All branches use same URL  

**Cons:**
⚠️ Requires internet (see offline fallback below)  
⚠️ Slow on poor WiFi (rare)  

---

### Method 2: Mobile Progressive Web App (PWA)

**Status:** Not implemented yet (future feature)

**When ready:**
```bash
# Users open browser, tap "Add to Home Screen"
# App runs like native app on home screen
# Works offline temporarily (service worker caches data)
```

---

## Sync & Data Consistency

### Real-Time Sync (Current Implementation)

**Flow:**
```
Branch 1 staff enters PO
    ↓
(HTTPS POST encrypted)
    ↓
Central API (server)
    ↓
Process & Save to MySQL
    ↓
Return Response (JSON)
    ↓
Branch 1 browser ← Show "บันทึกสำเร็จ" (success)
```

**Latency:**
- HQ/Local: ~50-100ms (very fast)
- Remote Branch 1-3: ~200-500ms (acceptable for retail)
- Slow internet: ~500-2000ms (still usable)

**Consistency:**
- ✅ ACID transactions ensure no data loss  
- ✅ Row-level locking prevents race conditions  
- ✅ All branches see same data (single database)  
- ✅ Changes visible to other branches in seconds
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

### CRITICAL: Prerequisites

Before you start, **you MUST have:**

```bash
1. ✅ Ubuntu Server installed (22.04 LTS)
   - IP from ISP (either static or use dynamic DNS)
   - Internet connection working
   
2. ✅ Public Domain registered (~200 THB/year)
   - Registrar: Namecheap, GoDaddy, or Thai .co.th registrar
   - Example: pos.yourdomain.com, scrapposhq.com
   
3. ✅ DNS A Record pointing to server
   - Type: A Record
   - Name: pos
   - Value: <your-public-ip> (e.g., 203.150.xxx.xxx)
   - Wait 24-48 hours for propagation
   
Verify DNS:
nslookup pos.yourdomain.com
# Should return your server's IP
```

### Step 0: Verify Network Ready

```bash
# From Ubuntu Server:
curl https://pos.yourdomain.com
# Should return error (not set up yet) - that's OK

# From any branch device:
ping pos.yourdomain.com
# Should respond (verifies DNS works)
```

### Step 1: Central Server Setup

```bash
# On Ubuntu Server at Headquarters:

cd /opt/scrap-pos/code

# Copy .env.example → .env
cp .env.example .env

# Edit .env with secrets:
nano .env

# MUST fill:
# DOMAIN=pos.yourdomain.com
# ACME_EMAIL=admin@yourdomain.com
# MYSQL_PASSWORD=<strong-password>
# JWT_SECRET=<random-generated>
# (see DEPLOYMENT_UBUNTU_SERVER.md for details)

# Start application (Caddy gets HTTPS certificate automatically)
docker-compose -f docker-compose.prod.yml up -d

# Verify containers running
docker-compose ps

# Check HTTPS working (takes 2-5 minutes for cert)
curl -I https://pos.yourdomain.com/admin/
# Should return: HTTP/2 200 + SSL certificate info
```

### Step 2: Create Admin Account

```bash
# From browser (HQ office or remote):
https://pos.yourdomain.com/admin/

# Default login:
username: admin
password: admin

# IMMEDIATELY change password:
Click Settings → Users → admin → Change Password
# Create strong password (8+ chars, uppercase, numbers, special)
```

### Step 3: Create Branch Users

```bash
# In admin panel:
Settings → Users → Add New User

# Create 4 branch manager accounts:
# - cashier_branch1 (role: Manager, branch: Branch 1)
# - cashier_branch2 (role: Manager, branch: Branch 2)
# - cashier_branch3 (role: Manager, branch: Branch 3)
# - (optional) cashier_branch4 (role: Manager, branch: Branch 4)

# System generates temporary password for each
# Share with branch managers (via SMS/WhatsApp, not email)
```

### Step 4: Branch 1 First Login

```bash
# From any device at Branch 1 (Tablet, Desktop, Laptop):

1. Open web browser
2. Go to: https://pos.yourdomain.com/admin/
3. Login with:
   username: cashier_branch1
   password: (temporary password from admin)
4. Force password change (required on first login)
5. Should see: Only Branch 1 data ✅
   - Branch 1 purchase orders
   - Branch 1 cash sessions
   - Branch 1 employees
   - NOT Branch 2/3 data

6. Test: Create test PO
   → Verify appears in admin panel as Branch 1
```

### Step 5: Repeat for Branches 2 & 3

```bash
# Same login flow as Step 4
# Different credentials for each branch
# Each branch completely isolated at data level
# (enforced server-side via JWT branch_id)
```

### Step 6: Cross-Branch Test

```bash
# In admin panel (HQ):
https://pos.yourdomain.com/admin/

Login as: admin

Should see:
✅ All purchase orders from all branches
✅ All cash sessions from all branches
✅ All employees from all branches
✅ Reports comparing branches
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

