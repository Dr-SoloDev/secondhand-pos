# Sale Lots API Tests
test_sale_lots() {
  test_section "Sale Lots"

  local res

  # 1. List sale lots
  res=$(api_get "sale-lots")
  assert_contains "$res" '"status":"success"' "List sale lots"

  # 2. Create draft sale lot
  local branch_id
  branch_id=$(api_get "branches" | sed 's/.*"id":\([0-9]*\).*/\1/' | head -1)
  [ -z "$branch_id" ] && branch_id=1

  local cat_id
  cat_id=$(api_get "inventory/categories" | sed 's/.*"id":\([0-9]*\).*/\1/' | head -1)
  [ -z "$cat_id" ] && cat_id=1

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Test Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"category_id\":$cat_id,
      \"quantity_kg\":50,
      \"unit_price\":30.00
    }],
    \"notes\":\"Test lot\"
  }")
  assert_contains "$res" '"status":"success"' "Create draft sale lot"

  # 3. Create sale lot without items — rejected
  local fail_res
  fail_res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"No Items Buyer\",
    \"items\":[]
  }")
  assert_contains "$fail_res" '"status":"error"' "Create sale lot without items rejected"
}
