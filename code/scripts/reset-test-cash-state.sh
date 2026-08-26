#!/usr/bin/env bash
# reset-test-cash-state.sh — safely reset a branch's cash-session state left by API tests.
#
# Usage:  bash code/scripts/reset-test-cash-state.sh [branch_id] [--purge]
#
# Strategy (audit-trail safe):
#   1. Default path = API only: close any open/pending session via close → close-approve
#      (creates proper audit events; no data deleted).
#   2. --purge (optional, test artifacts ONLY): after closing, DELETE session rows whose
#      opening_reason marks them as API-test artifacts ('API test setup' / 'reset for tests').
#      Refuses to purge sessions without an artifact marker.
#
# NEVER targets production business data: real sessions have real opening reasons and
# are only ever closed via step 1, never deleted.
set -euo pipefail

BRANCH="${1:-3}"
MODE="${2:-close-only}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
API_BASE="${API_BASE:-http://localhost:8080/api/index.php}"

TOKEN=$(curl -s -c - -X POST "$API_BASE/auth/login" \
  -H "Content-Type: application/json" -d '{"username":"admin","password":"admin"}' \
  | grep posToken | awk '{print $NF}')
[ -n "$TOKEN" ] || { echo "ERROR: login failed"; exit 1; }
AUTH="Authorization: Bearer $TOKEN"

api() { curl -s -X POST "$API_BASE/$1" -H "$AUTH" -H "Content-Type: application/json" -d "$2"; }

echo "=== branch $BRANCH: current session ==="
CUR=$(curl -s "$API_BASE/cash-sessions/current?branch_id=$BRANCH" -H "$AUTH")
SID=$(echo "$CUR" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('data',{}).get('id') or '')" 2>/dev/null)
STATUS=$(echo "$CUR" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('data',{}).get('status') or '')" 2>/dev/null)
EXPECTED=$(echo "$CUR" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('data',{}).get('current_expected_cash') or 0)" 2>/dev/null)
echo "session=$SID status=$STATUS expected_cash=$EXPECTED"

if [ -n "$SID" ] && [ "$STATUS" != "closed" ]; then
  echo "--- close with exact expected cash ---"
  api "cash-sessions/close" "{\"branch_id\":$BRANCH,\"actual_cash\":$EXPECTED,\"reason\":\"test cleanup\"}" | head -c 200; echo
  # if close went to pending_close (needs review), approve it
  ST=$(curl -s "$API_BASE/cash-sessions/current?branch_id=$BRANCH" -H "$AUTH" \
    | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('status') or '')" 2>/dev/null)
  if [ "$ST" = "pending_close" ]; then
    NEWID=$(curl -s "$API_BASE/cash-sessions/current?branch_id=$BRANCH" -H "$AUTH" \
      | python3 -c "import sys,json;print(json.load(sys.stdin).get('data',{}).get('id') or '')" 2>/dev/null)
    echo "--- approve close (id=$NEWID) ---"
    api "cash-sessions/close-approve" "{\"id\":$NEWID,\"review_note\":\"reset-test-cash-state\"}" | head -c 200; echo
  fi
else
  echo "no active session — nothing to close"
fi

if [ "$MODE" = "--purge" ]; then
  echo "=== purge test-artifact sessions (opening_reason marker required) ==="
  DBPASS=$(grep '^MYSQL_ROOT_PASSWORD=' "$ROOT/code/.env" | cut -d= -f2)
  MARKERS="'API test setup','reset for tests','test cleanup'"
  IDS=$(docker exec scrap-pos-db mysql -uroot -p"$DBPASS" pos_system -N -e \
    "SELECT id FROM cash_sessions WHERE branch_id=$BRANCH AND opening_reason IN ($MARKERS);" 2>/dev/null)
  for sid in $IDS; do
    echo "purging artifact session $sid"
    docker exec scrap-pos-db mysql -uroot -p"$DBPASS" pos_system -e "
      DELETE FROM cash_deposit_requests WHERE cash_session_id=$sid;
      DELETE FROM cash_session_events WHERE cash_session_id=$sid;
      DELETE FROM cash_movements WHERE cash_session_id=$sid;
      DELETE FROM cash_sessions WHERE id=$sid AND opening_reason IN ($MARKERS);" 2>/dev/null
  done
  [ -z "$IDS" ] && echo "(no marked artifacts found)"
fi

echo "=== state after ==="
curl -s "$API_BASE/cash-sessions/current?branch_id=$BRANCH" -H "$AUTH" \
  | python3 -c "import sys,json;d=json.load(sys.stdin).get('data',{});print({k:d.get(k) for k in ('id','status','baseline_id','drawer_balance','reserve_balance')})" 2>/dev/null
