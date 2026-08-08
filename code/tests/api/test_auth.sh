# Auth API Tests
test_auth() {
  test_section "Authentication"

  # 1. Login with valid credentials (login helper stores cookie in COOKIE_JAR)
  local logres
  logres=$(curl -s -c "$COOKIE_JAR" "$API_BASE/auth/login" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$TEST_USER\",\"password\":\"$TEST_PASS\"}")
  assert_contains "$logres" '"status":"success"' "Login with valid credentials"

  # 2. Login with invalid password
  local res_fail
  res_fail=$(curl -s "$API_BASE/auth/login" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d '{"username":"admin","password":"wrong"}')
  assert_contains "$res_fail" '"status":"error"' "Login with invalid password fails"

  # Failed attempts for another account must not lock out valid admin login on the same IP.
  local scoped_user="missing-rate-limit-$$" scoped_attempt scoped_login
  for scoped_attempt in 1 2 3 4 5; do
    curl -s "$API_BASE/auth/login" \
      -X POST \
      -H 'Content-Type: application/json' \
      -d "{\"username\":\"$scoped_user\",\"password\":\"wrong\"}" > /dev/null
  done
  scoped_login=$(curl -s "$API_BASE/auth/login" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"username\":\"$TEST_USER\",\"password\":\"$TEST_PASS\"}")
  assert_contains "$scoped_login" '"status":"success"' "Login rate limit is scoped by username"

  # 3. Login with empty username
  local res_empty
  res_empty=$(curl -s "$API_BASE/auth/login" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d '{"username":"","password":"admin"}')
  assert_contains "$res_empty" '"status":"error"' "Login with empty username fails"

  # 4. Verify token via cookie (uses COOKIE_JAR from login)
  local res_verify
  res_verify=$(curl -s -b "$COOKIE_JAR" "$API_BASE/auth/verify" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d '{}')
  assert_contains "$res_verify" '"status":"success"' "Verify via cookie"

  # Password changes must invalidate cookies issued before auth_version changes.
  local auth_user="auth-version-$$" auth_password="AuthVersionOld123!"
  local auth_new_password="AuthVersionNew123!" auth_user_res auth_user_id auth_cookie auth_login auth_old_verify
  auth_user_res=$(api_post "users" "{\"username\":\"$auth_user\",\"password\":\"$auth_password\",\"phone\":\"$auth_user\",\"full_name\":\"Auth Version Test\",\"role\":\"admin\",\"status\":\"active\"}")
  auth_user_id=$(echo "$auth_user_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$auth_user_res" '"status":"success"' "JWT invalidation: Create test user"

  auth_cookie="/tmp/auth_version_$$.cookie"
  auth_login=$(curl -s -c "$auth_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$auth_user\",\"password\":\"$auth_password\"}")
  assert_contains "$auth_login" '"status":"success"' "JWT invalidation: Login before password change"

  local malformed_password password_change new_login
  malformed_password=$(api_post "users/change-password" "{\"user_id\":$auth_user_id,\"new_password\":[]}")
  assert_contains "$malformed_password" '"status":"error"' "Password change rejects non-string password"
  password_change=$(api_post "users/change-password" "{\"user_id\":$auth_user_id,\"new_password\":\"$auth_new_password\"}")
  assert_contains "$password_change" '"status":"success"' "JWT invalidation: Change password"

  auth_old_verify=$(curl -s -b "$auth_cookie" "$API_BASE/auth/verify" \
    -X POST -H 'Content-Type: application/json' -d '{}')
  assert_contains "$auth_old_verify" '"status":"error"' "JWT invalidation: Old cookie is rejected"
  new_login=$(curl -s "$API_BASE/auth/login" -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$auth_user\",\"password\":\"$auth_new_password\"}")
  assert_contains "$new_login" '"status":"success"' "JWT invalidation: New password can log in"

  # SEC-03: an already-issued cookie must use the current role and branch,
  # never the stale authorization claims embedded at login time.
  local policy_user policy_password policy_create policy_id policy_cookie policy_login
  local branch_a branch_b policy_update policy_permissions
  policy_user="auth-policy-$$"
  policy_password='AuthPolicy123!'
  branch_a=$(api_get "branches" | json_get "data.0.id" 2>/dev/null)
  branch_b=$(api_get "branches" | json_get "data.1.id" 2>/dev/null)
  policy_create=$(api_post "users" \
    "{\"username\":\"$policy_user\",\"password\":\"$policy_password\",\"phone\":\"$policy_user\",\"full_name\":\"Auth Policy Test\",\"role\":\"cashier\",\"branch_id\":$branch_a,\"status\":\"active\"}")
  policy_id=$(echo "$policy_create" | json_get "data.id" 2>/dev/null)
  assert_contains "$policy_create" '"status":"success"' "SEC-03: Create stale-policy test user"

  policy_cookie="/tmp/auth_policy_$$.cookie"
  policy_login=$(curl -s -c "$policy_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"username\":\"$policy_user\",\"password\":\"$policy_password\"}")
  assert_contains "$policy_login" '"status":"success"' "SEC-03: Login before role and branch change"

  policy_update=$(api_put "users/user?id=$policy_id" \
    "{\"role\":\"manager\",\"branch_id\":$branch_b}")
  assert_contains "$policy_update" '"status":"success"' "SEC-03: Admin changes role and branch"
  policy_permissions=$(curl -s -b "$policy_cookie" "$API_BASE/auth/permissions")
  assert_contains "$policy_permissions" '"role":"manager"' "SEC-03: Old cookie uses current role policy"
  assert_contains "$policy_permissions" "\"branch_id\":$branch_b" "SEC-03: Old cookie uses current branch scope"

  # 5. Verify with invalid token (no cookie)
  local res_bad
  res_bad=$(curl -s "$API_BASE/auth/verify" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d '{"token":"invalid-token"}')
  assert_contains "$res_bad" '"status":"error"' "Verify with invalid token fails"

  # 6. Access without auth cookie returns error
  local res_noauth
  res_noauth=$(curl -s "$API_BASE/branches")
  assert_contains "$res_noauth" '"status":"error"' "Access without auth rejected"
}
