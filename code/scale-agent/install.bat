@echo off
REM Install Scale Agent — Tiger TI-01 (Windows 10)
REM รันบน PC หน้าเครื่องชั่งแต่ละสาขา

echo [1/3] Installing dependencies...
pip install -r requirements.txt

echo [2/3] Testing serial ports...
python scale_agent.py --mock 12.34 --http-port 9130
REM กด Ctrl+C เพื่อออก แล้วรันจริง:

echo [3/3] Run Scale Agent (auto-scan):
echo   python scale_agent.py
echo   Health: http://localhost:9130/health
echo   Weight: http://localhost:9130/weight
pause
