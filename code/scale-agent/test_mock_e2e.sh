#!/usr/bin/env bash
# Mock E2E — Tiger TI-01 Scale Agent (ไม่ต้องมีตาชั่งจริง)
# ทดสอบ: mock weight → /weight → /health → raw parser
# Usage: bash test_mock_e2e.sh
#        SCALE_PORT=COM3 bash test_mock_e2e.sh  (ต่อตาชั่งจริง)

set -euo pipefail

SCALE_HOST="${SCALE_HOST:-127.0.0.1}"
SCALE_PORT_HTTP="${SCALE_PORT_HTTP:-9130}"
MOCK_WEIGHT="${MOCK_WEIGHT:-12.34}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=================================================="
echo "  Scale Agent — Mock E2E (Tiger TI-01)"
echo "=================================================="
echo "  HTTP: http://$SCALE_HOST:$SCALE_PORT_HTTP"
echo "  Mock: $MOCK_WEIGHT kg"
echo ""

# 1. Start agent in mock mode (background)
echo "[1/5] Starting scale_agent.py --mock $MOCK_WEIGHT ..."
python3 "$SCRIPT_DIR/scale_agent.py" --mock "$MOCK_WEIGHT" --http-port "$SCALE_PORT_HTTP" &
AGENT_PID=$!
trap 'kill $AGENT_PID 2>/dev/null; echo "Stopped agent ($AGENT_PID)"; exit' EXIT INT TERM
sleep 2

# 2. Health
echo "[2/5] GET /health ..."
HEALTH=$(curl -s "http://$SCALE_HOST:$SCALE_PORT_HTTP/health")
echo "  $HEALTH"
if echo "$HEALTH" | grep -q '"connected": true\|"connected":true'; then
  echo "  PASS: health connected"
else
  echo "  FAIL: health not connected"
  echo "  Response: $HEALTH"
  exit 1
fi

# 3. Weight — ต้องได้ MOCK_WEIGHT และ stable=true
echo "[3/5] GET /weight (retry 5x) ..."
for i in 1 2 3 4 5; do
  WEIGHT_JSON=$(curl -s "http://$SCALE_HOST:$SCALE_PORT_HTTP/weight")
  echo "  try $i: $WEIGHT_JSON"
  W=$(echo "$WEIGHT_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('weight',0))" 2>/dev/null || echo "0")
  STABLE=$(echo "$WEIGHT_JSON" | python3 -c "import sys,json; print(json.load(sys.stdin).get('stable',False))" 2>/dev/null || echo "False")
  if python3 -c "import sys; w=float(sys.argv[1]); assert abs(w - float(sys.argv[2])) < 0.01" "$W" "$MOCK_WEIGHT" 2>/dev/null; then
    echo "  PASS: weight=$W kg (expected $MOCK_WEIGHT)"
    if [ "$STABLE" = "True" ] || [ "$STABLE" = "true" ]; then
      echo "  PASS: stable=true"
    else
      echo "  WARN: stable=$STABLE (อาจยังไม่นิ่ง รออีกนิด)"
    fi
    break
  fi
  sleep 0.5
  if [ "$i" -eq 5 ]; then
    echo "  FAIL: weight mismatch (got $W, expected $MOCK_WEIGHT)"
    exit 1
  fi
done

# 4. Raw
echo "[4/5] GET /raw ..."
RAW_JSON=$(curl -s "http://$SCALE_HOST:$SCALE_PORT_HTTP/raw")
echo "  $RAW_JSON"
if echo "$RAW_JSON" | grep -q "MOCK"; then
  echo "  PASS: raw contains MOCK"
else
  echo "  WARN: raw missing MOCK tag"
fi

# 5. Simulate frontend poll (5 times like scale-bridge.js)
echo "[5/5] Simulating scale-bridge.js poll (5x @ 500ms) ..."
for i in 1 2 3 4 5; do
  curl -s "http://$SCALE_HOST:$SCALE_PORT_HTTP/weight" | python3 -c "import sys,json; d=json.load(sys.stdin); print(f\"  poll {sys.argv[1]}: {d.get('weight')} kg stable={d.get('stable')} connected={d.get('connected')}\")" "$i"
  sleep 0.5
done

echo ""
echo "=================================================="
echo "  Mock E2E PASSED — พร้อมต่อ POS จริงแล้ว"
echo "=================================================="
echo "  ลองสร้าง PO ด้วย scale provenance:"
echo "  curl -s http://localhost:8080/api/index.php/scale/health?branch_id=1 | python3 -m json.tool"
echo "  แล้วรัน: bash code/tests/api/run.sh scale"
echo ""
kill $AGENT_PID
trap - EXIT
wait $AGENT_PID 2>/dev/null || true
