#!/usr/bin/env python3
"""
Scale Agent — Tiger TI-01 RS232 → HTTP bridge
รันบน Windows 10 หน้าเครื่องชั่งแต่ละสาขา ให้ POS (purchase-orders.js) poll น้ำหนักสดได้

Pattern เดียวกับ print_server_http.py (localhost:9120)
Scale Agent: localhost:9130

Usage:
    python scale_agent.py                    # auto-scan COM1-COM10, 9600 8N1
    python scale_agent.py --port COM3 --baud 9600 --http-port 9130
    python scale_agent.py --mock 12.34      # โหมดจำลอง (เทส UI โดยไม่มีตาชั่งจริง)

Endpoints:
    GET /health  → { status, model, port, connected, last_raw }
    GET /weight  → { weight, stable, unit:"kg", raw, device_id, ts, connected }
    GET /raw     → { raw, ts }  (debug ไว้จูน parser หน้างาน)
    GET /        → health
"""

import argparse
import json
import os
import re
import sys
import time
import threading
from datetime import datetime
from http.server import HTTPServer, BaseHTTPRequestHandler

# ── Config ──
HTTP_HOST = os.environ.get("SCALE_AGENT_HOST", "0.0.0.0")
HTTP_PORT = int(os.environ.get("SCALE_AGENT_PORT", "9130"))
DEFAULT_BAUD = int(os.environ.get("SCALE_BAUD", "9600"))
DEFAULT_MODEL = os.environ.get("SCALE_MODEL", "Tiger TI-01")

# ── Global state (thread-safe via lock) ──
state_lock = threading.Lock()
state = {
    "weight": 0.0,
    "stable": False,
    "raw": "",
    "connected": False,
    "port": "",
    "model": DEFAULT_MODEL,
    "last_update": None,
    "error": None,
}
mock_weight = None

# ── Tiger TI-01 Parser ──
# รุ่นนี้ส่ง continuous ASCII คล้าย XK3190 / CAS:
# ตัวอย่างที่พบบ่อย:
#   "  12.34 kg" / "ST,GS,  12.34kg" / "=  12.34" / "  12.34"
# เรา parse แบบ generic: หาเลขทศนิยมตัวแรกในบรรทัด
# stable: ถ้ามีคำว่า ST / S /  stable flag ใน string หรือน้ำหนักนิ่ง 1.5s

_weight_history = []  # [(ts, weight)]

def parse_weight_line(line: str):
    """Parse raw RS232 line → (weight: float, stable_hint: bool, raw: str)"""
    raw = line.strip()
    if not raw:
        return None

    # Stable hint จาก string (บางรุ่นส่ง ST=stable, US=unstable, GS=gross)
    stable_hint = False
    upper = raw.upper()
    if "ST" in upper or "STABLE" in upper:
        stable_hint = True
    # ถ้ามี "US" / "UNSTABLE" ให้ถือว่าไม่นิ่ง
    if "US" in upper and "ST" not in upper:
        stable_hint = False

    # หาเลขทศนิยม (รองรับ , เป็นจุด)
    # ลบตัวอักษรที่ไม่ใช่เลข/จุด/ลบ/คอมมา ออกก่อน
    # หา pattern เลข
    m = re.search(r"(-?\d+[.,]\d+)|(-?\d+)", raw)
    if not m:
        return None
    num_str = m.group(0).replace(",", ".")
    try:
        w = float(num_str)
    except ValueError:
        return None

    # กรองค่าผิดปกติ (ตาชั่งรับซื้อของเก่า -10..99999 kg, เผื่อ tare ติดลบ)
    if w < -10 or w > 99999:
        return None

    return w, stable_hint, raw

def update_state(weight: float, stable_hint: bool, raw: str):
    global _weight_history
    now = time.time()
    _weight_history.append((now, weight))
    # เก็บ 3 วินาทีล่าสุด
    _weight_history = [(t, v) for t, v in _weight_history if now - t < 3.0]

    # ถ้า stable_hint จากเครื่องบอกว่า stable → ใช้เลย
    # ถ้าไม่มี hint → ดูว่าน้ำหนักนิ่ง (±0.02) มา 1.5 วิ
    stable = stable_hint
    if not stable_hint and len(_weight_history) >= 3:
        recent = [v for t, v in _weight_history if now - t < 1.5]
        if len(recent) >= 3 and max(recent) - min(recent) < 0.02 and weight > 0.01:
            stable = True

    with state_lock:
        state["weight"] = round(weight, 2)
        state["stable"] = stable
        state["raw"] = raw
        state["connected"] = True
        state["last_update"] = datetime.now().isoformat()
        state["error"] = None

# ── Serial Reader Thread ──
def find_tiger_ports():
    """Auto-scan COM1-COM10 (Win) หรือ /dev/ttyUSB* (Linux)"""
    candidates = []
    if sys.platform.startswith("win"):
        candidates = [f"COM{i}" for i in range(1, 11)]
    else:
        import glob
        candidates = glob.glob("/dev/ttyUSB*") + glob.glob("/dev/ttyS*") + [f"/dev/ttyUSB{i}" for i in range(4)]
        if not candidates:
            candidates = ["/dev/ttyUSB0"]
    return candidates

def serial_reader_thread(port: str, baud: int):
    """Thread อ่าน serial ต่อเนื่อง"""
    # Mock mode ไม่ต้องใช้ pyserial
    if mock_weight is not None:
        while True:
            time.sleep(0.5)
            update_state(float(mock_weight), True, f"MOCK {mock_weight} kg")
            with state_lock:
                state["connected"] = True
                state["port"] = "MOCK"

    import serial  # noqa: E402 — import หลัง mock check

    ser = None
    while True:
        try:

            # Try to open port
            if ser is None or not ser.is_open:
                try:
                    ser = serial.Serial(port, baudrate=baud, bytesize=serial.EIGHTBITS, parity=serial.PARITY_NONE, stopbits=serial.STOPBITS_ONE, timeout=1)
                    with state_lock:
                        state["connected"] = True
                        state["port"] = port
                        state["error"] = None
                    print(f"[Scale] Connected {port} @ {baud}")
                except Exception as e:
                    with state_lock:
                        state["connected"] = False
                        state["error"] = f"Cannot open {port}: {e}"
                    time.sleep(2)
                    continue

            line = ser.readline().decode("utf-8", errors="ignore").strip()
            if not line:
                # บางรุ่นส่ง binary — ลองอ่าน byte
                continue
            parsed = parse_weight_line(line)
            if parsed:
                w, stable_hint, raw = parsed
                update_state(w, stable_hint, raw)
            else:
                # เก็บ raw ไว้ debug แม้ parse ไม่ได้
                with state_lock:
                    state["raw"] = line

        except Exception as e:
            with state_lock:
                state["connected"] = False
                state["error"] = str(e)
            print(f"[Scale] Error: {e}", file=sys.stderr)
            if ser:
                try: ser.close()
                except: pass
                ser = None
            time.sleep(2)

def auto_scan_thread(baud: int):
    """Auto-scan หา Tiger ที่ตอบสนอง"""
    import serial
    candidates = find_tiger_ports()
    print(f"[Scale] Auto-scan ports: {candidates}")
    while True:
        # ถ้า connected แล้วไม่ต้อง scan
        with state_lock:
            if state["connected"]:
                time.sleep(3)
                continue
        for port in candidates:
            try:
                ser = serial.Serial(port, baudrate=baud, timeout=1)
                # รอข้อมูล 2 วิ
                start = time.time()
                got = False
                while time.time() - start < 2:
                    line = ser.readline().decode("utf-8", errors="ignore").strip()
                    if line and parse_weight_line(line):
                        got = True
                        break
                ser.close()
                if got:
                    print(f"[Scale] Found Tiger on {port}")
                    # ส่งต่อให้ reader thread หลัก
                    threading.Thread(target=serial_reader_thread, args=(port, baud), daemon=True).start()
                    return
            except Exception:
                continue
        time.sleep(3)

# ── HTTP Handler ──
class ScaleHandler(BaseHTTPRequestHandler):
    def log_message(self, format, *args):
        sys.stderr.write(f"[{datetime.now().strftime('%H:%M:%S')}] {args[0]} {args[1]} {args[2]}\n")

    def _send_json(self, code, body):
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()
        self.wfile.write(json.dumps(body, ensure_ascii=False).encode("utf-8"))

    def do_OPTIONS(self):
        self._send_json(204, {})

    def do_GET(self):
        path = self.path.split("?")[0]
        with state_lock:
            snap = dict(state)

        if path in ("/health", "/", "/status"):
            self._send_json(200, {
                "status": "success" if snap["connected"] else "offline",
                "model": snap["model"],
                "port": snap["port"],
                "connected": snap["connected"],
                "last_raw": snap["raw"],
                "last_update": snap["last_update"],
                "error": snap["error"],
                "message": "Scale Agent Ready" if snap["connected"] else "Scale not connected",
            })
        elif path == "/weight":
            # ถ้าไม่มีข้อมูลเกิน 3 วิ → offline
            stale = False
            if snap["last_update"]:
                try:
                    last = datetime.fromisoformat(snap["last_update"])
                    if (datetime.now() - last).total_seconds() > 3:
                        stale = True
                except: pass
            else:
                stale = not snap["connected"]

            self._send_json(200, {
                "weight": snap["weight"],
                "stable": snap["stable"] and not stale,
                "unit": "kg",
                "raw": snap["raw"],
                "connected": snap["connected"] and not stale,
                "stale": stale,
                "port": snap["port"],
                "model": snap["model"],
                "ts": snap["last_update"] or datetime.now().isoformat(),
            })
        elif path == "/raw":
            self._send_json(200, {"raw": snap["raw"], "ts": snap["last_update"], "connected": snap["connected"]})
        else:
            self._send_json(404, {"status": "error", "message": "Use GET /weight, /health, /raw"})

def main():
    global mock_weight
    parser = argparse.ArgumentParser(description="Tiger TI-01 Scale Agent (RS232 → HTTP)")
    parser.add_argument("--port", type=str, default=os.environ.get("SCALE_PORT", ""), help="Serial port (COM3, /dev/ttyUSB0). Empty = auto-scan")
    parser.add_argument("--baud", type=int, default=DEFAULT_BAUD, help=f"Baud rate (default: {DEFAULT_BAUD})")
    parser.add_argument("--http-port", type=int, default=HTTP_PORT, help=f"HTTP port (default: {HTTP_PORT})")
    parser.add_argument("--http-host", type=str, default=HTTP_HOST, help=f"HTTP host (default: {HTTP_HOST})")
    parser.add_argument("--mock", type=str, default=None, help="Mock weight (e.g. 12.34) — เทส UI โดยไม่มีตาชั่งจริง")
    parser.add_argument("--model", type=str, default=DEFAULT_MODEL, help="Scale model name")
    args = parser.parse_args()

    if args.mock is not None:
        mock_weight = args.mock
        print(f"[Scale] MOCK mode weight={mock_weight} kg")

    with state_lock:
        state["model"] = args.model

    # Start serial/mock thread
    if mock_weight is not None:
        print(f"[Scale] MOCK thread weight={mock_weight} kg (no serial needed)")
        threading.Thread(target=serial_reader_thread, args=("MOCK", args.baud), daemon=True).start()
    elif args.port:
        print(f"[Scale] Using fixed port {args.port} @ {args.baud}")
        threading.Thread(target=serial_reader_thread, args=(args.port, args.baud), daemon=True).start()
    else:
        # Auto-scan (needs pyserial)
        try:
            import serial  # noqa: F401 — check availability
            threading.Thread(target=auto_scan_thread, args=(args.baud,), daemon=True).start()
        except ImportError:
            print("[Scale] pyserial not installed — run: pip install pyserial (mock mode still works)", file=sys.stderr)
            with state_lock:
                state["error"] = "pyserial not installed"

    server = HTTPServer((args.http_host, args.http_port), ScaleHandler)
    print(f"⚖️  Scale Agent Running on http://{args.http_host}:{args.http_port}")
    print(f"   Model: {args.model}")
    print(f"   Health:  GET http://localhost:{args.http_port}/health")
    print(f"   Weight:  GET http://localhost:{args.http_port}/weight")
    print(f"   Raw:     GET http://localhost:{args.http_port}/raw")
    print("   Press Ctrl+C to stop")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n👋 Scale Agent Stopped")
        server.server_close()

if __name__ == "__main__":
    main()
