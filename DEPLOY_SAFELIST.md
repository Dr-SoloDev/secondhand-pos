# Deploy Safety Policy — scrap-pos

## Rule 1: Deploy only from clean `main`
- Working tree must be clean (`git status --short` empty).
- Local `main` must match `origin/main`.
- `deploy.sh` enforces this automatically.

## Rule 2: Commit risky files separately
Risky production areas that affect data/API/printing:
- `code/customizations/api/Controllers/*.php`
- `code/customizations/api/Models/*.php`
- `code/print-server/*.py`
- `code/customizations/database/migrations/*.sql`

The pre-commit hook blocks commits that touch more than one of these areas at once.
Bypass only with `git commit --no-verify` and after manual review.

## Rule 3: PWA assets must be committed together
If `code/base-pos/index.html` references PWA assets, commit all of these together:
- `code/base-pos/manifest.json`
- `code/base-pos/sw.js`
- `code/base-pos/browserconfig.xml`
- `code/base-pos/favicon.ico`
- `code/base-pos/assets/images/icons/*`

Never commit only `index.html` without the assets it references.

## Rule 4: Never deploy with untracked temp files
Delete or ignore temp files like `print_v2_tmp.py` before deploy.

## Rule 5: Who can bypass
- `deploy.sh` safety gate: only the person who can edit the script or run `git commit --no-verify`.
- Pre-commit hook: bypass with `git commit --no-verify`.

## Current known pending changes (do NOT deploy together)
| File | Status | Risk if deployed now |
|------|--------|---------------------|
| `index.html` + untracked PWA assets | partially committed | 404 for manifest/sw/icons |
| `EmployeesController.php` / `Employee.php` | uncommitted | salary/SSO bypasses approval |
| `SellersController.php` / `Seller.php` | uncommitted | hides transfer/import POs |
| `print_receipt.py` / `print_server_http.py` | uncommitted | receipts lose stub |

Last updated: 2026-09-16
