# Stock transfer end-to-end tests
test_stock_transfers() {
  test_section "Stock Transfers"

  local res source_branch=1 destination_branch=2 seller_id cat_id transfer_id
  local item_name suffix manager_cookie
  local source_before destination_before source_after destination_after

  stock_transfer_extract_id() {
    echo "$1" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('data',{}).get('id','') if isinstance(d.get('data'),dict) else '')" 2>/dev/null
  }

  stock_transfer_float_eq() {
    local expected="${1:-0}" actual="${2:-0}" label="${3:-}"
    if python3 -c "import sys; sys.exit(0 if abs(float(sys.argv[1]) - float(sys.argv[2])) < 0.0001 else 1)" "$expected" "$actual" 2>/dev/null; then
      test_pass "$label"
    else
      test_fail "$label"
      echo "    (expected: '$expected', got: '$actual')"
    fi
  }

  stock_transfer_item_stock() {
    local branch_id="$1" item="$2"
    api_get "inventory/category-items?category_id=$cat_id&branch_id=$branch_id" |
      python3 -c "import sys,json; d=json.load(sys.stdin); name=sys.argv[1]; items=d.get('data',{}).get('items',[]); print(next((str(it.get('stock_kg')) for it in items if it.get('item_name') == name), ''))" "$item" 2>/dev/null
  }

  seller_id=$(api_get "sellers" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  if [ -z "$seller_id" ]; then
    res=$(api_post "sellers" "{\"name\":\"Stock Transfer Test Seller\",\"branch_id\":$source_branch}")
    seller_id=$(stock_transfer_extract_id "$res")
  fi
  [ -z "$seller_id" ] && seller_id=1

  cat_id=$(api_get "purchase-catalog/search?q=M01" |
    python3 -c "import sys,json; rows=json.load(sys.stdin).get('data',[]); print(rows[0].get('category_id','') if rows else '')" 2>/dev/null)
  if [ -z "$cat_id" ]; then
    cat_id=$(api_get "inventory/categories" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  fi
  [ -z "$cat_id" ] && cat_id=1

  suffix="$(date +%s)"
  item_name="QA Stock Transfer $suffix"

  # Create 1.5 kg net source stock and verify the transfer uses net quantity.
  res=$(api_post "purchase-orders" "{
    \"branch_id\":$source_branch,
    \"seller_id\":$seller_id,
    \"payment_method\":\"cash\",
    \"items\":[{
      \"item_name\":\"$item_name\",
      \"category_id\":$cat_id,
      \"quantity\":2,
      \"weight_deduction\":0.5,
      \"unit\":\"กก.\",
      \"unit_price\":10.00
    }]
  }")
  assert_contains "$res" '"status":"success"' "Transfer: Create source stock"

  source_before=$(stock_transfer_item_stock "$source_branch" "$item_name")
  destination_before=$(stock_transfer_item_stock "$destination_branch" "$item_name")
  [ -z "$destination_before" ] && destination_before=0
  assert_neq "" "$source_before" "Transfer: Source item appears in branch stock"
  stock_transfer_float_eq "1.5" "$source_before" "Transfer: Source net weight respects deduction"

  res=$(api_post "stock-transfers" "{
    \"from_branch_id\":$source_branch,
    \"to_branch_id\":$destination_branch,
    \"category_id\":$cat_id,
    \"item_name\":\"$item_name\",
    \"weight_kg\":1.0,
    \"note\":\"QA stock transfer $suffix\"
  }")
  transfer_id=$(stock_transfer_extract_id "$res")
  assert_contains "$res" '"status":"success"' "Transfer: Create pending transfer"
  assert_neq "" "$transfer_id" "Transfer: Pending transfer has ID"
  [ -z "$transfer_id" ] && transfer_id=1

  res=$(api_get "stock-transfers?status=pending")
  assert_contains "$res" "\"item_name\":\"$item_name\"" "Transfer: Pending transfer stores item name"

  # The receiving manager must see incoming transfers but may not cancel the sender's document.
  manager_cookie="/tmp/stock_transfer_manager_${suffix}.txt"
  res=$(curl -s -c "$manager_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br02","password":"admin"}')
  assert_contains "$res" '"status":"success"' "Transfer: Destination manager login"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/stock-transfers?status=pending")
  assert_contains "$res" "\"id\":$transfer_id" "Transfer: Destination manager sees incoming transfer"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/stock-transfers/cancel" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":$transfer_id}")
  assert_contains "$res" '"status":"error"' "Transfer: Destination manager cannot cancel incoming transfer"

  res=$(curl -s -b "$manager_cookie" "$API_BASE/stock-transfers/confirm" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":$transfer_id,\"received_weight_kg\":1.0,\"receive_note\":\"QA received\"}")
  assert_contains "$res" '"status":"success"' "Transfer: Destination manager confirms incoming transfer"

  source_after=$(stock_transfer_item_stock "$source_branch" "$item_name")
  destination_after=$(stock_transfer_item_stock "$destination_branch" "$item_name")
  stock_transfer_float_eq "0.5" "$source_after" "Transfer: Confirmation deducts exact source stock"
  stock_transfer_float_eq "1.0" "$destination_after" "Transfer: Confirmation adds exact destination stock"

  res=$(api_post "stock-transfers/confirm" "{\"id\":$transfer_id,\"received_weight_kg\":1.0}")
  assert_contains "$res" '"status":"error"' "Transfer: Confirming the same transfer twice is rejected"
}
