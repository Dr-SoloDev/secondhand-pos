# Sale Lots API Tests
test_sale_lots() {
  test_section "Sale Lots"

  local res

  # 1. List sale lots
  res=$(api_get "sale-lots")
  assert_contains "$res" '"status":"success"' "List sale lots"

  # 2. Create sale lot (auto-confirmed immediately)
  local branch_id
  branch_id=$(api_get "branches" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  [ -z "$branch_id" ] && branch_id=1

  local cat_id
  cat_id=$(api_get "inventory/categories" | python3 -c "import sys,json; print(json.load(sys.stdin)['data'][0]['id'])" 2>/dev/null)
  [ -z "$cat_id" ] && cat_id=1

  res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"Test Buyer\",
    \"sale_date\":\"$(date +%Y-%m-%d)\",
    \"items\":[{
      \"category_id\":$cat_id,
      \"quantity_kg\":0.001,
      \"unit_price\":30.00
    }],
    \"notes\":\"Test lot\"
  }")
  assert_contains "$res" '"status":"success"' "Create sale lot"

  # 3. Create sale lot without items — rejected
  local fail_res
  fail_res=$(api_post "sale-lots" "{
    \"branch_id\":$branch_id,
    \"buyer_name\":\"No Items Buyer\",
    \"items\":[]
  }")
  assert_contains "$fail_res" '"status":"error"' "Create sale lot without items rejected"
}
