# Stock transfer end-to-end tests
test_stock_transfers() {
  test_section "Stock Transfers"

  ensure_all_cash_sessions_open

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

  stock_transfer_item_stock_with_cookie() {
    local cookie="$1" branch_id="$2" category_id="$3" item="$4"
    curl -s -b "$cookie" "$API_BASE/inventory/category-items?category_id=$category_id&branch_id=$branch_id" |
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
    \"payment_method\":\"bank_transfer\",
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

  # Multi-item transfer created by the branch manager. The source branch is
  # taken from the authenticated user; a client-supplied mismatch is rejected.
  local multi_item_one multi_item_two cat_id_two source_cookie destination_cookie
  local multi_transfer_id multi_from_branch multi_item_count multi_line_ids
  local multi_line_one_id multi_line_two_id multi_confirmed_by
  local multi_source_branch=3 multi_destination_branch=4
  multi_item_one="QA Multi Transfer A $suffix"
  multi_item_two="QA Multi Transfer B $suffix"
  cat_id_two=$(api_get "inventory/categories" |
    python3 -c "import sys,json; rows=json.load(sys.stdin).get('data',[]); ids=[str(r.get('id')) for r in rows if str(r.get('id')) != str(sys.argv[1])]; print(ids[0] if ids else '')" "$cat_id" 2>/dev/null)
  [ -z "$cat_id_two" ] && cat_id_two=$cat_id

  source_cookie="/tmp/stock_transfer_source_${suffix}.txt"
  res=$(curl -s -c "$source_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br03","password":"admin"}')
  assert_contains "$res" '"status":"success"' "Transfer: Source manager login"

  res=$(curl -s -b "$source_cookie" "$API_BASE/purchase-orders" \
    -X POST -H 'Content-Type: application/json' \
    -d "{
      \"branch_id\":$multi_source_branch,
      \"seller_id\":$seller_id,
      \"payment_method\":\"bank_transfer\",
      \"items\":[
        {\"item_name\":\"$multi_item_one\",\"category_id\":$cat_id,\"quantity\":1.25,\"weight_deduction\":0,\"unit\":\"กก.\",\"unit_price\":11.00},
        {\"item_name\":\"$multi_item_two\",\"category_id\":$cat_id_two,\"quantity\":0.80,\"weight_deduction\":0,\"unit\":\"กก.\",\"unit_price\":12.00}
      ]
    }")
  assert_contains "$res" '"status":"success"' "Transfer: Create multi-item source stock"

  res=$(curl -s -b "$source_cookie" "$API_BASE/stock-transfers" \
    -X POST -H 'Content-Type: application/json' \
    -d "{
      \"from_branch_id\":$multi_destination_branch,
      \"to_branch_id\":$destination_branch,
      \"items\":[
        {\"category_id\":$cat_id,\"item_name\":\"$multi_item_one\",\"weight_kg\":1.25},
        {\"category_id\":$cat_id_two,\"item_name\":\"$multi_item_two\",\"weight_kg\":0.80}
      ]
    }")
  assert_contains "$res" '"status":"error"' "Transfer: Reject source branch mismatch"

  res=$(curl -s -b "$source_cookie" "$API_BASE/stock-transfers" \
    -X POST -H 'Content-Type: application/json' \
    -d "{
      \"to_branch_id\":$multi_destination_branch,
      \"items\":[
        {\"category_id\":$cat_id,\"item_name\":\"$multi_item_one\",\"weight_kg\":1.25},
        {\"category_id\":$cat_id_two,\"item_name\":\"$multi_item_two\",\"weight_kg\":0.80}
      ],
      \"note\":\"QA multi-item stock transfer $suffix\"
    }")
  multi_transfer_id=$(stock_transfer_extract_id "$res")
  assert_contains "$res" '"status":"success"' "Transfer: Create multi-item pending transfer"
  assert_neq "" "$multi_transfer_id" "Transfer: Multi-item transfer has ID"

  res=$(curl -s -b "$source_cookie" "$API_BASE/stock-transfers?status=pending")
  multi_from_branch=$(echo "$res" | python3 -c "import sys,json; d=json.load(sys.stdin); tid=int(sys.argv[1]); rows=d.get('data',{}).get('items',[]); row=next((r for r in rows if int(r.get('id',0)) == tid), {}); print(row.get('from_branch_id',''))" "$multi_transfer_id" 2>/dev/null)
  multi_item_count=$(echo "$res" | python3 -c "import sys,json; d=json.load(sys.stdin); tid=int(sys.argv[1]); rows=d.get('data',{}).get('items',[]); row=next((r for r in rows if int(r.get('id',0)) == tid), {}); print(len(row.get('items',[])))" "$multi_transfer_id" 2>/dev/null)
  multi_line_ids=$(echo "$res" | python3 -c "import sys,json; d=json.load(sys.stdin); tid=int(sys.argv[1]); rows=d.get('data',{}).get('items',[]); row=next((r for r in rows if int(r.get('id',0)) == tid), {}); print(' '.join(str(i.get('id')) for i in row.get('items',[]) if i.get('id')))" "$multi_transfer_id" 2>/dev/null)
  assert_eq "$multi_source_branch" "$multi_from_branch" "Transfer: Multi-item source is locked to manager branch"
  assert_eq "2" "$multi_item_count" "Transfer: List returns normalized item array"
  assert_neq "" "$multi_line_ids" "Transfer: Multi-item lines have IDs"
  read -r multi_line_one_id multi_line_two_id <<< "$multi_line_ids"

  destination_cookie="/tmp/stock_transfer_destination_multi_${suffix}.txt"
  res=$(curl -s -c "$destination_cookie" "$API_BASE/auth/login" \
    -X POST -H 'Content-Type: application/json' \
    -d '{"username":"manager-br04","password":"admin"}')
  assert_contains "$res" '"status":"success"' "Transfer: Multi-item destination manager login"

  res=$(curl -s -b "$destination_cookie" "$API_BASE/stock-transfers/confirm" \
    -X POST -H 'Content-Type: application/json' \
    -d "{
      \"id\":$multi_transfer_id,
      \"confirmed_by\":1,
      \"receiver_id\":1,
      \"items\":[
        {\"id\":$multi_line_one_id,\"received_weight_kg\":1.25,\"receive_note\":\"Line A received\"},
        {\"id\":$multi_line_two_id,\"received_weight_kg\":0.80,\"receive_note\":\"Line B received\"}
      ],
      \"receive_note\":\"QA multi-item received\"
    }")
  assert_contains "$res" '"status":"success"' "Transfer: Confirm multi-item receipt"

  source_one_after=$(stock_transfer_item_stock_with_cookie "$source_cookie" "$multi_source_branch" "$cat_id" "$multi_item_one")
  source_two_after=$(stock_transfer_item_stock_with_cookie "$source_cookie" "$multi_source_branch" "$cat_id_two" "$multi_item_two")
  destination_one_after=$(stock_transfer_item_stock_with_cookie "$destination_cookie" "$multi_destination_branch" "$cat_id" "$multi_item_one")
  destination_two_after=$(stock_transfer_item_stock_with_cookie "$destination_cookie" "$multi_destination_branch" "$cat_id_two" "$multi_item_two")
  [ -z "$source_one_after" ] && source_one_after=0
  [ -z "$source_two_after" ] && source_two_after=0
  [ -z "$destination_one_after" ] && destination_one_after=0
  [ -z "$destination_two_after" ] && destination_two_after=0
  stock_transfer_float_eq "0" "$source_one_after" "Transfer: Multi-item source line A deducted"
  stock_transfer_float_eq "0" "$source_two_after" "Transfer: Multi-item source line B deducted"
  stock_transfer_float_eq "1.25" "$destination_one_after" "Transfer: Multi-item destination line A added"
  stock_transfer_float_eq "0.8" "$destination_two_after" "Transfer: Multi-item destination line B added"

  res=$(curl -s -b "$destination_cookie" "$API_BASE/stock-transfers?status=confirmed")
  multi_confirmed_by=$(echo "$res" | python3 -c "import sys,json; d=json.load(sys.stdin); tid=int(sys.argv[1]); rows=d.get('data',{}).get('items',[]); row=next((r for r in rows if int(r.get('id',0)) == tid), {}); print(row.get('confirmed_by_name',''))" "$multi_transfer_id" 2>/dev/null)
  assert_eq "ผู้จัดการสาขา 4" "$multi_confirmed_by" "Transfer: Confirmed by authenticated destination manager"

  # Transfer-generated POs are provenance records and cannot be cancelled directly.
  local generated_po_id reversal_id self_reversal_id reversal_source_item_id
  res=$(api_get "purchase-orders?branch_id=$multi_destination_branch&limit=100")
  generated_po_id=$(echo "$res" | python3 -c "import sys,json; d=json.load(sys.stdin); sid=int(sys.argv[1]); rows=d.get('data',{}).get('items',[]); row=next((r for r in rows if r.get('source_type') == 'stock_transfer' and int(r.get('source_id') or 0) == sid), {}); print(row.get('id',''))" "$multi_transfer_id" 2>/dev/null)
  assert_neq "" "$generated_po_id" "Transfer reversal: Generated PO stores transfer provenance"
  res=$(api_post_id "purchase-orders/cancel" "$generated_po_id" '{"reason":"QA forbidden direct transfer PO cancellation"}')
  assert_contains "$res" '"status":"error"' "Transfer reversal: Generated PO cannot be cancelled directly"
  assert_contains "$res" 'โอนสต็อก' "Transfer reversal: Generated PO directs user to reversal workflow"

  # An admin may request a reversal, but the same admin may not approve it.
  res=$(api_get "stock-transfers?status=confirmed")
  reversal_source_item_id=$(echo "$res" | python3 -c "import sys,json; d=json.load(sys.stdin); tid=int(sys.argv[1]); rows=d.get('data',{}).get('items',[]); row=next((r for r in rows if int(r.get('id',0)) == tid), {}); items=row.get('items',[]); print(items[0].get('id','') if items else '')" "$transfer_id" 2>/dev/null)
  res=$(api_post "stock-transfers/reversal-request" "{\"id\":$transfer_id,\"reason\":\"QA admin self approval guard\",\"items\":[{\"source_transfer_item_id\":$reversal_source_item_id,\"weight_kg\":0.25}]}")
  self_reversal_id=$(stock_transfer_extract_id "$res")
  assert_contains "$res" '"status":"success"' "Transfer reversal: Admin can submit request"
  res=$(api_post "stock-transfers/reversal-approve" "{\"id\":$self_reversal_id}")
  assert_contains "$res" '"status":"error"' "Transfer reversal: Requester cannot approve own request"
  res=$(api_post "stock-transfers/confirm" "{\"id\":$self_reversal_id}")
  assert_contains "$res" '"status":"error"' "Transfer reversal: Regular confirm endpoint cannot bypass approval"

  # Destination manager requests a full return; only admin may approve it.
  res=$(curl -s -b "$destination_cookie" "$API_BASE/stock-transfers/reversal-request" \
    -X POST -H 'Content-Type: application/json' \
    -d "{
      \"id\":$multi_transfer_id,
      \"reason\":\"QA return received stock\",
      \"items\":[
        {\"source_transfer_item_id\":$multi_line_one_id,\"weight_kg\":1.25},
        {\"source_transfer_item_id\":$multi_line_two_id,\"weight_kg\":0.80}
      ]
    }")
  reversal_id=$(stock_transfer_extract_id "$res")
  assert_contains "$res" '"status":"success"' "Transfer reversal: Destination manager submits request"
  assert_neq "" "$reversal_id" "Transfer reversal: Request has ID"

  res=$(curl -s -b "$destination_cookie" "$API_BASE/stock-transfers/reversal-approve" \
    -X POST -H 'Content-Type: application/json' -d "{\"id\":$reversal_id}")
  assert_contains "$res" '"status":"error"' "Transfer reversal: Non-admin cannot approve"

  res=$(curl -s -b "$destination_cookie" "$API_BASE/stock-transfers/reversal-request" \
    -X POST -H 'Content-Type: application/json' \
    -d "{\"id\":$multi_transfer_id,\"reason\":\"QA over-return guard\",\"items\":[{\"source_transfer_item_id\":$multi_line_one_id,\"weight_kg\":0.01}]}")
  assert_contains "$res" '"status":"error"' "Transfer reversal: Pending requests reserve the received quantity"

  res=$(api_post "stock-transfers/reversal-approve" "{\"id\":$reversal_id,\"review_note\":\"QA approved return\"}")
  assert_contains "$res" '"status":"success"' "Transfer reversal: Admin approves and executes return"

  source_one_after=$(stock_transfer_item_stock_with_cookie "$source_cookie" "$multi_source_branch" "$cat_id" "$multi_item_one")
  source_two_after=$(stock_transfer_item_stock_with_cookie "$source_cookie" "$multi_source_branch" "$cat_id_two" "$multi_item_two")
  destination_one_after=$(stock_transfer_item_stock_with_cookie "$destination_cookie" "$multi_destination_branch" "$cat_id" "$multi_item_one")
  destination_two_after=$(stock_transfer_item_stock_with_cookie "$destination_cookie" "$multi_destination_branch" "$cat_id_two" "$multi_item_two")
  [ -z "$destination_one_after" ] && destination_one_after=0
  [ -z "$destination_two_after" ] && destination_two_after=0
  stock_transfer_float_eq "1.25" "$source_one_after" "Transfer reversal: Original source stock restored for line A"
  stock_transfer_float_eq "0.8" "$source_two_after" "Transfer reversal: Original source stock restored for line B"
  stock_transfer_float_eq "0" "$destination_one_after" "Transfer reversal: Destination stock removed for line A"
  stock_transfer_float_eq "0" "$destination_two_after" "Transfer reversal: Destination stock removed for line B"

  res=$(api_get "stock-transfers?transfer_type=reversal&approval_status=approved")
  assert_contains "$res" "\"id\":$reversal_id" "Transfer reversal: Approved request remains auditable"
  res=$(api_get "stock-transfers?status=confirmed")
  assert_contains "$res" "\"id\":$multi_transfer_id" "Transfer reversal: Original transfer remains confirmed and immutable"
}
