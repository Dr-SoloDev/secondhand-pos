# Sellers API Tests
test_sellers() {
  test_section "Sellers"

  local res ts suffix unique_id phone
  ts=$(date +%s)
  suffix="$(printf '%05d' $((RANDOM % 100000)))"
  unique_id="0000000000${suffix}0"
  unique_id="${unique_id:0:13}"
  phone="08${ts: -8}"

  # 1. List sellers
  res=$(api_get "sellers")
  assert_contains "$res" '"status":"success"' "List sellers"

  # 2. Search sellers
  res=$(api_get "sellers/search?q=test")
  assert_contains "$res" '"status":"success"' "Search sellers"

  # 3. Create seller (valid 13-digit ID + unique phone)
  res=$(api_post "sellers" "{\"full_name\":\"Test Seller $ts\",\"id_card\":\"$unique_id\",\"phone\":\"$phone\",\"address\":\"Test Address\",\"vehicle_plate\":\"\"}")
  assert_contains "$res" '"status":"success"' "Create seller"
  assert_contains "$res" '"id"' "Create returns id"
  local seller_id
  seller_id=$(echo "$res" | python3 -c "import sys,json; print(json.load(sys.stdin).get('data',{}).get('id',''))" 2>/dev/null)

  # 4. Duplicate ID card rejected
  local dup_res
  dup_res=$(api_post "sellers" "{\"full_name\":\"Duplicate Seller\",\"id_card\":\"$unique_id\",\"phone\":\"09${ts: -7}\"}")
  assert_contains "$dup_res" '"status":"error"' "Duplicate ID card rejected"
  assert_contains "$dup_res" "เลขบัตรประชาชน" "Error message mentions ID card"

  # 4b. Duplicate phone rejected
  local dup_phone new_id
  new_id="${unique_id:0:12}9"
  dup_phone=$(api_post "sellers" "{\"full_name\":\"Duplicate Phone Seller\",\"id_card\":\"$new_id\",\"phone\":\"$phone\"}")
  assert_contains "$dup_phone" '"status":"error"' "Duplicate phone rejected"

  # 5. Invalid ID card (not 13 digits)
  local invalid_res
  invalid_res=$(api_post "sellers" "{\"full_name\":\"Bad ID Seller\",\"id_card\":\"12345\"}")
  assert_contains "$invalid_res" '"status":"error"' "Invalid ID card (not 13 digits) rejected"

  # 6. Seller without name rejected
  local no_name_res
  no_name_res=$(api_post "sellers" "{\"full_name\":\"\"}")
  assert_contains "$no_name_res" '"status":"error"' "Seller without name rejected"

  # 7. Blacklist seller (skip if no seller_id captured)
  if [ -n "$seller_id" ]; then
    local black_res unblack_res list_res
    black_res=$(api_post "sellers/blacklist" "{\"id\":$seller_id,\"reason\":\"Test blacklist\"}")
    assert_contains "$black_res" '"status":"success"' "Blacklist seller"

    # 8. Blacklisted seller hidden from default list
    list_res=$(api_get "sellers")
    assert_not_contains "$list_res" "\"id\":$seller_id" "Blacklisted seller hidden from list"

    # 9. Unblacklist seller
    unblack_res=$(api_post "sellers/unblacklist" "{\"id\":$seller_id}")
    assert_contains "$unblack_res" '"status":"success"' "Unblacklist seller"
  fi
}
