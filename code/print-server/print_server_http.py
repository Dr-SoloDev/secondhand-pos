#!/usr/bin/env python3
"""
Print Server HTTP — รับคำสั่งพิมพ์ผ่าน HTTP
ให้ Docker container (PHP) สามารถสั่งพิมพ์เครื่องร้อนผ่าน Host Machine ได้

Usage:
    python3 print_server_http.py          # รันที่ localhost:9120
    python3 print_server_http.py --port 9120
"""

import json
import os
import subprocess
import sys
import argparse
from http.server import HTTPServer, BaseHTTPRequestHandler
from print_receipt import (
    print_direct,
    print_raw,
    fetch_purchase_receipt,
    build_purchase_receipt,
    build_escpos_raw,
    render_receipt_image,
    render_receipt_image_as_png,
)

# ── Config ──
HOST = os.environ.get('PRINT_SERVER_HOST', '0.0.0.0')
PORT = int(os.environ.get('PRINT_SERVER_PORT', '9120'))
PRINTER_NAME = os.environ.get('PRINTER_NAME', 'Deli-S420')


class PrintHandler(BaseHTTPRequestHandler):
    """HTTP handler for print requests"""

    def log_message(self, format, *args):
        """Log with timestamp"""
        from datetime import datetime
        sys.stderr.write(f"[{datetime.now().strftime('%H:%M:%S')}] {args[0]} {args[1]} {args[2]}\n")

    def _send_json(self, status_code, body):
        self.send_response(status_code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()
        self.wfile.write(json.dumps(body, ensure_ascii=False).encode('utf-8'))

    def do_OPTIONS(self):
        """CORS preflight"""
        self._send_json(204, {})

    def do_GET(self):
        """GET /health — health check"""
        if self.path == '/health' or self.path == '/':
            # Test if printer is reachable
            try:
                result = subprocess.run(
                    ['lpstat', '-p', PRINTER_NAME],
                    capture_output=True, timeout=10, text=True
                )
                printer_ok = 'idle' in result.stdout
            except Exception as e:
                printer_ok = False

            self._send_json(200, {
                'status': 'success',
                'printer': PRINTER_NAME,
                'printer_ok': printer_ok,
                'message': 'Print Server Ready'
            })
        else:
            self._send_json(404, {'status': 'error', 'message': 'Not found'})

    def do_POST(self):
        """POST /print — สั่งพิมพ์ใบเสร็จ, POST /preview — แสดงภาพก่อนพิมพ์"""
        is_preview_request = self.path == '/preview'
        if self.path not in ('/print', '/preview'):
            self._send_json(404, {'status': 'error', 'message': 'Use POST /print or /preview'})
            return

        # Read body
        content_length = int(self.headers.get('Content-Length', 0))
        if content_length == 0:
            self._send_json(400, {'status': 'error', 'message': 'No data'})
            return

        body = self.rfile.read(content_length)
        try:
            data = json.loads(body.decode('utf-8'))
        except json.JSONDecodeError:
            self._send_json(400, {'status': 'error', 'message': 'Invalid JSON'})
            return

        receipt_type = data.get('type', 'purchase')
        receipt_id = data.get('id', 0)
        preview = data.get('preview', False)
        po_data = data.get('data')  # Optional: already-formatted receipt data from API
        mode = data.get('mode', 'text')  # 'text' (cp874) or 'image' (bitmap)
        encoding = data.get('encoding', 'cp874')

        if not receipt_id:
            self._send_json(400, {'status': 'error', 'message': 'Missing id'})
            return

        try:
            if is_preview_request:
                if mode == 'image':
                    if not po_data and receipt_type == 'purchase':
                        api_base = os.environ.get('API_BASE', 'http://localhost:8080/api/index.php')
                        po_data = fetch_purchase_receipt(receipt_id, api_base=api_base)
                    if not po_data:
                        self._send_json(400, {'status': 'error', 'message': 'Missing receipt data'})
                        return

                    image_base64 = render_receipt_image_as_png(po_data, include_stub=True)
                    self._send_json(200, {
                        'status': 'success',
                        'image_base64': image_base64,
                        'id': receipt_id,
                        'type': receipt_type
                    })
                    return

                if po_data:
                    text = build_purchase_receipt(po_data)
                    self._send_json(200, {
                        'status': 'success',
                        'preview': text,
                        'lines': len(text.split('\n')),
                        'id': receipt_id,
                        'type': receipt_type
                    })
                    return

                self._send_json(400, {'status': 'error', 'message': 'Missing receipt data'})
                return

            if mode == 'image' and po_data:
                # Image mode: วาดใบเสร็จเป็นรูปภาพ (รองรับทุกภาษา 100%)
                raw_data = render_receipt_image(po_data, include_stub=True)
                result = print_raw(raw_data, printer_name=PRINTER_NAME)

            elif po_data:
                # Text mode: ส่งข้อความไป printer
                text = build_purchase_receipt(po_data)
                if preview:
                    self._send_json(200, {'status': 'success', 'preview': text, 'lines': len(text.split('\n'))})
                    return
                raw_data = build_escpos_raw(text, encoding=encoding)
                result = print_raw(raw_data, printer_name=PRINTER_NAME)

            elif receipt_type == 'purchase':
                api_base = os.environ.get('API_BASE', 'http://localhost:8080/api/index.php')
                po_data = fetch_purchase_receipt(receipt_id, api_base=api_base)
                text = build_purchase_receipt(po_data)
                if preview:
                    self._send_json(200, {'status': 'success', 'preview': text, 'lines': len(text.split('\n'))})
                    return
                result = print_direct(text, printer_name=PRINTER_NAME, mode='image')
            else:
                self._send_json(400, {'status': 'error', 'message': f'Unsupported type: {receipt_type}'})
                return
            self._send_json(200, {
                'status': 'success',
                'message': f'พิมพ์ #{receipt_id} สำเร็จ',
                'id': receipt_id,
                'type': receipt_type,
                'result': result
            })

        except Exception as e:
            self._send_json(500, {
                'status': 'error',
                'message': str(e)
            })


def main():
    parser = argparse.ArgumentParser(description='Scrap POS Print Server (HTTP)')
    parser.add_argument('--port', type=int, default=PORT, help=f'Port (default: {PORT})')
    parser.add_argument('--host', type=str, default=HOST, help=f'Host (default: {HOST})')
    args = parser.parse_args()

    server = HTTPServer((args.host, args.port), PrintHandler)

    print(f"🖨️  Print Server Running on http://{args.host}:{args.port}")
    print(f"   Printer: {PRINTER_NAME}")
    print(f"   Health:  http://localhost:{args.port}/health")
    print(f"   Print:   POST http://localhost:{args.port}/print")
    print(f"   Preview: POST http://localhost:{args.port}/preview")
    print("   Press Ctrl+C to stop")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\n👋 Print Server Stopped")
        server.server_close()


if __name__ == '__main__':
    main()
