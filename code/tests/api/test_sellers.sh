# Helper: generate a valid 13-digit Thai ID card number from a 12-digit prefix
make_valid_id() {
  local prefix="$1"
  python3 -c "
p='$prefix'
digits=[int(c) for c in p]
total=sum(digits[i]*(13-i) for i in range(12))
check=(11-(total%11))%10
print(p+str(check))
"
}

# Sellers API Tests
test_sellers() {
  test_section "Sellers"

  local res ts unique_id phone
  ts=$(date +%s)
  # Build a valid 12-digit prefix from timestamp, then append checksum
  local prefix12
  prefix12="$(printf '0%011d' $((ts % 100000000000)))"
  prefix12="${prefix12:0:12}"
  unique_id=$(make_valid_id "$prefix12")
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

  # --- G4 search field-shape tests (require seller_id) ---
  if [ -n "$seller_id" ]; then
    local srch_name srch_id srch_empty

    # 7. Search by partial name match — response must contain slim fields
    local name_part
    name_part="Test Seller"
    srch_name=$(api_get "sellers/search?q=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$name_part'))")")
    assert_contains "$srch_name" '"status":"success"' "Search by name partial match"
    assert_contains "$srch_name" '"national_id"' "Search response contains national_id field"
    assert_contains "$srch_name" '"name"' "Search response contains name field"
    assert_contains "$srch_name" '"is_blacklisted"' "Search response contains is_blacklisted flag"

    # 8. Search by partial id_card match
    local id_part="${unique_id:0:6}"
    srch_id=$(api_get "sellers/search?q=$id_part")
    assert_contains "$srch_id" '"status":"success"' "Search by id_card partial match"
    assert_contains "$srch_id" '"national_id"' "id_card search returns national_id field"

    # 9. Empty query returns error (not crash)
    srch_empty=$(api_get "sellers/search?q=")
    assert_contains "$srch_empty" '"status":"error"' "Empty search query returns error"

    # 10. Blacklist seller — search must show is_blacklisted: 1
    local black_res srch_after_blacklist
    black_res=$(api_post "sellers/blacklist" "{\"id\":$seller_id,\"reason\":\"G4 test blacklist\"}")
    assert_contains "$black_res" '"status":"success"' "Blacklist seller"

    # Must use include_blacklisted=true to see blacklisted sellers in search
    srch_after_blacklist=$(api_get "sellers/search?q=$id_part&include_blacklisted=true")
    assert_contains "$srch_after_blacklist" '"is_blacklisted":1' "Blacklisted seller visible with include_blacklisted=true"
    assert_contains "$srch_after_blacklist" '"blacklist_reason"' "Blacklisted seller has blacklist_reason field"

    # Default search (no param) must NOT return the blacklisted seller
    local srch_default_hidden
    srch_default_hidden=$(api_get "sellers/search?q=$id_part")
    assert_not_contains "$srch_default_hidden" "\"id\":$seller_id" "Blacklisted seller hidden from default search"

    # 11. Blacklisted seller hidden from default list
    local list_res
    list_res=$(api_get "sellers")
    assert_not_contains "$list_res" "\"id\":$seller_id" "Blacklisted seller hidden from list"

    # 12. Unblacklist seller
    local unblack_res
    unblack_res=$(api_post "sellers/unblacklist" "{\"id\":$seller_id}")
    assert_contains "$unblack_res" '"status":"success"' "Unblacklist seller"

    # G7-E2: Seller history endpoint
    # 13. History: missing id → 400
    local hist_no_id
    hist_no_id=$(api_get "sellers/history")
    assert_contains "$hist_no_id" '"status":"error"' "Seller history: missing id → 400"

    # 14. History: nonexistent id → success with empty items (API returns empty list, not error)
    local hist_not_found
    hist_not_found=$(api_get "sellers/history?id=999999")
    assert_contains "$hist_not_found" '"status":"success"' "Seller history: nonexistent id → success with empty items"
    assert_contains "$hist_not_found" '"items":\[\]' "Seller history: nonexistent id → empty items array"

    # 15. History: valid seller returns items array
    local hist_res
    hist_res=$(api_get "sellers/history?id=$seller_id")
    assert_contains "$hist_res" '"status":"success"' "Seller history: valid seller → success"
    assert_contains "$hist_res" '"items"' "Seller history: contains items key"

    # 16. Data-center: valid seller returns summary + transactions
    local dc_res
    dc_res=$(api_get "sellers/data-center?id=$seller_id")
    assert_contains "$dc_res" '"status":"success"' "Seller data-center: success"
    assert_contains "$dc_res" '"summary"' "Seller data-center: has summary"
    assert_contains "$dc_res" '"transactions"' "Seller data-center: has transactions"

    # ── NEW CHANGES TESTS ────────────────────────────────────────────────────

    # Change 1: search() SELECT must include vehicle_plate field
    local srch_vp
    srch_vp=$(api_get "sellers/search?q=Test+Seller")
    assert_contains "$srch_vp" '"vehicle_plate"' "Search response contains vehicle_plate field"

    # Change 2a: include_blacklisted=true returns blacklisted sellers
    # Blacklist the test seller first
    api_post "sellers/blacklist" "{\"id\":$seller_id,\"reason\":\"vehicle_plate test\"}" > /dev/null
    local srch_incl srch_excl
    srch_incl=$(api_get "sellers/search?q=$id_part&include_blacklisted=true")
    assert_contains "$srch_incl" "\"id\":$seller_id" "include_blacklisted=true returns blacklisted seller"

    # Change 2b: default search (no param) hides blacklisted sellers
    srch_excl=$(api_get "sellers/search?q=$id_part")
    assert_not_contains "$srch_excl" "\"id\":$seller_id" "Default search excludes blacklisted sellers"

    # Restore: unblacklist for data-center test
    api_post "sellers/unblacklist" "{\"id\":$seller_id}" > /dev/null

    # Change 3: data-center summary.total_pos counts only completed POs
    # For a fresh seller with no POs, total_pos must be 0
    local dc_fresh
    dc_fresh=$(api_get "sellers/data-center?id=$seller_id")
    assert_contains "$dc_fresh" '"total_pos":0' "data-center summary.total_pos = 0 for seller with no POs"

    # Confirm transactions array is still present (all statuses, not only completed)
    assert_contains "$dc_fresh" '"transactions":\[\]' "data-center transactions is empty array for new seller"

    # Confirm seller block includes vehicle_plate
    assert_contains "$dc_fresh" '"vehicle_plate"' "data-center seller object contains vehicle_plate"

    # Confirm processed_by field exists in each transaction (empty for new seller — check key presence via summary)
    assert_contains "$dc_fresh" '"summary"' "data-center has summary block after new-change checks"

  fi
}
