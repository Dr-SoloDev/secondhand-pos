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

  local cat_id
  cat_id=$(api_get "inventory/categories" | json_get "data.0.id" 2>/dev/null)
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

  # 3. Create sale lot without category_id — rejected
  local fail_res
  fail_res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"No Category Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"item_name\":\"Manual Walk-in Item\",
      \"quantity_kg\":1,
      \"unit_price\":25
    }]
  }")
  assert_contains "$fail_res" '"status":"error"' "Create sale lot without category rejected"
}
