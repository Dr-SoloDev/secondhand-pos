# Sale Lots FIFO Flow Tests (draft create, confirm, cancel, draft update/delete)
test_sale_lots_fifo() {
  test_section "Sale Lots FIFO Draft-first Flow"

  local res branch_id seller_id cat_id po_id lot_id draft_id overstock_id
  local suffix fifo_item low_stock_item

  extract_id() {
    echo "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('id','') if isinstance(d.get('data'),dict) else '')" 2>/dev/null
  }

  calc_float() {
    python3 -c "import sys; print(f'{float(sys.argv[1]) + float(sys.argv[2]):.3f}')" "$1" "$2" 2>/dev/null
  }

  assert_float_eq() {
    local expected="${1:-0}" actual="${2:-0}" label="${3:-}"
    if python3 -c "import sys; sys.exit(0 if abs(float(sys.argv[1]) - float(sys.argv[2])) < 0.0001 else 1)" "$expected" "$actual" 2>/dev/null; then
      test_pass "$label"
    else
      test_fail "$label"
      echo "    (expected: '$expected', got: '$actual')"
    fi
  }

  get_item_stock() {
    local item_name="$1"
    api_get "inventory/category-items?category_id=$cat_id&branch_id=$branch_id" | python3 -c "import sys,json; d=json.load(sys.stdin); name=sys.argv[1]; items=d.get('data',{}).get('items',[]); print(next((str(it.get('stock_kg')) for it in items if it.get('item_name') == name), ''))" "$item_name" 2>/dev/null
  }

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

  suffix="$(date +%s)"
  fifo_item="FIFO Test Item $suffix"
  low_stock_item="Low Stock Test $suffix"

  # 1. Create PO to have available stock
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"$fifo_item\",
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

  local initial_stock
  initial_stock=$(get_item_stock "$fifo_item")
  assert_neq "" "$initial_stock" "FIFO: Initial stock exists in branch_stock"

  # 2. Create sale lot as draft; stock must remain unchanged
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"FIFO Test Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"$fifo_item\",
      \"category_id\":$cat_id,
      \"quantity_kg\":30,
      \"unit_price\":25.00
    }],
    \"notes\":\"FIFO draft flow test\"
  }")
  lot_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create draft sale lot"
  assert_neq "" "$lot_id" "FIFO: Sale lot has ID"
  [ -z "$lot_id" ] && lot_id=1

  local after_create_stock
  after_create_stock=$(get_item_stock "$fifo_item")
  assert_float_eq "$initial_stock" "$after_create_stock" "FIFO: Draft create does not reduce stock"

  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"success"' "FIFO: Get draft lot details"
  assert_contains "$res" '"status":"draft"' "FIFO: Lot status is draft after create"
  assert_contains "$res" '"profit_breakdown"' "FIFO: Draft lot has profit breakdown"

  # 3. Confirm draft lot; confirm is the only path that deducts stock
  res=$(api_post_id "sale-lots/confirm" "$lot_id" "{}")
  assert_contains "$res" '"status":"success"' "FIFO: Confirm draft sale lot"

  local after_confirm_stock expected_after_confirm
  after_confirm_stock=$(get_item_stock "$fifo_item")
  expected_after_confirm=$(calc_float "$initial_stock" "-30")
  assert_float_eq "$expected_after_confirm" "$after_confirm_stock" "FIFO: Confirm reduces stock"

  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"confirmed"' "FIFO: Lot status is confirmed after confirm"
  assert_contains "$res" '"total_cost"' "FIFO: Confirmed lot has total_cost"

  # 4. Cancel confirmed lot; stock is restored exactly once
  res=$(api_post_id "sale-lots/cancel" "$lot_id" "{}")
  assert_contains "$res" '"status":"success"' "FIFO: Cancel confirmed sale lot"

  local after_cancel_stock
  after_cancel_stock=$(get_item_stock "$fifo_item")
  assert_float_eq "$initial_stock" "$after_cancel_stock" "FIFO: Cancel restores stock"

  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"cancelled"' "FIFO: Lot status is cancelled"

  # 5. Draft update/delete must not change stock
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"FIFO Draft Edit Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"$fifo_item\",
      \"category_id\":$cat_id,
      \"quantity_kg\":10,
      \"unit_price\":25.00
    }]
  }")
  draft_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create second draft for update/delete"
  assert_neq "" "$draft_id" "FIFO: Second draft has ID"
  [ -z "$draft_id" ] && draft_id=1

  res=$(api_put "sale-lots/sale-lot?id=$draft_id" "{
    \"buyer_name\":\"FIFO Draft Edit Buyer Updated\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"$fifo_item\",
      \"category_id\":$cat_id,
      \"quantity_kg\":20,
      \"unit_price\":26.00
    }]
  }")
  assert_contains "$res" '"status":"success"' "FIFO: Update draft sale lot"

  local after_update_stock
  after_update_stock=$(get_item_stock "$fifo_item")
  assert_float_eq "$initial_stock" "$after_update_stock" "FIFO: Draft update does not change stock"

  res=$(api_delete "sale-lots/sale-lot?id=$draft_id")
  assert_contains "$res" '"status":"success"' "FIFO: Delete draft sale lot"

  local after_delete_stock
  after_delete_stock=$(get_item_stock "$fifo_item")
  assert_float_eq "$initial_stock" "$after_delete_stock" "FIFO: Draft delete does not change stock"

  # 6. Overstock draft can be saved, but confirm fails and stock remains unchanged
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"$low_stock_item\",
      \"category_id\":$cat_id,
      \"quantity\":10,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":15.00
    }]
  }")
  assert_contains "$res" '"status":"success"' "FIFO: Create PO for low stock test"

  local low_initial_stock
  low_initial_stock=$(get_item_stock "$low_stock_item")
  assert_neq "" "$low_initial_stock" "FIFO: Low stock item exists"

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Overstock Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"$low_stock_item\",
      \"category_id\":$cat_id,
      \"quantity_kg\":999999,
      \"unit_price\":25.00
    }]
  }")
  overstock_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Overstock draft can be saved"
  assert_neq "" "$overstock_id" "FIFO: Overstock draft has ID"
  [ -z "$overstock_id" ] && overstock_id=1

  local low_after_create_stock
  low_after_create_stock=$(get_item_stock "$low_stock_item")
  assert_float_eq "$low_initial_stock" "$low_after_create_stock" "FIFO: Overstock draft create does not change stock"

  res=$(api_post_id "sale-lots/confirm" "$overstock_id" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Overstock confirm rejected"

  local low_after_confirm_stock
  low_after_confirm_stock=$(get_item_stock "$low_stock_item")
  assert_float_eq "$low_initial_stock" "$low_after_confirm_stock" "FIFO: Failed confirm does not change stock"

  res=$(api_delete "sale-lots/sale-lot?id=$overstock_id")
  assert_contains "$res" '"status":"success"' "FIFO: Delete overstock draft"

  # 7. Invalid status transitions remain rejected
  res=$(api_post_id "sale-lots/confirm" "$lot_id" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm cancelled lot rejected"

  res=$(api_post_id "sale-lots/confirm" "99999" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm non-existent lot rejected"

  res=$(api_delete "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"success"' "FIFO: Delete cancelled lot"
}
