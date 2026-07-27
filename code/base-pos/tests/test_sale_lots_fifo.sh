# Sale Lots FIFO Flow Tests (create→confirmed immediately, cancel, overstock)
test_sale_lots_fifo() {
  test_section "Sale Lots FIFO Flow"

  local res branch_id seller_id cat_id po_id lot_id

  extract_id() { echo "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('id','') if isinstance(d.get('data'),dict) else '');" 2>/dev/null; }

  branch_id=$(api_get "branches" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  [ -z "$branch_id" ] && branch_id=1

  seller_id=$(api_get "sellers" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  if [ -z "$seller_id" ]; then
    res=$(api_post "sellers" "{\"name\":\"Test Seller FIFO\",\"branch_id\":$branch_id}")
    seller_id=$(extract_id "$res")
  fi
  [ -z "$seller_id" ] && seller_id=1

  cat_id=$(api_get "inventory/categories" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1

  # 1. Create PO to have available stock
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"FIFO Test Item\",
      \"category_id\":$cat_id,
      \"quantity\":100,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":10.00
    }]
  }")
  po_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create PO for stock"
  assert_neq "" "$po_id" "FIFO: PO has ID"
  [ -z "$po_id" ] && po_id=1

  get_fifo_stock() {
    api_get "inventory/category-items?category_id=$cat_id&branch_id=$branch_id" | python3 -c "import sys,json; d=json.load(sys.stdin); items=d.get('data',{}).get('items',[]); print(next((str(it.get('stock_kg')) for it in items if it.get('item_name') == 'FIFO Test Item'), ''))" 2>/dev/null
  }

  local initial_stock
  initial_stock=$(get_fifo_stock)
  assert_neq "" "$initial_stock" "FIFO: Initial stock exists in branch_stock"

  # 2. Create sale lot — now creates as confirmed immediately (no separate confirm step)
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"FIFO Test Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"FIFO Test Item\",
      \"category_id\":$cat_id,
      \"quantity_kg\":30,
      \"unit_price\":25.00
    }],
    \"notes\":\"FIFO flow test\"
  }")
  lot_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create sale lot (auto-confirmed)"
  assert_neq "" "$lot_id" "FIFO: Sale lot has ID"
  [ -z "$lot_id" ] && lot_id=1

  local after_create_stock
  after_create_stock=$(get_fifo_stock)
  assert_neq "$initial_stock" "$after_create_stock" "FIFO: Stock is reduced after sale lot"

  # 3. Verify lot is already confirmed after creation
  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"success"' "FIFO: Get lot details"
  assert_contains "$res" '"status":"confirmed"' "FIFO: Lot status is confirmed immediately"
  assert_contains "$res" '"total_cost"' "FIFO: Lot has total_cost"
  assert_contains "$res" '"profit_breakdown"' "FIFO: Lot has profit breakdown"

  # 4. Cancel confirmed lot
  res=$(api_post_id "sale-lots/cancel" "$lot_id" "{}")
  assert_contains "$res" '"status":"success"' "FIFO: Cancel sale lot"

  local after_cancel_stock
  after_cancel_stock=$(get_fifo_stock)
  assert_eq "$initial_stock" "$after_cancel_stock" "FIFO: Stock restored after cancel"

  # 5. Verify lot is cancelled
  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"cancelled"' "FIFO: Lot status is cancelled"

  # 6. Try to confirm cancelled lot — should fail
  res=$(api_post_id "sale-lots/confirm" "$lot_id" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm cancelled lot rejected"

  # 7. Try to confirm non-existent lot
  res=$(api_post_id "sale-lots/confirm" "99999" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm non-existent lot rejected"

  # 8. Create PO for overstock test
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"Low Stock Test\",
      \"category_id\":$cat_id,
      \"quantity\":10,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":15.00
    }]
  }")
  assert_contains "$res" '"status":"success"' "FIFO: Create PO for low stock test"

  # 9. Create overstock lot — should fail immediately (stock check at create time)
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Overstock Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"Low Stock Test\",
      \"category_id\":$cat_id,
      \"quantity_kg\":999999,
      \"unit_price\":25.00
    }]
  }")
  assert_contains "$res" '"status":"error"' "FIFO: Overstock lot rejected at creation"

  # 10. Delete cancelled lot (clean up)
  res=$(api_delete "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"success"' "FIFO: Delete cancelled lot"
}
