#!/bin/bash
# Reset branch 3 cash session to a clean state so test_cash_position can run its full cycle.
# Flow: current session (open/pending) -> close + approve -> then baseline re-init is allowed? No —
# baseline already exists (id=1). Test expects: no baseline_id OR reuse. Baseline exists now and
# current() exposes it → "Reuse reserve baseline" should PASS, then it opens a session.
# Blocker: session id 16 stuck open from earlier runs. Close+approve it first.
cd ~/projects/scrap-pos
TOKEN=$(curl -s -c - -X POST http://localhost:8080/api/index.php/auth/login \
  -H "Content-Type: application/json" -d '{"username":"admin","password":"admin"}' \
  | grep posToken | awk '{print $NF}')

echo "=== sessions branch 3 (today) ==="
docker exec scrap-pos-db mysql -u root -p"$(grep '^MYSQL_ROOT_PASSWORD=' code/.env | cut -d= -f2)" pos_system \
  -e "SELECT id,status,business_date FROM cash_sessions WHERE branch_id=3 ORDER BY id DESC LIMIT 3;" 2>/dev/null

echo "=== close stale session ==="
curl -s -X POST "http://localhost:8080/api/index.php/cash-sessions/close" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"branch_id":3,"actual_cash":0,"reason":"reset for tests"}' | head -c 150
echo
SID=$(curl -s "http://localhost:8080/api/index.php/cash-sessions/current?branch_id=3" \
  -H "Authorization: Bearer $TOKEN" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)
echo "session id = $SID"
if [ -n "$SID" ]; then
  echo "=== approve close ==="
  curl -s -X POST "http://localhost:8080/api/index.php/cash-sessions/close-approve" \
    -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
    -d "{\"id\":$SID,\"review_note\":\"test cleanup\"}" | head -c 150
fi
echo
echo "=== state after ==="
curl -s "http://localhost:8080/api/index.php/cash-sessions/current?branch_id=3" \
  -H "Authorization: Bearer $TOKEN" | grep -o '"status":"[a-z_]*"\|"baseline_id":[0-9]*' | head -4
