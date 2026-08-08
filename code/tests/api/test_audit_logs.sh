# W8 structured audit trail, search, redaction and append-only checks.

test_audit_logs() {
  test_section "W8 Structured Audit Log"
  role_fixture_prepare || { test_fail "Audit fixtures are ready"; return; }

  local today ts prefix12 id_card create_res seller_id detail_res data_center_res
  local filter audit_res actor_role entity_id branch_id raw_after raw_before
  today=$(date +%Y-%m-%d)
  ts=$(date +%s)
  prefix12="$(printf '8%011d' $((ts % 100000000000)))"
  prefix12="${prefix12:0:12}"
  id_card=$(make_valid_id "$prefix12")

  local denied_res denied_export
  denied_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "audit-logs?limit=1")
  assert_contains "$denied_res" '"status":"error"' "AUTH-35: non-admin cannot read audit logs"
  denied_export=$(role_fixture_get "$AUTH_FIXTURE_MANAGER_A_COOKIE" "audit-logs/export")
  assert_contains "$denied_export" '"status":"error"' "Audit export is admin-only"

  create_res=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers" \
    "{\"full_name\":\"Audit Seller $ts\",\"id_card\":\"$id_card\",\"phone\":\"audit-$ts\",\"pdpa_consent\":true}")
  assert_contains "$create_res" '"status":"success"' "Audit fixture seller created by cashier"
  seller_id=$(echo "$create_res" | json_get 'data.id')

  detail_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/seller?id=$seller_id")
  assert_contains "$detail_res" '"status":"success"' "AUD-01: seller detail opened"
  data_center_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/data-center?id=$seller_id")
  assert_contains "$data_center_res" '"status":"success"' "Seller data center opened"

  local photo_file upload_res photo_status
  photo_file="/tmp/audit-seller-$seller_id.png"
  printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' | base64 -d > "$photo_file"
  upload_res=$(curl -s -b "$AUTH_FIXTURE_CASHIER_A_COOKIE" \
    -F "photo=@$photo_file;type=image/png" "$API_BASE/sellers/photo?id=$seller_id")
  assert_contains "$upload_res" '"status":"success"' "Seller ID-card photo uploaded"
  photo_status=$(curl -s -o /dev/null -w '%{http_code}' -b "$AUTH_FIXTURE_CASHIER_A_COOKIE" \
    "$API_BASE/sellers/photo-view?id=$seller_id")
  assert_eq "200" "$photo_status" "AUD-02: seller ID-card photo opened"

  filter="start_datetime=$today&end_datetime=$today&role=cashier&branch_id=$AUTH_FIXTURE_BRANCH_A&module=sellers&entity=$seller_id&limit=100"
  audit_res=$(api_get "audit-logs?$filter")
  assert_contains "$audit_res" '"status":"success"' "AUTH-36: admin combines date, role, branch and entity filters"
  actor_role=$(echo "$audit_res" | json_find 'data.logs' 'action' 'view_seller_detail' 'actor_role')
  entity_id=$(echo "$audit_res" | json_find 'data.logs' 'action' 'view_seller_detail' 'entity_id')
  branch_id=$(echo "$audit_res" | json_find 'data.logs' 'action' 'view_seller_detail' 'actor_branch_id')
  assert_eq "cashier" "$actor_role" "AUD-01: event stores actor role snapshot"
  assert_eq "$seller_id" "$entity_id" "AUD-01: event stores seller entity ID"
  assert_eq "$AUTH_FIXTURE_BRANCH_A" "$branch_id" "AUD-01: event stores actor branch snapshot"
  assert_eq "view_seller_data_center" \
    "$(echo "$audit_res" | json_find 'data.logs' 'action' 'view_seller_data_center' 'action')" \
    "Seller data-center view has its own event"
  assert_eq "view_seller_id_card_photo" \
    "$(echo "$audit_res" | json_find 'data.logs' 'action' 'view_seller_id_card_photo' 'action')" \
    "AUD-02: photo view has a separate event"

  raw_after=$(echo "$audit_res" | json_find 'data.logs' 'action' 'create_seller' 'after_json')
  raw_before=$(echo "$audit_res" | json_find 'data.logs' 'action' 'update_seller' 'before_json')
  assert_not_contains "$audit_res" "$id_card" "AUD-06: audit response never contains the full ID card"
  assert_contains "$raw_after" 'id_card' "AUD-06: structured after snapshot exists"
  assert_not_contains "$raw_after$raw_before" "$id_card" "AUD-06: before/after snapshots redact PII"

  local count_before count_after search_res
  count_before=$(echo "$audit_res" | json_get 'data.pagination.total')
  search_res=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/search?q=Audit%20Seller")
  assert_contains "$search_res" '"status":"success"' "Seller autocomplete succeeds"
  audit_res=$(api_get "audit-logs?$filter")
  count_after=$(echo "$audit_res" | json_get 'data.pagination.total')
  assert_eq "$count_before" "$count_after" "AUD-03: autocomplete does not create per-result audit events"

  local denied_audit
  denied_audit=$(api_get "audit-logs?role=cashier&outcome=denied&action=authorization_denied&module=audit-logs&start_datetime=$today&end_datetime=$today&limit=20")
  assert_eq "denied" \
    "$(echo "$denied_audit" | json_find 'data.logs' 'action' 'authorization_denied' 'outcome')" \
    "AUD-05: denied action records actor and outcome without secrets"

  local snapshot_user snapshot_pass snapshot_create snapshot_id snapshot_cookie snapshot_view snapshot_update snapshot_logs
  snapshot_user="qa-audit-snapshot-$ts"
  snapshot_pass='AuditSnapshot123!'
  snapshot_create=$(api_post "users" \
    "{\"username\":\"$snapshot_user\",\"password\":\"$snapshot_pass\",\"phone\":\"snapshot-$ts\",\"full_name\":\"Audit Snapshot\",\"role\":\"cashier\",\"branch_id\":$AUTH_FIXTURE_BRANCH_A,\"status\":\"active\"}")
  snapshot_id=$(echo "$snapshot_create" | json_get 'data.id')
  snapshot_cookie="/tmp/audit-snapshot-$ts.cookie"
  role_fixture_login "$snapshot_user" "$snapshot_pass" "$snapshot_cookie"
  snapshot_view=$(role_fixture_get "$snapshot_cookie" "sellers/seller?id=$seller_id")
  assert_contains "$snapshot_view" '"status":"success"' "Role-snapshot user creates an audit event"
  snapshot_update=$(api_put "users/user?id=$snapshot_id" \
    "{\"role\":\"manager\",\"branch_id\":$AUTH_FIXTURE_BRANCH_A}")
  assert_contains "$snapshot_update" '"status":"success"' "Role-snapshot user role changed after event"
  snapshot_logs=$(api_get "audit-logs?user_id=$snapshot_id&role=cashier&action=view_seller_detail&entity=$seller_id&limit=20")
  assert_eq "cashier" \
    "$(echo "$snapshot_logs" | json_find 'data.logs' 'action' 'view_seller_detail' 'actor_role')" \
    "AUD-04: old event keeps the old role snapshot"

  local immutable_before put_res delete_res immutable_after
  immutable_before=$(echo "$(api_get "audit-logs?$filter")" | json_get 'data.pagination.total')
  put_res=$(api_put "audit-logs?id=1" '{"description":"tampered"}')
  delete_res=$(api_delete "audit-logs?id=1")
  assert_contains "$put_res" '"status":"error"' "AUD-07: audit update route does not exist"
  assert_contains "$delete_res" '"status":"error"' "AUD-07: audit delete route does not exist"
  immutable_after=$(echo "$(api_get "audit-logs?$filter")" | json_get 'data.pagination.total')
  assert_eq "$immutable_before" "$immutable_after" "AUD-07: mutation attempts do not alter matching events"

  local page_one first_id second_page second_id
  page_one=$(api_get "audit-logs?$filter&page=1&limit=1")
  second_page=$(api_get "audit-logs?$filter&page=2&limit=1")
  first_id=$(echo "$page_one" | json_get 'data.logs.0.id')
  second_id=$(echo "$second_page" | json_get 'data.logs.0.id')
  if [ -n "$first_id" ] && [ -n "$second_id" ] && [ "$first_id" -gt "$second_id" ]; then
    test_pass "AUD-08: pagination uses stable newest-first created_at/id ordering"
  else
    test_fail "AUD-08: pagination uses stable newest-first created_at/id ordering"
  fi

  local export_res
  export_res=$(api_get "audit-logs/export?role=cashier&entity=$seller_id&start_datetime=$today&end_datetime=$today")
  assert_contains "$export_res" 'actor_role' "Admin exports server-filtered audit CSV"
}
