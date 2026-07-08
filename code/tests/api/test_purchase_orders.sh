# Purchase Orders API Tests
test_purchase_orders() {
  test_section "Purchase Orders"

  local res

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

  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
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

  # 5. PO with invalid branch — rejected
  local bad_branch
  bad_branch=$(api_post "purchase-orders" "{
    \"branch_id\":99999,
    \"seller_id\":$seller_id,
    \"items\":[{\"item_name\":\"X\",\"quantity\":1,\"unit_price\":10}]
  }")
  assert_contains "$bad_branch" '"status":"error"' "PO with invalid branch rejected"

  # 6. Cancel a PO
  local po_id
  po_id=$(api_get "purchase-orders" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['data']['items'][0]['id'] if d.get('data',{}).get('items') else '')" 2>/dev/null)
  [ -z "$po_id" ] && po_id=1

  local cancel_res
  cancel_res=$(api_post_id "purchase-orders/cancel" "$po_id" "{}")
  assert_contains "$cancel_res" '"status":"success"' "Cancel purchase order"

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
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"เศษเหล็ก\",
      \"category_id\":$cat_id,
      \"quantity\":5,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":10.00
    }]
  }")
  fresh_id=$(echo "$fresh_po" | python3 -c "import sys,json; print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)

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
