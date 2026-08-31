# Simple daily drawer model: open=insert amount, deposits add to drawer,
# close=variance check only, no auto-transfer between days.
test_cash_position() {
  test_section "Cash Position — Simple Daily Drawer"

  local branch=2 res session_id deposit deposit_id
  local drawer expected status

  # Purge any existing session for this branch today (needed after cash_sessions test leaves session open)
  local db_host=${DB_HOST:-localhost}
  local db_user=${DB_USER:-root}
  local db_pass=${DB_PASS:-rootpass}
  docker exec scrap-pos-db mysql -h 127.0.0.1 -u "$db_user" -p"$db_pass" pos_system -e "
    SET FOREIGN_KEY_CHECKS=0;
    DELETE FROM cash_deposit_requests WHERE cash_session_id IN (SELECT id FROM cash_sessions WHERE branch_id=$branch AND business_date=CURDATE());
    DELETE FROM cash_movements WHERE cash_session_id IN (SELECT id FROM cash_sessions WHERE branch_id=$branch AND business_date=CURDATE());
    DELETE FROM cash_sessions WHERE branch_id=$branch AND business_date=CURDATE();
    SET FOREIGN_KEY_CHECKS=1;
  " 2>/dev/null
  # Re-init baseline for this branch
  curl -s -b "$COOKIE_JAR" "$API_BASE/cash-sessions/init-baseline" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch}" >/dev/null 2>&1

  # ── Open: insert amount directly = drawer balance ──
  res=$(api_post "cash-sessions/open" "{\"branch_id\":$branch,\"actual_cash\":20000}")
  session_id=$(echo "$res" | json_get "data.id" 2>/dev/null)
  assert_contains "$res" '"status":"success"' "Cash position: Open session"
  assert_not_contains "$res" '"reserve_transfer"' "Cash position: Open has no reserve_transfer"
  assert_not_contains "$res" '"capital_injection"' "Cash position: Open has no capital_injection"

  drawer=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.drawer_balance" 2>/dev/null)
  expected=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq 20000 "$drawer" && float_eq 20000 "$expected"; then
    test_pass "Cash position: Open sets drawer to entered amount"
  else
    test_fail "Cash position: Open sets drawer to entered amount"
    echo "    (drawer=$drawer expected=$expected)"
  fi

  # ── Deposit: adds to drawer ──
  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch,\"amount\":15000,\"source_type\":\"owner_capital\",\"source_name\":\"Owner\",\"reason\":\"เติมเงิน\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  assert_contains "$deposit" '"status":"success"' "Cash position: Request deposit"
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve deposit"

  drawer=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.drawer_balance" 2>/dev/null)
  expected=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq 35000 "$drawer" && float_eq 35000 "$expected"; then
    test_pass "Cash position: Deposit increases drawer"
  else
    test_fail "Cash position: Deposit increases drawer"
    echo "    (drawer=$drawer expected=$expected)"
  fi

  # ── Cash PO: deducts from drawer ──
  local seller_id cat_id suffix item_name catalog_res catalog_id po
  seller_id=$(api_get "sellers" | json_get "data.0.id" 2>/dev/null); [ -z "$seller_id" ] && seller_id=1
  suffix="$(date +%s)"
  item_name="QA CashPos $suffix"
  catalog_res=$(api_post "purchase-catalog" "{\"code\":\"QA-$suffix\",\"name\":\"$item_name\",\"category_id\":$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)}")
  catalog_id=$(echo "$catalog_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$catalog_res" '"status":"success"' "Cash position: Create QA catalog"
  cat_id=$(api_get "purchase-catalog/item?id=$catalog_id" | json_get "data.category_id" 2>/dev/null); [ -z "$cat_id" ] && cat_id=1

  po=$(api_post "purchase-orders" "{\"branch_id\":$branch,\"seller_id\":$seller_id,\"payment_method\":\"cash\",\"items\":[{\"catalog_id\":$catalog_id,\"item_name\":\"${item_name}B\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5000}]}")
  assert_contains "$po" '"status":"success"' "Cash position: Cash PO within drawer"

  drawer=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.drawer_balance" 2>/dev/null)
  expected=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq 25000 "$drawer" && float_eq 25000 "$expected"; then
    test_pass "Cash position: Cash PO deducts from drawer"
  else
    test_fail "Cash position: Cash PO deducts from drawer"
    echo "    (drawer=$drawer expected=$expected)"
  fi

  # ── Close: exact count → no variance, no transfer ──
  res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch,\"actual_cash\":25000}")
  assert_contains "$res" '"status":"success"' "Cash position: Close exact count"

  drawer=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.drawer_balance" 2>/dev/null)
  expected=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq 25000 "$drawer" && float_eq 25000 "$expected"; then
    test_pass "Cash position: Close preserves drawer (no auto-transfer)"
  else
    test_fail "Cash position: Close preserves drawer (no auto-transfer)"
    echo "    (drawer=$drawer expected=$expected)"
  fi

  # ── Second open same day must be rejected (open once per day; top-up instead) ──
  res=$(api_post "cash-sessions/open" "{\"branch_id\":$branch,\"actual_cash\":40000,\"reason\":\"Re-open fresh\"}")
  assert_contains "$res" '"status":"error"' "Cash position: Second open same day is rejected"
  assert_contains "$res" 'เปิดยอดประจำวันนี้ไปแล้ว' "Cash position: Rejection tells cashier to top up"

  # ── Admin reopen continues the same round (movements preserved, nothing wiped) ──
  res=$(api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"QA reopen same day\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Admin reopens closed session"

  drawer=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.drawer_balance" 2>/dev/null)
  expected=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.current_expected_cash" 2>/dev/null)
  if float_eq 25000 "$drawer" && float_eq 25000 "$expected"; then
    test_pass "Cash position: Reopen keeps ledger (open 20000 + deposit 15000 - PO 10000)"
  else
    test_fail "Cash position: Reopen keeps ledger (open 20000 + deposit 15000 - PO 10000)"
    echo "    (drawer=$drawer expected=$expected)"
  fi

  # ── Close with variance → needs reason ──
  res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch,\"actual_cash\":24950}")
  assert_contains "$res" '"status":"error"' "Cash position: Close with variance requires reason"

  res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch,\"actual_cash\":24950,\"reason\":\"นับขาด\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Close with variance + reason"

  status=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.status" 2>/dev/null)
  if [ "$status" = "closed" ]; then
    test_pass "Cash position: Close with small variance closes directly"
  else
    test_fail "Cash position: Close with small variance closes directly"
    echo "    (status=$status)"
  fi

  # ── Large variance → pending approval ──
  res=$(api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"QA large variance setup\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Admin reopens for large variance round"
  res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch,\"actual_cash\":23000,\"reason\":\"นับขาดเยอะ\"}")
  status=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.status" 2>/dev/null)
  if [ "$status" = "pending_close" ]; then
    test_pass "Cash position: Large variance requires approval"
  else
    test_fail "Cash position: Large variance requires approval"
    echo "    (status=$status)"
  fi

  # ── Admin approves pending close ──
  session_id=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.id" 2>/dev/null)
  res=$(api_post "cash-sessions/close-approve" "{\"id\":$session_id,\"review_note\":\"Approved\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Admin approves pending close"
  status=$(api_get "cash-sessions/current?branch_id=$branch" | json_get "data.status" 2>/dev/null)
  if [ "$status" = "closed" ]; then
    test_pass "Cash position: Approved close completes"
  else
    test_fail "Cash position: Approved close completes"
    echo "    (status=$status)"
  fi
}
