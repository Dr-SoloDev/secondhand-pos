# Branches API Tests
test_branches() {
  test_section "Branches"

  local res

  # 1. List all branches
  res=$(api_get "branches")
  assert_contains "$res" '"status":"success"' "List all branches"
  assert_contains "$res" '"data"' "Branches has data array"

  # 2. Get active branches
  res=$(api_get "branches/active")
  assert_contains "$res" '"status":"success"' "Get active branches"
}
