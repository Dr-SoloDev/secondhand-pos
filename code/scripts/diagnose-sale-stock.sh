#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# Secondhand POS — Sale Stock Diagnostic (READ-ONLY)
#
# Usage:
#   ./scripts/diagnose-sale-stock.sh --branch 2
#   ./scripts/diagnose-sale-stock.sh --branch 2 --item "เคร่อคอมพิวเตอร์"
#   ./scripts/diagnose-sale-stock.sh --branch 2 --lot 123
#   ./scripts/diagnose-sale-stock.sh --branch 2 --hours 48 --output report.txt
#
# The script runs SELECT-only diagnostics against the running MySQL
# container and tails recent application logs. It never mutates data.
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CODE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPOSE_FILE="$CODE_DIR/docker-compose.yml"

BRANCH_ID=""
ITEM_NAME=""
LOT_ID=""
HOURS="${HOURS:-72}"
OUTPUT_FILE=""
LOG_LINES="${LOG_LINES:-200}"

usage() {
  cat <<'USAGE'
Usage: scripts/diagnose-sale-stock.sh --branch BRANCH_ID [options]

Options:
  --branch ID        Branch ID to inspect (required)
  --item NAME        Exact item name to inspect
  --lot ID           Inspect one Sale Lot ID in detail
  --hours N          Recent window for lots and audit logs (default: 72)
  --log-lines N      Recent web log lines to show (default: 200)
  --output FILE      Also write the full report to FILE
  -h, --help         Show this help

All database statements are SELECT/SHOW only.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --branch)
      [[ $# -ge 2 ]] || { echo "ERROR: --branch needs a value" >&2; exit 2; }
      BRANCH_ID="$2"; shift 2 ;;
    --item)
      [[ $# -ge 2 ]] || { echo "ERROR: --item needs a value" >&2; exit 2; }
      ITEM_NAME="$2"; shift 2 ;;
    --lot)
      [[ $# -ge 2 ]] || { echo "ERROR: --lot needs a value" >&2; exit 2; }
      LOT_ID="$2"; shift 2 ;;
    --hours)
      [[ $# -ge 2 ]] || { echo "ERROR: --hours needs a value" >&2; exit 2; }
      HOURS="$2"; shift 2 ;;
    --log-lines)
      [[ $# -ge 2 ]] || { echo "ERROR: --log-lines needs a value" >&2; exit 2; }
      LOG_LINES="$2"; shift 2 ;;
    --output)
      [[ $# -ge 2 ]] || { echo "ERROR: --output needs a value" >&2; exit 2; }
      OUTPUT_FILE="$2"; shift 2 ;;
    -h|--help)
      usage; exit 0 ;;
    *)
      echo "ERROR: unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
done

[[ "$BRANCH_ID" =~ ^[0-9]+$ ]] || { echo "ERROR: --branch must be a positive integer" >&2; usage >&2; exit 2; }
[[ "$HOURS" =~ ^[0-9]+$ ]] || { echo "ERROR: --hours must be an integer" >&2; exit 2; }
[[ "$LOG_LINES" =~ ^[0-9]+$ ]] || { echo "ERROR: --log-lines must be an integer" >&2; exit 2; }
if [[ -n "$LOT_ID" && ! "$LOT_ID" =~ ^[0-9]+$ ]]; then
  echo "ERROR: --lot must be a positive integer" >&2; exit 2
fi

command -v docker >/dev/null 2>&1 || { echo "ERROR: docker not found" >&2; exit 1; }
docker compose -f "$COMPOSE_FILE" ps db >/dev/null 2>&1 || {
  echo "ERROR: cannot query Docker Compose services" >&2; exit 1
}

db_running="$(docker compose -f "$COMPOSE_FILE" ps --status running --services db 2>/dev/null || true)"
[[ "$db_running" == "db" ]] || { echo "ERROR: db service is not running" >&2; exit 1; }

escape_sql() {
  local value="${1-}"
  value="${value//\\/\\\\}"
  value="${value//\'/\'\'}"
  printf '%s' "$value"
}

run_sql() {
  docker compose -f "$COMPOSE_FILE" exec -T db sh -lc \
    'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" --default-character-set=utf8mb4'
}

REPORT_TMP="$(mktemp)"
trap 'rm -f "$REPORT_TMP"' EXIT
if [[ -n "$OUTPUT_FILE" ]]; then
  touch "$OUTPUT_FILE"
  exec > >(tee -a "$REPORT_TMP") 2>&1
else
  exec > >(tee "$REPORT_TMP") 2>&1
fi

echo "=========================================="
echo " Secondhand POS — Sale Stock Diagnostic"
echo " Generated: $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo " Branch:    $BRANCH_ID"
[[ -n "$ITEM_NAME" ]] && echo " Item:      $ITEM_NAME"
[[ -n "$LOT_ID" ]] && echo " Lot ID:    $LOT_ID"
echo " Window:    last $HOURS hour(s)"
echo " Mode:      READ-ONLY"
echo "=========================================="

safe_item="$(escape_sql "$ITEM_NAME")"
item_filter="1=1"
[[ -n "$ITEM_NAME" ]] && item_filter="CONVERT(TRIM(bs.item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci=CONVERT('${safe_item}' USING utf8mb4) COLLATE utf8mb4_unicode_ci"

echo ""
echo "== 1. Container status =="
docker compose -f "$COMPOSE_FILE" ps

echo ""
echo "== 2. Branch stock vs sellable PO batches =="
echo "-- Positive branch_stock rows; sellable_po_stock comes from completed PO batches."
run_sql <<SQL
SELECT MAX(bs.id) AS branch_stock_id,
       MAX(bs.branch_id) AS branch_id,
       MAX(c.name) AS category_name,
       CONVERT(TRIM(bs.item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS item_name,
       SUM(bs.stock_kg) AS displayed_stock_kg,
       COALESCE((
         SELECT SUM(poi.net_quantity - poi.consumed_qty)
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.id = poi.purchase_order_id
         WHERE po.branch_id = MAX(bs.branch_id)
           AND poi.category_id = MAX(bs.category_id)
           AND CONVERT(TRIM(poi.item_name) USING utf8mb4) =
               CONVERT(TRIM(MAX(TRIM(bs.item_name))) USING utf8mb4)
           AND po.status = 'completed'
       ), 0) AS sellable_po_stock_kg,
       MAX(bs.last_updated) AS last_updated
FROM branch_stock bs
LEFT JOIN categories c ON c.id = bs.category_id
WHERE bs.branch_id = ${BRANCH_ID}
  AND ${item_filter}
GROUP BY CONVERT(TRIM(bs.item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci
ORDER BY displayed_stock_kg DESC, item_name ASC;
SQL

echo ""
echo "== 2b. Catalog vs branch-stock name comparison =="
echo "-- This exposes invisible/spelling mismatches that break exact-name sale deductions."
run_sql <<SQL
WITH candidates AS (
  SELECT bs.id AS branch_stock_id,
         bs.branch_id,
         bs.category_id,
         CONVERT(TRIM(bs.item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS stock_name,
         c.id AS catalog_id,
         CONVERT(TRIM(CONVERT(c.name USING utf8mb4)) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS catalog_name
  FROM branch_stock bs
  JOIN purchase_item_catalog c ON c.category_id = bs.category_id
  WHERE bs.branch_id = ${BRANCH_ID}
    AND bs.stock_kg > 0
)
SELECT branch_stock_id,
       branch_id,
       category_id,
       stock_name,
       catalog_id,
       catalog_name,
       CHAR_LENGTH(stock_name) AS stock_chars,
       CHAR_LENGTH(catalog_name) AS catalog_chars,
       ABS(CHAR_LENGTH(stock_name)-CHAR_LENGTH(catalog_name)) AS length_diff,
       CASE WHEN stock_name = catalog_name THEN 'EXACT_AFTER_TRIM' ELSE 'MISMATCH_REVIEW' END AS match_level,
       HEX(stock_name) AS stock_name_hex,
       HEX(REPLACE(stock_name,' ','')) AS normalized_stock_name_hex,
       HEX(REPLACE(catalog_name,' ','')) AS normalized_catalog_name_hex
FROM candidates
WHERE REPLACE(stock_name,' ','') <> REPLACE(catalog_name,' ','')
  AND ABS(CHAR_LENGTH(stock_name)-CHAR_LENGTH(catalog_name)) <= 2
  AND HEX(SUBSTRING(stock_name,1,3)) = HEX(SUBSTRING(catalog_name,1,3))
ORDER BY branch_stock_id, catalog_id, stock_name
LIMIT 200;
SQL

echo ""
echo "== 3. Aggregate mismatch summary =="
echo "-- diff > 0 means display stock is higher than sellable PO stock."
run_sql <<SQL
SELECT CONVERT(TRIM(bs.item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci AS item_name,
       MAX(bs.category_id) AS category_id,
       ROUND(SUM(bs.stock_kg), 3) AS displayed_stock_kg,
       COALESCE((
         SELECT SUM(poi.net_quantity - poi.consumed_qty)
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.id = poi.purchase_order_id
         WHERE po.branch_id = MAX(bs.branch_id)
           AND poi.category_id = MAX(bs.category_id)
           AND CONVERT(TRIM(poi.item_name) USING utf8mb4) =
               CONVERT(TRIM(MAX(TRIM(bs.item_name))) USING utf8mb4)
           AND po.status = 'completed'
       ), 0) AS sellable_po_stock_kg,
       ROUND(SUM(bs.stock_kg) - COALESCE((
         SELECT SUM(poi.net_quantity - poi.consumed_qty)
         FROM purchase_order_items poi
         JOIN purchase_orders po ON po.id = poi.purchase_order_id
         WHERE po.branch_id = MAX(bs.branch_id)
           AND poi.category_id = MAX(bs.category_id)
           AND CONVERT(TRIM(poi.item_name) USING utf8mb4) =
               CONVERT(TRIM(MAX(TRIM(bs.item_name))) USING utf8mb4)
           AND po.status = 'completed'
       ), 0), 3) AS diff_kg
FROM branch_stock bs
WHERE bs.branch_id = ${BRANCH_ID} AND bs.stock_kg > 0
GROUP BY CONVERT(TRIM(bs.item_name) USING utf8mb4) COLLATE utf8mb4_unicode_ci
HAVING ABS(diff_kg) > 0.001
ORDER BY ABS(diff_kg) DESC, item_name ASC;
SQL

echo ""
echo "== 4. Recent Sale Lots =="
run_sql <<SQL
SELECT sl.id,
       sl.reference_no,
       sl.status,
       sl.buyer_name,
       sl.total_amount,
       sl.total_cost,
       sl.actual_revenue,
       sl.created_at,
       sl.updated_at,
       COALESCE(SUM(sli.quantity_kg), 0) AS total_quantity_kg
FROM sale_lots sl
LEFT JOIN sale_lot_items sli ON sli.sale_lot_id = sl.id
WHERE sl.created_at >= DATE_SUB(NOW(), INTERVAL ${HOURS} HOUR)
$( [[ -n "$BRANCH_ID" ]] && printf '  AND sl.branch_id = %s\n' "$BRANCH_ID" )
GROUP BY sl.id
ORDER BY sl.created_at DESC
LIMIT 50;
SQL

echo ""
echo "== 5. Confirm-failure audit events =="
run_sql <<SQL
SELECT id,
       created_at,
       user_id,
       action,
       entity_type,
       entity_id,
       outcome,
       reason,
       before_json
FROM activity_log
WHERE action IN ('confirm_sale_lot_failed', 'cancel_sale_lot_failed')
  AND created_at >= DATE_SUB(NOW(), INTERVAL ${HOURS} HOUR)
$( [[ -n "$BRANCH_ID" ]] && printf '  AND entity_branch_id = %s\n' "$BRANCH_ID" )
ORDER BY created_at DESC
LIMIT 100;
SQL

if [[ -n "$LOT_ID" ]]; then
  echo ""
  echo "== 6. Sale Lot detail: $LOT_ID =="
  run_sql <<SQL
SELECT id, reference_no, branch_id, buyer_name, sale_date, status,
       total_amount, total_cost, actual_revenue, created_at, updated_at
FROM sale_lots
WHERE id = ${LOT_ID};

SELECT sli.id AS sale_lot_item_id,
       sli.catalog_id,
       CONVERT(sli.item_name USING utf8mb4) AS item_name,
       sli.category_id,
       c.name AS category_name,
       sli.quantity_kg,
       sli.unit_price,
       sli.fifo_cost
FROM sale_lot_items sli
LEFT JOIN categories c ON c.id = sli.category_id
WHERE sli.sale_lot_id = ${LOT_ID}
ORDER BY sli.id;

SELECT a.id AS allocation_id,
       a.sale_lot_item_id,
       a.purchase_order_item_id,
       a.quantity_kg,
       a.unit_cost,
       a.cost_method,
       a.restored_at,
       a.created_at,
       CONVERT(poi.item_name USING utf8mb4) AS poi_item_name,
       poi.net_quantity,
       poi.consumed_qty
FROM sale_lot_stock_allocations a
JOIN sale_lot_items sli ON sli.id = a.sale_lot_item_id
JOIN purchase_order_items poi ON poi.id = a.purchase_order_item_id
WHERE a.sale_lot_id = ${LOT_ID}
ORDER BY a.id;
SQL
else
  echo ""
  echo "== 6. Latest active allocations =="
  run_sql <<SQL
SELECT a.id AS allocation_id,
       a.sale_lot_id,
       a.sale_lot_item_id,
       a.purchase_order_item_id,
       CONVERT(poi.item_name USING utf8mb4) AS item_name,
       poi.category_id,
       a.quantity_kg,
       a.unit_cost,
       a.restored_at,
       a.created_at
FROM sale_lot_stock_allocations a
JOIN purchase_order_items poi ON poi.id = a.purchase_order_item_id
JOIN sale_lots sl ON sl.id = a.sale_lot_id
WHERE sl.branch_id = ${BRANCH_ID}
  AND a.created_at >= DATE_SUB(NOW(), INTERVAL ${HOURS} HOUR)
ORDER BY a.id DESC
LIMIT 100;
SQL
fi

echo ""
echo "== 7. Recent web log signals =="
docker logs scrap-pos-web --since "${HOURS}h" 2>&1 \
  | grep -Ei 'SaleLot|sale lot|confirm failed|stock|inventory|fatal|error' \
  | tail -n "$LOG_LINES" || true

echo ""
echo "== 8. Read-only verification =="
echo "This script executed SELECT/SHOW statements only."
echo "Report complete."

if [[ -n "$OUTPUT_FILE" ]]; then
  cp "$REPORT_TMP" "$OUTPUT_FILE"
  echo "Saved report: $OUTPUT_FILE"
fi
