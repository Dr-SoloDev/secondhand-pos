# Inventory API Tests
test_inventory() {
  test_section "Inventory"

  local res

  # 1. List categories
  res=$(api_get "inventory/categories")
  assert_contains "$res" '"status":"success"' "List categories"
  assert_contains "$res" '"data"' "Categories has data"

  # 2. List products
  res=$(api_get "inventory/products")
  assert_contains "$res" '"status":"success"' "List products"
}
