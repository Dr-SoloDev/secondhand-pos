#!/bin/bash
# Test: Reports API

test_reports() {
    echo ""
    echo "Testing: Reports API"

    # Test: Get recent purchases
    echo "  Testing: Get recent purchases..."
    local response=$(api_get "reports/recent-purchases?limit=5")
    # Extract only the FIRST "status" field (API envelope status, not record status)
    local status=$(echo "$response" | grep -o '^{"status":"[^"]*"' | cut -d'"' -f4)

    if [ "$status" = "success" ]; then
        local has_data=$(echo "$response" | grep -o '"data":\[')
        if [ -n "$has_data" ]; then
            test_pass "Get recent purchases returns data array"
        else
            test_fail "Get recent purchases missing data array"
        fi
    else
        test_fail "Get recent purchases - status not success"
        echo "    Response: $(echo "$response" | head -c 200)..."
    fi

    # Test: Get recent purchases with different limit
    response=$(api_get "reports/recent-purchases?limit=2")
    status=$(echo "$response" | grep -o '^{"status":"[^"]*"' | cut -d'"' -f4)
    assert_eq "success" "$status" "Get recent purchases with limit=2"

    # Test: Get recent sale lots
    echo "  Testing: Get recent sale lots..."
    response=$(api_get "reports/recent-sale-lots?limit=5")
    status=$(echo "$response" | grep -o '^{"status":"[^"]*"' | cut -d'"' -f4)
    assert_eq "success" "$status" "Get recent sale lots"

    # Test: Get dashboard stats
    echo "  Testing: Get dashboard stats..."
    response=$(api_get "reports/dashboard-stats")
    status=$(echo "$response" | grep -o '^{"status":"[^"]*"' | cut -d'"' -f4)

    if [ "$status" = "success" ]; then
        test_pass "Get dashboard stats"
    else
        test_fail "Get dashboard stats"
        echo "    Response: $response"
    fi
}
