# Deployment Checklist — scrap-pos
> อัพเดท: 6 ก.ค. 2569 | สถานะปัจจุบัน: **NO-GO** — 3 blockers ยังเปิดอยู่
> ดูแผนเต็ม: [`docs/OPS-DEPLOYMENT-PLAN.md`](OPS-DEPLOYMENT-PLAN.md)

## CRITICAL BLOCKERS (ต้องปิดก่อน deploy)

- [ ] **B1** — รัน `47-046_enforce_precious_receipt_all_risk_categories.sql` → activate G3 ทุกหมวด
- [ ] **B2** — เปลี่ยน admin/admin password (ดู Section "First Deploy" ด้านล่าง)
- [ ] **B3** — ตั้งค่า HTTPS (ดู `docs/HTTPS-SETUP.md`)

---

## Pre-Deploy

- [ ] Set all required environment variables in `.env` (see `.env.example`)
- [ ] Confirm `APP_ENV=production` is set
- [ ] Confirm `JWT_SECRET` is a strong random value (not default)
- [ ] Confirm DB credentials are non-default

## First Deploy (Post-Database Seed)

1. **Change admin password immediately after first login**
   - Login with: `admin` / `admin`
   - Navigate to Settings > Change Password (or Users admin panel)
   - Set a strong, unique password (minimum 12 chars, mixed case, numbers, symbols)
   - Do NOT leave the default `admin` password active in any environment

2. **Verify no other seeded users have default passwords**
   - Check all users created by seed files in `code/docker/entrypoint/`

## Security Gaps — Manual Steps Until Fixed in Code

### GAP-1: No force-password-change on first login
- **Status**: NOT IMPLEMENTED
- **Risk**: If admin forgets to change the default password, credentials remain `admin/admin`
- **Current mitigation**: This checklist — manually enforced
- **Recommended fix**: Add a `force_password_change` boolean column to the `users` table.
  On login, if `force_password_change = TRUE`, redirect to a mandatory password change page
  before allowing access. Set this flag to `TRUE` for all seed/default users.

### GAP-2: Hardcoded seed credentials in SQL
- **File**: `code/docker/entrypoint/00-base-schema.sql` line 157
- **Status**: Hash of `admin` is seeded by default
- **Recommended fix**: Use an environment variable or a post-seed script to set the initial
  admin password from a secret rather than a static hash.

## Ongoing

- [ ] Rotate JWT_SECRET periodically
- [ ] Review login_attempts table for suspicious activity
- [ ] Confirm `httpOnly` cookies are set (already implemented in AuthController)
