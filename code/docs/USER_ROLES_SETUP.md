# User Roles, Permissions & Setup Guide

**Last updated:** 14 กรกฎาคม 2569  
**Version:** 1.0  
**Audience:** Admin / HR (non-technical)

---

## Table of Contents
1. [Role Hierarchy](#role-hierarchy)
2. [Permissions Matrix](#permissions-matrix)
3. [Creating User Accounts](#creating-user-accounts)
4. [Password Policy](#password-policy)
5. [Account Lifecycle](#account-lifecycle)
6. [Two-Factor Authentication (2FA)](#two-factor-authentication-2fa)
7. [Approval Workflows](#approval-workflows)
8. [Branch Scoping](#branch-scoping)
9. [Troubleshooting Access Issues](#troubleshooting-access-issues)

---

## Role Hierarchy

The POS system has **4 user roles**, arranged in hierarchy from least to most privileged:

```
                    ┌─────────────┐
                    │    ADMIN    │  (System-wide permissions)
                    └──────┬──────┘
                           │
                    ┌──────▼──────┐
                    │ SUPER_MNGR  │  (Multi-branch oversight)
                    └──────┬──────┘
                           │
                    ┌──────▼──────┐
                    │  MANAGER    │  (Single branch operations)
                    └──────┬──────┘
                           │
                    ┌──────▼──────┐
                    │   CASHIER   │  (Data entry only)
                    └─────────────┘
```

---

## Permissions Matrix

| Feature | Cashier | Manager | Super Manager | Admin |
|---------|---------|---------|---------------|----- |
| **Purchase Orders** | | | | |
| Create | ✅ Own branch | ✅ Own branch | ✅ All | ✅ All |
| View | ✅ Own branch | ✅ Own branch | ✅ All | ✅ All |
| Approve | ❌ | ✅ Small POs | ✅ All POs | ✅ All |
| Cancel | ❌ | ✅ Same day | ✅ All dates | ✅ All |
| **Sale Lots** | | | | |
| Create | ❌ | ✅ Own branch | ✅ All | ✅ All |
| Confirm | ❌ | ✅ Pending lots | ✅ All | ✅ All |
| View | ✅ Own branch | ✅ Own branch | ✅ All | ✅ All |
| **Cash Sessions** | | | | |
| Open session | ✅ Own branch | ✅ Own branch | ✅ All | ✅ All |
| Approve variance | ❌ | ✅ <฿100 | ✅ All | ✅ All |
| Close session | ✅ Own branch | ✅ Own branch | ✅ All | ✅ All |
| View all | ❌ Own branch | ❌ Own branch | ✅ All | ✅ All |
| **Adjustment Documents** | | | | |
| Create | ❌ | ❌ | ❌ | ✅ Only |
| View | ❌ | ✅ Own branch | ✅ All | ✅ All |
| Approve | ❌ | ❌ | ❌ | ✅ Only |
| **Business Expenses** | | | | |
| Create | ✅ | ✅ | ✅ | ✅ |
| Approve | ❌ | ✅ Own branch | ✅ All | ✅ All |
| View | ✅ Own | ✅ Own branch | ✅ All | ✅ All |
| **Reports & Analytics** | | | | |
| Daily summary | ❌ | ✅ Own branch | ✅ All | ✅ All |
| Branch comparison | ❌ | ❌ | ✅ All | ✅ All |
| Admin reports | ❌ | ❌ | ❌ | ✅ All |
| Export data | ❌ | ✅ Own (CSV) | ✅ All (CSV) | ✅ All (SQL) |
| **Settings & Users** | | | | |
| User management | ❌ | ❌ | ❌ | ✅ Only |
| Branch settings | ❌ | ❌ | ❌ | ✅ Only |
| System settings | ❌ | ❌ | ❌ | ✅ Only |

---

## Creating User Accounts

### Prerequisites
- Admin account must be created (done during installation)
- Admin must be logged in to access admin panel

### Step-by-Step: Create Cashier Account

**Scenario:** Create cashier for Branch 1 (Surin North)

```
1. Login to admin panel
   URL: https://pos.yourdomain.com/admin/
   Username: admin
   Password: (your admin password)

2. Navigate to: Settings → Users → Add New User

3. Fill form:
   ┌────────────────────────────────────┐
   │ Username:     cashier_branch1       │
   │ Email:        cashier1@branch1.com  │
   │ Password:     (auto-generate below) │
   │ Role:         CASHIER              │
   │ Branch:       Branch 1 (Surin N)   │
   │ Active:       ✅ Yes                │
   └────────────────────────────────────┘

4. Generate strong password:
   Click "Generate Password" → Copy to clipboard
   → Save & Send to employee (separately)

5. Click "Create User" → Success message
   ✅ User created, password emailed

6. Test login:
   Have employee login from branch device
   → Should see only Branch 1 data
```

### Step-by-Step: Create Branch Manager Account

**Scenario:** Promote cashier to manager at Branch 2

```
1. In admin panel: Users → Find existing cashier
   Name: "cashier_branch2"

2. Click "Edit" → Change Role:
   Role: MANAGER (was CASHIER)

3. Check permissions:
   ✅ Can now approve POs
   ✅ Can create sale lots
   ✅ Can close cash sessions
   ✅ Can view only Branch 2 data

4. Notify manager:
   "Your role upgraded to Manager
    - New responsibilities: PO approvals, cash reconciliation
    - Access: Branch 2 only
    - Escalate to super-manager if issues"
```

### Step-by-Step: Create Super Manager Account

**Scenario:** Create multi-branch oversight user at headquarters

```
1. In admin panel: Users → Add New User

2. Fill form:
   ┌────────────────────────────────────┐
   │ Username:     super_manager_hq      │
   │ Email:        manager@company.com   │
   │ Password:     (auto-generate)       │
   │ Role:         SUPER_MANAGER        │
   │ Branch:       (leave empty = all)   │
   │ Active:       ✅ Yes                │
   └────────────────────────────────────┘

3. Permissions granted:
   ✅ Can view all 4 branches simultaneously
   ✅ Can approve POs from any branch
   ✅ Can analyze performance comparisons
   ✅ Can NOT create adjustments (admin only)
   ❌ Cannot access system settings
```

---

## Password Policy

### Requirements for All Users

| Rule | Details | Reasoning |
|------|---------|-----------|
| **Minimum length** | 8 characters | Complexity |
| **Must include:** | - Uppercase (A-Z) | Prevent dictionary attacks |
|  | - Lowercase (a-z) | Prevent common patterns |
|  | - Numbers (0-9) | Increase entropy |
|  | - Special char (!@#$%) | Defense in depth |
| **Cannot contain** | Username | Prevent weak passwords |
| **Expiry** | Every 90 days | Limit impact if compromised |
| **History** | Cannot reuse last 5 | Prevent cycling back |

### Password Generation

```bash
# Recommendation: Use Linux tool to generate
openssl rand -base64 12 | tr -d '=' | cut -c1-12
# Example: "aB9$kL2mN4vP"

# Or use online generator (if no Linux access):
# https://www.random.org/passwords/
# 1 password, 12 characters, numbers + mixed case

# Never use:
# ❌ "Password123"
# ❌ "Branch1_2024"
# ❌ "123456789012"
# ❌ Birth dates, company name, role name
```

### First Login: Forced Password Change

```
On first login, system will force:
1. Old password change
2. New password must differ from generated one
3. Confirm new password twice
4. User must acknowledge password policy

After this, password expires every 90 days
→ User gets notification 7 days before expiry
→ Must change before login blocked
```

---

## Account Lifecycle

### 1. Onboarding (New Employee)

```
Week 1: HR hires employee
  ↓
Week 2: Admin creates account
  ├─ Username: first.last_branch
  ├─ Temp password: generated & emailed
  ├─ Role: Cashier (default start)
  └─ Branch: Assigned based on location
  ↓
Employee 1st login:
  ├─ Enter temp password
  ├─ Forced password change
  ├─ Must acknowledge terms
  └─ Access granted
  ↓
Training: 2-3 days with supervisor
  └─ Learn POS workflow, shortcuts
  ↓
Ready for production ✅
```

### 2. Active Use

```
Daily:
  - Employee logs in (lasts 8 hours or until logout)
  - Works at assigned branch only
  - Can perform role-assigned actions
  
Weekly:
  - Admin reviews login logs for anomalies
  - Check for failed login attempts (possible breach)
  
Monthly:
  - Admin audits which users access what
  - Disable unused accounts
  - Verify role appropriateness
```

### 3. Promotion

```
Current: Cashier at Branch 1
Manager: "You're promoted to manager"
  ↓
Process:
1. Admin edits user account
   Role: CASHIER → MANAGER
   
2. Responsibilities explained:
   ✅ PO approvals (up to ฿5,000)
   ✅ Cash session reconciliation
   ✅ Expense approvals
   ✅ Can view advanced reports
   
3. Training: 1 day with super-manager
   → Approval workflows
   → Escalation procedures
   
4. Go live ✅
```

### 4. Separation (Employee Leaves)

```
Employee resignation/termination
  ↓
Same day (ideally):
1. Admin disables account immediately
   Active: ✅ → ❌
   
2. Check for:
   - Any pending approvals (reassign)
   - Any recent transactions (audit)
   - Any suspicious activity (report)
   
3. Delete sensitive data:
   - Clear password hash from DB (cannot recover)
   - Archive audit logs (keep 7 years for compliance)
   
4. Secure any devices (tablets, laptops)
   - Remotely wipe if possible
   - Remove from branch inventory
   
Process complete ✅
```

---

## Two-Factor Authentication (2FA)

### Current Status: Not Implemented

**Planned for Phase 2** (after initial deployment)

### When Implemented: SMS-Based 2FA

```
Login Flow (with 2FA):

1. Username: admin
2. Password: (enter password)
3. ✅ Credentials valid
   → System sends SMS: "Your code: 123456"
   
4. User enters code in 30 seconds
5. ✅ 2FA verified → Access granted
   
Fallback if SMS fails:
- Use backup codes (printed during setup)
- Email-based OTP (slower but more reliable in rural areas)
```

### Recommended: Print Backup Codes

```
When 2FA enabled for admin:
1. System generates 10 backup codes:
   XXXX-1234
   XXXX-5678
   XXXX-9012
   ... (10 total)

2. Print & store in safe:
   ✅ Keep in locked drawer
   ✅ Only for emergency access
   ✅ Never share via email
   
3. If phone lost:
   - Use one backup code to login
   - Disable 2FA (requires password)
   - Re-register device
```

---

## Approval Workflows

### Purchase Order Approval (Manager)

**Scenario:** Cashier enters PO for ฿3,500; Manager must approve

```
1. Cashier enters PO:
   - Seller: Mr. Somchai
   - Items: Refrigerator + Microwave
   - Amount: ฿3,500
   - Click "Submit for Approval"
   
2. Status changes: PENDING → AWAITING_APPROVAL
   ✅ PO visible to manager in approval queue
   
3. Manager reviews (next morning):
   - Seller: Known? ✅
   - Price: Fair? ✅
   - Items: Condition OK? ✅
   - Click "Approve"
   
4. Cashier notified:
   ✅ PO approved, proceed to payment
   
5. If rejected:
   Manager clicks "Reject" + comment:
   "Seller blacklisted - check records"
   → Cashier sees rejection, must redo with different seller
```

### Cash Session Variance Approval

**Scenario:** Closing balance differs from expected (> ฿100)

```
Cashier closes session:
- Opening balance: ฿10,000
- Expected closing: ฿8,500 (after -฿1,500 purchases)
- Actual closing: ฿7,200
- Variance: -฿1,300 (over ฿100 threshold)
  ↓
Status: PENDING_CLOSE
✅ Variance field locked until approved
  ↓
Manager (next morning) reviews:
- Reason: "Counted ฿1,300 short"
- Possible causes:
  * Cashier counting error? (ask for recount)
  * Cash drawer theft? (investigate)
  * Recording error in system? (check PO records)
  ↓
Manager approves if acceptable:
- "Discrepancy acceptable - recounted, verified"
- Click "Approve"
  ↓
Session now CLOSED ✅
Variance documented for audit
```

---

## Branch Scoping

### What is "Branch Scoping"?

**Simple explanation:**
- Each non-admin user can ONLY see data from their assigned branch
- Even if user tries to hack URL or API, system checks branch_id
- If branch mismatch, access DENIED

### How It Works (Technical)

```
1. Cashier logs in:
   Username: cashier_branch1
   Password: ••••••••
   
2. System creates JWT token:
   {
     "user_id": 42,
     "username": "cashier_branch1",
     "branch_id": 1,           ← LOCKED IN
     "role": "CASHIER",
     "expires": 1234567890
   }
   
3. Every API call checks:
   "Is this user's branch_id = requested data's branch_id?"
   
   Example:
   Cashier tries: GET /purchase-orders?id=999
   Server checks: purchase_order[999].branch_id == 1?
   - YES → Return data ✅
   - NO → Return "Unauthorized" ❌

4. URL manipulation doesn't help:
   Cashier tries: /purchase-orders?id=999&branch_id=2
   Server ignores branch_id param, uses JWT only
   → Still shows error if PO belongs to Branch 2
```

### Admin Exception

```
Admin login:
{
  "user_id": 1,
  "username": "admin",
  "branch_id": null,    ← NULL = no restriction
  "role": "ADMIN",
  "expires": ...
}

Admin can see all branches simultaneously ✅
```

---

## Troubleshooting Access Issues

### Issue 1: "Login Failed" - Wrong Credentials

```
Symptom: Username or password incorrect message

Diagnosis:
1. Check if username exists:
   Admin panel → Users → Search username
   - If not found: Account not created yet
   
2. Check if account active:
   Users table → active column
   - If false: Account disabled (contact admin)
   
3. Check password:
   - Passwords expire every 90 days
   - If > 90 days: Change password
   - Temporary password: Must change on first login
   
Solution:
1. Ask user to double-check caps lock
2. If still fails: Admin resets password
   Admin panel → Users → Reset Password
   → Send new password via SMS/email
3. User changes password on next login
```

### Issue 2: "Access Denied" - Permission Denied

```
Symptom: "You do not have permission to access this resource"

Diagnosis:
1. Check role:
   Admin panel → Users → View user
   - Role: Cashier
   - Expected: Manager (for PO approvals)
   
2. Check branch assignment:
   - User branch_id: 1
   - Requested data branch_id: 2
   → Mismatch!
   
3. Check if feature available:
   - Only managers can approve POs
   - Only admins can create adjustments

Solution:
1. If wrong branch:
   User must be at correct branch device
   - Branch 1 user can only work at Branch 1
   - Cannot remotely access other branch
   
2. If role too low:
   Admin must promote user
   Admin panel → Users → Edit → Role
   Change to: MANAGER (or SUPER_MANAGER)
   
3. If feature restricted:
   Feature available only at certain times/roles
   - Example: Adjustment documents only by admin
   - Example: PO approvals only after 2 hours (future)
```

### Issue 3: Session Expired

```
Symptom: "Session expired, please login again"

Diagnosis:
- JWT token has 8-hour expiry (configurable)
- User logged in > 8 hours ago without activity
- Token automatically invalidated

Solution:
1. Click "Login" button
2. Re-enter credentials
3. Session reset ✅

Prevention:
- Logout at end of shift
- Don't leave device unattended (auto-logout in future)
```

### Issue 4: Branch Data Mismatch

```
Symptom: "I created a PO but it doesn't appear in my list"

Diagnosis:
1. Check which branch user is assigned:
   Admin: Users → user details
   - Expected: Branch 1
   
2. Check PO creation:
   Admin: Purchase Orders → Filter by branch
   - Is PO there?
   
3. Verify JWT:
   Browser DevTools → Application → Cookies
   - Cookie "posToken" exists?
   - Decode: contains branch_id=1?

Solution:
1. If PO not in system:
   User must redo (was not saved)
   
2. If PO in different branch:
   System error (report to admin)
   
3. If branch_id wrong in JWT:
   Clear cookies: DevTools → Application → Clear
   Logout & login again
```

### Issue 5: Admin Panel Not Loading

```
Symptom: Admin page shows blank or "404 Not Found"

Diagnosis:
1. Check URL: https://pos.yourdomain.com/admin/
   - Path must end with /admin/
   - https (not http)
   
2. Check server running:
   docker-compose ps
   - web: running?
   - db: running?
   
3. Check logs:
   docker-compose logs web
   - PHP errors?
   - 500 errors?

Solution:
1. Restart containers:
   docker-compose restart web
   
2. Check file permissions:
   sudo chown www-data:www-data /var/www/html -R
   
3. Review logs for errors:
   docker-compose logs db | tail -20
```

---

## User Account Template

When creating multiple users, print this form:

```
┌─────────────────────────────────────────────────┐
│   NEW USER ACCOUNT REQUEST FORM                 │
├─────────────────────────────────────────────────┤
│                                                 │
│ Employee Name: _______________________________  │
│                                                 │
│ Employee ID: _____________                     │
│                                                 │
│ Branch: ☐ North ☐ Central ☐ South ☐ East      │
│                                                 │
│ Role:   ☐ Cashier ☐ Manager ☐ Super Manager   │
│         ☐ Admin                                │
│                                                 │
│ Start Date: ______________                     │
│                                                 │
│ Email: _________________________________        │
│                                                 │
│ Supervisor: __________________________          │
│                                                 │
│ Special Notes:                                 │
│ _______________________________________________│
│                                                 │
│ Date Request Submitted: ______________         │
│                                                 │
│ Admin Signature: ________________               │
│                                                 │
│ Date Account Created: ______________           │
│                                                 │
└─────────────────────────────────────────────────┘
```

---

## Password Reset Procedures

### User Forgets Password

```
1. User calls admin: "I forgot my password"

2. Admin does:
   Admin panel → Users → Find user
   Click "Reset Password"
   
3. System generates temporary password:
   Temp: "aB9$kL2mN4vP" (auto-generated)
   
4. Admin sends via SMS or email:
   "Your password reset: aB9$kL2mN4vP
    Login at https://pos.yourdomain.com/admin/
    You MUST change password on first login"
    
5. User logs in with temp password:
   - Forced password change screen appears
   - Must create new secure password
   - Confirm new password
   
6. User now logged in with new password ✅
```

### Admin Resets Own Password (Lost)

```
Problem: Admin forgot password, nobody else can reset

Solution (requires server access):
1. SSH into server:
   ssh ubuntu@192.168.1.10
   
2. Access MySQL:
   docker exec -it scrap-pos-db mysql \
     -u pos_user -p$MYSQL_PASSWORD pos_system
   
3. Generate new password hash:
   (Get bcrypt hash from online generator or PHP CLI)
   bcrypt("newadminpass123") = $2y$10$...
   
4. Update database:
   UPDATE users SET password_hash='$2y$10$...' 
   WHERE username='admin';
   
5. Verify:
   SELECT username, password_hash FROM users 
   WHERE username='admin' LIMIT 1;
   
6. Logout all users (clear JWT tokens):
   UPDATE users SET last_logout=NOW();
   
7. Login with new password ✅
```

---

## Next: Data Security & Cleanup

See `DATA_SECURITY_GUIDE.md` for:
- Customer PII encryption
- Test data cleanup before production
- Backup security
- Compliance (PDPA, local regulations)

