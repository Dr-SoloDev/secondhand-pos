# Daily branch cash control and ledger integration tests
test_cash_sessions() {
  test_section "Daily Cash Sessions"

  local branch_id=2 manager_cookie res seller_id cat_id suffix item_name
  local before after bank_po cash_po expense expense_id large_expense large_expense_id
  local bank_lot bank_lot_id cash_lot cash_lot_id expected session_id deposit deposit_id

  ensure_cash_session_open "$branch_id"
  manager_cookie="/tmp/test_cash_manager_$$.cookie"
  res=$(curl -s -c "$manager_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br02","password":"admin"}')
  assert_contains "$res" '"status":"success"' "Cash session: Branch manager login"

  seller_id=$(api_get "sellers" | json_get "data.0.id" 2>/dev/null)
  [ -z "$seller_id" ] && seller_id=1
  cat_id=$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1
  suffix="$(date +%s)_$$"
  item_name="QA Cash Ledger $suffix"

  before=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  assert_neq "" "$before" "Cash session: Current expected cash is available"

  deposit=$(curl -s -b "$manager_cookie" "$API_BASE/cash-sessions/deposit-request" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"amount\":20000,\"source_type\":\"owner_capital\",\"source_name\":\"QA Owner\",\"reason\":\"Cash float for integration test\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  assert_contains "$deposit" '"status":"success"' "Cash deposit: Cashier-side user submits top-up request"
  res=$(curl -s -b "$manager_cookie" "$API_BASE/cash-sessions/deposit-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"error"' "Cash deposit: Requester cannot approve own top-up"
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash deposit: Admin approves documented top-up"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n + 20000 }')
  if float_eq "$expected" "$after"; then test_pass "Cash deposit: Approved top-up adds to drawer"; else test_fail "Cash deposit: Approved top-up adds to drawer"; fi
  before=$after

  bank_po=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"$item_name Bank\",\"category_id\":$cat_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":7}]}")
  assert_contains "$bank_po" '"status":"success"' "Cash ledger: Create bank-transfer PO"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq "$before" "$after"; then test_pass "Cash ledger: Bank-transfer PO does not affect drawer"; else test_fail "Cash ledger: Bank-transfer PO does not affect drawer"; fi

  cash_po=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"cash\",\"items\":[{\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":10}]}")
  assert_contains "$cash_po" '"status":"success"' "Cash ledger: Create cash PO"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n - 20 }')
  if float_eq "$expected" "$after"; then test_pass "Cash ledger: Cash PO deducts drawer"; else test_fail "Cash ledger: Cash PO deducts drawer"; fi

  expense=$(api_post "financial/expenses" "{\"branch_id\":$branch_id,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"ค่าดำเนินการ\",\"amount\":50,\"payment_method\":\"cash\",\"beneficiary_name\":\"QA Recipient\",\"note\":\"Small expense approval\"}")
  expense_id=$(echo "$expense" | json_get "data.id" 2>/dev/null)
  assert_contains "$expense" '"status":"success"' "Expense: Submit cash expense request"
  before=$after
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq "$before" "$after"; then test_pass "Expense: Pending request does not affect drawer"; else test_fail "Expense: Pending request does not affect drawer"; fi

  res=$(curl -s -b "$manager_cookie" "$API_BASE/financial/expenses/approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$expense_id}")
  assert_contains "$res" '"status":"success"' "Expense: Manager approves amount up to 500"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n - 50 }')
  if float_eq "$expected" "$after"; then test_pass "Expense: Approved cash expense deducts drawer"; else test_fail "Expense: Approved cash expense deducts drawer"; fi

  large_expense=$(curl -s -b "$manager_cookie" "$API_BASE/financial/expenses" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"ค่าขนส่ง\",\"amount\":600,\"payment_method\":\"cash\",\"beneficiary_name\":\"QA Carrier\"}")
  large_expense_id=$(echo "$large_expense" | json_get "data.id" 2>/dev/null)
  assert_contains "$large_expense" '"status":"success"' "Expense: Manager submits amount above 500"
  res=$(curl -s -b "$manager_cookie" "$API_BASE/financial/expenses/approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$large_expense_id}")
  assert_contains "$res" '"status":"error"' "Expense: Requester cannot approve own request"
  res=$(api_post "financial/expenses/approve" "{\"id\":$large_expense_id}")
  assert_contains "$res" '"status":"success"' "Expense: Admin approves amount 501-5000"
  before=$after
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n - 600 }')
  if float_eq "$expected" "$after"; then test_pass "Expense: Approved large cash expense deducts drawer"; else test_fail "Expense: Approved large cash expense deducts drawer"; fi

  bank_lot=$(api_post "sale-lots" "{\"branch_id\":$branch_id,\"buyer_name\":\"QA Bank Buyer\",\"sale_date\":\"$(date +%Y-%m-%d)\",\"items\":[{\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":0.2,\"unit_price\":30}]}")
  bank_lot_id=$(echo "$bank_lot" | json_get "data.id" 2>/dev/null)
  res=$(api_post_id "sale-lots/confirm" "$bank_lot_id" "{}")
  assert_contains "$res" '"status":"success"' "Sale LOT cash: Confirm bank-payment lot"
  before=$after
  res=$(api_post_id "sale-lots/record-revenue" "$bank_lot_id" '{"actual_revenue":30,"payment_method":"bank_transfer"}')
  assert_contains "$res" '"status":"success"' "Sale LOT cash: Record bank-transfer revenue"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq "$before" "$after"; then test_pass "Sale LOT cash: Bank revenue does not affect drawer"; else test_fail "Sale LOT cash: Bank revenue does not affect drawer"; fi

  cash_lot=$(api_post "sale-lots" "{\"branch_id\":$branch_id,\"buyer_name\":\"QA Cash Buyer\",\"sale_date\":\"$(date +%Y-%m-%d)\",\"items\":[{\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":0.2,\"unit_price\":30}]}")
  cash_lot_id=$(echo "$cash_lot" | json_get "data.id" 2>/dev/null)
  res=$(api_post_id "sale-lots/confirm" "$cash_lot_id" "{}")
  assert_contains "$res" '"status":"success"' "Sale LOT cash: Confirm cash-payment lot"
  res=$(api_post_id "sale-lots/record-revenue" "$cash_lot_id" '{"actual_revenue":40,"payment_method":"cash"}')
  assert_contains "$res" '"status":"success"' "Sale LOT cash: Record cash revenue"
  after=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  expected=$(awk -v n="$before" 'BEGIN { printf "%.2f", n + 40 }')
  if float_eq "$expected" "$after"; then test_pass "Sale LOT cash: Cash revenue adds to drawer"; else test_fail "Sale LOT cash: Cash revenue adds to drawer"; fi
  res=$(api_post_id "sale-lots/record-revenue" "$cash_lot_id" '{"actual_revenue":40,"payment_method":"cash"}')
  assert_contains "$res" '"status":"error"' "Sale LOT cash: Duplicate revenue recording is rejected"

  expected=$after
  local approved_actual
  approved_actual=$(awk -v n="$expected" 'BEGIN { print n + 150 }')
  res=$(curl -s -b "$manager_cookie" "$API_BASE/cash-sessions/close" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"actual_cash\":$approved_actual,\"reason\":\"QA variance approval\"}")
  session_id=$(echo "$res" | json_get "data.id" 2>/dev/null)
  assert_contains "$res" '"status":"success"' "Cash close: Submit variance above 100"
  assert_contains "$res" 'pending_close' "Cash close: Large variance waits for approval"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"Blocked While Closing\",\"category_id\":$cat_id,\"quantity\":1,\"unit_price\":1}]}")
  assert_contains "$res" '"status":"success"' "Cash close: Bank transaction remains available while drawer approval is pending"

  res=$(api_post "cash-sessions/close-approve" "{\"id\":$session_id,\"review_note\":\"QA approved variance\"}")
  assert_contains "$res" '"status":"success"' "Cash close: Admin approves another user's variance"
  res=$(api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"Continue API tests\"}")
  assert_contains "$res" '"status":"success"' "Cash close: Admin reopens same-day session with reason"

  expected=$(api_get "cash-sessions/current?branch_id=$branch_id" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq "$approved_actual" "$expected"; then
    test_pass "Cash reopen: Expected cash rebases to approved closing actual"
  else
    test_fail "Cash reopen: Expected cash rebases to approved closing actual"
    echo "    (approved closing: '$approved_actual', reopened expected: '$expected')"
  fi
  res=$(curl -s -b "$manager_cookie" "$API_BASE/cash-sessions/close" \
    -X POST -H 'Content-Type: application/json' -d "{\"branch_id\":$branch_id,\"actual_cash\":$expected}")
  assert_contains "$res" '"status":"success"' "Cash close: Exact count closes immediately"
  assert_contains "$res" '"status":"closed"' "Cash close: Exact count does not require approval"
  res=$(api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"Leave branch open for remaining tests\"}")
  assert_contains "$res" '"status":"success"' "Cash close: Test cleanup reopens branch"
}
