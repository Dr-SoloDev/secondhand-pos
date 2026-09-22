#!/bin/bash
# Install Scale Agent — Tiger TI-01 (Linux)
set -e
echo "[1/3] Installing dependencies..."
pip3 install -r requirements.txt
echo "[2/3] Test mock (Ctrl+C to exit)..."
echo "  python3 scale_agent.py --mock 12.34"
echo "[3/3] Run:"
echo "  python3 scale_agent.py --port /dev/ttyUSB0 --baud 9600"
echo "  Health: http://localhost:9130/health"
