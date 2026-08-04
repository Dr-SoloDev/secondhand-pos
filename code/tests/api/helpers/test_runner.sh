# Test runner core
HELPER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HELPER_DIR/auth.sh"

PASS_COUNT=0
FAIL_COUNT=0

test_section() {
  local name="${1:-}"
  [ -z "$name" ] && return
  echo ""
  echo "=========================================="
  echo "  $name"
  echo "=========================================="
}

test_pass() {
  local label="${1:-}"
  echo "  PASS: $label"
  PASS_COUNT=$((PASS_COUNT + 1))
}

test_fail() {
  local label="${1:-}"
  echo "  FAIL: $label"
  FAIL_COUNT=$((FAIL_COUNT + 1))
}

assert_eq() {
  local expected="${1:-}" actual="${2:-}" label="${3:-}"
  if [ "$expected" = "$actual" ]; then
    test_pass "$label"
  else
    test_fail "$label"
    echo "    (expected: '$expected', got: '$actual')"
  fi
}

assert_neq() {
  local unexpected="${1:-}" actual="${2:-}" label="${3:-}"
  if [ "$unexpected" != "$actual" ]; then
    test_pass "$label"
  else
    test_fail "$label"
    echo "    (should not be: '$unexpected')"
  fi
}

assert_contains() {
  local haystack="${1:-}" needle="${2:-}" label="${3:-}"
  if echo "$haystack" | grep -q "$needle"; then
    test_pass "$label"
  else
    test_fail "$label"
    echo "    (expected to contain '$needle')"
  fi
}

assert_not_contains() {
  local haystack="${1:-}" needle="${2:-}" label="${3:-}"
  if echo "$haystack" | grep -q "$needle"; then
    test_fail "$label"
    echo "    (should NOT contain '$needle')"
  else
    test_pass "$label"
  fi
}

summary() {
  local total=$((PASS_COUNT + FAIL_COUNT))
  echo ""
  echo "=========================================="
  if [ "$FAIL_COUNT" -eq 0 ]; then
    echo "  All $PASS_COUNT tests passed!"
  else
    echo "  $PASS_COUNT/$total passed — FAILURES: $FAIL_COUNT"
  fi
  echo "=========================================="
  return "$FAIL_COUNT"
}

json_get() {
  local path="${1:-}"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import json,sys
path=[p for p in sys.argv[1].split(".") if p]
cur=json.load(sys.stdin)
for part in path:
    if isinstance(cur, list):
        cur = cur[int(part)]
    elif isinstance(cur, dict):
        cur = cur.get(part, "")
    else:
        cur = ""
        break
if isinstance(cur, (dict, list)):
    print(json.dumps(cur, ensure_ascii=False))
else:
    print(cur)' "$path"
    return
  fi

  if command -v php >/dev/null 2>&1; then
    php -r '$path = array_values(array_filter(explode(".", $argv[1]), fn($p) => $p !== "")); $cur = json_decode(stream_get_contents(STDIN), true); foreach ($path as $part) { if (is_array($cur) && array_is_list($cur)) { $cur = $cur[(int)$part] ?? ""; } elseif (is_array($cur)) { $cur = $cur[$part] ?? ""; } else { $cur = ""; break; } } if (is_array($cur)) { echo json_encode($cur, JSON_UNESCAPED_UNICODE); } else { echo $cur; }' "$path"
    return
  fi

  return 1
}

json_find() {
  local array_path="${1:-}" match_key="${2:-}" match_value="${3:-}" result_key="${4:-}"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import json,sys
array_path=[p for p in sys.argv[1].split(".") if p]
match_key, match_value, result_key = sys.argv[2], sys.argv[3], sys.argv[4]
cur=json.load(sys.stdin)
for part in array_path:
    if isinstance(cur, list):
        cur = cur[int(part)]
    elif isinstance(cur, dict):
        cur = cur.get(part, [])
    else:
        cur = []
        break
if isinstance(cur, list):
    for item in cur:
        if isinstance(item, dict) and str(item.get(match_key, "")) == match_value:
            cur = item.get(result_key, "")
            break
    else:
        cur = ""
if isinstance(cur, (dict, list)):
    print(json.dumps(cur, ensure_ascii=False))
else:
    print(cur)' "$array_path" "$match_key" "$match_value" "$result_key"
    return
  fi

  if command -v php >/dev/null 2>&1; then
    php -r '$arrayPath = array_values(array_filter(explode(".", $argv[1]), fn($p) => $p !== "")); $matchKey = $argv[2]; $matchValue = $argv[3]; $resultKey = $argv[4]; $cur = json_decode(stream_get_contents(STDIN), true); foreach ($arrayPath as $part) { if (is_array($cur) && array_is_list($cur)) { $cur = $cur[(int)$part] ?? []; } elseif (is_array($cur)) { $cur = $cur[$part] ?? []; } else { $cur = []; break; } } if (is_array($cur)) { foreach ($cur as $item) { if (is_array($item) && isset($item[$matchKey]) && (string)$item[$matchKey] === $matchValue) { $cur = $item[$resultKey] ?? ""; break; } } } if (is_array($cur)) { echo json_encode($cur, JSON_UNESCAPED_UNICODE); } else { echo $cur; }' "$array_path" "$match_key" "$match_value" "$result_key"
    return
  fi

  return 1
}

json_len() {
  local path="${1:-}"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import json,sys
path=[p for p in sys.argv[1].split(".") if p]
cur=json.load(sys.stdin)
for part in path:
    if isinstance(cur, list):
        cur = cur[int(part)]
    elif isinstance(cur, dict):
        cur = cur.get(part, [])
    else:
        cur = []
        break
print(len(cur) if isinstance(cur, list) else 0)' "$path"
    return
  fi

  if command -v php >/dev/null 2>&1; then
    php -r '$path = array_values(array_filter(explode(".", $argv[1]), fn($p) => $p !== "")); $cur = json_decode(stream_get_contents(STDIN), true); foreach ($path as $part) { if (is_array($cur) && array_is_list($cur)) { $cur = $cur[(int)$part] ?? []; } elseif (is_array($cur)) { $cur = $cur[$part] ?? []; } else { $cur = []; break; } } echo is_array($cur) && array_is_list($cur) ? count($cur) : 0;' "$path"
    return
  fi

  return 1
}

url_encode() {
  local value="${1:-}"
  if command -v python3 >/dev/null 2>&1; then
    python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$value"
    return
  fi

  if command -v php >/dev/null 2>&1; then
    php -r 'echo rawurlencode($argv[1]);' "$value"
    return
  fi

  return 1
}

float_add() {
  awk -v a="${1:-0}" -v b="${2:-0}" 'BEGIN { printf "%.3f", a + b }'
}

float_eq() {
  awk -v expected="${1:-0}" -v actual="${2:-0}" 'BEGIN { exit((expected - actual < 0.0001 && actual - expected < 0.0001) ? 0 : 1) }'
}

cash_reviewer_cookie() {
  local cookie="/tmp/cash_session_reviewer.cookie" username="api-cash-reviewer"
  local password="ApiCashReview123!" login_res
  login_res=$(curl -s -c "$cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$username\",\"password\":\"$password\"}")
  if ! echo "$login_res" | grep -q '"status":"success"'; then
    api_post "users" "{\"username\":\"$username\",\"password\":\"$password\",\"phone\":\"api-cash-reviewer\",\"full_name\":\"API Cash Reviewer\",\"role\":\"admin\",\"status\":\"active\"}" >/dev/null
    login_res=$(curl -s -c "$cookie" "$API_BASE/auth/login" \
      -X POST -H 'Content-Type: application/json' \
      -d "{\"username\":\"$username\",\"password\":\"$password\"}")
  fi
  echo "$login_res" | grep -q '"status":"success"' || return 1
  printf '%s' "$cookie"
}

ensure_cash_session_open() {
  local branch_id="${1:-}" retry_count="${2:-0}" current status session_id actual business_date expected close_res
  local reviewer_cookie review_res
  [ -z "$branch_id" ] && return 1
  [ "$retry_count" -gt 2 ] && return 1
  current=$(api_get "cash-sessions/current?branch_id=$branch_id")
  status=$(echo "$current" | json_get "data.status" 2>/dev/null)
  session_id=$(echo "$current" | json_get "data.id" 2>/dev/null)
  business_date=$(echo "$current" | json_get "data.business_date" 2>/dev/null)
  case "$status" in
    open)
      if [ -n "$business_date" ] && [ "$business_date" != "$(date +%Y-%m-%d)" ]; then
        expected=$(echo "$current" | json_get "data.current_expected_cash" 2>/dev/null)
        actual=$(awk -v n="${expected:-0}" 'BEGIN { printf "%.2f", n < 0 ? 0 : n }')
        close_res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch_id,\"actual_cash\":$actual,\"reason\":\"Close stale API test session\"}")
        echo "$close_res" | grep -q '"status":"success"' || return 1
        ensure_cash_session_open "$branch_id" "$((retry_count + 1))"
        return
      fi
      ensure_cash_test_float "$branch_id"
      return ;;
    closed)
      api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"API test setup\"}" >/dev/null
      ensure_cash_test_float "$branch_id"
      return ;;
    pending_open)
      reviewer_cookie=$(cash_reviewer_cookie) || return 1
      review_res=$(curl -s -b "$reviewer_cookie" "$API_BASE/cash-sessions/open-approve" \
        -X POST -H 'Content-Type: application/json' -d "{\"id\":$session_id,\"review_note\":\"API test stale-session recovery\"}")
      echo "$review_res" | grep -q '"status":"success"' || return 1
      ensure_cash_session_open "$branch_id" "$((retry_count + 1))"
      return ;;
    pending_close)
      reviewer_cookie=$(cash_reviewer_cookie) || return 1
      review_res=$(curl -s -b "$reviewer_cookie" "$API_BASE/cash-sessions/close-approve" \
        -X POST -H 'Content-Type: application/json' -d "{\"id\":$session_id,\"review_note\":\"API test stale-session recovery\"}")
      echo "$review_res" | grep -q '"status":"success"' || return 1
      ensure_cash_session_open "$branch_id" "$((retry_count + 1))"
      return ;;
  esac

  actual=$(api_get "cash-sessions?branch_id=$branch_id" | python3 -c "import sys,json; d=json.load(sys.stdin); rows=d.get('data',{}).get('items',[]); print(rows[0].get('closing_actual') or 0 if rows else 0)" 2>/dev/null)
  [ -z "$actual" ] && actual=0
  api_post "cash-sessions/open" "{\"branch_id\":$branch_id,\"actual_cash\":$actual,\"reason\":\"API test setup\"}" >/dev/null
  ensure_cash_test_float "$branch_id"
}

ensure_cash_test_float() {
  local branch_id="${1:-}" expected amount username cookie login_res request request_id
  expected=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  [ -z "$expected" ] && return 0
  if awk -v n="$expected" 'BEGIN { exit(n >= 10000 ? 0 : 1) }'; then
    return 0
  fi

  username=$(printf 'manager-br%02d' "$branch_id")
  cookie="/tmp/cash_float_${branch_id}_$$.cookie"
  login_res=$(curl -s -c "$cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$username\",\"password\":\"admin\"}")
  echo "$login_res" | grep -q '"status":"success"' || return 0
  amount=$(awk -v n="$expected" 'BEGIN { printf "%.2f", 100000 - n }')
  request=$(curl -s -b "$cookie" "$API_BASE/cash-sessions/deposit-request" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"amount\":$amount,\"source_name\":\"API test float\",\"reason\":\"Automated test setup\"}")
  request_id=$(echo "$request" | json_get "data.id" 2>/dev/null)
  [ -z "$request_id" ] && return 0
  api_post "cash-sessions/deposit-approve" "{\"id\":$request_id}" >/dev/null
}

ensure_all_cash_sessions_open() {
  local ids branch_id
  ids=$(api_get "branches" | python3 -c "import sys,json; d=json.load(sys.stdin); print(' '.join(str(row.get('id')) for row in d.get('data',[]) if row.get('id')))" 2>/dev/null)
  for branch_id in $ids; do
    ensure_cash_session_open "$branch_id" || return 1
  done
}
