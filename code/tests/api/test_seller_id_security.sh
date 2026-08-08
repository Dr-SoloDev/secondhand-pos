# W9 seller ID encryption and keyed exact-search regression.

test_seller_id_security() {
  test_section "W9 Seller ID Encryption"
  role_fixture_prepare || { test_fail "Seller security fixtures are ready"; return; }

  local ts prefix12 id_card phone create_res seller_id detail_res exact_res partial_res duplicate_res
  ts=$(date +%s)
  prefix12="$(printf '7%011d' $((ts % 100000000000)))"
  prefix12="${prefix12:0:12}"
  id_card=$(make_valid_id "$prefix12")
  phone="security-$ts"

  create_res=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers" \
    "{\"full_name\":\"Encrypted Seller $ts\",\"id_card\":\"$id_card\",\"phone\":\"$phone\"}")
  assert_contains "$create_res" '"status":"success"' "Encrypted seller is created"
  seller_id=$(echo "$create_res" | json_get 'data.id')

  detail_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/seller?id=$seller_id")
  assert_contains "$detail_res" "$id_card" "Authorized workflow decrypts seller ID"
  assert_not_contains "$detail_res" 'id_card_encrypted' "Ciphertext is never exposed by seller API"
  assert_not_contains "$detail_res" 'id_card_search_hash' "Search hash is never exposed by seller API"

  exact_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/search?q=$id_card")
  assert_contains "$exact_res" "\"id\":$seller_id" "Exact seller ID search resolves through keyed hash"
  assert_contains "$exact_res" "$id_card" "Exact search returns decrypted national_id to authorized workflow"

  partial_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/search?q=${id_card:0:8}")
  assert_not_contains "$partial_res" "\"id\":$seller_id" "Partial plaintext ID search is disabled"

  duplicate_res=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers" \
    "{\"full_name\":\"Duplicate Encrypted Seller\",\"id_card\":\"$id_card\",\"phone\":\"duplicate-$ts\"}")
  assert_contains "$duplicate_res" '"status":"error"' "Duplicate seller ID is rejected by unique search hash"

  local audit_res
  audit_res=$(api_get "audit-logs?module=sellers&entity=$seller_id&limit=100")
  assert_not_contains "$audit_res" "$id_card" "Encrypted seller ID remains redacted in audit snapshots"
  assert_not_contains "$audit_res" 'v1.' "Ciphertext remains redacted in audit snapshots"
}
