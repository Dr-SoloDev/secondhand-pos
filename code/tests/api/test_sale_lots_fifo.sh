# Sale Lots FIFO Flow Tests (draft create, confirm, cancel, draft update/delete)
test_sale_lots_fifo() {
  test_section "Sale Lots FIFO Draft-first Flow"

  ensure_all_cash_sessions_open

  local res branch_id seller_id cat_id po_id lot_id draft_id overstock_id
  local suffix fifo_item low_stock_item deducted_item

  extract_id() {
    echo "$1" | json_get "data.id" 2>/dev/null
  }

  calc_float() {
    float_add "$1" "$2"
  }

  assert_float_eq() {
    local expected="${1:-0}" actual="${2:-0}" label="${3:-}"
    if float_eq "$expected" "$actual"; then
      test_pass "$label"
    else
      test_fail "$label"
      echo "    (expected: '$expected', got: '$actual')"
    fi
  }

  get_item_stock() {
    local item_name="$1"
    api_get "inventory/category-items?category_id=$cat_id&branch_id=$branch_id" | json_find "data.items" "item_name" "$item_name" "stock_kg" 2>/dev/null
  }

  branch_id=$(api_get "branches" | json_get "data.0.id" 2>/dev/null)
  [ -z "$branch_id" ] && branch_id=1

  seller_id=$(api_get "sellers" | json_get "data.0.id" 2>/dev/null)
  if [ -z "$seller_id" ]; then
    res=$(api_post "sellers" "{\"name\":\"Test Seller FIFO\",\"branch_id\":$branch_id}")
    seller_id=$(extract_id "$res")
  fi
  [ -z "$seller_id" ] && seller_id=1

  cat_id=$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1

  suffix="$(date +%s)"
  fifo_item="FIFO Test Item $suffix"
  low_stock_item="Low Stock Test $suffix"
  deducted_item="Deducted FIFO Test $suffix"

  # 1. Create PO to have available stock
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
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
    \"payment_method\":\"bank_transfer\",
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

  # 8. Deducted weight must never enter FIFO availability or cost.
  local deducted_po_a deducted_po_b deducted_lot deducted_cost deducted_stock
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{
      \"item_name\":\"$deducted_item\",
      \"category_id\":$cat_id,
      \"quantity\":10,
      \"weight_deduction\":4,
      \"unit\":\"kg\",
      \"unit_price\":10.00
    }]
  }")
  deducted_po_a=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "Net FIFO: Create first PO with deducted weight"

  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,
    \"seller_id\":$seller_id,
    \"payment_method\":\"bank_transfer\",
    \"items\":[{
      \"item_name\":\"$deducted_item\",
      \"category_id\":$cat_id,
      \"quantity\":10,
      \"weight_deduction\":0,
      \"unit\":\"kg\",
      \"unit_price\":20.00
    }]
  }")
  deducted_po_b=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "Net FIFO: Create second PO at different cost"

  deducted_stock=$(get_item_stock "$deducted_item")
  assert_float_eq "16.000" "$deducted_stock" "Net FIFO: Stock contains only net purchased weight"

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Net FIFO Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"$deducted_item\",
      \"category_id\":$cat_id,
      \"quantity_kg\":8,
      \"unit_price\":30.00
    }]
  }")
  deducted_lot=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "Net FIFO: Create sale lot across two net batches"

  res=$(api_post_id "sale-lots/confirm" "$deducted_lot" "{}")
  assert_contains "$res" '"status":"success"' "Net FIFO: Confirm sale lot"

  res=$(api_get "sale-lots/sale-lot?id=$deducted_lot")
  deducted_cost=$(echo "$res" | json_get "data.total_cost" 2>/dev/null)
  assert_float_eq "100.000" "$deducted_cost" "Net FIFO: Cost uses 6kg@10 plus 2kg@20"

  res=$(api_get "purchase-orders/order?id=$deducted_po_a")
  assert_float_eq "6.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Net FIFO: First PO cannot consume discarded weight"

  res=$(api_get "purchase-orders/order?id=$deducted_po_b")
  assert_float_eq "2.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Net FIFO: Remaining quantity comes from second PO"

  res=$(api_post_id "sale-lots/cancel" "$deducted_lot" "{}")
  assert_contains "$res" '"status":"success"' "Net FIFO: Cleanup sale lot"
  res=$(api_post_id "purchase-orders/cancel" "$deducted_po_a" '{"reason":"QA cleanup"}')
  assert_contains "$res" '"status":"success"' "Net FIFO: Cleanup first PO"
  res=$(api_post_id "purchase-orders/cancel" "$deducted_po_b" '{"reason":"QA cleanup"}')
  assert_contains "$res" '"status":"success"' "Net FIFO: Cleanup second PO"

  # 9. Cancelling an older lot must restore only the PO batches allocated to that lot.
  local allocation_item allocation_po_a allocation_po_b allocation_lot_a allocation_lot_b
  allocation_item="Allocation Restore Test $suffix"
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",
    \"items\":[{\"item_name\":\"$allocation_item\",\"category_id\":$cat_id,\"quantity\":10,\"unit\":\"kg\",\"unit_price\":10}]
  }")
  allocation_po_a=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "Allocation: Create first PO batch"

  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",
    \"items\":[{\"item_name\":\"$allocation_item\",\"category_id\":$cat_id,\"quantity\":10,\"unit\":\"kg\",\"unit_price\":20}]
  }")
  allocation_po_b=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "Allocation: Create second PO batch"

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,\"buyer_name\":\"Allocation Buyer A\",\"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{\"item_name\":\"$allocation_item\",\"category_id\":$cat_id,\"quantity_kg\":6,\"unit_price\":30}]
  }")
  allocation_lot_a=$(extract_id "$res")
  res=$(api_post_id "sale-lots/confirm" "$allocation_lot_a" "{}")
  assert_contains "$res" '"status":"success"' "Allocation: Confirm older lot"

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,\"buyer_name\":\"Allocation Buyer B\",\"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{\"item_name\":\"$allocation_item\",\"category_id\":$cat_id,\"quantity_kg\":6,\"unit_price\":30}]
  }")
  allocation_lot_b=$(extract_id "$res")
  res=$(api_post_id "sale-lots/confirm" "$allocation_lot_b" "{}")
  assert_contains "$res" '"status":"success"' "Allocation: Confirm newer lot"

  res=$(api_post_id "sale-lots/cancel" "$allocation_lot_a" "{}")
  assert_contains "$res" '"status":"success"' "Allocation: Cancel older lot first"
  res=$(api_get "purchase-orders/order?id=$allocation_po_a")
  assert_float_eq "4.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Allocation: Older cancellation preserves newer PO1 consumption"
  res=$(api_get "purchase-orders/order?id=$allocation_po_b")
  assert_float_eq "2.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Allocation: Older cancellation preserves newer PO2 consumption"

  res=$(api_post_id "sale-lots/cancel" "$allocation_lot_b" "{}")
  assert_contains "$res" '"status":"success"' "Allocation: Cancel newer lot"
  res=$(api_get "purchase-orders/order?id=$allocation_po_a")
  assert_float_eq "0.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Allocation: PO1 fully restored"
  res=$(api_get "purchase-orders/order?id=$allocation_po_b")
  assert_float_eq "0.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Allocation: PO2 fully restored"

  # 10. Weighted costing must consume every remaining batch proportionally.
  local original_cost_method weighted_item weighted_po_a weighted_po_b weighted_lot weighted_cost
  original_cost_method=$(api_get "branches/branch?id=$branch_id" | json_get "data.cost_method" 2>/dev/null)
  [ -z "$original_cost_method" ] && original_cost_method="fifo"
  res=$(api_put "branches/branch?id=$branch_id" '{"cost_method":"weighted"}')
  assert_contains "$res" '"status":"success"' "Weighted: Enable weighted cost method"

  weighted_item="Weighted Allocation Test $suffix"
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",
    \"items\":[{\"item_name\":\"$weighted_item\",\"category_id\":$cat_id,\"quantity\":10,\"unit\":\"kg\",\"unit_price\":10}]
  }")
  weighted_po_a=$(extract_id "$res")
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$branch_id,\"seller_id\":$seller_id,\"payment_method\":\"bank_transfer\",
    \"items\":[{\"item_name\":\"$weighted_item\",\"category_id\":$cat_id,\"quantity\":10,\"unit\":\"kg\",\"unit_price\":20}]
  }")
  weighted_po_b=$(extract_id "$res")

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,\"buyer_name\":\"Weighted Buyer\",\"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{\"item_name\":\"$weighted_item\",\"category_id\":$cat_id,\"quantity_kg\":10,\"unit_price\":30}]
  }")
  weighted_lot=$(extract_id "$res")
  res=$(api_post_id "sale-lots/confirm" "$weighted_lot" "{}")
  assert_contains "$res" '"status":"success"' "Weighted: Confirm proportional lot"
  res=$(api_get "sale-lots/sale-lot?id=$weighted_lot")
  weighted_cost=$(echo "$res" | json_get "data.total_cost" 2>/dev/null)
  assert_float_eq "150.000" "$weighted_cost" "Weighted: Cost is 10kg at average 15"
  res=$(api_get "purchase-orders/order?id=$weighted_po_a")
  assert_float_eq "5.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Weighted: First batch consumed proportionally"
  res=$(api_get "purchase-orders/order?id=$weighted_po_b")
  assert_float_eq "5.000" "$(echo "$res" | json_get "data.items.0.consumed_qty" 2>/dev/null)" "Weighted: Second batch consumed proportionally"

  res=$(api_post_id "sale-lots/cancel" "$weighted_lot" "{}")
  assert_contains "$res" '"status":"success"' "Weighted: Cancel restores exact allocations"
  res=$(api_put "branches/branch?id=$branch_id" "{\"cost_method\":\"$original_cost_method\"}")
  assert_contains "$res" '"status":"success"' "Weighted: Restore branch cost method"
}
