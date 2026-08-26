#!/bin/bash
# Diagnose cash position baseline state for branch 3
cd ~/projects/scrap-pos
TOKEN=$(curl -s -c - -X POST http://localhost:8080/api/index.php/auth/login \
  -H "Content-Type: application/json" -d '{"username":"admin","password":"admin"}' \
  | grep posToken | awk '{print $NF}')

echo "=== current?branch_id=3 baseline_id field ==="
curl -s "http://localhost:8080/api/index.php/cash-sessions/current?branch_id=3" \
  -H "Authorization: Bearer $TOKEN" | grep -o '"baseline_id":[^,]*'
echo "(empty = endpoint does not return baseline_id)"

echo "=== DB: cash_position_baselines branch 3 ==="
DBPASS=$(grep '^MYSQL_ROOT_PASSWORD=' code/.env | cut -d= -f2)
docker exec scrap-pos-db mysql -u root -p"$DBPASS" pos_system \
  -e "SELECT id,branch_id,effective_date,drawer_balance,reserve_balance FROM cash_position_baselines WHERE branch_id=3 ORDER BY id DESC LIMIT 3;" 2>&1 | grep -v Warning

echo "=== DB: open sessions branch 3 ==="
docker exec scrap-pos-db mysql -u root -p"$DBPASS" pos_system \
  -e "SELECT id,branch_id,status,business_date FROM cash_sessions WHERE branch_id=3 ORDER BY id DESC LIMIT 5;" 2>&1 | grep -v Warning
