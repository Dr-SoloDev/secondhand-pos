# Sale Lots API Tests
test_sale_lots() {
  test_section "Sale Lots"

  local res

  # 1. List sale lots
  res=$(api_get "sale-lots")
  assert_contains "$res" '"status":"success"' "List sale lots"

  extract_id() {
    echo "$1" | json_get "data.id" 2>/dev/null
  }

  # 2. Create sale lot as draft; draft create must not require/consume stock
  local branch_id
  branch_id=$(api_get "branches" | json_get "data.0.id" 2>/dev/null)
  [ -z "$branch_id" ] && branch_id=1

  local suffix catalog_res catalog_id
  suffix="$(date +%s)"
  catalog_res=$(api_post "purchase-catalog" "{\"code\":\"QA-$suffix\",\"name\":\"QA Sale Lot $suffix\",\"category_id\":$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)}")
  catalog_id=$(echo "$catalog_res" | json_get "data.id" 2>/dev/null)
  assert_contains "$catalog_res" '"status":"success"' "Create unique sale-lot QA catalog"
  assert_neq "" "$catalog_id" "Unique sale-lot QA catalog returns ID"

  local cat_id
  cat_id=$(api_get "purchase-catalog/item?id=$catalog_id" | json_get "data.category_id" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1

  local item_name
  item_name=$(api_get "inventory/category-items?category_id=$cat_id&branch_id=$branch_id" | json_get "data.items.0.item_name")
  [ -z "$item_name" ] && item_name="Test Item"

  local lot_id
  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Test Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"catalog_id\":$catalog_id,
      \"item_name\":\"$item_name\",
      \"category_id\":$cat_id,
      \"quantity_kg\":0.001,
      \"unit_price\":30.00
    }],
    \"notes\":\"Test lot\"
  }")
  lot_id=$(extract_id "$res")
  assert_contains "$res" '"status":"success"' "Create draft sale lot"
  assert_neq "" "$lot_id" "Create draft sale lot returns ID"

  if [ -n "$lot_id" ]; then
    res=$(api_get "sale-lots/sale-lot?id=$lot_id")
    assert_contains "$res" '"status":"draft"' "Created sale lot remains draft"
    res=$(api_delete "sale-lots/sale-lot?id=$lot_id")
    assert_contains "$res" '"status":"success"' "Delete draft sale lot"
  fi

  local lot_idempotency_key lot_idempotency_payload lot_idempotency_first
  local lot_idempotency_second lot_idempotency_body lot_idempotency_code
  local lot_idempotency_first_id lot_idempotency_second_id
  lot_idempotency_key="qa-lot-idem-$$-$(date +%s)"
  lot_idempotency_payload="{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"SEC-04 Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"idempotency_key\":\"$lot_idempotency_key\",
    \"items\":[{\"catalog_id\":$catalog_id,\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":0.001,\"unit_price\":1}]
  }"
  lot_idempotency_first=$(api_post "sale-lots" "$lot_idempotency_payload")
  lot_idempotency_first_id=$(echo "$lot_idempotency_first" | json_get "data.id" 2>/dev/null)
  assert_contains "$lot_idempotency_first" '"status":"success"' "SEC-04: First Sale Lot request succeeds"
  lot_idempotency_second=$(curl -s -w $'\n%{http_code}' -b "$COOKIE_JAR" \
    "$API_BASE/sale-lots" -X POST -H 'Content-Type: application/json' \
    -d "$lot_idempotency_payload")
  lot_idempotency_code="${lot_idempotency_second##*$'\n'}"
  lot_idempotency_body="${lot_idempotency_second%$'\n'*}"
  lot_idempotency_second_id=$(echo "$lot_idempotency_body" | json_get "data.id" 2>/dev/null)
  assert_eq "409" "$lot_idempotency_code" "SEC-04: Repeated Sale Lot request is rejected with HTTP 409"
  assert_eq "$lot_idempotency_first_id" "$lot_idempotency_second_id" "SEC-04: Repeated Sale Lot returns the original document"
  assert_contains "$lot_idempotency_body" 'Duplicate request' "SEC-04: Repeated Sale Lot response is explicit"

  # 3. Create sale lot without category_id — rejected
  local fail_res
  fail_res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"No Category Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"catalog_id\":$catalog_id,
      \"item_name\":\"Manual Walk-in Item\",
      \"quantity_kg\":1,
      \"unit_price\":25
    }]
  }")
  assert_contains "$fail_res" '"status":"error"' "Create sale lot without category rejected"

  local negative_price negative_transport negative_expense
  negative_price=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,\"buyer_name\":\"Negative Price\",\"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{\"catalog_id\":$catalog_id,\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":1,\"unit_price\":-1}]
  }")
  assert_contains "$negative_price" '"status":"error"' "Sale lot rejects negative unit price"

  negative_transport=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,\"buyer_name\":\"Negative Transport\",\"sale_date\":\"$(date +%Y-%m-%d)\",\"transport_cost\":-1,
    \"items\":[{\"catalog_id\":$catalog_id,\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":1,\"unit_price\":1}]
  }")
  assert_contains "$negative_transport" '"status":"error"' "Sale lot rejects negative transport cost"

  negative_expense=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,\"buyer_name\":\"Negative Expense\",\"sale_date\":\"$(date +%Y-%m-%d)\",
    \"expenses\":[{\"description\":\"invalid\",\"amount\":-1}],
    \"items\":[{\"catalog_id\":$catalog_id,\"item_name\":\"$item_name\",\"category_id\":$cat_id,\"quantity_kg\":1,\"unit_price\":1}]
  }")
  assert_contains "$negative_expense" '"status":"error"' "Sale lot rejects negative expense"
}
