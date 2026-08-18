# WF-06 cash-position v2.1: rollover open, no close transfer, manual 2-way transfers,
# bank movements affect total only, drawer sufficiency enforced.
test_cash_position() {
  test_section "Cash Position V2.1"

  local branch=3 branch_regression=4 res session_id deposit deposit_id
  local total drawer reserve

  # ── Baseline + rollover open (branch 3: drawer=0, reserve=30000) ──
  res=$(api_post "cash-sessions/initialize-position" "{\"branch_id\":$branch,\"effective_date\":\"$(date +%Y-%m-%d)\",\"drawer_balance\":0,\"reserve_balance\":30000,\"note\":\"WF-06 v2.1 sandbox baseline\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Initialize reserve baseline"

  res=$(api_post "cash-sessions/open" "{\"branch_id\":$branch,\"actual_cash\":20000}")
  session_id=$(echo "$res" | json_get "data.id" 2>/dev/null)
  assert_contains "$res" '"status":"success"' "Cash position: Open session"
  assert_contains "$res" '"reserve_transfer":0' "Cash position: Rollover open creates no reserve transfer"
  assert_contains "$res" '"capital_injection":0' "Cash position: Rollover open creates no new capital"

  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 30000 "$total" && float_eq 0 "$drawer" && float_eq 30000 "$reserve"; then
    test_pass "Cash position: Rollover open preserves balances (drawer still empty)"
  else
    test_fail "Cash position: Rollover open preserves balances (drawer still empty)"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  # ── Fill drawer mid-day (owner puts cash into the drawer) ──
  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch,\"amount\":20000,\"source_type\":\"reserve_transfer\",\"source_name\":\"เซฟสาขา\",\"reason\":\"เติมเงินเข้าลิ้นชักก่อนค้าขาย\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  assert_contains "$deposit" '"status":"success"' "Cash position: Request reserve transfer into drawer"
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve reserve transfer"

  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 30000 "$total" && float_eq 20000 "$drawer" && float_eq 10000 "$reserve"; then
    test_pass "Cash position: Reserve transfer moves money into drawer, total unchanged"
  else
    test_fail "Cash position: Reserve transfer moves money into drawer, total unchanged"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  # ── Owner capital (external money enters the system) ──
  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch,\"amount\":7000,\"source_type\":\"owner_capital\",\"source_name\":\"Owner\",\"reason\":\"เติมเงินทุนใหม่\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve owner capital"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  if float_eq 37000 "$total" && float_eq 27000 "$drawer"; then
    test_pass "Cash position: Owner capital increases total by actual addition"
  else
    test_fail "Cash position: Owner capital increases total by actual addition"
    echo "    (total=$total drawer=$drawer)"
  fi

  # ── Drawer sufficiency: cash PO above drawer must be rejected (G1) ──
  local seller_id cat_id suffix item_name po
  seller_id=$(api_get "sellers" | json_get "data.0.id" 2>/dev/null); [ -z "$seller_id" ] && seller_id=1
  cat_id=$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null); [ -z "$cat_id" ] && cat_id=1
  suffix="$(date +%s)_$$"
  item_name="QA CashPos $suffix"
  po=$(api_post "purchase-orders" "{\"branch_id\":$branch,\"seller_id\":$seller_id,\"payment_method\":\"cash\",\"items\":[{\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":20000}]}")
  assert_contains "$po" '"status":"error"' "Cash position: Cash PO above drawer is rejected"
  assert_contains "$po" 'เงินสดในลิ้นชักไม่เพียงพอ' "Cash position: Rejection message tells cashier to top up drawer"

  # ── Cash PO within drawer: deducts drawer, total unchanged ──
  po=$(api_post "purchase-orders" "{\"branch_id\":$branch,\"seller_id\":$seller_id,\"payment_method\":\"cash\",\"items\":[{\"item_name\":\"${item_name}B\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5000}]}")
  assert_contains "$po" '"status":"success"' "Cash position: Cash PO within drawer succeeds"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  if float_eq 27000 "$total" && float_eq 17000 "$drawer"; then
    test_pass "Cash position: Cash PO deducts drawer and total (money left the system)"
  else
    test_fail "Cash position: Cash PO deducts drawer and total (money left the system)"
    echo "    (total=$total drawer=$drawer)"
  fi

  # ── Bank transfer PO: affects total only, drawer untouched ──
  po=$(api_post "purchase-orders" "{\"branch_id\":$branch,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"${item_name}C\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5000}]}")
  assert_contains "$po" '"status":"success"' "Cash position: Bank transfer PO succeeds"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  bank=$(echo "$res" | json_get "data.bank_balance" 2>/dev/null)
  if float_eq 17000 "$total" && float_eq 17000 "$drawer" && float_eq -10000 "$bank"; then
    test_pass "Cash position: Bank PO reduces total, drawer untouched"
  else
    test_fail "Cash position: Bank PO reduces total, drawer untouched"
    echo "    (total=$total drawer=$drawer bank=$bank)"
  fi

  # ── Bank revenue (Sale Lot): increases total only ──
  local lot lot_id
  lot=$(api_post "sale-lots" "{\"branch_id\":$branch,\"buyer_name\":\"QA CashPos Buyer\",\"sale_date\":\"$(date +%Y-%m-%d)\",\"items\":[{\"item_name\":\"${item_name}C\",\"category_id\":$cat_id,\"quantity_kg\":0.2,\"unit_price\":30000}]}")
  lot_id=$(echo "$lot" | json_get "data.id" 2>/dev/null)
  assert_contains "$lot" '"status":"success"' "Cash position: Create Sale Lot for bank revenue"
  res=$(api_post_id "sale-lots/confirm" "$lot_id" '{}')
  assert_contains "$res" '"status":"success"' "Cash position: Confirm Sale Lot"
  res=$(api_post_id "sale-lots/record-revenue" "$lot_id" '{"actual_revenue":6000,"payment_method":"bank_transfer"}')
  assert_contains "$res" '"status":"success"' "Cash position: Record bank revenue"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  bank=$(echo "$res" | json_get "data.bank_balance" 2>/dev/null)
  if float_eq 23000 "$total" && float_eq 17000 "$drawer" && float_eq -4000 "$bank"; then
    test_pass "Cash position: Bank revenue increases total, drawer untouched"
  else
    test_fail "Cash position: Bank revenue increases total, drawer untouched"
    echo "    (total=$total drawer=$drawer bank=$bank)"
  fi

  # ── Manual drawer cash-out: drawer → reserve (internal, total unchanged) ──
  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch,\"amount\":7000,\"source_type\":\"drawer_to_reserve\",\"source_name\":\"เก็บเข้าสำรอง\",\"reason\":\"เอาเงินเกินไปไว้เซฟ\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  assert_contains "$deposit" '"status":"success"' "Cash position: Request drawer to reserve"
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve drawer to reserve"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 23000 "$total" && float_eq 10000 "$drawer" && float_eq 17000 "$reserve"; then
    test_pass "Cash position: Drawer to reserve keeps total, moves drawer cash to safe"
  else
    test_fail "Cash position: Drawer to reserve keeps total, moves drawer cash to safe"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  # ── Manual drawer cash-out: drawer → owner (external, total decreases) ──
  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch,\"amount\":3000,\"source_type\":\"drawer_to_owner\",\"source_name\":\"Owner เบิก\",\"reason\":\"Owner เอาเงินออก\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  assert_contains "$deposit" '"status":"success"' "Cash position: Request drawer to owner"
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve drawer to owner"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 20000 "$total" && float_eq 7000 "$drawer" && float_eq 17000 "$reserve"; then
    test_pass "Cash position: Drawer to owner decreases total (money left the system)"
  else
    test_fail "Cash position: Drawer to owner decreases total (money left the system)"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  # ── Close: exact count, NO auto transfer (numbers stay) ──
  res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch,\"actual_cash\":7000}")
  assert_contains "$res" '"status":"success"' "Cash position: Close exact drawer count"
  assert_contains "$res" '"closing_transfer_amount":0' "Cash position: Close creates no transfer"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 20000 "$total" && float_eq 7000 "$drawer" && float_eq 17000 "$reserve"; then
    test_pass "Cash position: Close keeps drawer and reserve as-is"
  else
    test_fail "Cash position: Close keeps drawer and reserve as-is"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  # ── Reopen next day: rollover continues from ledger drawer, no reversal ──
  res=$(api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"WF-06 v2.1 rollover test\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Reopen starts next round"
  res=$(api_get "cash-sessions/current?branch_id=$branch")
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  if float_eq 7000 "$drawer" && float_eq 17000 "$reserve" && float_eq 20000 "$total"; then
    test_pass "Cash position: Rollover open carries ledger balances forward"
  else
    test_fail "Cash position: Rollover open carries ledger balances forward"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  # ── Rejected: cash-out above drawer ──
  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch,\"amount\":50000,\"source_type\":\"drawer_to_owner\",\"source_name\":\"Owner เบิก\",\"reason\":\"เกินลิ้นชัก\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"error"' "Cash position: Drawer cash-out above drawer is rejected"

  # ── Regression: opening with cash already in the drawer preserves balances ──
  local res2 drawer2 reserve2 total2
  res2=$(api_post "cash-sessions/initialize-position" "{\"branch_id\":$branch_regression,\"effective_date\":\"$(date +%Y-%m-%d)\",\"drawer_balance\":12000,\"reserve_balance\":50000,\"note\":\"WF-06 v2.1 baseline with existing drawer cash\"}")
  assert_contains "$res2" '"status":"success"' "Cash position: Initialize baseline with existing drawer cash"
  res2=$(api_post "cash-sessions/open" "{\"branch_id\":$branch_regression,\"actual_cash\":99999}")
  assert_contains "$res2" '"reserve_transfer":0' "Cash position: Rollover open ignores typed amount"
  assert_contains "$res2" '"capital_injection":0' "Cash position: Rollover open creates no capital"
  res2=$(api_get "cash-sessions/current?branch_id=$branch_regression")
  drawer2=$(echo "$res2" | json_get "data.drawer_balance" 2>/dev/null)
  reserve2=$(echo "$res2" | json_get "data.reserve_balance" 2>/dev/null)
  total2=$(echo "$res2" | json_get "data.business_total_cash" 2>/dev/null)
  if float_eq 12000 "$drawer2" && float_eq 50000 "$reserve2" && float_eq 62000 "$total2"; then
    test_pass "Cash position: Opening with existing drawer cash preserves balances"
  else
    test_fail "Cash position: Opening with existing drawer cash preserves balances"
    echo "    (drawer=$drawer2 reserve=$reserve2 total=$total2)"
  fi
}