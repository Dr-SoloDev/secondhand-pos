# Admin-only historical adjustment document tests
test_adjustments() {
  test_section "Adjustment Documents"

  local branch_id=2 manager_cookie manager_login res before after expected
  local seller_id cat_id suffix item_name po po_id po_ref lot lot_id doc_id yesterday

  ensure_cash_session_open "$branch_id"
  manager_cookie="/tmp/test_adjustment_manager_$$.cookie"
  manager_login=$(curl -s -c "$manager_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br02","password":"admin"}')
  assert_contains "$manager_login" '"status":"success"' "Adjustment: Branch manager login"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/adjustment-documents?branch_id=$branch_id")
  assert_contains "$res" '"status":"error"' "Adjustment: Non-admin cannot list documents"

  yesterday=$(date -d yesterday +%Y-%m-%d)
  before=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  res=$(api_post "adjustment-documents" "{\"adjustment_type\":\"historical_expense\",\"branch_id\":$branch_id,\"effective_date\":\"$yesterday\",\"category\":\"ค่าทดสอบย้อนหลัง\",\"amount\":25,\"payment_method\":\"cash\",\"beneficiary_name\":\"QA Historical Recipient\",\"reason\":\"Integration test historical expense\"}")
  doc_id=$(echo "$res" | json_get "data.id" 2>/dev/null)
  assert_contains "$res" '"status":"success"' "Adjustment: Admin posts historical cash expense"
  assert_neq "" "$doc_id" "Adjustment: Historical expense receives document ID"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n - 25 }')
  if float_eq "$expected" "$after"; then test_pass "Adjustment: Historical cash expense deducts current drawer"; else test_fail "Adjustment: Historical cash expense deducts current drawer"; fi

  seller_id=$(api_get "sellers" | json_get "data.0.id" 2>/dev/null)
  [ -z "$seller_id" ] && seller_id=1
  cat_id=$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1
  suffix="$(date +%s)_$$"
  item_name="QA Adjustment $suffix"
  po=$(api_post "purchase-orders" "{\"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5}]}")
  po_id=$(echo "$po" | json_get "data.id" 2>/dev/null)
  po_ref=$(echo "$po" | json_get "data.reference_no" 2>/dev/null)
  assert_contains "$po" '"status":"success"' "Adjustment: Create stock source PO"

  res=$(api_post "adjustment-documents" "{\"adjustment_type\":\"purchase_order_cancellation\",\"target_reference\":\"$po_ref\",\"reason\":\"Same-day bypass test\"}")
  assert_contains "$res" '"status":"error"' "Adjustment: Same-day PO cannot bypass cancellation approval"

  lot=$(api_post "sale-lots" "{\"branch_id\":$branch_id,\"buyer_name\":\"QA Adjustment Buyer\",\"sale_date\":\"$(date +%Y-%m-%d)\",\"items\":[{\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":0.2,\"unit_price\":30}]}")
  lot_id=$(echo "$lot" | json_get "data.id" 2>/dev/null)
  assert_contains "$lot" '"status":"success"' "Adjustment: Create Sale Lot for revenue correction"
  res=$(api_post_id "sale-lots/confirm" "$lot_id" '{}')
  assert_contains "$res" '"status":"success"' "Adjustment: Confirm Sale Lot"
  res=$(api_post_id "sale-lots/record-revenue" "$lot_id" '{"actual_revenue":40,"payment_method":"bank_transfer"}')
  assert_contains "$res" '"status":"success"' "Adjustment: Record original bank revenue"

  before=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  res=$(api_post "adjustment-documents" "{\"adjustment_type\":\"sale_lot_revenue_correction\",\"target_reference\":\"$lot_id\",\"effective_date\":\"$(date +%Y-%m-%d)\",\"amount\":55,\"payment_method\":\"cash\",\"reason\":\"Factory paid cash instead of transfer\",\"note\":\"QA corrected revenue\"}")
  assert_contains "$res" '"status":"success"' "Adjustment: Correct Sale Lot bank revenue to cash"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n + 55 }')
  if float_eq "$expected" "$after"; then test_pass "Adjustment: Revenue correction adds cash difference to drawer"; else test_fail "Adjustment: Revenue correction adds cash difference to drawer"; fi

  res=$(api_get "adjustment-documents?branch_id=$branch_id")
  assert_contains "$res" 'historical_expense' "Adjustment: History includes historical expense"
  assert_contains "$res" 'sale_lot_revenue_correction' "Adjustment: History includes Sale Lot correction"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/adjustment-documents" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"adjustment_type\":\"historical_expense\",\"branch_id\":$branch_id,\"effective_date\":\"$yesterday\",\"category\":\"Blocked\",\"amount\":1,\"payment_method\":\"cash\",\"beneficiary_name\":\"Blocked\",\"reason\":\"Blocked\"}")
  assert_contains "$res" '"status":"error"' "Adjustment: Non-admin cannot create documents"
}
