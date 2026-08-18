# WF-06 cash-position v2: reserve, drawer, capital, close transfer, and reopen reversal.
test_cash_position() {
  test_section "Cash Position V2"

  local branch_transfer=3 branch_capital=4 res session_id deposit deposit_id
  local total drawer reserve

  res=$(api_post "cash-sessions/initialize-position" "{\"branch_id\":$branch_transfer,\"effective_date\":\"$(date +%Y-%m-%d)\",\"drawer_balance\":0,\"reserve_balance\":30000,\"note\":\"WF-06 sandbox baseline\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Initialize reserve baseline"

  res=$(api_post "cash-sessions/open" "{\"branch_id\":$branch_transfer,\"actual_cash\":20000}")
  session_id=$(echo "$res" | json_get "data.id" 2>/dev/null)
  assert_contains "$res" '"status":"success"' "Cash position: Open drawer with part of reserve"
  assert_contains "$res" '"capital_injection":0' "Cash position: Opening below reserve creates no new capital"

  res=$(api_get "cash-sessions/current?branch_id=$branch_transfer")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 30000 "$total" && float_eq 20000 "$drawer" && float_eq 10000 "$reserve"; then
    test_pass "Cash position: Internal opening transfer preserves total"
  else
    test_fail "Cash position: Internal opening transfer preserves total"
    echo "    (total=$total drawer=$drawer reserve=$reserve)"
  fi

  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch_transfer,\"amount\":5000,\"source_type\":\"reserve_transfer\",\"source_name\":\"เซฟสาขา\",\"reason\":\"เพิ่มเงินซื้อของระหว่างวัน\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  assert_contains "$deposit" '"status":"success"' "Cash position: Request reserve transfer"
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve reserve transfer"

  res=$(api_get "cash-sessions/current?branch_id=$branch_transfer")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 30000 "$total" && float_eq 25000 "$drawer" && float_eq 5000 "$reserve"; then
    test_pass "Cash position: Mid-day reserve transfer preserves total"
  else
    test_fail "Cash position: Mid-day reserve transfer preserves total"
  fi

  deposit=$(api_post "cash-sessions/deposit-request" "{\"branch_id\":$branch_transfer,\"amount\":7000,\"source_type\":\"owner_capital\",\"source_name\":\"Owner\",\"reason\":\"เติมเงินทุนใหม่\"}")
  deposit_id=$(echo "$deposit" | json_get "data.id" 2>/dev/null)
  res=$(api_post "cash-sessions/deposit-approve" "{\"id\":$deposit_id}")
  assert_contains "$res" '"status":"success"' "Cash position: Approve owner capital"
  res=$(api_get "cash-sessions/current?branch_id=$branch_transfer")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  if float_eq 37000 "$total" && float_eq 32000 "$drawer"; then
    test_pass "Cash position: Owner capital increases total by actual addition"
  else
    test_fail "Cash position: Owner capital increases total by actual addition"
  fi

  res=$(api_post "cash-sessions/close" "{\"branch_id\":$branch_transfer,\"actual_cash\":32000}")
  assert_contains "$res" '"status":"success"' "Cash position: Close exact drawer count"
  res=$(api_get "cash-sessions/current?branch_id=$branch_transfer")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 37000 "$total" && float_eq 0 "$drawer" && float_eq 37000 "$reserve"; then
    test_pass "Cash position: Close moves all drawer cash to reserve"
  else
    test_fail "Cash position: Close moves all drawer cash to reserve"
  fi

  res=$(api_post "cash-sessions/reopen" "{\"id\":$session_id,\"reason\":\"WF-06 reversal test\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Reopen reverses close transfer"
  res=$(api_get "cash-sessions/current?branch_id=$branch_transfer")
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 32000 "$drawer" && float_eq 5000 "$reserve"; then
    test_pass "Cash position: Reopen restores drawer and reserve exactly"
  else
    test_fail "Cash position: Reopen restores drawer and reserve exactly"
  fi

  res=$(api_post "cash-sessions/initialize-position" "{\"branch_id\":$branch_capital,\"effective_date\":\"$(date +%Y-%m-%d)\",\"drawer_balance\":0,\"reserve_balance\":30000,\"note\":\"WF-06 capital split baseline\"}")
  assert_contains "$res" '"status":"success"' "Cash position: Initialize second branch baseline"
  res=$(api_post "cash-sessions/open" "{\"branch_id\":$branch_capital,\"actual_cash\":40000}")
  assert_contains "$res" '"capital_injection":10000' "Cash position: Opening above reserve records only excess as capital"
  res=$(api_get "cash-sessions/current?branch_id=$branch_capital")
  total=$(echo "$res" | json_get "data.business_total_cash" 2>/dev/null)
  drawer=$(echo "$res" | json_get "data.drawer_balance" 2>/dev/null)
  reserve=$(echo "$res" | json_get "data.reserve_balance" 2>/dev/null)
  if float_eq 40000 "$total" && float_eq 40000 "$drawer" && float_eq 0 "$reserve"; then
    test_pass "Cash position: Capital split produces correct balances"
  else
    test_fail "Cash position: Capital split produces correct balances"
  fi

  # Regression: opening with cash already in the drawer must NOT double-transfer (WF-06 #18)
  local branch_drawer_start=1 res2 drawer2 reserve2 total2
  res2=$(api_post "cash-sessions/initialize-position" "{\"branch_id\":$branch_drawer_start,\"effective_date\":\"$(date +%Y-%m-%d)\",\"drawer_balance\":12000,\"reserve_balance\":50000,\"note\":\"WF-06 baseline with existing drawer cash\"}")
  assert_contains "$res2" '"status":"success"' "Cash position: Initialize baseline with existing drawer cash"
  res2=$(api_post "cash-sessions/open" "{\"branch_id\":$branch_drawer_start,\"actual_cash\":12000}")
  assert_contains "$res2" '"reserve_transfer":0' "Cash position: Opening with drawer cash does not transfer from reserve"
  assert_contains "$res2" '"capital_injection":0' "Cash position: Opening with drawer cash does not create capital"
  res2=$(api_get "cash-sessions/current?branch_id=$branch_drawer_start")
  drawer2=$(echo "$res2" | json_get "data.drawer_balance" 2>/dev/null)
  reserve2=$(echo "$res2" | json_get "data.reserve_balance" 2>/dev/null)
  total2=$(echo "$res2" | json_get "data.business_total_cash" 2>/dev/null)
  if float_eq 12000 "$drawer2" && float_eq 50000 "$reserve2" && float_eq 62000 "$total2"; then
    test_pass "Cash position: Opening with existing drawer cash preserves balances"
  else
    test_fail "Cash position: Opening with existing drawer cash preserves balances"
  fi
}
