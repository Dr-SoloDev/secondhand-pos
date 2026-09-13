# Authorization API tests for the v3 role/branch matrix.
# These tests intentionally fail on permissions that have not been implemented yet.

auth_extract_id() {
  echo "${1:-}" | json_get "data.id" 2>/dev/null
}

auth_prepare_cash_sessions() {
  local saved_cookie="${COOKIE_JAR:-}"
  COOKIE_JAR="$AUTH_FIXTURE_ADMIN_COOKIE"
  ensure_cash_session_open "$AUTH_FIXTURE_BRANCH_A" >/dev/null 2>&1 || { COOKIE_JAR="$saved_cookie"; return 1; }
  ensure_cash_session_open "$AUTH_FIXTURE_BRANCH_B" >/dev/null 2>&1 || { COOKIE_JAR="$saved_cookie"; return 1; }
  COOKIE_JAR="$saved_cookie"
}

test_authorization() {
  test_section "Authorization Matrix (v3)"
  local auth_fixture_password="${AUTH_FIXTURE_PASSWORD:-AccessTest123!}"

  if ! role_fixture_prepare; then
    test_fail "AUTH fixtures: admin credential and deterministic role users are available"
    return
  fi
  test_pass "AUTH fixtures: admin, cashier, manager and super-manager identities are ready"
  if ! auth_prepare_cash_sessions; then
    test_fail "AUTH fixtures: Branch A/B cash sessions are open"
    return
  fi
  test_pass "AUTH fixtures: Branch A/B cash sessions are open"

  local suffix category_id seller_id seller_response
  local branch_a="$AUTH_FIXTURE_BRANCH_A" branch_b="$AUTH_FIXTURE_BRANCH_B"
  local po_a po_a_id cancel_a cancel_a_id
  local po_b po_b_id cancel_b cancel_b_id
  local po_self po_self_id cancel_self cancel_self_id
  local catalog_response catalog_id seller_update transfer_response transfer_id
  local transfer_po transfer_po_id transfer_item_name
  suffix="$(date +%s)"

  local qa_catalog_id qa_catalog_code
  category_id=$(role_fixture_get "$AUTH_FIXTURE_ADMIN_COOKIE" "inventory/categories" | json_get "data.0.id" 2>/dev/null)
  [ -z "$category_id" ] && category_id=1
  qa_catalog_code="QA-FLOW-$suffix"
  role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "purchase-catalog" "{\"code\":\"$qa_catalog_code\",\"name\":\"QA Auth Flow Stock $suffix\",\"category_id\":$category_id}" >/dev/null
  qa_catalog_id=$(role_fixture_get "$AUTH_FIXTURE_ADMIN_COOKIE" "purchase-catalog/search?q=$qa_catalog_code" | json_get "data.0.id" 2>/dev/null)

  seller_response=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers" \
    "{\"full_name\":\"QA Authorization Seller $suffix\",\"phone\":\"09${suffix: -8}\"}")
  seller_id=$(auth_extract_id "$seller_response")
  if [ -z "$seller_id" ]; then
    test_fail "AUTH fixtures: create seller for PO tests"
    return
  fi

  # AUTH-01: unauthenticated requests must not reach a protected endpoint.
  local unauthenticated
  unauthenticated=$(curl -s -b /dev/null "$API_BASE/purchase-orders")
  assert_contains "$unauthenticated" '"status":"error"' "AUTH-01: anonymous request returns 401"

  # AUTH-02/03: cashier may create only in the branch from the JWT.
  po_a=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders" "{
    \"branch_id\":$branch_a,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"QA Authorization PO A $suffix\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
  }")
  po_a_id=$(auth_extract_id "$po_a")
  assert_contains "$po_a" '"status":"success"' "AUTH-02: cashier-A creates PO in Branch A"

  local cross_branch_po
  cross_branch_po=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders" "{
    \"branch_id\":$branch_b,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"QA Authorization Cross Branch $suffix\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
  }")
  assert_contains "$cross_branch_po" '"status":"error"' "AUTH-03: cashier-A cannot create PO in Branch B"

  if [ -n "$po_a_id" ]; then
    # AUTH-04/05: cashier can request cancellation but cannot approve it.
    cancel_a=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders/cancel?id=$po_a_id" \
      '{"reason":"QA authorization cancellation request"}')
    cancel_a_id=$(auth_extract_id "$cancel_a")
    assert_contains "$cancel_a" '"status":"success"' "AUTH-04: cashier-A requests PO cancellation"

    local cashier_approve
    cashier_approve=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders/cancellation-approve" \
      "{\"id\":${cancel_a_id:-0}}")
    assert_contains "$cashier_approve" '"status":"error"' "AUTH-05: cashier-A cannot approve PO cancellation"

    # AUTH-06: manager-A can approve a Branch A request made by cashier-A.
    if [ -n "$cancel_a_id" ]; then
      local manager_approve
      manager_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "purchase-orders/cancellation-approve" \
        "{\"id\":$cancel_a_id,\"review_note\":\"QA manager approval\"}")
      assert_contains "$manager_approve" '"status":"success"' "AUTH-06: manager-A approves Branch A cancellation"
    else
      test_fail "AUTH-06: cancellation fixture has request ID"
    fi

    # W2 also grants the manager a same-branch reject decision.
    local reject_po reject_po_id reject_request reject_request_id manager_reject
    reject_po=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders" "{
      \"branch_id\":$branch_a,
      \"seller_id\":$seller_id,
      \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"QA Authorization Reject PO $suffix\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
    }")
    reject_po_id=$(auth_extract_id "$reject_po")
    if [ -n "$reject_po_id" ]; then
      reject_request=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders/cancel?id=$reject_po_id" \
        '{"reason":"QA authorization manager rejection"}')
      reject_request_id=$(auth_extract_id "$reject_request")
    fi
    manager_reject=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "purchase-orders/cancellation-reject" \
      "{\"id\":${reject_request_id:-0},\"review_note\":\"QA manager rejection\"}")
    assert_contains "$manager_reject" '"status":"success"' "AUTH-06b: manager-A rejects Branch A cancellation"
  else
    test_fail "AUTH-04: Branch A PO fixture has ID"
    test_fail "AUTH-06: Branch A cancellation fixture has ID"
  fi

  # AUTH-07: a manager may not approve a cancellation belonging to another branch.
  po_b=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_B_COOKIE" "purchase-orders" "{
    \"branch_id\":$branch_b,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"QA Authorization PO B $suffix\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
  }")
  po_b_id=$(auth_extract_id "$po_b")
  if [ -n "$po_b_id" ]; then
    cancel_b=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_B_COOKIE" "purchase-orders/cancel?id=$po_b_id" \
      '{"reason":"QA cross-branch approval request"}')
    cancel_b_id=$(auth_extract_id "$cancel_b")
  fi
  local cross_branch_approve
  cross_branch_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "purchase-orders/cancellation-approve" \
    "{\"id\":${cancel_b_id:-0},\"review_note\":\"QA cross-branch attempt\"}")
  assert_contains "$cross_branch_approve" '"status":"error"' "AUTH-07: manager-A cannot approve Branch B cancellation"
  local cross_branch_reject
  cross_branch_reject=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "purchase-orders/cancellation-reject" \
    "{\"id\":${cancel_b_id:-0},\"review_note\":\"QA cross-branch reject attempt\"}")
  assert_contains "$cross_branch_reject" '"status":"error"' "AUTH-07b: manager-A cannot reject Branch B cancellation"

  # AUTH-08: requester cannot approve own request, even when role is manager.
  po_self=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders" "{
    \"branch_id\":$branch_a,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"QA Authorization Self PO $suffix\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
  }")
  po_self_id=$(auth_extract_id "$po_self")
  if [ -n "$po_self_id" ]; then
    cancel_self=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "purchase-orders/cancel?id=$po_self_id" \
      '{"reason":"QA manager self-approval request"}')
    cancel_self_id=$(auth_extract_id "$cancel_self")
  fi
  local self_approve
  self_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "purchase-orders/cancellation-approve" \
    "{\"id\":${cancel_self_id:-0},\"review_note\":\"QA self-approval attempt\"}")
  assert_contains "$self_approve" '"status":"error"' "AUTH-08: manager-A cannot approve own request"

  # AUTH-09/10: cashier may edit catalog but cannot delete master data.
  catalog_response=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-catalog" "{
    \"code\":\"QA-AUTH-$suffix\",
    \"name\":\"QA Authorization Catalog $suffix\",
    \"category_id\":$category_id,
    \"default_unit\":\"kg\",
    \"tier_prices\":[{\"label\":\"บิล 1\",\"price\":1},{\"label\":\"บิล 2\",\"price\":2}]
  }")
  catalog_id=$(auth_extract_id "$catalog_response")
  assert_contains "$catalog_response" '"status":"success"' "AUTH-09: cashier-A creates catalog item"
  if [ -n "$catalog_id" ]; then
    local catalog_update catalog_category catalog_delete
    catalog_update=$(role_fixture_put "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-catalog/item?id=$catalog_id" \
      "{\"id\":$catalog_id,\"name\":\"QA Authorization Catalog Updated $suffix\"}")
    assert_contains "$catalog_update" '"status":"success"' "AUTH-09b: cashier-A updates catalog item"
    catalog_category=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-catalog/update-category" \
      "{\"catalog_id\":$catalog_id,\"category_id\":$category_id}")
    assert_contains "$catalog_category" '"status":"success"' "AUTH-09c: cashier-A updates catalog category"
    catalog_delete=$(role_fixture_delete "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-catalog/item?id=$catalog_id")
    assert_contains "$catalog_delete" '"status":"error"' "AUTH-10: cashier-A cannot delete catalog item"
  else
    test_fail "AUTH-09b: catalog fixture has ID"
    test_fail "AUTH-09c: catalog fixture has ID"
    test_fail "AUTH-10: catalog fixture has ID"
  fi

  # AUTH-11: cashier may change the seller tier.
  seller_update=$(role_fixture_put "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/seller?id=$seller_id" \
    "{\"id\":$seller_id,\"tier_level\":2}")
  assert_contains "$seller_update" '"status":"success"' "AUTH-11: cashier-A updates seller tier"
  local cashier_blacklist_bypass
  cashier_blacklist_bypass=$(role_fixture_put "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sellers/seller?id=$seller_id" \
    "{\"id\":$seller_id,\"is_blacklisted\":1,\"blacklist_reason\":\"QA forged blacklist\"}")
  assert_contains "$cashier_blacklist_bypass" '"status":"error"' "AUTH-12: cashier-A cannot blacklist through seller update"

  # AUTH-28: Owner is the only role allowed to self-approve a request.
  local admin_po admin_po_id admin_cancel admin_cancel_id admin_self_approve
  admin_po=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "purchase-orders" "{
    \"branch_id\":$branch_a,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"QA Authorization Admin Self PO $suffix\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
  }")
  admin_po_id=$(auth_extract_id "$admin_po")
  if [ -n "$admin_po_id" ]; then
    admin_cancel=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "purchase-orders/cancel?id=$admin_po_id" \
      '{"reason":"QA owner self-approval policy"}')
    admin_cancel_id=$(auth_extract_id "$admin_cancel")
  fi
  admin_self_approve=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "purchase-orders/cancellation-approve" \
    "{\"id\":${admin_cancel_id:-0},\"review_note\":\"QA owner self approval\"}")
  assert_contains "$admin_self_approve" '"status":"success"' "AUTH-28: admin may self-approve PO cancellation"

  # AUTH-18/20/21: transfer create/receive is branch-scoped.
  # Keep transfer stock separate from the cancellation fixture above: a
  # correctly cancelled PO must no longer contribute source stock.
  transfer_item_name="QA Authorization Transfer Stock $suffix"
  transfer_po=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "purchase-orders" "{
    \"branch_id\":$branch_a,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"category_id\":$category_id,\"quantity\":1,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
  }")
  transfer_po_id=$(auth_extract_id "$transfer_po")
  assert_contains "$transfer_po" '"status":"success"' "AUTH-17: cashier-A creates source stock for transfer"
  assert_neq "" "$transfer_po_id" "AUTH-17b: source-stock PO fixture has ID"

  local wrong_source_transfer
  wrong_source_transfer=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "stock-transfers" "{
    \"from_branch_id\":$branch_b,
    \"to_branch_id\":$branch_a,
    \"items\":[{\"category_id\":$category_id,\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"weight_kg\":0.25}],
    \"note\":\"QA forged source branch\"
  }")
  assert_contains "$wrong_source_transfer" '"status":"error"' "AUTH-19: cashier-A cannot create transfer from Branch B"

  transfer_response=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "stock-transfers" "{
    \"from_branch_id\":$branch_a,
    \"to_branch_id\":$branch_b,
    \"items\":[{\"category_id\":$category_id,\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"weight_kg\":0.5}],
    \"note\":\"QA authorization transfer\"
  }")
  transfer_id=$(auth_extract_id "$transfer_response")
  assert_contains "$transfer_response" '"status":"success"' "AUTH-18: cashier-A creates transfer from Branch A"
  if [ -n "$transfer_id" ]; then
    local wrong_receive correct_receive
    wrong_receive=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "stock-transfers/confirm" \
      "{\"id\":$transfer_id,\"received_weight_kg\":0.5}")
    assert_contains "$wrong_receive" '"status":"error"' "AUTH-21: cashier-A cannot receive transfer to Branch B"
    assert_contains "$wrong_receive" 'ไม่พบรายการรอตรวจรับที่สาขานี้' "AUTH-21b: wrong-branch receive explains branch mismatch"
    correct_receive=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_B_COOKIE" "stock-transfers/confirm" \
      "{\"id\":$transfer_id,\"received_weight_kg\":0.5,\"receive_note\":\"QA received\"}")
    assert_contains "$correct_receive" '"status":"success"' "AUTH-20: cashier-B receives pending transfer to Branch B"

    local cashier_cancel cashier_reversal_approve manager_wrong_branch_cancel
    cashier_cancel=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "stock-transfers/cancel" \
      "{\"id\":$transfer_id}")
    assert_contains "$cashier_cancel" '"status":"error"' "AUTH-22: cashier-A cannot cancel a stock transfer"
    cashier_reversal_approve=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "stock-transfers/reversal-approve" \
      "{\"id\":$transfer_id}")
    assert_contains "$cashier_reversal_approve" '"status":"error"' "AUTH-22b: cashier-A cannot approve a reversal"
    manager_wrong_branch_cancel=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "stock-transfers/cancel" \
      "{\"id\":$transfer_id}")
    assert_contains "$manager_wrong_branch_cancel" '"status":"error"' "AUTH-23: manager-B cannot cancel Branch A transfer"

    local manager_transfer manager_transfer_id manager_cancel
    manager_transfer=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "stock-transfers" "{
      \"from_branch_id\":$branch_a,
      \"to_branch_id\":$branch_b,
      \"items\":[{\"category_id\":$category_id,\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"weight_kg\":0.25}],
      \"note\":\"QA manager cancellation flow\"
    }")
    manager_transfer_id=$(auth_extract_id "$manager_transfer")
    manager_cancel=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "stock-transfers/cancel" \
      "{\"id\":${manager_transfer_id:-0}}")
    assert_contains "$manager_cancel" '"status":"success"' "AUTH-23b: manager-A cancels own pending transfer"

    # W4: manager can complete Sale Lot flow only in the assigned branch;
    # cashier has no Sale Lot transaction access.
    local manager_lot manager_lot_id manager_lot_update manager_lot_confirm
    local wrong_branch_lot_update wrong_branch_lot_cancel manager_lot_cancel cashier_sale_lots
    manager_lot=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "sale-lots" "{
      \"branch_id\":$branch_a,
      \"buyer_name\":\"QA W4 Manager Buyer $suffix\",
      \"sale_date\":\"$(date +%Y-%m-%d)\",
      \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"category_id\":$category_id,\"quantity_kg\":0.25,\"unit_price\":3}]
    }")
    manager_lot_id=$(auth_extract_id "$manager_lot")
    assert_contains "$manager_lot" '"status":"success"' "AUTH-13: manager-A creates Sale Lot in Branch A"
    if [ -n "$manager_lot_id" ]; then
      manager_lot_update=$(role_fixture_put "$AUTH_FIXTURE_MANAGER_A_COOKIE" "sale-lots/sale-lot?id=$manager_lot_id" "{
        \"buyer_name\":\"QA W4 Manager Buyer Updated $suffix\",
        \"sale_date\":\"$(date +%Y-%m-%d)\",
        \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"category_id\":$category_id,\"quantity_kg\":0.25,\"unit_price\":3.5}]
      }")
      assert_contains "$manager_lot_update" '"status":"success"' "AUTH-13a: manager-A updates own draft Sale Lot"

      manager_lot_confirm=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "sale-lots/confirm?id=$manager_lot_id" '{}')
      assert_contains "$manager_lot_confirm" '"status":"success"' "AUTH-13b: manager-A confirms own Sale Lot"

      wrong_branch_lot_update=$(role_fixture_put "$AUTH_FIXTURE_MANAGER_B_COOKIE" "sale-lots/sale-lot?id=$manager_lot_id" "{
        \"buyer_name\":\"QA W4 Cross Branch Update\",
        \"sale_date\":\"$(date +%Y-%m-%d)\",
        \"items\":[{\"catalog_id\":$qa_catalog_id,\"item_name\":\"$transfer_item_name\",\"category_id\":$category_id,\"quantity_kg\":0.1,\"unit_price\":3}]
      }")
      assert_contains "$wrong_branch_lot_update" '"status":"error"' "AUTH-14: manager-B cannot update Branch A Sale Lot"
      wrong_branch_lot_cancel=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "sale-lots/cancel" \
        "{\"id\":$manager_lot_id}")
      assert_contains "$wrong_branch_lot_cancel" '"status":"error"' "AUTH-14a: manager-B cannot cancel Branch A Sale Lot"

      manager_lot_cancel=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "sale-lots/cancel?id=$manager_lot_id" '{}')
      assert_contains "$manager_lot_cancel" '"status":"success"' "AUTH-13c: manager-A cancels own Sale Lot"
    else
      test_fail "AUTH-13a: manager Sale Lot fixture has ID"
      test_fail "AUTH-13b: manager Sale Lot fixture has ID"
      test_fail "AUTH-14: manager-B Sale Lot fixture has ID"
      test_fail "AUTH-14a: manager-B Sale Lot fixture has ID"
      test_fail "AUTH-13c: manager Sale Lot fixture has ID"
    fi

    cashier_sale_lots=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "sale-lots")
    assert_contains "$cashier_sale_lots" '"status":"error"' "AUTH-15: cashier-A cannot access Sale Lot transactions"

    # W4 inventory reads are branch-scoped even when a cashier forges a
    # branch_id query parameter. Create a Branch B-only marker for the check.
    local branch_b_item_name branch_b_stock branch_b_catalog_id cashier_inventory own_inventory forged_inventory
    branch_b_item_name="QA W4 Branch B Only $suffix"
    branch_b_catalog_id=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "purchase-catalog" "{\"code\":\"QA-BR-B-$suffix\",\"name\":\"$branch_b_item_name\",\"category_id\":$category_id}" | json_get "data.id" 2>/dev/null)
    branch_b_stock=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "purchase-orders" "{
      \"branch_id\":$branch_b,
      \"seller_id\":$seller_id,
      \"payment_method\":\"bank_transfer\",
      \"items\":[{\"catalog_id\":$branch_b_catalog_id,\"item_name\":\"$branch_b_item_name\",\"category_id\":$category_id,\"quantity\":0.75,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":1}]
    }")
    assert_contains "$branch_b_stock" '"status":"success"' "AUTH-16: create Branch B inventory fixture"
    own_inventory=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "inventory/category-items?category_id=$category_id&branch_id=$branch_a")
    assert_contains "$own_inventory" '"status":"success"' "AUTH-16a: cashier-A reads own branch inventory"
    forged_inventory=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "inventory/category-items?category_id=$category_id&branch_id=$branch_b")
    assert_contains "$forged_inventory" '"status":"success"' "AUTH-16b: forged branch inventory request remains readable"
    assert_not_contains "$forged_inventory" "$branch_b_item_name" "AUTH-16c: cashier-A cannot read Branch B inventory"

    local cashier_adjustment manager_adjustment
    cashier_adjustment=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "inventory/transactions" \
      '{"product_id":1,"type":"adjustment","quantity":999,"notes":"QA forbidden direct adjustment"}')
    assert_contains "$cashier_adjustment" '"status":"error"' "AUTH-17: cashier-A cannot directly adjust inventory"
    manager_adjustment=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "inventory/transactions" \
      '{"product_id":1,"type":"adjustment","quantity":999,"notes":"QA forbidden manager adjustment"}')
    assert_contains "$manager_adjustment" '"status":"error"' "AUTH-17a: manager-A cannot directly adjust inventory"

    local cashier_transactions super_transactions
    cashier_transactions=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "inventory/transactions?branch_id=$branch_b")
    assert_not_contains "$cashier_transactions" "\"branch_id\":$branch_b" \
      "AUTH-17b: cashier-A cannot read Branch B inventory transactions"

    super_transactions=$(role_fixture_get "$AUTH_FIXTURE_SUPER_COOKIE" "inventory/transactions?branch_id=$branch_b")
    assert_contains "$super_transactions" '"status":"success"' \
      "AUTH-17c: super-manager reads selected Branch B inventory transactions"
  else
    test_fail "AUTH-20: transfer fixture has ID"
    test_fail "AUTH-21: transfer fixture has ID"
    test_fail "AUTH-22: transfer fixture has ID"
    test_fail "AUTH-22b: transfer fixture has ID"
    test_fail "AUTH-23: transfer fixture has ID"
    test_fail "AUTH-23b: transfer fixture has ID"
  fi

  # W5: cash session and financial approval is branch-scoped. Only the
  # owner may approve a request created by the same user.
  local deposit_cashier_a deposit_cashier_a_id deposit_manager_a deposit_manager_a_id
  local deposit_cross_branch deposit_admin_self deposit_admin_self_id
  deposit_cashier_a=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "cash-sessions/deposit-request" "{
    \"branch_id\":$branch_a,\"amount\":11,\"source_type\":\"owner_capital\",\"source_name\":\"QA W5 cashier\",\"reason\":\"W5 approval matrix\"
  }")
  deposit_cashier_a_id=$(auth_extract_id "$deposit_cashier_a")
  assert_contains "$deposit_cashier_a" '"status":"success"' "AUTH-30: cashier-A requests Branch A cash deposit"
  if [ -n "$deposit_cashier_a_id" ]; then
    local manager_deposit_approve manager_b_deposit_approve
    manager_deposit_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "cash-sessions/deposit-approve" \
      "{\"id\":$deposit_cashier_a_id}")
    assert_contains "$manager_deposit_approve" '"status":"success"' "AUTH-31: manager-A approves another user's Branch A deposit"

    deposit_cross_branch=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_B_COOKIE" "cash-sessions/deposit-request" "{
      \"branch_id\":$branch_b,\"amount\":12,\"source_type\":\"owner_capital\",\"source_name\":\"QA W5 cross\",\"reason\":\"W5 cross branch\"
    }")
    local deposit_cross_id
    deposit_cross_id=$(auth_extract_id "$deposit_cross_branch")
    manager_b_deposit_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "cash-sessions/deposit-approve" \
      "{\"id\":${deposit_cross_id:-0}}")
    assert_contains "$manager_b_deposit_approve" '"status":"error"' "AUTH-32: manager-A cannot approve Branch B deposit"

    # Clean the cross-branch fixture with its assigned manager.
    if [ -n "$deposit_cross_id" ]; then
      role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "cash-sessions/deposit-reject" \
        "{\"id\":$deposit_cross_id,\"review_note\":\"QA cleanup\"}" >/dev/null
    fi
  else
    test_fail "AUTH-31: deposit fixture has ID"
    test_fail "AUTH-32: cross-branch deposit fixture has ID"
  fi

  deposit_manager_a=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "cash-sessions/deposit-request" "{
    \"branch_id\":$branch_a,\"amount\":13,\"source_type\":\"owner_capital\",\"source_name\":\"QA W5 manager\",\"reason\":\"W5 self approval\"
  }")
  deposit_manager_a_id=$(auth_extract_id "$deposit_manager_a")
  local manager_self_deposit
  manager_self_deposit=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "cash-sessions/deposit-approve" \
    "{\"id\":${deposit_manager_a_id:-0}}")
  assert_contains "$manager_self_deposit" '"status":"error"' "AUTH-33: manager-A cannot approve own deposit request"
  if [ -n "$deposit_manager_a_id" ]; then
    role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "cash-sessions/deposit-approve" \
      "{\"id\":$deposit_manager_a_id}" >/dev/null
  fi

  deposit_admin_self=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "cash-sessions/deposit-request" "{
    \"branch_id\":$branch_a,\"amount\":14,\"source_type\":\"owner_capital\",\"source_name\":\"QA W5 owner\",\"reason\":\"W5 owner self approval\"
  }")
  deposit_admin_self_id=$(auth_extract_id "$deposit_admin_self")
  local admin_self_deposit
  admin_self_deposit=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "cash-sessions/deposit-approve" \
    "{\"id\":${deposit_admin_self_id:-0}}")
  assert_contains "$admin_self_deposit" '"status":"success"' "AUTH-34: admin may self-approve cash deposit"

  local expense_cashier_a expense_cashier_a_id expense_cross expense_cross_id expense_manager_a expense_manager_a_id
  expense_cashier_a=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "financial/expenses" "{
    \"branch_id\":$branch_a,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"QA W5 expense\",\"amount\":15,
    \"payment_method\":\"bank_transfer\",\"beneficiary_name\":\"QA W5 recipient\"
  }")
  expense_cashier_a_id=$(auth_extract_id "$expense_cashier_a")
  assert_contains "$expense_cashier_a" '"status":"success"' "AUTH-35: cashier-A records Branch A expense immediately"
  assert_contains "$expense_cashier_a" '"status":"approved"' "AUTH-35b: expense is recorded without approval"
  local manager_expense_approve
  manager_expense_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "financial/expenses/approve" \
    "{\"id\":${expense_cashier_a_id:-0}}")
  assert_contains "$manager_expense_approve" '"status":"error"' "AUTH-36: recorded expense needs no approval"

  expense_cross=$(role_fixture_post "$AUTH_FIXTURE_CASHIER_A_COOKIE" "financial/expenses" "{
    \"branch_id\":$branch_a,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"QA W5 cross expense\",\"amount\":16,
    \"payment_method\":\"bank_transfer\",\"beneficiary_name\":\"QA W5 cross recipient\"
  }")
  expense_cross_id=$(auth_extract_id "$expense_cross")
  local manager_b_expense_approve
  manager_b_expense_approve=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "financial/expenses/approve" \
    "{\"id\":${expense_cross_id:-0}}")
  assert_contains "$manager_b_expense_approve" '"status":"error"' "AUTH-37: manager-B cannot approve Branch A expense"
  if [ -n "$expense_cross_id" ]; then
    role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "financial/expenses/reject" \
      "{\"id\":$expense_cross_id,\"review_note\":\"QA cleanup\"}" >/dev/null
  fi

  expense_manager_a=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "financial/expenses" "{
    \"branch_id\":$branch_a,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"QA W5 self expense\",\"amount\":17,
    \"payment_method\":\"bank_transfer\",\"beneficiary_name\":\"QA W5 self recipient\"
  }")
  expense_manager_a_id=$(auth_extract_id "$expense_manager_a")
  local manager_self_expense
  manager_self_expense=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "financial/expenses/approve" \
    "{\"id\":${expense_manager_a_id:-0}}")
  assert_contains "$manager_self_expense" '"status":"error"' "AUTH-38: manager-A cannot approve already-recorded expense"
  if [ -n "$expense_manager_a_id" ]; then
    role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "financial/expenses/approve" \
      "{\"id\":$expense_manager_a_id}" >/dev/null
  fi

  local expense_admin_self expense_admin_self_id admin_self_expense
  expense_admin_self=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "financial/expenses" "{
    \"branch_id\":$branch_a,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"QA W5 owner expense\",\"amount\":18,
    \"payment_method\":\"bank_transfer\",\"beneficiary_name\":\"QA W5 owner recipient\"
  }")
  expense_admin_self_id=$(auth_extract_id "$expense_admin_self")
  admin_self_expense=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "financial/expenses/approve" \
    "{\"id\":${expense_admin_self_id:-0}}")
  assert_contains "$expense_admin_self" '"status":"approved"' "AUTH-39: admin records expense without approval"
  assert_contains "$admin_self_expense" '"status":"error"' "AUTH-39b: recorded admin expense needs no approval"

  local expense_super expense_super_id super_expense_review
  expense_super=$(role_fixture_post "$AUTH_FIXTURE_SUPER_COOKIE" "financial/expenses" "{
    \"branch_id\":$branch_b,\"expense_date\":\"$(date +%Y-%m-%d)\",\"category\":\"QA W5 super expense\",\"amount\":19,
    \"payment_method\":\"bank_transfer\",\"beneficiary_name\":\"QA W5 super recipient\"
  }")
  expense_super_id=$(auth_extract_id "$expense_super")
  assert_contains "$expense_super" '"status":"success"' "AUTH-39c: super-manager records expense without approval"
  assert_contains "$expense_super" '"status":"approved"' "AUTH-39d: super-manager expense is immediately approved"
  super_expense_review=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "financial/expenses/approve" \
    "{\"id\":${expense_super_id:-0}}")
  assert_contains "$super_expense_review" '"status":"error"' "AUTH-39e: immediately recorded super-manager expense cannot be approved again"

  # AUTH-40/41: session review respects branch scope and requester guard.
  local close_a close_a_id manager_b_close manager_a_close
  local branch_a_expected
  branch_a_expected=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "cash-sessions/current?branch_id=$branch_a" | json_get "data.current_expected_cash" 2>/dev/null)
  close_a=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "cash-sessions/close" "{
    \"branch_id\":$branch_a,\"actual_cash\":$(awk -v n="$branch_a_expected" 'BEGIN { print n + 150 }'),\"reason\":\"QA W5 close review\"
  }")
  close_a_id=$(auth_extract_id "$close_a")
  assert_contains "$close_a" '"status":"success"' "AUTH-40: cashier-A submits Branch A close variance"
  manager_b_close=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_B_COOKIE" "cash-sessions/close-approve" \
    "{\"id\":${close_a_id:-0},\"review_note\":\"QA cross branch\"}")
  assert_contains "$manager_b_close" '"status":"error"' "AUTH-41: manager-B cannot review Branch A close"
  manager_a_close=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "cash-sessions/close-approve" \
    "{\"id\":${close_a_id:-0},\"review_note\":\"QA self review\"}")
  assert_contains "$manager_a_close" '"status":"error"' "AUTH-42: manager-A cannot approve own close request"
  if [ -n "$close_a_id" ]; then
    local admin_close
    admin_close=$(role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "cash-sessions/close-approve" \
      "{\"id\":$close_a_id,\"review_note\":\"QA owner review\"}")
    assert_contains "$admin_close" '"status":"success"' "AUTH-43: admin approves Branch A close variance"
    role_fixture_post "$AUTH_FIXTURE_ADMIN_COOKIE" "cash-sessions/reopen" \
      "{\"id\":$close_a_id,\"reason\":\"QA W5 cleanup\"}" >/dev/null
  else
    test_fail "AUTH-43: close fixture has ID"
  fi

  # W6 employees: manager writes are forced to the JWT branch, while the
  # super manager can manage employee records across branches.
  local employee_a employee_a_id employee_a_branch employee_cross_read
  employee_a=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "employees" "{
    \"branch_id\":$branch_b,\"full_name\":\"QA W6 Employee A $suffix\",\"position\":\"cashier\",\"status\":\"active\"
  }")
  employee_a_id=$(auth_extract_id "$employee_a")
  employee_a_branch=$(echo "$employee_a" | json_get "data.branch_id" 2>/dev/null)
  assert_contains "$employee_a" '"status":"success"' "AUTH-44: manager-A creates employee"
  assert_eq "$branch_a" "$employee_a_branch" "AUTH-45: manager-A employee is forced to Branch A"
  employee_cross_read=$(role_fixture_get "$AUTH_FIXTURE_MANAGER_B_COOKIE" "employees/employee?id=${employee_a_id:-0}")
  assert_contains "$employee_cross_read" '"status":"error"' "AUTH-46: manager-B cannot read Branch A employee"

  local super_employee super_employee_id manager_cross_update cashier_employee_list
  super_employee=$(role_fixture_post "$AUTH_FIXTURE_SUPER_COOKIE" "employees" "{
    \"branch_id\":$branch_b,\"full_name\":\"QA W6 Employee B $suffix\",\"position\":\"manager\",\"status\":\"active\"
  }")
  super_employee_id=$(auth_extract_id "$super_employee")
  assert_contains "$super_employee" '"status":"success"' "AUTH-47: super manager creates Branch B employee"
  manager_cross_update=$(role_fixture_put "$AUTH_FIXTURE_MANAGER_A_COOKIE" "employees/employee?id=${super_employee_id:-0}" \
    '{"full_name":"QA forbidden cross-branch update"}')
  assert_contains "$manager_cross_update" '"status":"error"' "AUTH-48: manager-A cannot update Branch B employee"
  cashier_employee_list=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "employees")
  assert_contains "$cashier_employee_list" '"status":"error"' "AUTH-49: cashier cannot access employee management"

  # W6 users: super manager may manage cashier/manager accounts only.
  local w6_username w6_phone super_create_user super_create_user_id
  local super_create_admin super_promote_admin manager_users
  w6_username="qa-w6-user-${suffix}-$$"
  w6_phone="qa-w6-phone-${suffix}-$$"
  super_create_user=$(role_fixture_post "$AUTH_FIXTURE_SUPER_COOKIE" "users" "{
    \"username\":\"$w6_username\",\"password\":\"$auth_fixture_password\",\"phone\":\"$w6_phone\",
    \"full_name\":\"QA W6 Managed User\",\"role\":\"cashier\",\"branch_id\":$branch_a,\"status\":\"active\"
  }")
  super_create_user_id=$(auth_extract_id "$super_create_user")
  assert_contains "$super_create_user" '"status":"success"' "AUTH-29: super manager creates cashier user"

  super_create_admin=$(role_fixture_post "$AUTH_FIXTURE_SUPER_COOKIE" "users" "{
    \"username\":\"qa-w6-admin-${suffix}-$$\",\"password\":\"$auth_fixture_password\",\"phone\":\"qa-w6-admin-phone-${suffix}-$$\",
    \"full_name\":\"QA W6 Forbidden Admin\",\"role\":\"admin\",\"status\":\"active\"
  }")
  assert_contains "$super_create_admin" '"status":"error"' "AUTH-30: super manager cannot create admin user"
  super_promote_admin=$(role_fixture_put "$AUTH_FIXTURE_SUPER_COOKIE" "users/user?id=${super_create_user_id:-0}" \
    "{\"role\":\"admin\"}")
  assert_contains "$super_promote_admin" '"status":"error"' "AUTH-30b: super manager cannot promote cashier to admin"
  manager_users=$(role_fixture_get "$AUTH_FIXTURE_MANAGER_A_COOKIE" "users/all")
  assert_contains "$manager_users" '"status":"error"' "AUTH-30c: branch manager cannot manage system users"

  # W6 settings: branch operational keys are separate from global/security
  # keys and backup endpoints remain owner-only.
  local manager_branch_setting manager_cross_setting manager_global_setting
  local super_branch_setting super_backup_history manager_setting_read
  manager_branch_setting=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "settings/system" \
    "{\"branch_id\":$branch_a,\"low_stock_threshold\":\"$((suffix % 50 + 10))\"}")
  assert_contains "$manager_branch_setting" '"status":"success"' "AUTH-31: manager-A updates Branch A operational setting"
  manager_setting_read=$(role_fixture_get "$AUTH_FIXTURE_MANAGER_A_COOKIE" "settings/system?branch_id=$branch_b")
  assert_contains "$manager_setting_read" '"status":"error"' "AUTH-31b: manager-A cannot read Branch B settings"
  manager_cross_setting=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "settings/system" \
    "{\"branch_id\":$branch_b,\"low_stock_threshold\":\"99\"}")
  assert_contains "$manager_cross_setting" '"status":"error"' "AUTH-32: manager-A cannot update Branch B setting"
  manager_global_setting=$(role_fixture_post "$AUTH_FIXTURE_MANAGER_A_COOKIE" "settings/system" \
    "{\"branch_id\":$branch_a,\"time_zone\":\"UTC\"}")
  assert_contains "$manager_global_setting" '"status":"error"' "AUTH-32b: manager-A cannot update global system setting"
  super_branch_setting=$(role_fixture_post "$AUTH_FIXTURE_SUPER_COOKIE" "settings/system" \
    "{\"branch_id\":$branch_b,\"low_stock_threshold\":\"$((suffix % 50 + 20))\"}")
  assert_contains "$super_branch_setting" '"status":"success"' "AUTH-32c: super manager updates Branch B operational setting"
  super_backup_history=$(role_fixture_get "$AUTH_FIXTURE_SUPER_COOKIE" "settings/backup/history")
  assert_contains "$super_backup_history" '"status":"error"' "AUTH-32d: backup remains admin-only"

  # W7: navigation and action controls consume a permission map issued by
  # the authenticated backend rather than trusting localStorage roles.
  local cashier_permissions manager_permissions super_permissions
  cashier_permissions=$(role_fixture_get "$AUTH_FIXTURE_CASHIER_A_COOKIE" "auth/permissions")
  manager_permissions=$(role_fixture_get "$AUTH_FIXTURE_MANAGER_A_COOKIE" "auth/permissions")
  super_permissions=$(role_fixture_get "$AUTH_FIXTURE_SUPER_COOKIE" "auth/permissions")
  assert_contains "$cashier_permissions" '"sale-lots.html":false' "AUTH-50: cashier permission map hides Sale Lot page"
  assert_contains "$cashier_permissions" '"blacklist":false' "AUTH-50b: cashier permission map hides seller blacklist action"
  assert_contains "$manager_permissions" '"sale-lots.html":true' "AUTH-51: manager permission map allows Sale Lot page"
  assert_contains "$manager_permissions" '"users.html":false' "AUTH-51b: branch manager permission map hides user management"
  assert_contains "$super_permissions" '"users.html":true' "AUTH-52: super manager permission map allows lower-role user management"
  assert_contains "$super_permissions" '"backup":false' "AUTH-52b: super manager permission map hides backup actions"
}
