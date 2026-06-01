# Auth helper — login and store token
API_BASE="${API_BASE:-http://localhost:8080/api/index.php}"
TEST_USER="${TEST_USER:-admin}"
TEST_PASS="${TEST_PASS:-admin}"
TOKEN_FILE="${TOKEN_FILE:-/tmp/test_token.txt}"

login() {
  local res
  res=$(curl -s "$API_BASE/auth/login" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$TEST_USER\",\"password\":\"$TEST_PASS\"}")

  local token
  token=$(echo "$res" | sed 's/.*"token":"\([^"]*\)".*/\1/')

  if [ -n "$token" ] && [ "$token" != "$res" ]; then
    echo "$token" > "$TOKEN_FILE"
    echo "$token"
  else
    echo "LOGIN_FAILED" >&2
    echo "Response: $res" >&2
    return 1
  fi
}

get_token() {
  if [ -f "$TOKEN_FILE" ]; then
    cat "$TOKEN_FILE"
  else
    login
  fi
}

api_get() {
  local endpoint="${1:-}"
  local token
  token=$(get_token) || return 1
  curl -s "$API_BASE/$endpoint" \
    -H "Authorization: Bearer $token"
}

api_post() {
  local endpoint="${1:-}" data="${2:-}"
  local token
  token=$(get_token) || return 1
  curl -s "$API_BASE/$endpoint" \
    -X POST \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token" \
    -d "$data"
}

api_post_id() {
  local endpoint="${1:-}" id="${2:-}" data="${3:-}"
  local token
  token=$(get_token) || return 1
  curl -s "$API_BASE/$endpoint?id=$id" \
    -X POST \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token" \
    -d "$data"
}

api_put() {
  local endpoint="${1:-}" data="${2:-}"
  local token
  token=$(get_token) || return 1
  curl -s "$API_BASE/$endpoint" \
    -X PUT \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token" \
    -d "$data"
}

api_delete() {
  local endpoint="${1:-}"
  local token
  token=$(get_token) || return 1
  curl -s "$API_BASE/$endpoint" \
    -X DELETE \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $token"
}
