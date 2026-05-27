# Production Readiness Plan — Secondhand POS

**Generated:** 2026-05-27 | **Agents:** Security Engineer, Software Architect, Database Optimizer, Code Reviewer
**Total Issues Found:** ~74 (4 CRITICAL, 15 HIGH, 12 MEDIUM, 8 BUGS)

---

## Phase 1: Backend Hardening (Week 1)

### Day 1 — Critical Security Fixes
| # | Agent | Task | File |
|---|-------|------|------|
| 1 | `@security-engineer` | 🔴 Fix JWT secret → environment variable | `config.php` |
| 2 | `@security-engineer` | 🔴 Fix backup restore SQL injection → PDO | `BackupService.php` |
| 3 | `@security-engineer` | 🔴 Fix backup creation shell injection | `BackupService.php` |
| 4 | `@security-engineer` | 🔴 Fix `Response::file()` path traversal | `Response.php` |

### Day 2-3 — Database + Model Fixes
| # | Agent | Task | File |
|---|-------|------|------|
| 5 | `@database-optimizer` | 🔴 Fix `ON DELETE CASCADE` on inventory_transactions | migration 011 |
| 6 | `@database-optimizer` | 🔴 Add missing FK indexes (7 indexes) | migration 011 |
| 7 | `@database-optimizer` | 🔴 Add FIFO composite index `(branch_id, status, created_at)` | migration 011 |
| 8 | `@code-reviewer` | 🔴 Fix `deductStock()` silent underflow (BUG-01) | `SaleLot.php` |
| 9 | `@code-reviewer` | 🔴 Fix PO reference race condition (BUG-02, use MAX+1) | `PurchaseOrder.php` |
| 10 | `@code-reviewer` | 🔴 Fix `htmlspecialchars` data corruption (BUG-03) | `Controller.php` |
| 11 | `@code-reviewer` | 🔴 Fix `restoreStock()` missing null check (BUG-04) | `SaleLot.php` |
| 12 | `@database-optimizer` | Fix correlated subqueries in Branch/Sale queries | `Branch.php`, `Sale.php` |
| 13 | `@database-optimizer` | Change STORED → VIRTUAL generated columns | migration 011 |

### Day 4-5 — Code Quality + Security Hardening
| # | Agent | Task | File |
|---|-------|------|------|
| 14 | `@security-engineer` | Add rate limiting to auth/login | `AuthController.php` |
| 15 | `@security-engineer` | Strengthen password policy (min 10 chars) | `UsersController.php` |
| 16 | `@security-engineer` | Fix CORS wildcard → specific origins | `index.php` |
| 17 | `@security-engineer` | Add security headers (HSTS, XFO, CSP) | Apache config |
| 18 | `@code-reviewer` | Extract shared `getFifoRows()` helper | `SaleLot.php` |
| 19 | `@code-reviewer` | Fix `Seller::create()` missing field validation | `Seller.php` |
| 20 | `@code-reviewer` | Fix TOCTOU race in Seller/Branch create() | `Seller.php`, `Branch.php` |

---

## Phase 2: Frontend Polish (Week 2)

### Day 6-7 — React Bug Fixes
| # | Agent | Task | File |
|---|-------|------|------|
| 21 | `@frontend-developer` | Fix "เพิ่มผู้ขาย" button — add onClick handler | `Sellers.jsx` |
| 22 | `@frontend-developer` | Add cancel button for confirmed Sale Lots | `SaleLots.jsx` |
| 23 | `@frontend-developer` | Fix React Query `onSuccess` deprecation | `SaleLots.jsx` |
| 24 | `@frontend-developer` | Fix double localStorage (sync API interceptor with Zustand) | `authStore.js`, `api.js` |
| 25 | `@frontend-developer` | Fix global error handling (silent .catch) | All pages |

### Day 8-10 — UI Component Extraction
| # | Agent | Task | File |
|---|-------|------|------|
| 26 | `@frontend-developer` | Extract shared `Table` component (sort/search/pagination) | New |
| 27 | `@frontend-developer` | Extract shared `Modal` component | New |
| 28 | `@frontend-developer` | Extract shared `ItemFormModal` from SaleLot/PO duplication | New |
| 29 | `@frontend-developer` | Extract `Button`, `Input`, `Badge`, `Select`, `Card` components | New |
| 30 | `@ui-designer` | Review UI consistency across all pages | All pages |

---

## Phase 3: QA & Testing (Week 3)

### Day 11-12 — Architecture & Deployment
| # | Agent | Task | File |
|---|-------|------|------|
| 31 | `@devops-automator` | Add migration tracking (`schema_migrations` table) | Docker |
| 32 | `@devops-automator` | Build React in Docker pipeline (multi-stage) | `Dockerfile.php` |
| 33 | `@devops-automator` | Move phpMyAdmin to dev profile | `docker-compose.yml` |
| 34 | `@devops-automator` | Add `.dockerignore` | Root |
| 35 | `@infrastructure-maintainer` | SSL setup + backup strategy | New |

### Day 13-15 — Testing
| # | Agent | Task | File |
|---|-------|------|------|
| 36 | `@api-tester` | Test all 15+ custom API endpoints | API |
| 37 | `@api-tester` | Test FIFO costing accuracy (buy → sell → profit calc) | `SaleLot.php` |
| 38 | `@api-tester` | Test auth flows (login, token refresh, role access) | `AuthController` |
| 39 | `@performance-benchmarker` | Load test with 10K transactions | System |
| 40 | `@reality-checker` | Production readiness gate review | All |

---

## Phase 4: Deploy & Deliver (Week 4)

| # | Agent | Task |
|---|-------|------|
| 41 | `@technical-writer` | API documentation (Swagger/OpenAPI) |
| 42 | `@technical-writer` | User manual (Thai) + training guide |
| 43 | `@executive-summary-generator` | Client handover report |
| 44 | `@studio-producer` | Project completion summary |

---

## Quick Wins (สั่งได้เลยตอนนี้)

```bash
# Fix JWT secret → env var
@security-engineer Fix the JWT secret in config.php to use environment variable only, no fallback to hardcoded default

# Fix backup security
@security-engineer Replace shell_exec mysql/mysqldump with PDO-based backup in BackupService.php

# Fix database cascade
@database-optimizer Create migration 011 to fix ON DELETE CASCADE on inventory_transactions and add all missing indexes

# Fix PO reference race condition
@code-reviewer Fix generateReferenceNo in PurchaseOrder.php to use MAX+1 instead of COUNT+1

# Fix deductStock underflow
@code-reviewer Add remaining stock check in SaleLot::deductStock before confirming sale
```
