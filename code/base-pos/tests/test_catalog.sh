# Purchase Catalog API Tests
test_catalog() {
  test_section "Purchase Catalog"

  local res

  # 1. List catalog
  res=$(api_get "purchase-catalog")
  assert_contains "$res" '"status":"success"' "List catalog"

  # 2. Search catalog
  res=$(api_get "purchase-catalog/search?q=test")
  assert_contains "$res" '"status":"success"' "Search catalog"

  # 3. Create catalog item
  res=$(api_post "purchase-catalog" "{
    \"code\":\"TEST-$(date +%s)\",
    \"name\":\"Test Catalog Item\",
    \"default_unit\":\"kg\",
    \"default_price\":25.00
  }")
  assert_contains "$res" '"status":"success"' "Create catalog item"
}

# Price Tiers Tests
test_price_tiers() {
  test_section "Price Tiers"

  local res

  res=$(api_get "price-tiers")
  assert_contains "$res" '"status":"success"' "Get price tiers"
}
