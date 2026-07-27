#!/bin/bash
# Test: Reports API

test_reports() {
    test_section "Reports"

    local admin_cookie="$COOKIE_JAR"
    local manager_cookie="/tmp/reports_manager_$$.txt"
    local manager_user_id=""
    local manager_username=""
    local manager_branch=""
    local manager_created=0
    local branch_res="" create_res="" update_res="" login_res="" verify_res=""

    trap 'if [ "${manager_created:-0}" -eq 1 ] && [ -n "${manager_user_id:-}" ]; then COOKIE_JAR="$admin_cookie" api_delete "users/user?id=$manager_user_id" >/dev/null 2>&1 || true; fi; rm -f "$manager_cookie"' RETURN

    branch_res=$(api_get "branches/active")
    manager_branch=$(printf '%s' "$branch_res" | python3 -c "import sys, json
data = json.load(sys.stdin)
items = data.get('data') or []
print(items[0].get('id', '') if items else '')")

    if [ -z "$manager_branch" ]; then
        test_fail "Find active branch for reports"
        return
    fi

    manager_username="reports_mgr_$(date +%s)_$$"
    local manager_email="${manager_username}@example.com"

    create_res=$(api_post "users" "{\"username\":\"$manager_username\",\"password\":\"$TEST_PASS\",\"email\":\"$manager_email\",\"full_name\":\"Reports Manager\",\"role\":\"manager\",\"status\":\"active\"}")
    if ! echo "$create_res" | grep -q '"status":"success"'; then
        test_fail "Create manager account for reports"
        echo "    Response: $create_res"
        return
    fi

    manager_user_id=$(printf '%s' "$create_res" | python3 -c "import sys, json
data = json.load(sys.stdin)
payload = data.get('data') or {}
print(payload.get('id', ''))")
    if [ -z "$manager_user_id" ]; then
        test_fail "Capture manager account id for reports"
        return
    fi

    manager_created=1

    update_res=$(api_put "users/user?id=$manager_user_id" "{\"branch_id\":$manager_branch}")
    if ! echo "$update_res" | grep -q '"status":"success"'; then
        test_fail "Assign branch to manager account for reports"
        echo "    Response: $update_res"
        return
    fi

    login_res=$(curl -s -c "$manager_cookie" "$API_BASE/auth/login" \
        -X POST \
        -H 'Content-Type: application/json' \
        -d "{\"username\":\"$manager_username\",\"password\":\"$TEST_PASS\"}")

    if ! echo "$login_res" | grep -q '"status":"success"'; then
        test_fail "Login as manager for reports"
        echo "    Response: $login_res"
        return
    fi

    COOKIE_JAR="$manager_cookie"

    verify_res=$(api_get "auth/verify")
    assert_contains "$verify_res" '"role":"manager"' "Verify manager role for reports"
    assert_contains "$verify_res" "\"branch_id\":$manager_branch" "Verify manager branch for reports"

    local current_year current_month current_month_num month_start month_end res
    current_year=$(date +%Y)
    current_month=$(date +%-m)
    current_month_num=$((10#$current_month))
    month_start=$(date +%Y-%m-01)
    month_end=$(date -d "$(date +%Y-%m-01) +1 month -1 day" +%Y-%m-%d)

    res=$(api_get "financial/summary?period=month&year=$current_year&month=$current_month_num")
    assert_contains "$res" '"status":"success"' "Branch summary loads"
    assert_contains "$res" "\"branch_id\":$manager_branch" "Branch summary is scoped to manager branch"
    assert_contains "$res" '"total_purchase"' "Branch summary contains purchase total"

    res=$(api_get "purchase-orders?date_from=$month_start&date_to=$month_end&limit=5")
    assert_contains "$res" '"status":"success"' "Purchase orders load for report"
    assert_contains "$res" "\"branch_id\":$manager_branch" "Purchase orders scoped to manager branch"

    res=$(api_get "sale-lots?date_from=$month_start&date_to=$month_end&limit=5")
    assert_contains "$res" '"status":"success"' "Sale lots load for report"
    assert_contains "$res" "\"branch_id\":$manager_branch" "Sale lots scoped to manager branch"

    res=$(api_get "inventory/categories")
    assert_contains "$res" '"status":"success"' "Inventory categories load"
    assert_contains "$res" '"stock_kg"' "Inventory categories include stock"

    res=$(api_get "inventory/stock-alerts")
    assert_contains "$res" '"status":"success"' "Inventory alerts load"
    assert_contains "$res" '"items"' "Inventory alerts include items array"

    res=$(api_get "employees?limit=5")
    assert_contains "$res" '"status":"success"' "Employees load"
    assert_contains "$res" "\"branch_id\":$manager_branch" "Employees scoped to manager branch"
    assert_not_contains "$res" '"branch_id":99999' "Employees ignore cross-branch filter"

    res=$(api_get "reports/tax-report?date_from=$month_start&date_to=$month_end&period=daily")
    assert_contains "$res" '"status":"success"' "Tax report loads"
    assert_contains "$res" "\"branch_id\":$manager_branch" "Tax report is scoped to manager branch"
    assert_contains "$res" '"periods"' "Tax report contains periods"

    COOKIE_JAR="$admin_cookie"

    verify_res=$(api_get "auth/verify")
    assert_contains "$verify_res" '"role":"admin"' "Verify admin role can access reports"

    res=$(api_get "financial/summary?period=month&year=$current_year&month=$current_month_num")
    assert_contains "$res" '"status":"success"' "Admin summary loads"
}
