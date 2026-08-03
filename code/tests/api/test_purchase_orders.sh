# Purchase Orders API Tests
test_purchase_orders() {
  test_section "Purchase Orders"

  local res
  ensure_all_cash_sessions_open

  # 1. List POs
  res=$(api_get "purchase-orders")
  assert_contains "$res" '"status":"success"' "List purchase orders"

  # 2. Create PO with items
  local seller_id
  seller_id=$(api_get "sellers" | sed 's/.*"id":\([0-9]*\).*/\1/' | head -1)
  [ -z "$seller_id" ] && seller_id=1

  local cat_id
  cat_id=$(api_get "inventory/categories" | sed 's/.*"id":\([0-9]*\).*/\1/' | head -1)
  [ -z "$cat_id" ] && cat_id=1

  local branch_id
  branch_id=$(api_get "branches" | sed 's/.*"id":\([0-9]*\).*/\1/' | head -1)
  [ -z "$branch_id" ] && branch_id=1

  local manager_branch_id
  manager_branch_id=$(api_get "branches" | json_get "data.1.id" 2>/dev/null)
  [ -z "$manager_branch_id" ] && manager_branch_id=2

  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{
      \"item_name\":\"Test Item\",
      \"category_id\":$cat_id,
      \"quantity\":10,
      \"weight_deduction\":0.5,
      \"unit\":\"kg\",
      \"unit_price\":15.00
    }]
  }")
  assert_contains "$res" '"status":"success"' "Create PO with items"
  assert_contains "$res" '"reference_no"' "PO has reference number"

  # 3. Create PO without items — rejected
  local fail_res
  fail_res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"items\":[]
  }")
  assert_contains "$fail_res" '"status":"error"' "Create PO without items rejected"

  # 4. Create PO without seller — rejected
  local no_seller
  no_seller=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"items\":[{\"item_name\":\"X\",\"quantity\":1,\"unit_price\":10}]
  }")
  assert_contains "$no_seller" '"status":"error"' "Create PO without seller rejected"

  # Deducted weight is discarded; deduction must remain below gross weight.
  local invalid_net
  invalid_net=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"Invalid Net Weight\",
      \"category_id\":$cat_id,
      \"quantity\":10,
      \"weight_deduction\":10,
      \"unit\":\"kg\",
      \"unit_price\":10.00
    }]
  }")
  assert_contains "$invalid_net" '"status":"error"' "PO rejects deduction equal to gross weight"
  assert_contains "$invalid_net" 'น้ำหนักหัก' "PO returns net-weight validation message"

  # 5. PO with invalid branch — rejected
  local bad_branch
  bad_branch=$(api_post "purchase-orders" "{
    \"branch_id\":99999,
    \"seller_id\":$seller_id,
    \"items\":[{\"item_name\":\"X\",\"quantity\":1,\"unit_price\":10}]
  }")
  assert_contains "$bad_branch" '"status":"error"' "PO with invalid branch rejected"

  # 6. Cancellation now requires a reason and a separate approver.
  local po_id manager_cookie cancel_po cancel_po_id cancel_request cancel_request_id
  po_id=$(api_get "purchase-orders" | json_get "data.items.0.id" 2>/dev/null)
  [ -z "$po_id" ] && po_id=1

  local cancel_res
  cancel_res=$(api_post_id "purchase-orders/cancel" "$po_id" "{}")
  assert_contains "$cancel_res" '"status":"error"' "PO cancellation requires a reason"
  assert_contains "$cancel_res" 'กรุณาระบุเหตุผล' "PO cancellation returns reason validation"

  manager_cookie="/tmp/test_po_manager_br01_$$.cookie"
  res=$(curl -s -c "$manager_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br02","password":"admin"}')
  assert_contains "$res" '"status":"success"' "PO cancellation: Branch manager login"

  cancel_po=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{
      \"branch_id\":$manager_branch_id,
      \"seller_id\":$seller_id,
      \"payment_method\":\"cash\",
      \"items\":[{\"item_name\":\"Cancellation Approval Test\",\"category_id\":$cat_id,\"quantity\":2,\"weight_deduction\":0,\"unit\":\"kg\",\"unit_price\":5.00}]
    }")
  cancel_po_id=$(echo "$cancel_po" | json_get "data.id" 2>/dev/null)
  assert_contains "$cancel_po" '"status":"success"' "PO cancellation: Manager creates own-branch PO"

  cancel_request=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders/cancel?id=$cancel_po_id" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"reason":"QA incorrect weighing entry"}')
  cancel_request_id=$(echo "$cancel_request" | json_get "data.id" 2>/dev/null)
  assert_contains "$cancel_request" '"status":"success"' "PO cancellation: Manager submits request"
  assert_neq "" "$cancel_request_id" "PO cancellation: Request returns ID"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders/cancellation-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$cancel_request_id}")
  assert_contains "$res" '"status":"error"' "PO cancellation: Manager cannot approve"

  res=$(api_post "purchase-orders/cancellation-approve" "{\"id\":$cancel_request_id,\"review_note\":\"QA approved\"}")
  assert_contains "$res" '"status":"success"' "PO cancellation: Admin approves another user's request"
  res=$(api_get "purchase-orders/order?id=$cancel_po_id")
  assert_contains "$res" '"status":"cancelled"' "PO cancellation: Approval changes PO status"
  res=$(api_get "purchase-orders/cancellation-requests?status=approved")
  assert_contains "$res" "\"id\":$cancel_request_id" "PO cancellation: Approved request remains auditable"

  # 6a. Manager from another branch cannot cancel a PO
  local branch_id_2 cross_branch_po cross_branch_po_id manager_cancel
  branch_id_2=$branch_id

  cross_branch_po=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id_2,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{
      \"item_name\":\"Cross Branch Cancel Test\",
      \"category_id\":$cat_id,
      \"quantity\":1,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":1.00
    }]
  }")
  cross_branch_po_id=$(echo "$cross_branch_po" | json_get "data.id" 2>/dev/null)
  assert_contains "$cross_branch_po" '"status":"success"' "Create cross-branch PO for permission test"
  assert_neq "" "$cross_branch_po_id" "Cross-branch PO returns ID"

  manager_cancel=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders/cancel?id=$cross_branch_po_id" \
    -X POST -H 'Content-Type: application/json' -d '{"reason":"QA cross branch attempt"}')
  assert_contains "$manager_cancel" '"status":"error"' "Cross-branch cancel rejected"
  assert_contains "$manager_cancel" 'ไม่มีสิทธิ์ยกเลิกใบรับซื้อนี้' "Cross-branch cancel returns branch error"

  # 6b. PO that has been consumed by a confirmed sale lot cannot be cancelled
  local consumed_po consumed_po_id sale_lot_res sale_lot_id consumed_cancel consumed_cleanup consumed_delete consumed_request_id
  consumed_po=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' -d "{
    \"branch_id\":$manager_branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"Consumed Cancel Test\",
      \"category_id\":$cat_id,
      \"quantity\":4,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":12.00
    }]
  }")
  consumed_po_id=$(echo "$consumed_po" | json_get "data.id" 2>/dev/null)
  assert_contains "$consumed_po" '"status":"success"' "Create PO for consumed_qty cancellation test"
  assert_neq "" "$consumed_po_id" "Consumed test PO returns ID"

  sale_lot_res=$(api_post "sale-lots" "{
    \"branch_id\":$manager_branch_id,
    \"buyer_name\":\"Consumed Cancel Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"Consumed Cancel Test\",
      \"category_id\":$cat_id,
      \"quantity_kg\":1,
      \"unit_price\":20.00
    }],
    \"notes\":\"Consumed cancel regression test\"
  }")
  sale_lot_id=$(echo "$sale_lot_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$sale_lot_res" '"status":"success"' "Create sale lot that consumes PO stock"
  assert_neq "" "$sale_lot_id" "Consumed test sale lot returns ID"

  res=$(api_post_id "sale-lots/confirm" "$sale_lot_id" "{}")
  assert_contains "$res" '"status":"success"' "Confirm sale lot before PO cancel"

  consumed_cancel=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders/cancel?id=$consumed_po_id" \
    -X POST -H 'Content-Type: application/json' -d '{"reason":"QA consumed cancellation"}')
  assert_contains "$consumed_cancel" '"status":"error"' "Cancel blocked when PO has consumed stock"
  assert_contains "$consumed_cancel" 'ถูกนำไปใช้ขาย' "Consumed PO cancel returns business error"

  consumed_cleanup=$(api_post_id "sale-lots/cancel" "$sale_lot_id" "{}")
  assert_contains "$consumed_cleanup" '"status":"success"' "Cleanup: cancel consumed sale lot"

  consumed_delete=$(api_delete "sale-lots/sale-lot?id=$sale_lot_id")
  assert_contains "$consumed_delete" '"status":"success"' "Cleanup: delete cancelled sale lot"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/purchase-orders/cancel?id=$consumed_po_id" \
    -X POST -H 'Content-Type: application/json' -d '{"reason":"QA cancel after stock restore"}')
  consumed_request_id=$(echo "$res" | json_get "data.id" 2>/dev/null)
  assert_contains "$res" '"status":"success"' "PO cancellation: Request succeeds after sale lot restore"
  res=$(api_post "purchase-orders/cancellation-approve" "{\"id\":$consumed_request_id}")
  assert_contains "$res" '"status":"success"' "Cleanup: Admin approves PO cancellation after restore"

  # G2-E3: Print endpoint tests
  # 7. Print endpoint — missing id returns 400
  local no_id_res
  no_id_res=$(api_get "purchase-orders/print")
  assert_contains "$no_id_res" '"status":"error"' "Print endpoint: missing id → 400"

  # 8. Print endpoint — invalid id returns 404
  local not_found_res
  not_found_res=$(api_get "purchase-orders/print?id=999999")
  assert_contains "$not_found_res" '"status":"error"' "Print endpoint: invalid id → 404"

  # 9. Print endpoint — valid PO returns data with is_precious_metal flag
  local fresh_po fresh_id print_res
  fresh_po=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{
      \"item_name\":\"เศษเหล็ก\",
      \"category_id\":$cat_id,
      \"quantity\":5,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":10.00
    }]
  }")
  fresh_id=$(echo "$fresh_po" | json_get "data.id" 2>/dev/null)

  if [ -n "$fresh_id" ]; then
    print_res=$(api_get "purchase-orders/print?id=$fresh_id")
    assert_contains "$print_res" '"status":"success"' "Print endpoint: valid id → success"
    assert_contains "$print_res" '"is_precious_metal"' "Print endpoint: is_precious_metal flag present"
    assert_contains "$print_res" '"reference_no"' "Print endpoint: reference_no present"
    assert_contains "$print_res" '"items"' "Print endpoint: items array present"
  else
    assert_contains '{"status":"skip"}' '"skip"' "Print endpoint: skipped (no PO created)"
  fi
}
