# Deterministic role fixtures for authorization tests.
# Fixtures are created through the API so the suite does not require direct DB access.

AUTH_FIXTURE_PASSWORD="${AUTH_FIXTURE_PASSWORD:-AccessTest123!}"
AUTH_FIXTURE_ADMIN_USER="${AUTH_FIXTURE_ADMIN_USER:-admin}"
AUTH_FIXTURE_ADMIN_PASS="${AUTH_FIXTURE_ADMIN_PASS:-admin}"
AUTH_FIXTURE_COOKIE_DIR="${AUTH_FIXTURE_COOKIE_DIR:-/tmp/scrap_pos_auth_fixtures}"
AUTH_FIXTURE_BRANCH_A="${AUTH_FIXTURE_BRANCH_A:-1}"
AUTH_FIXTURE_BRANCH_B="${AUTH_FIXTURE_BRANCH_B:-2}"

mkdir -p "$AUTH_FIXTURE_COOKIE_DIR"

role_fixture_cookie_path() {
  local key="${1:-fixture}"
  printf '%s/%s.cookie' "$AUTH_FIXTURE_COOKIE_DIR" "$key"
}

role_fixture_login() {
  local username="${1:-}" password="${2:-}" cookie="${3:-}"
  local response
  [ -n "$username" ] && [ -n "$password" ] && [ -n "$cookie" ] || return 1
  response=$(curl -s -c "$cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$username\",\"password\":\"$password\"}")
  echo "$response" | grep -q '"status":"success"'
}

role_fixture_get() {
  local cookie="${1:-}" endpoint="${2:-}"
  curl -s -b "$cookie" "$API_BASE/$endpoint"
}

role_fixture_post() {
  local cookie="${1:-}" endpoint="${2:-}" data="${3:-}"
  curl -s -b "$cookie" "$API_BASE/$endpoint" \
    -X POST -H 'Content-Type: application/json' -d "$data"
}

role_fixture_put() {
  local cookie="${1:-}" endpoint="${2:-}" data="${3:-}"
  curl -s -b "$cookie" "$API_BASE/$endpoint" \
    -X PUT -H 'Content-Type: application/json' -d "$data"
}

role_fixture_delete() {
  local cookie="${1:-}" endpoint="${2:-}"
  curl -s -b "$cookie" "$API_BASE/$endpoint" \
    -X DELETE -H 'Content-Type: application/json'
}

role_fixture_require_branch() {
  local branch_id="${1:-}" response actual_id
  [ -n "${AUTH_FIXTURE_ADMIN_COOKIE:-}" ] && [ -n "$branch_id" ] || return 1
  response=$(role_fixture_get "$AUTH_FIXTURE_ADMIN_COOKIE" "branches")
  actual_id=$(echo "$response" | json_find "data" "id" "$branch_id" "id" 2>/dev/null)
  [ "$actual_id" = "$branch_id" ]
}

role_fixture_check_identity() {
  local response="${1:-}" expected_role="${2:-}" expected_branch="${3:-}"
  local actual_role actual_branch
  actual_role=$(echo "$response" | json_get "data.user.role" 2>/dev/null)
  actual_branch=$(echo "$response" | json_get "data.user.branch_id" 2>/dev/null)
  [ "$actual_role" = "$expected_role" ] || return 1
  if [ -n "$expected_branch" ]; then
    [ "$actual_branch" = "$expected_branch" ] || return 1
  else
    [ -z "$actual_branch" ] || [ "$actual_branch" = "null" ] || [ "$actual_branch" = "None" ] || [ "$actual_branch" = "0" ] || return 1
  fi
}

role_fixture_create_user() {
  local role="${1:-}" branch_id="${2:-}" username="${3:-}" full_name="${4:-}"
  local phone payload response
  phone="qa-auth-${username}"
  payload="{\"username\":\"$username\",\"password\":\"$AUTH_FIXTURE_PASSWORD\",\"phone\":\"$phone\",\"full_name\":\"$full_name\",\"role\":\"$role\",\"status\":\"active\""
  if [ -n "$branch_id" ]; then
    payload="$payload,\"branch_id\":$branch_id"
  fi
  payload="$payload}"
  response=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "users" "$payload")
  echo "$response" | grep -q '"status":"success"'
}

role_fixture_ensure_user() {
  local key="${1:-}" username="${2:-}" role="${3:-}" branch_id="${4:-}" full_name="${5:-}"
  local cookie response
  cookie=$(role_fixture_cookie_path "$key")

  if role_fixture_login "$username" "$AUTH_FIXTURE_PASSWORD" "$cookie"; then
    response=$(curl -s -c "$cookie" "$API_BASE/auth/login" \
      -X POST -H 'Content-Type: application/json' \
      -d "{\"username\":\"$username\",\"password\":\"$AUTH_FIXTURE_PASSWORD\"}")
    role_fixture_check_identity "$response" "$role" "$branch_id" || {
      echo "AUTH_FIXTURE_ROLE_MISMATCH:$username" >&2
      return 1
    }
    return 0
  fi

  [ -n "${AUTH_FIXTURE_ADMIN_COOKIE:-}" ] || return 1
  role_fixture_create_user "$role" "$branch_id" "$username" "$full_name" || return 1
  role_fixture_login "$username" "$AUTH_FIXTURE_PASSWORD" "$cookie" || return 1
  response=$(curl -s -b "$cookie" "$API_BASE/auth/verify" -X POST \
    -H 'Content-Type: application/json' -d '{}')
  role_fixture_check_identity "$response" "$role" "$branch_id"
}

role_fixture_prepare() {
  if [ "${AUTH_FIXTURES_READY:-0}" = "1" ]; then
    return 0
  fi

  local admin_cookie admin_response
  admin_cookie=$(role_fixture_cookie_path "fixture-admin")
  if role_fixture_login "$AUTH_FIXTURE_ADMIN_USER" "$AUTH_FIXTURE_ADMIN_PASS" "$admin_cookie"; then
    AUTH_FIXTURE_ADMIN_COOKIE="$admin_cookie"
  elif [ -n "${TEST_USER:-}" ] \
    && role_fixture_login "$TEST_USER" "${TEST_PASS:-}" "$admin_cookie"; then
    admin_response=$(curl -s -b "$admin_cookie" "$API_BASE/auth/verify" -X POST \
      -H 'Content-Type: application/json' -d '{}')
    if [ "$(echo "$admin_response" | json_get "data.user.role" 2>/dev/null)" = "admin" ]; then
      AUTH_FIXTURE_ADMIN_COOKIE="$admin_cookie"
    else
      rm -f "$admin_cookie"
    fi
  fi

  role_fixture_require_branch "$AUTH_FIXTURE_BRANCH_A" || {
    echo "AUTH_FIXTURE_BRANCH_MISSING:${AUTH_FIXTURE_BRANCH_A}" >&2
    return 1
  }
  role_fixture_require_branch "$AUTH_FIXTURE_BRANCH_B" || {
    echo "AUTH_FIXTURE_BRANCH_MISSING:${AUTH_FIXTURE_BRANCH_B}" >&2
    return 1
  }

  # Use fixed names so rerunning the suite reuses the same accounts.
  role_fixture_ensure_user "cashier-a" "qa-auth-cashier-a" "cashier" "$AUTH_FIXTURE_BRANCH_A" "QA Auth Cashier A" || return 1
  role_fixture_ensure_user "cashier-b" "qa-auth-cashier-b" "cashier" "$AUTH_FIXTURE_BRANCH_B" "QA Auth Cashier B" || return 1
  role_fixture_ensure_user "manager-a" "qa-auth-manager-a" "manager" "$AUTH_FIXTURE_BRANCH_A" "QA Auth Manager A" || return 1
  role_fixture_ensure_user "manager-b" "qa-auth-manager-b" "manager" "$AUTH_FIXTURE_BRANCH_B" "QA Auth Manager B" || return 1
  role_fixture_ensure_user "super-manager" "qa-auth-super-manager" "super_manager" "" "QA Auth Super Manager" || return 1

  AUTH_FIXTURE_CASHIER_A_COOKIE=$(role_fixture_cookie_path "cashier-a")
  AUTH_FIXTURE_CASHIER_B_COOKIE=$(role_fixture_cookie_path "cashier-b")
  AUTH_FIXTURE_MANAGER_A_COOKIE=$(role_fixture_cookie_path "manager-a")
  AUTH_FIXTURE_MANAGER_B_COOKIE=$(role_fixture_cookie_path "manager-b")
  AUTH_FIXTURE_SUPER_COOKIE=$(role_fixture_cookie_path "super-manager")
  AUTH_FIXTURES_READY=1
  export AUTH_FIXTURE_ADMIN_COOKIE AUTH_FIXTURE_CASHIER_A_COOKIE AUTH_FIXTURE_CASHIER_B_COOKIE
  export AUTH_FIXTURE_MANAGER_A_COOKIE AUTH_FIXTURE_MANAGER_B_COOKIE AUTH_FIXTURE_SUPER_COOKIE
  export AUTH_FIXTURE_BRANCH_A AUTH_FIXTURE_BRANCH_B
}
