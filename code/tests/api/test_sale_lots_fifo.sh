# Sale Lots FIFO Flow Tests (confirm → cancel → cost verification)
test_sale_lots_fifo() {
  test_section "Sale Lots FIFO Flow"

  local res branch_id seller_id cat_id po_id lot_id

  # Helper: extract numeric ID from JSON response (handles "id":123 and "id":"123")
  extract_id() { echo "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('id','') if isinstance(d.get('data'),dict) else '');" 2>/dev/null; }

  # Setup: get branch
  branch_id=$(api_get "branches" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  [ -z "$branch_id" ] && branch_id=1

  # Setup: get/create seller
  seller_id=$(api_get "sellers" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  if [ -z "$seller_id" ]; then
    res=$(api_post "sellers" "{\"name\":\"Test Seller FIFO\",\"branch_id\":$branch_id}")
    seller_id=$(extract_id "$res")
  fi
  [ -z "$seller_id" ] && seller_id=1

  # Setup: get category
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

  # 2. Create draft sale lot
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"FIFO Test Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"category_id\":$cat_id,
      \"quantity_kg\":30,
      \"unit_price\":25.00
    }],
    \"notes\":\"FIFO flow test\"
  }")
  lot_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create draft sale lot"
  assert_neq "" "$lot_id" "FIFO: Sale lot has ID"
  [ -z "$lot_id" ] && lot_id=1

  # 3. Confirm sale lot
  res=$(api_post_id "sale-lots/confirm" "$lot_id" "{}")
  assert_contains "$res" '"status":"success"' "FIFO: Confirm sale lot"

  # 4. Verify lot is confirmed
  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"success"' "FIFO: Get lot details"
  assert_contains "$res" '"status":"confirmed"' "FIFO: Lot status is confirmed"
  assert_contains "$res" '"total_cost"' "FIFO: Lot has total_cost"
  assert_contains "$res" '"profit_breakdown"' "FIFO: Lot has profit breakdown"

  # 5. Cancel sale lot
  res=$(api_post_id "sale-lots/cancel" "$lot_id" "{}")
  assert_contains "$res" '"status":"success"' "FIFO: Cancel sale lot"

  # 6. Verify lot is cancelled
  res=$(api_get "sale-lots/sale-lot?id=$lot_id")
  assert_contains "$res" '"status":"cancelled"' "FIFO: Lot status is cancelled"

  # 7. Try to confirm again — should fail (already cancelled)
  res=$(api_post_id "sale-lots/confirm" "$lot_id" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm cancelled lot rejected"

  # 8. Try to confirm non-existent lot
  res=$(api_post_id "sale-lots/confirm" "99999" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm non-existent lot rejected"

  # 9. Create new PO + lot, confirm with insufficient stock
  local po2_id lot2_id
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
  po2_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create PO for low stock test"
  [ -z "$po2_id" ] && po2_id=1

  # Create lot with more qty than any category could possibly have
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Overstock Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"category_id\":$cat_id,
      \"quantity_kg\":999999,
      \"unit_price\":25.00
    }]
  }")
  lot2_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "FIFO: Create overstock draft lot"
  [ -z "$lot2_id" ] && lot2_id=1

  # Confirm should fail (not enough stock)
  res=$(api_post_id "sale-lots/confirm" "$lot2_id" "{}")
  assert_contains "$res" '"status":"error"' "FIFO: Confirm overstock lot rejected"

  # 10. Update draft lot
  res=$(api_put "sale-lots/sale-lot?id=$lot2_id" "{
    \"buyer_name\":\"Updated Buyer Name\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"notes\":\"Updated notes\",
    \"items\":[{
      \"category_id\":$cat_id,
      \"quantity_kg\":5,
      \"unit_price\":30.00
    }]
  }")
  assert_contains "$res" '"status":"success"' "FIFO: Update draft lot"

  # 11. Delete draft lot
  res=$(api_delete "sale-lots/sale-lot?id=$lot2_id")
  assert_contains "$res" '"status":"success"' "FIFO: Delete draft lot"
}
