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
