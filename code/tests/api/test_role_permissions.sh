# P0 Role Permission Tests (PRD v2 Access Control)
# Covers: P0-2 cashier catalog, P0-3 cashier seller tier, P0-4 cashier stock transfer,
#         P0-1 manager branch-scoped PO cancellation approval, P0-5 manager branch-scoped cash session approval
# Requires: admin (default login), manager-br01 (branch 1), manager-br02 (branch 2), cashier-br02 (created if missing)
test_role_permissions() {
  test_section "P0 Role Permissions"

  local res cashier_cookie manager1_cookie manager2_cookie seller_id cat_id
  local branch1=1 branch2=2 suffix
  ensure_all_cash_sessions_open

  seller_id=$(api_get "sellers" | json_get "data.0.id" 2>/dev/null)
  [ -z "$seller_id" ] && seller_id=1
  cat_id=$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1
  suffix="$(date +%s)_$$"

  # ---- Prep: cashier user (create only if missing) ----
  cashier_cookie="/tmp/test_cashier_$$.cookie"
  res=$(curl -s -c "$cashier_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"cashier-br02","password":"CashierTest1234"}')
  if ! echo "$res" | grep -q '"status":"success"'; then
    api_post "users" '{"username":"cashier-br02","password":"CashierTest1234","phone":"099-000-0002","full_name":"QA Cashier BR02","role":"cashier","branch_id":2}' >/dev/null
    res=$(curl -s -c "$cashier_cookie" "$API_BASE/auth/login" \
      -X POST -H 'Content-Type: application/json' \
      -d '{"username":"cashier-br02","password":"CashierTest1234"}')
  fi
  assert_contains "$res" '"status":"success"' "P0: Cashier login"

  manager1_cookie="/tmp/test_mgr1_$$.cookie"
  curl -s -c "$manager1_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br01","password":"admin"}' >/dev/null
  manager2_cookie="/tmp/test_mgr2_$$.cookie"
  curl -s -c "$manager2_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br02","password":"admin"}' >/dev/null

  # ===================== P0-2: cashier catalog =====================
  local cat_res cat_id2 cat_upd cat_del
  cat_res=$(curl -s -b "$cashier_cookie" "$API_BASE/purchase-catalog" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"code\":\"QA-CAT-$suffix\",\"name\":\"QA Catalog $suffix\",\"category_id\":$cat_id,\"default_unit\":\"kg\",\"default_price\":25}")
  cat_id2=$(echo "$cat_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$cat_res" '"status":"success"' "P0-2: Cashier creates catalog item"
  assert_neq "" "$cat_id2" "P0-2: Catalog item ID returned"

  cat_upd=$(curl -s -b "$cashier_cookie" "$API_BASE/purchase-catalog/item" \
    -X PUT -H 'Content-Type: application/json' \
    -d "{\"id\":$cat_id2,\"default_price\":30}")
  assert_contains "$cat_upd" '"status":"success"' "P0-2: Cashier updates catalog item"

  cat_del=$(curl -s -b "$cashier_cookie" "$API_BASE/purchase-catalog/item?id=$cat_id2" -X DELETE)
  assert_contains "$cat_del" '"status":"error"' "P0-2: Cashier cannot delete catalog item"
  api_delete "purchase-catalog/item?id=$cat_id2" >/dev/null  # admin cleanup

  # ===================== P0-3: cashier seller tier =====================
  local seller_res seller_id2 tier_res
  seller_res=$(curl -s -b "$cashier_cookie" "$API_BASE/sellers" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"full_name\":\"QA Tier Seller $suffix\",\"phone\":\"099-000-00$suffix\",\"tier_level\":2}")
  seller_id2=$(echo "$seller_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$seller_res" '"status":"success"' "P0-3: Cashier creates seller with tier"
  assert_neq "" "$seller_id2" "P0-3: Seller ID returned"

  tier_res=$(curl -s -b "$cashier_cookie" "$API_BASE/sellers/seller" \
    -X PUT -H 'Content-Type: application/json' \
    -d "{\"id\":$seller_id2,\"tier_level\":3}")
  assert_contains "$tier_res" '"status":"success"' "P0-3: Cashier updates seller tier"

  # ===================== P0-4: cashier stock transfer =====================
  local cashier_po transfer_res transfer_id confirm_deny in_transfer in_transfer_id confirm_ok
  cashier_po=$(curl -s -b "$cashier_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch2,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"QA RoleTransfer $suffix\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":10}]}")
  assert_contains "$cashier_po" '"status":"success"' "P0-4: Cashier creates own-branch PO"

  transfer_res=$(curl -s -b "$cashier_cookie" "$API_BASE/stock-transfers" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"from_branch_id\":$branch2,\"to_branch_id\":$branch1,\"items\":[{\"category_id\":$cat_id,\"item_name\":\"QA RoleTransfer $suffix\",\"weight_kg\":1}],\"note\":\"P0 role test\"}")
  transfer_id=$(echo "$transfer_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$transfer_res" '"status":"success"' "P0-4: Cashier creates stock transfer from own branch"
  assert_neq "" "$transfer_id" "P0-4: Transfer ID returned"

  confirm_deny=$(curl -s -b "$cashier_cookie" "$API_BASE/stock-transfers/confirm" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":$transfer_id}")
  assert_contains "$confirm_deny" '"status":"error"' "P0-4: Cashier cannot confirm transfer to other branch"

  # admin creates 1→2 transfer, cashier confirms (destination = own branch)
  api_post "purchase-orders" "{\"branch_id\":$branch1,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"QA RoleInbound $suffix\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":10}]}" >/dev/null
  in_transfer=$(api_post "stock-transfers" "{\"from_branch_id\":$branch1,\"to_branch_id\":$branch2,\"items\":[{\"category_id\":$cat_id,\"item_name\":\"QA RoleInbound $suffix\",\"weight_kg\":1}],\"note\":\"P0 role inbound\"}")
  in_transfer_id=$(echo "$in_transfer" | json_get "data.id" 2>/dev/null)
  confirm_ok=$(curl -s -b "$cashier_cookie" "$API_BASE/stock-transfers/confirm" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":$in_transfer_id}")
  assert_contains "$confirm_ok" '"status":"success"' "P0-4: Cashier confirms incoming transfer to own branch"

  # cleanup: cancel pending transfer 2→1
  api_post "stock-transfers/cancel" "{\"id\":$transfer_id}" >/dev/null

  # ===================== P0-1: manager PO cancellation =====================
  local cancel_po cancel_po_id cancel_req cancel_req_id mgr_approve
  cancel_po=$(curl -s -b "$cashier_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch2,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"QA CancelOwn $suffix\",\"category_id\":$cat_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5}]}")
  cancel_po_id=$(echo "$cancel_po" | json_get "data.id" 2>/dev/null)
  cancel_req=$(curl -s -b "$cashier_cookie" "$API_BASE/purchase-orders/cancel?id=$cancel_po_id" \
    -X POST -H 'Content-Type: application/json' -d '{"reason":"P0 QA cancel own branch"}')
  cancel_req_id=$(echo "$cancel_req" | json_get "data.id" 2>/dev/null)
  assert_neq "" "$cancel_req_id" "P0-1: Cancellation request created"

  mgr_approve=$(curl -s -b "$manager2_cookie" "$API_BASE/purchase-orders/cancellation-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$cancel_req_id}")
  assert_contains "$mgr_approve" '"status":"success"' "P0-1: Manager approves own-branch PO cancellation"

  # manager cannot approve other-branch request
  local cross_po cross_po_id cross_req cross_req_id cross_approve
  cross_po=$(api_post "purchase-orders" "{\"branch_id\":$branch1,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",\"items\":[{\"item_name\":\"QA CancelCross $suffix\",\"category_id\":$cat_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5}]}")
  cross_po_id=$(echo "$cross_po" | json_get "data.id" 2>/dev/null)
  cross_req=$(api_post_id "purchase-orders/cancel" "$cross_po_id" '{"reason":"P0 QA cross branch cancel"}')
  cross_req_id=$(echo "$cross_req" | json_get "data.id" 2>/dev/null)
  cross_approve=$(curl -s -b "$manager2_cookie" "$API_BASE/purchase-orders/cancellation-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$cross_req_id}")
  assert_contains "$cross_approve" '"status":"error"' "P0-1: Manager cannot approve other-branch PO cancellation"
  api_post "purchase-orders/cancellation-approve" "{\"id\":$cross_req_id}" >/dev/null  # admin cleanup

  # ===================== P0-5: manager cash session approvals =====================
  local expected open_actual open_res session_id open_approve
  expected=$(api_get "cash-sessions/current?branch_id=$branch2" | json_get "data.current_expected_cash" 2>/dev/null)
  [ -z "$expected" ] && expected=0
  open_actual=$(awk -v n="$expected" 'BEGIN { printf "%.2f", n + 150 }')
  open_res=$(curl -s -b "$cashier_cookie" "$API_BASE/cash-sessions/open" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch2,\"actual_cash\":$open_actual,\"reason\":\"P0 QA variance\"}")
  session_id=$(echo "$open_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$open_res" 'pending_open' "P0-5: Cashier open with variance waits for approval"

  # manager from OTHER branch cannot approve
  open_approve=$(curl -s -b "$manager1_cookie" "$API_BASE/cash-sessions/open-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$session_id}")
  assert_contains "$open_approve" '"status":"error"' "P0-5: Manager cannot approve other-branch open"

  open_approve=$(curl -s -b "$manager2_cookie" "$API_BASE/cash-sessions/open-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$session_id,\"review_note\":\"P0 QA approved\"}")
  assert_contains "$open_approve" '"status":"success"' "P0-5: Manager approves own-branch open"

  # close with variance → manager approves own branch
  local close_actual close_res close_id close_approve
  expected=$(api_get "cash-sessions/current?branch_id=$branch2" | json_get "data.current_expected_cash" 2>/dev/null)
  [ -z "$expected" ] && expected=0
  close_actual=$(awk -v n="$expected" 'BEGIN { printf "%.2f", n + 150 }')
  close_res=$(curl -s -b "$cashier_cookie" "$API_BASE/cash-sessions/close" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"branch_id\":$branch2,\"actual_cash\":$close_actual,\"reason\":\"P0 QA close variance\"}")
  close_id=$(echo "$close_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$close_res" 'pending_close' "P0-5: Cashier close with variance waits for approval"

  close_approve=$(curl -s -b "$manager1_cookie" "$API_BASE/cash-sessions/close-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$close_id}")
  assert_contains "$close_approve" '"status":"error"' "P0-5: Manager cannot approve other-branch close"

  close_approve=$(curl -s -b "$manager2_cookie" "$API_BASE/cash-sessions/close-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$close_id,\"review_note\":\"P0 QA approved close\"}")
  assert_contains "$close_approve" '"status":"success"' "P0-5: Manager approves own-branch close"

  # cleanup: leave branch open for remaining tests
  api_post "cash-sessions/reopen" "{\"id\":$close_id,\"reason\":\"P0 QA cleanup reopen\"}" >/dev/null
}
