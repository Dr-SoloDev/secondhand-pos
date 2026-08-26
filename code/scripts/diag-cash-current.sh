#!/bin/bash
# Check what current() actually returns — is baseline_id in the response?
cd ~/projects/scrap-pos
TOKEN=$(curl -s -c - -X POST http://localhost:8080/api/index.php/auth/login \
  -H "Content-Type: application/json" -d '{"username":"admin","password":"admin"}' \
  | grep posToken | awk '{print $NF}')

echo "=== FULL current?branch_id=3 top-level keys ==="
curl -s "http://localhost:8080/api/index.php/cash-sessions/current?branch_id=3" \
  -H "Authorization: Bearer $TOKEN" > /tmp/cash_current.json
head -c 600 /tmp/cash_current.json
echo
echo "=== grep baseline ==="
grep -o 'baseline[a-z_]*' /tmp/cash_current.json | sort -u
echo "(empty = current() does NOT expose baseline_id → test's reuse-check misses → tries initialize → 400)"
