# Data Security & Test Data Cleanup Guide

**Last updated:** 14 กรกฎาคม 2569  
**Version:** 1.0  
**Audience:** Admin, IT Lead, Compliance Officer

---

## Table of Contents
1. [Executive Summary](#executive-summary)
2. [Test Data Cleanup](#test-data-cleanup)
3. [Customer PII Protection](#customer-pii-protection)
4. [Data Encryption](#data-encryption)
5. [Access Logging & Audit](#access-logging--audit)
6. [Backup Security](#backup-security)
7. [Incident Response](#incident-response)
8. [Compliance Checklist](#compliance-checklist-thailand)

---

## Executive Summary

**Before going live to production**, follow this 3-step process:

```
Week 1: Clean test data
  ↓
Week 2: Harden security
  ↓
Week 3: Audit & sign-off
```

| Item | Status | Deadline |
|------|--------|----------|
| Remove test customers | ☐ TODO | Day 1-3 |
| Remove test transactions | ☐ TODO | Day 1-3 |
| Verify customer data anonymized | ☐ TODO | Day 4-5 |
| Enable audit logging | ☐ TODO | Day 5-6 |
| Encrypt sensitive fields | ☐ TODO | Day 5-6 |
| Test backup encryption | ☐ TODO | Day 7 |
| Security audit (external) | ☐ TODO | Day 7-14 |
| Sign-off document | ☐ TODO | Day 14 |

---

## Test Data Cleanup

### What is "Test Data"?

```
Development database had:
- 500 fake customers
- 5,000 dummy transactions
- Test user accounts
- Sample images/attachments

Before production, MUST remove all ⚠️
```

### Step 1: Export Production-Ready Data

```bash
# BEFORE deleting anything, backup current test DB
docker exec scrap-pos-db mysqldump \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system \
  > /tmp/backup_with_testdata_$(date +%Y%m%d).sql

# Keep this file for 30 days (in case of error)
# Then delete from /tmp (don't keep test data on server)
```

### Step 2: Identify Test Data

**Test customers:** (manually review and identify)

```bash
# Connect to database
docker exec -it scrap-pos-db mysql \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system

# Run these SQL commands:
```

```sql
-- Find test customers (pattern-based)
SELECT id, name, phone FROM customers 
WHERE name LIKE 'Test%' 
   OR name LIKE 'Demo%'
   OR phone = '000-0000-0000'
   OR phone LIKE '555%'
LIMIT 20;

-- Note the IDs for deletion
-- Example: IDs 1-50 are all test customers
```

**Test transactions:**

```sql
-- Find orphaned transactions (no real customer)
SELECT COUNT(*) FROM purchase_orders 
WHERE customer_id IN (1,2,3,4,5);  -- After identifying test customers

-- Find test payments
SELECT COUNT(*) FROM payment_records 
WHERE amount = 0 OR amount > 999999;

-- Find unmatched entries
SELECT COUNT(*) FROM sale_lots 
WHERE status = 'DRAFT' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

### Step 3: Delete Test Data (Carefully)

```sql
-- Step 1: Get list of test customer IDs
SET @test_ids = '1,2,3,4,5,6,7,8,9,10';  -- Replace with actual IDs

-- Step 2: Start transaction (can rollback if wrong)
BEGIN TRANSACTION;

-- Step 3: Delete related records
DELETE FROM purchase_order_items 
WHERE purchase_order_id IN 
  (SELECT id FROM purchase_orders 
   WHERE customer_id IN (1,2,3,4,5));

DELETE FROM purchase_orders 
WHERE customer_id IN (1,2,3,4,5);

-- Step 4: Delete customers
DELETE FROM customers 
WHERE id IN (1,2,3,4,5);

-- Step 5: Verify count
SELECT 'Deleted:' as status, COUNT(*) FROM customers 
WHERE id IN (1,2,3,4,5);

-- Step 6: If correct, commit
COMMIT;

-- Step 7: If wrong, rollback
-- ROLLBACK;
```

**Alternative: Visual inspection & deletion via admin panel**

```
If only few test items:
1. Admin panel → Customers
2. Search "Test" or "Demo"
3. Click each one → Click "Delete"
4. Verify before confirming
```

### Step 4: Verify Cleanup

```bash
# After deletion, verify:
docker exec scrap-pos-db mysql \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system \
  -e "SELECT COUNT(*) as 'Total Customers' FROM customers;"

# Should show realistic number (50-500, not 5000)

docker exec scrap-pos-db mysql \
  -u pos_user \
  -p$MYSQL_PASSWORD \
  pos_system \
  -e "SELECT COUNT(*) as 'Total POs' FROM purchase_orders;"

# Should match expected (not thousands of test POs)
```

### Step 5: Delete Test User Accounts

```bash
# Via admin panel:
Admin → Settings → Users
Search for: "test", "demo", "admin123"
For each:
  - Click user
  - Click "Delete Account"
  - Confirm deletion

# Production users to keep:
✅ admin (main account)
✅ manager_branch1, manager_branch2, etc. (real staff)
✅ super_manager_hq (oversight account)
```

---

## Customer PII Protection

### What is PII (Personally Identifiable Information)?

In this POS system:

| Data | Type | Sensitivity |
|------|------|------------|
| Customer name | PII | ⚠️ MEDIUM |
| Phone number | PII | ⚠️ MEDIUM |
| Address | PII | ⚠️ MEDIUM |
| ID card number | PII | 🔴 HIGH |
| Email | PII | ⚠️ MEDIUM |
| Transaction history | PII | 🔴 HIGH |

**Thailand Compliance:** Personal Data Protection Act (PDPA, 2562 B.E.)
- Requires explicit consent to collect PII
- User right to access, correct, delete their data
- Vendor must ensure data security

### Consent & Disclosure

**Required before production:**

```
1. Print Privacy Notice / Terms of Service
   (Must be in Thai)
   
   Sample text:
   "บริษัทรับซื้อของเก่า collect your name, phone, and 
    address to:
    - Issue receipts
    - Manage inventory
    - Contact about sales
    
    Your data is protected and will not be sold.
    You can request to access/delete your data anytime."
    
2. Display at each branch:
   - In waiting area (poster)
   - In customer receipt (printed)
   - On receipt envelope
   
3. Train staff to explain:
   If customer asks: "Why do you need my ID?"
   Answer: "For receipt and bank transaction purposes.
            It's secured and will not be shared."
```

### Data Minimization

**Only collect what's needed:**

```
✅ COLLECT:
  - Name (issuing receipt)
  - Phone (contact for payment issues)
  - Address (optional, only if delivery)
  
❌ DON'T COLLECT:
  - ID card number (unless required by law)
  - Birth date (unnecessary)
  - Employment info (irrelevant)
  - Family members (not needed)
```

### Access Control for PII

**Who can see customer data:**

```
Cashier:
  ✅ Can see name, phone of seller they're transacting with
  ❌ Cannot see other branch customers
  ❌ Cannot see customer history/patterns
  
Manager:
  ✅ Can see all customers in their branch
  ✅ Can see transaction patterns
  ❌ Cannot see ID numbers (if stored)
  ❌ Cannot export full customer list
  
Admin:
  ✅ Can see everything (for compliance audit)
  ✅ Can access data export (only if legally required)
  
No one:
  ❌ Cannot view by batch
  ❌ Cannot share customer data outside company
  ❌ Cannot use for marketing (no email blasts)
```

### Right to Access / Deletion

**Implement deletion workflow:**

```
Customer requests: "Delete my data"
  ↓
Process:
1. Verify identity (phone number match)
2. Check if any open transactions
   - If yes: Cannot delete (legal requirement)
   - If no: Proceed
3. Admin marks customer as "DELETED"
   - Don't permanently delete (accounting trail)
   - Just anonymize: name → "DELETED_customer_12345"
4. Remove from any export/reports
5. Confirm to customer (verbal or email)

Code example:
UPDATE customers SET 
  name = CONCAT('DELETED_', id),
  phone = NULL,
  address = NULL,
  is_deleted = 1,
  deleted_at = NOW()
WHERE id = 123;
```

---

## Data Encryption

### Encryption at Rest (Database)

**Level 1: Filesystem Encryption (Easiest)**

```bash
# If Docker volume is on Linux with LUKS:
# (Physical server, not cloud)

# 1. Create encrypted volume
sudo cryptsetup luksFormat /dev/sdX1
sudo cryptsetup luksOpen /dev/sdX1 encrypted_data

# 2. Create filesystem
sudo mkfs.ext4 /dev/mapper/encrypted_data

# 3. Mount
sudo mount /dev/mapper/encrypted_data /var/lib/docker/volumes

# 4. Docker uses encrypted volume automatically
docker volume inspect mysql_data
# Shows mountpoint: /var/lib/docker/volumes/encrypted_data/

# ✅ All MySQL data encrypted on disk
```

**Level 2: Database-Level Encryption (MySQL 8.0)**

```sql
-- Enable InnoDB Transparent Data Encryption (TDE)
-- (requires MySQL Enterprise or config changes)

-- Conservative approach: encrypt sensitive columns only
-- Use AES_ENCRYPT() function on specific columns

-- Example: Encrypt customer ID numbers
UPDATE customers SET 
  id_number = AES_ENCRYPT(id_number, 'encryption_key')
WHERE id_number IS NOT NULL;

-- On retrieval:
SELECT id, AES_DECRYPT(id_number, 'encryption_key') as id_number
FROM customers;
```

**Level 3: Application-Level Encryption (Most Secure)**

```php
// In PHP (customizations/api/Helpers/Encryption.php)

class EncryptionHelper {
  public static function encrypt($plaintext, $key) {
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt(
      $plaintext, 
      'AES-256-CBC', 
      hash('sha256', $key), 
      0, 
      $iv
    );
    return base64_encode($iv . $encrypted);
  }
  
  public static function decrypt($ciphertext, $key) {
    $decoded = base64_decode($ciphertext);
    $iv = substr($decoded, 0, 16);
    $encrypted = substr($decoded, 16);
    return openssl_decrypt(
      $encrypted, 
      'AES-256-CBC', 
      hash('sha256', $key), 
      0, 
      $iv
    );
  }
}

// Usage:
$encrypted = EncryptionHelper::encrypt($phone, $encryption_key);
// Store $encrypted in DB
// Retrieve and decrypt when needed
```

**Recommendation:** Start with **Level 1** (filesystem encryption)  
Upgrade to **Level 2-3** if audit requires

### Encryption in Transit (Network)

**Already implemented:**

```bash
# HTTPS/SSL configured with Caddy
# All communication encrypted with TLS 1.2+

Verify:
curl -I https://pos.yourdomain.com/
# Should show: SSL certificate, TLS 1.2+
```

### Key Management

```
Encryption Key Storage:
  ❌ DON'T: Hardcode in PHP files
  ❌ DON'T: Store in Git
  ✅ DO: Store in .env file (restricted permissions)
  ✅ DO: Rotate keys every 12 months
  ✅ DO: Keep separate from DB password

Example .env:
ENCRYPTION_KEY=your_32_char_random_key_here
```

---

## Access Logging & Audit

### Enable Audit Logging

**Every action logged with:**
- WHO (user_id, username)
- WHAT (table, operation)
- WHEN (timestamp, timezone)
- WHERE (IP address)
- WHY (business reason, if applicable)

```sql
-- Audit log table (already in migrations)
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  username VARCHAR(255),
  action VARCHAR(50),           -- CREATE, UPDATE, DELETE, LOGIN
  table_name VARCHAR(100),
  record_id INT,
  old_values JSON,              -- Before change
  new_values JSON,              -- After change
  timestamp DATETIME,
  ip_address VARCHAR(45),
  reason VARCHAR(500),          -- Why (if required)
  INDEX (user_id, timestamp),
  INDEX (action),
  INDEX (timestamp)
);
```

**Trigger example (auto-logging on purchase order change):**

```sql
DELIMITER $$

CREATE TRIGGER purchase_order_audit AFTER UPDATE ON purchase_orders
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs 
  (user_id, username, action, table_name, record_id, old_values, new_values, timestamp, ip_address) 
  VALUES 
  (
    CURRENT_USER(), 
    'system_user',  -- Would need session var in real impl
    'UPDATE',
    'purchase_orders',
    NEW.id,
    JSON_OBJECT(
      'status', OLD.status,
      'total_amount', OLD.total_amount
    ),
    JSON_OBJECT(
      'status', NEW.status,
      'total_amount', NEW.total_amount
    ),
    NOW(),
    '192.168.1.100'  -- Would need to pass from PHP
  );
END$$

DELIMITER ;
```

### View Audit Logs

```bash
# Recent activity (last 24 hours)
docker exec scrap-pos-db mysql \
  -u pos_user -p$MYSQL_PASSWORD pos_system \
  -e "SELECT user_id, action, table_name, timestamp 
      FROM audit_logs 
      WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
      ORDER BY timestamp DESC 
      LIMIT 50;"

# Who accessed customer data
docker exec scrap-pos-db mysql \
  -u pos_user -p$MYSQL_PASSWORD pos_system \
  -e "SELECT DISTINCT user_id, username, COUNT(*) 
      FROM audit_logs 
      WHERE table_name = 'customers' 
      GROUP BY user_id 
      ORDER BY COUNT(*) DESC;"

# Suspicious patterns (multiple deletions)
docker exec scrap-pos-db mysql \
  -u pos_user -p$MYSQL_PASSWORD pos_system \
  -e "SELECT user_id, COUNT(*) as delete_count 
      FROM audit_logs 
      WHERE action = 'DELETE' 
        AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY) 
      GROUP BY user_id 
      HAVING COUNT(*) > 10;"
```

### Audit Log Retention

```bash
# Keep audit logs for minimum 7 years (Thailand compliance)
# Archive to external storage after 2 years

# Monthly cleanup job (remove > 7 years):
docker exec scrap-pos-db mysql \
  -u pos_user -p$MYSQL_PASSWORD pos_system \
  -e "DELETE FROM audit_logs 
      WHERE timestamp < DATE_SUB(NOW(), INTERVAL 84 MONTH);"

# Schedule in cron:
crontab -e
# Add: 0 3 1 * * docker exec scrap-pos-db mysql ... DELETE FROM audit_logs ...
```

---

## Backup Security

### Backup Location

```
❌ DON'T:
  - Store backup on same server
  - Store backup in /tmp (visible to anyone)
  - Store backup with world-readable permissions
  
✅ DO:
  - Store in /opt/scrap-pos/backups/ (restricted user access)
  - Copy weekly to USB external drive (encrypted, kept in safe)
  - Copy weekly to cloud storage (Dropbox, Google Drive, encrypted)
```

### Backup Encryption

```bash
# Encrypt backup before storing
gpg --symmetric \
  /opt/scrap-pos/backups/pos_system_20240714.sql

# Output: pos_system_20240714.sql.gpg (encrypted)
# Store password in separate location (not with backup)

# Restore encrypted backup:
gpg --decrypt pos_system_20240714.sql.gpg | \
  docker exec -i scrap-pos-db \
    mysql -u pos_user -p$MYSQL_PASSWORD pos_system
```

### Backup Integrity Testing

**Weekly:** Restore backup to test environment & verify

```bash
# 1. Restore latest backup to test DB
docker run --rm -v /opt/scrap-pos/backups:/backups \
  -e MYSQL_PASSWORD=$MYSQL_PASSWORD \
  mysql:8.0 \
  mysql -h mysql-test -u root -p$MYSQL_PASSWORD < /backups/latest.sql

# 2. Run sanity checks
docker exec mysql-test mysql -u root -p$MYSQL_PASSWORD pos_system \
  -e "SELECT COUNT(*) FROM purchase_orders; \
      SELECT COUNT(*) FROM sale_lots; \
      SELECT MAX(created_at) FROM purchase_orders;"

# 3. If all good: Document in log
# If failed: Alert admin & investigate
```

---

## Incident Response

### Security Breach Detected

**If data breach suspected (hacked, unauthorized access, data leak):**

```
Step 1: ISOLATE (within 1 hour)
  - Disconnect server from internet
  - Stop all services: docker-compose down
  - Preserve logs: Copy /var/log to USB (don't modify)
  
Step 2: ASSESS (within 2 hours)
  - What data was accessed?
  - How did breach happen?
  - How many records affected?
  
Step 3: NOTIFY (within 24 hours)
  - Owner/CEO
  - Affected customers (if > 100 records)
  - Police (if criminal activity suspected)
  - Regulator (if PDPA breach)
  
Step 4: REMEDIATE (within 7 days)
  - Hire security audit firm
  - Patch vulnerability
  - Reset all passwords
  - Restore from clean backup
  - Rebuild server from scratch
  
Step 5: RESTORE (when ready)
  - Deploy to new server
  - Test thoroughly
  - Bring back online
  
Step 6: FOLLOW-UP (ongoing)
  - Implement monitoring/alerting
  - Security audit quarterly
  - Incident review (post-mortem)
```

### Password Breach Detected

```
If admin password compromised:

1. Immediate action:
   - Disable admin account
   - Create new admin account with different username
   - Reset all user passwords
   
2. Review access logs:
   - docker-compose logs web | grep ERROR
   - docker exec scrap-pos-db mysql ... SELECT * FROM audit_logs
   
3. Change all secrets:
   - JWT_SECRET in .env
   - MySQL passwords
   - Database backup encryption key
```

---

## Compliance Checklist (Thailand)

### Personal Data Protection Act (PDPA) B.E. 2562

| Requirement | Status | Evidence |
|-------------|--------|----------|
| **Consent** | | |
| ☐ Privacy notice published | | Printed at branch |
| ☐ Customer acknowledges (signed form or verbal OK) | | Staff training doc |
| ☐ Staff can explain why data collected | | Training materials |
| **Security** | | |
| ☐ PII encrypted in transit (HTTPS) | | SSL cert verified |
| ☐ PII encrypted at rest (filesystem/DB) | | LUKS + MySQL config |
| ☐ Access controls (role-based) | | User roles test |
| ☐ Audit logging enabled | | Audit table queried |
| ☐ Regular backups tested | | Restoration log |
| **Individual Rights** | | |
| ☐ Access mechanism (customer can request data) | | Admin process doc |
| ☐ Correction mechanism | | Admin process doc |
| ☐ Deletion mechanism (anonymization) | | SQL script reviewed |
| **Incident Response** | | |
| ☐ Incident plan documented | | Response plan written |
| ☐ Notification procedure | | Escalation contact list |
| ☐ Post-incident audit capability | | Audit logs preserved |

### Implementation Checklist

Before going live:

```
Week 1: Privacy & Consent
  ☐ Draft privacy notice (Thai language)
  ☐ Print & post at all 4 branches
  ☐ Create staff training slides
  ☐ Train all staff (role-play consent scenarios)
  
Week 2: Security Hardening
  ☐ Enable HTTPS/SSL (Caddy + Let's Encrypt)
  ☐ Enable audit logging (MySQL triggers)
  ☐ Encrypt sensitive DB columns (or use TDE)
  ☐ Implement access controls (role verification)
  ☐ Test backup encryption & restore
  
Week 3: Testing & Documentation
  ☐ Run security audit (external firm)
  ☐ Document all security measures
  ☐ Create incident response plan
  ☐ Create data access request form
  ☐ Create data deletion request form
  
Week 4: Sign-Off
  ☐ CEO/Owner reviews & approves
  ☐ Sign Data Protection Policy
  ☐ Get staff acknowledgment (sign-in sheet)
  ☐ Get customer acknowledgment (privacy notice sign-off)
  ☐ File compliance documentation (7 years)
```

### Data Handling Best Practices

**For all staff:**

```
DO:
✅ Keep passwords secure (don't write on sticky notes)
✅ Lock computer when away (even 5 minutes)
✅ Logout at end of shift
✅ Report suspicious activity immediately
✅ Ask permission before sharing customer data
✅ Destroy printed receipts with PII (shred, don't trash)

DON'T:
❌ Take photos of customer ID
❌ Write down passwords
❌ Share login credentials
❌ Forward customer data via email
❌ Discuss customer info in public
❌ Leave device unattended while logged in
❌ Use public WiFi for admin access
```

---

## Data Retention Policy

### How Long to Keep Data?

| Data Type | Retention | Reason |
|-----------|-----------|--------|
| Transaction records (PO, Sale Lot) | 7 years | Tax law (Thailand) |
| Customer data (name, phone) | 3 years (or until deletion request) | Business need + PDPA |
| Audit logs | 7 years | Compliance + fraud detection |
| Backups | 30 days (rolling) | Recovery window |
| Deleted customer data | Anonymized, kept 7 years | Accounting trail |
| User login logs | 90 days | Security monitoring |
| System error logs | 30 days | Troubleshooting |

### Automated Purging

```sql
-- Monthly cron job to archive/purge old data

-- Archive old transactions (older than 3 years)
INSERT INTO archive_purchase_orders
SELECT * FROM purchase_orders 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 YEAR);

DELETE FROM purchase_orders 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 YEAR);

-- Purge very old backups (older than 90 days)
find /opt/scrap-pos/backups -name "*.sql.gz" -mtime +90 -delete

-- Clear login logs (older than 90 days)
DELETE FROM login_logs 
WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY);
```

---

## Pre-Production Checklist (Final)

Print & sign off before going live:

```
┌─────────────────────────────────────────────────────────┐
│        PRE-PRODUCTION SIGN-OFF DOCUMENT                 │
├─────────────────────────────────────────────────────────┤
│                                                         │
│ Data Cleanup                                            │
│   ☐ Test data removed (customers: 5000 → 50)           │
│   ☐ Test transactions removed                          │
│   ☐ Test user accounts removed                         │
│   ☐ Backup verified with clean data only               │
│                                                         │
│ Security Hardening                                      │
│   ☐ HTTPS enabled (Let's Encrypt cert)                │
│   ☐ Audit logging enabled & tested                     │
│   ☐ Encryption enabled (at rest & in transit)         │
│   ☐ Firewall configured (port restrictions)           │
│   ☐ Failed login attempt monitoring enabled            │
│   ☐ Backup encryption enabled                          │
│                                                         │
│ Compliance                                              │
│   ☐ Privacy notice posted (Thai language)              │
│   ☐ Staff trained on data handling                     │
│   ☐ PDPA compliance review completed                   │
│   ☐ Data access request process documented            │
│   ☐ Data deletion process documented                   │
│   ☐ Incident response plan approved                    │
│                                                         │
│ Testing                                                 │
│   ☐ Backup restoration tested                          │
│   ☐ Disaster recovery tested                           │
│   ☐ User access controls verified                      │
│   ☐ Branch data isolation verified                     │
│   ☐ Performance acceptable (< 500ms latency)          │
│                                                         │
│ Authorized By:                                          │
│   Owner/CEO Name: ________________________              │
│   Date: ________________                               │
│   Signature: ________________________                   │
│                                                         │
│ IT Lead/Admin:                                          │
│   Name: ________________________                        │
│   Date: ________________                               │
│   Signature: ________________________                   │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

## Resources & References

| Topic | Reference |
|-------|-----------|
| PDPA Thailand | https://www.pdpc.go.th/ (Thai regulator) |
| Data Encryption | MySQL Encryption: https://dev.mysql.com/doc/refman/8.0/en/innodb-tablespace-encryption.html |
| Security Audit | OWASP Top 10: https://owasp.org/www-project-top-ten/ |
| Backup Best Practices | https://www.digitalocean.com/community/tutorials/backup-recovery |
| Incident Response | NIST Incident Response Plan: https://nvlpubs.nist.gov/nistpubs/SpecialPublications/NIST.SP.800-61r3.pdf |

