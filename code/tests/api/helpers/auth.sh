# Auth helper — login via httpOnly cookie (posToken)
API_BASE="${API_BASE:-http://localhost:8080/api/index.php}"
TEST_USER="${TEST_USER:-admin}"
TEST_PASS="${TEST_PASS:-password}"
COOKIE_JAR="${COOKIE_JAR:-/tmp/test_cookies.txt}"

login() {
  local res
  res=$(curl -s -c "$COOKIE_JAR" "$API_BASE/auth/login" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$TEST_USER\",\"password\":\"$TEST_PASS\"}")

  if echo "$res" | grep -q '"status":"success"'; then
    return 0
  else
    echo "LOGIN_FAILED" >&2
    echo "Response: $res" >&2
    return 1
  fi
}

api_get() {
  local endpoint="${1:-}"
  curl -s -b "$COOKIE_JAR" "$API_BASE/$endpoint"
}

api_post() {
  local endpoint="${1:-}" data="${2:-}"
  curl -s -b "$COOKIE_JAR" "$API_BASE/$endpoint" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "$data"
}

api_post_id() {
  local endpoint="${1:-}" id="${2:-}" data="${3:-}"
  curl -s -b "$COOKIE_JAR" "$API_BASE/$endpoint?id=$id" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "$data"
}

api_put() {
  local endpoint="${1:-}" data="${2:-}"
  curl -s -b "$COOKIE_JAR" "$API_BASE/$endpoint" \
    -X PUT \
    -H 'Content-Type: application/json' \
    -d "$data"
}

api_delete() {
  local endpoint="${1:-}"
  curl -s -b "$COOKIE_JAR" "$API_BASE/$endpoint" \
    -X DELETE \
    -H 'Content-Type: application/json'
}
