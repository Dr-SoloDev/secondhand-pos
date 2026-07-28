#!/usr/bin/env python3
"""
Print Receipt for Scrap POS — ส่งใบรับซื้อ/ขายไปยัง Deli S420 Thermal Printer
Usage: echo '{"id": 123, "type": "purchase"}' | python3 print_receipt.py
       python3 print_receipt.py --id 123 --type purchase
"""

import json
import sys
import subprocess
import os
import argparse
import urllib.request
import urllib.error
import io
from datetime import datetime

# Try image printing libraries (optional — ใช้เมื่อ encoding cp874 ไม่ได้)
try:
    from PIL import Image, ImageDraw, ImageFont
    HAS_PIL = True
except ImportError:
    HAS_PIL = False

# ── Configuration ──────────────────────────────────────────────────
API_BASE = os.environ.get("PRINT_API_URL", "http://localhost:8080/api/index.php")
PRINTER_NAME = os.environ.get("PRINTER_NAME", "Deli-S420")
W = 32  # ตัวอักษรต่อบรรทัดสำหรับ 58mm thermal (~12cpi)

# ── ESC/POS Commands ───────────────────────────────────────────────
ESC = b'\x1b'
GS = b'\x1d'

INIT = ESC + b'\x40'           # Initialize printer
CUT = GS + b'\x56\x01'         # Full cut (feed + cut)
CUT_PARTIAL = GS + b'\x56\x00' # Partial cut
FEED = ESC + b'\x64'           # Feed n lines e.g. FEED + b'\x04'
ALIGN_LEFT = ESC + b'\x61\x00'
ALIGN_CENTER = ESC + b'\x61\x01'
ALIGN_RIGHT = ESC + b'\x61\x02'
BOLD_ON = ESC + b'\x45\x01'
BOLD_OFF = ESC + b'\x45\x00'
DOUBLE_H = ESC + b'\x21\x10'   # Double height
DOUBLE_W = ESC + b'\x21\x20'   # Double width
FONT_NORMAL = ESC + b'\x21\x00'
UNDERLINE_ON = ESC + b'\x2d\x01'
UNDERLINE_OFF = ESC + b'\x2d\x00'
OPEN_DRAWER = ESC + b'\x70\x00\x19\xfa'
CHAR_SIZE_0 = GS + b'\x21\x00' # Normal size
CHAR_SIZE_1 = GS + b'\x21\x11' # 2x size

# ── Data Fetching ──────────────────────────────────────────────────

def fetch_purchase_receipt(po_id, api_base=None):
    """Fetch purchase order receipt data from API"""
    base = api_base or API_BASE
    url = f"{base}/purchase-orders/print?id={po_id}"
    req = urllib.request.Request(url)
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            if data.get('status') == 'success':
                return data['data']
            raise Exception(data.get('message', 'API returned error'))
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8')[:200]
        raise Exception(f"HTTP {e.code}: {body}")
    except urllib.error.URLError as e:
        raise Exception(f"Connection failed: {e.reason}")


def fetch_sale_receipt(lot_id):
    """Fetch sale lot receipt data — TODO when sale lot print endpoint exists"""
    # Placeholder for future sale lot print
    return None


# ── Receipt Formatting ─────────────────────────────────────────────

def center(text):
    return text.center(W)

def pad_right(text, width=W):
    return str(text).ljust(width)

def fmt_date(datestr):
    """Convert ISO date to 日/月/ปี ไทย format"""
    try:
        d = datetime.fromisoformat(datestr.replace('Z', '+00:00'))
        th_year = d.year + 543
        return d.strftime(f"%d/%m/{th_year} %H:%M")
    except:
        return datestr

def fmt_money(val):
    return f"{float(val or 0):,.2f}"

def mask_id_card(id_card):
    """Mask ID card: 1-XXXX-XXXXX-XX-X"""
    s = ''.join(c for c in str(id_card) if c.isdigit())
    if len(s) < 4:
        return s
    return f"{s[0]}-XXXX-XXXXX-{s[-2:]}-X"


def build_purchase_receipt(po):
    """Build receipt text for purchase order (ใบรับซื้อ)"""
    lines = []

    # ── Header ──
    lines.append("=" * W)
    lines.append(center("รักษ์สะอาดรีไซเคิล"))
    branch_parts = [po.get('branch_name', ''),
                    f"สาขา{po.get('branch_code', '')}" if po.get('branch_code') else '']
    branch_str = ' · '.join(p for p in branch_parts if p)
    if branch_str:
        lines.append(center(branch_str))
    lines.append(center("ใบรับซื้อของเก่า"))
    lines.append("=" * W)
    lines.append("")

    # ── Info ──
    ref = po.get('reference_no', '')
    dt = fmt_date(po.get('created_at', ''))
    lines.append(f" เลขที่     {ref}")
    lines.append(f" วันที่     {dt}")
    lines.append("")

    # ── Seller ──
    seller = po.get('seller_name', '')
    id_card = po.get('seller_id_card', '')
    n = po.get('items', [{}])[0].get('seller_name', '') if po.get('items') else ''
    lines.append(f" ผู้ขาย     {seller}")
    if id_card:
        lines.append(f" บัตร      {mask_id_card(id_card)}")
    lines.append("")

    # ── Items Table ──
    lines.append("-" * W)
    # Header row
    lines.append(f" {'รายการ':<20} {'หัก':>4} {'นน.':>5} {'ราคา':>8}")
    lines.append("-" * W)

    for item in po.get('items', []):
        name = item.get('item_name', '')[:18]
        dq = float(item.get('weight_deduction', 0))
        qty = float(item.get('quantity', 0))
        net = max(0, qty - dq)
        price = float(item.get('total_price', 0))

        deduct_str = f"{dq:.1f}" if dq > 0 else "-"
        lines.append(f" {name:<18} {deduct_str:>4} {net:>5.1f} {fmt_money(price):>8}")

    lines.append("-" * W)

    # ── Total ──
    total = float(po.get('total_amount', 0))
    total_line = f" {'รวมทั้งสิ้น':>{W-15}} ฿{fmt_money(total):>10}"
    lines.append(total_line)
    lines.append("")

    # ── Payment ──
    pay_map = {'cash': 'เงินสด', 'bank': 'โอน', 'qr': 'QR', 'promptpay': 'พร้อมเพย์'}
    pay = pay_map.get(po.get('payment_method', ''), po.get('payment_method', '-'))
    lines.append(f" ชำระ: {pay}")
    lines.append(f" แคชเชียร์: {po.get('user_name', '-')}")
    lines.append("")

    # ── Precious metal section ──
    if po.get('is_precious_metal'):
        lines.append("-" * W)
        lines.append(center("⚠️ สินค้ามีค่า ⚠️"))
        lines.append(center("ข้าพเจ้านำสินค้านี้มาโดยสุจริต"))
        lines.append(center("ลายเซ็นผู้ขาย: _______________"))
        lines.append("-" * W)

    # ── Footer ──
    lines.append("=" * W)
    lines.append(center("บริการดี ราคาดี ตาชั่งมาตรฐาน"))
    phone = po.get('branch_phone', '')
    if phone:
        lines.append(center(f"ติดต่อ: {phone}"))
    lines.append(center("🙏 ขอบคุณที่ใช้บริการ 🙏"))
    lines.append("=" * W)

    return "\n".join(lines)


def build_sale_receipt(lot):
    """Build receipt text for sale lot (ใบขาย) — TODO"""
    return "Sale lot receipt — coming soon"


# ── Printing ───────────────────────────────────────────────────────

def build_escpos_raw(text, encoding='utf-8'):
    """
    Wrap plain text with ESC/POS commands for thermal printing.

    Encoding:
      - 'cp874' (Windows-874/TIS-620) = ภาษาไทย (แนะนำ)
      - 'utf-8' = Unicode (บางรุ่นรองรับ)
      - 'ascii' = ตัดอักษรพิเศษทิ้ง (safe)

    สั่ง codepage switch ก่อนพิมพ์ เพื่อให้ printer รู้จักภาษาไทย
    ESC t n  — Select character code table
      n=10: CP874 (Thai) สำหรับรุ่นที่เข้ากับ Deli/Chinese thermal
      n=13: CP874 (Thai) สำหรับรุ่น Epson/มาตรฐาน POS
    ส่งทั้งสองค่าไปก่อน เผื่อรุ่นไหนไม่รองรับจะ fallback เอง
    """
    # Thai codepage switching — ลองทั้ง 2 ค่า (10 และ 13)
    CP874_1 = ESC + b't\x0a'   # Codepage 10: CP874 Thai (common on Chinese printers)
    CP874_2 = ESC + b't\x0d'   # Codepage 13: CP874 Thai (Epson standard)

    receipt = b""
    receipt += INIT
    receipt += CP874_1          # ลอง CP874 v1
    receipt += CP874_2          # ลอง CP874 v2 (เผื่อ v1 ไม่ support)
    receipt += CHAR_SIZE_0
    receipt += ALIGN_LEFT

    # Encode text ตาม encoding ที่เลือก
    if encoding == 'cp874':
        # CP874 = Windows-874 = TIS-620 (ภาษาไทย)
        receipt += text.encode('cp874', errors='replace')
    elif encoding == 'tis-620':
        receipt += text.encode('tis-620', errors='replace')
    elif encoding == 'utf-8':
        # ส่ง UTF-8 ตรงๆ (printer ต้องรองรับ)
        receipt += text.encode('utf-8', errors='replace')
    else:
        # ASCII fallback
        receipt += text.encode('ascii', errors='replace')

    receipt += b"\n\n\n"  # Feed paper
    receipt += CUT       # Cut paper
    return receipt


def print_raw(data: bytes, printer_name=None):
    """Send raw ESC/POS bytes directly to printer via CUPS"""
    name = printer_name or PRINTER_NAME
    cmd = ['lp', '-d', name, '-o', 'raw']
    proc = subprocess.run(cmd, input=data, capture_output=True, timeout=30)
    if proc.returncode != 0:
        stderr = proc.stderr.decode('utf-8', errors='replace')
        raise Exception(f"Print failed (code {proc.returncode}): {stderr}")
    return proc.stdout.decode('utf-8', errors='replace').strip()


def print_via_cups(data: bytes, raw_mode=True):
    """Send data to printer via CUPS lp command"""
    cmd = ['lp', '-d', PRINTER_NAME]
    if raw_mode:
        cmd.extend(['-o', 'raw'])

    proc = subprocess.run(
        cmd,
        input=data,
        capture_output=True,
        timeout=30
    )

    if proc.returncode != 0:
        stderr = proc.stderr.decode('utf-8', errors='replace')
        raise Exception(f"CUPS print failed (code {proc.returncode}): {stderr}")

    return proc.stdout.decode('utf-8', errors='replace').strip()


def render_receipt_image(po_data, include_stub=True):
    """
    วาดใบเสร็จเป็นรูปภาพด้วย Pillow โดยตรง (ต้นขั้ว 1:1)

    Args:
        po_data: ข้อมูล PO (dict)
        include_stub: แสดงต้นขั้วด้านล่าง (true = แยก 2 ส่วน)

    ขนาด: 384 × ความสูงตามเนื้อหา @ 203dpi (58mm thermal — Deli S420)

    Spec Deli S420:
    - กระดาษกว้าง 58mm (2 นิ้ว)
    - พื้นที่พิมพ์จริง 48mm = 384 dots
    - ใช้ image mode เสมอ (printer ไม่มีฟอนต์ไทย)
    """
    if not HAS_PIL:
        raise ImportError("Pillow not installed")

    W = 384        # กว้าง 48mm @ 203dpi (Deli S420 max print width)
    M = 10         # ขอบซ้ายขวา (~1.25mm)

    # ── ฟอนต์ (ย่อส่วนลงเพราะแคบ) ───────────────────────
    FONT_PATH = '/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf'
    if not os.path.exists(FONT_PATH):
        FONT_PATH = '/usr/share/fonts/truetype/tlwg/Garuda.ttf'
    if not os.path.exists(FONT_PATH):
        FONT_PATH = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'

    f_big   = ImageFont.truetype(FONT_PATH, 20)   # หัวข้อ (28→20)
    f_normal = ImageFont.truetype(FONT_PATH, 15)  # เนื้อหา (20→15)
    f_small  = ImageFont.truetype(FONT_PATH, 12)  # รายละเอียด (17→12)
    f_table = ImageFont.truetype(FONT_PATH, 13)   # ตาราง (18→13)
    f_tiny  = ImageFont.truetype(FONT_PATH, 10)   # ส่วนท้าย (14→10)

    LH = 22   # line height ปกติ (30→22)
    LH_S = 18 # line height เล็ก (24→18)

    # ── helpers ────────────────────────────────────────────
    def tw(text, f=None):
        b = (ImageDraw.Draw(Image.new('1', (1,1))).textbbox((0,0), str(text), font=f or f_normal))
        return b[2] - b[0]

    # สร้าง canvas ชั่วคราวเพื่อวัด
    tmp = Image.new('1', (W, 2000), 1)
    d = ImageDraw.Draw(tmp)

    def tsize(text, f):
        b = d.textbbox((0,0), str(text), font=f)
        return b[2] - b[0], b[3] - b[1]

    # ── ฟังก์ชันวาด ────────────────────────────────────────
    def render_section(draw, po, is_stub=False, y_start=0):
        """วาดใบเสร็จ 1 ส่วน (หลัก หรือ ต้นขั้ว)"""
        y = y_start + 10
        scale = 0.7 if is_stub else 1.0
        lh = int(LH * scale)
        lh_s = int(LH_S * scale)

        # ฟอนต์ตาม scale
        if is_stub:
            f_h = f_small
            f_n = f_tiny
            f_t = f_tiny
            f_tn = f_tiny
        else:
            f_h = f_big
            f_n = f_normal
            f_t = f_table
            f_tn = f_tiny

        def draw_c(text, font, y_pos):
            w, _ = tsize(text, font)
            x = (W - w) // 2
            draw.text((x, y_pos), text, font=font, fill=0)

        def draw_l(text, font, y_pos, x_offset=M):
            draw.text((x_offset, y_pos), text, font=font, fill=0)

        def draw_r(text, font, y_pos):
            w, _ = tsize(text, font)
            draw.text((W - M - w, y_pos), text, font=font, fill=0)

        def draw_hr(y_pos, h=1):
            draw.line([(M, y_pos+2), (W-M, y_pos+2)], fill=0, width=h)

        # ═══ HEADER ═══
        draw_c('รักษ์สะอาดรีไซเคิล', f_h, y); y += lh
        branch = f"{po.get('branch_name','')} สาขา{po.get('branch_code','')}"
        if branch.strip():
            draw_c(branch, f_n, y); y += lh
        draw_c('ใบรับซื้อของเก่า', f_h, y); y += lh + 4
        draw_hr(y, 2); y += 8

        # ═══ INFO ═══
        draw_l(f"เลขที่: {po.get('reference_no','')}", f_n, y); y += lh
        created = str(po.get('created_at',''))[:16]
        draw_l(f"วันที่: {created}", f_n, y); y += lh

        seller = po.get('seller_name','')
        if seller:
            draw_l(f"ผู้ขาย: {seller}", f_n, y); y += lh
        id_card = po.get('seller_id_card','')
        if id_card:
            s = str(id_card)
            masked = s[:1] + '-XXXX-XXXXX-' + s[-2:] + '-X'
            draw_l(f"บัตร: {masked}", f_tn, y); y += lh

        y += 4
        draw_hr(y, 1); y += 6

        # ═══ TABLE HEADER ═══
        draw_l('รายการ', f_t, y, M)
        draw_l('หัก', f_t, y, int(W*0.55))
        draw_l('นน.', f_t, y, int(W*0.63))
        draw_r('ราคา', f_t, y)
        y += lh_s
        draw_hr(y, 1); y += 4

        # ═══ ITEMS ═══
        for item in po.get('items', []):
            name = str(item.get('item_name',''))[:20]
            dq = float(item.get('weight_deduction', 0))
            qty = float(item.get('quantity', 0))
            net = max(0, qty - dq)
            price = float(item.get('total_price', 0))

            draw_l(name, f_t, y, M)
            dq_str = f"{dq:.1f}" if dq > 0 else '-'
            draw_l(dq_str, f_t, y, int(W*0.55))
            draw_l(f"{net:.1f}", f_t, y, int(W*0.63))
            draw_r(f"{price:,.2f}", f_t, y)
            y += lh_s

        y += 2
        draw_hr(y, 1); y += 6

        # ═══ TOTAL ═══
        total = float(po.get('total_amount', 0))
        draw_r(f"รวม ฿{total:,.2f}", f_h, y)
        draw_l('รวมทั้งสิ้น', f_n, y)
        y += lh + 4
        draw_hr(y, 2); y += 8

        # ═══ PAYMENT ═══
        pay_map = {'cash': 'เงินสด', 'bank': 'โอน', 'qr': 'QR', 'promptpay': 'พร้อมเพย์'}
        pay = pay_map.get(po.get('payment_method',''), str(po.get('payment_method','')))
        draw_l(f"ชำระ: {pay}", f_n, y); y += lh
        draw_l(f"แคชเชียร์: {po.get('user_name','-')}", f_tn, y); y += lh

        # ═══ PRECIOUS METAL ═══
        if po.get('is_precious_metal'):
            y += 4
            draw_hr(y, 1); y += 4
            draw_c('⚠️ สินค้ามีค่า ⚠️', f_n, y); y += lh
            draw_c('ข้าพเจ้านำสินค้านี้มาโดยสุจริต', f_tn, y); y += lh
            draw_l('ลายเซ็นผู้ขาย: _________________', f_tn, y); y += lh
            draw_hr(y, 1); y += 4

        # ═══ FOOTER ═══
        y += 4
        draw_c('บริการดี ราคาดี ตาชั่งมาตรฐาน', f_tn, y); y += lh_s
        phone = po.get('branch_phone','')
        if phone:
            draw_c(f"ติดต่อ: {phone}", f_tn, y); y += lh_s
        draw_c('🙏 ขอบคุณที่ใช้บริการ 🙏', f_tn, y); y += lh_s + 6
        draw_hr(y, 2); y += 8

        return y + 10

    # ══════════════════════════════════════════════════════════
    # 1. สร้างภาพแต่ละส่วนแยกกัน
    # ══════════════════════════════════════════════════════════
    # ส่วนหลัก (ใบให้ลูกค้า) - ใช้ canvas ใหญ่พอ
    img_main = Image.new('1', (W, 2000), 1)
    draw_main = ImageDraw.Draw(img_main)
    y1 = render_section(draw_main, po_data, is_stub=False)
    img_main = img_main.crop((0, 0, W, y1 + 10))

    if include_stub:
        # ส่วนต้นขั้ว
        img_stub = Image.new('1', (W, 2000), 1)
        draw_stub = ImageDraw.Draw(img_stub)
        y_stub_end = render_section(draw_stub, po_data, is_stub=True, y_start=0)
        img_stub = img_stub.crop((0, 0, W, y_stub_end + 10))

        # สร้าง gap image (เส้นประ + label) — ปรับให้พอดี 384px
        gap_h = 50
        img_gap = Image.new('1', (W, gap_h), 1)
        draw_gap = ImageDraw.Draw(img_gap)
        dash_count = max(1, (W - 2*M) // 35)
        for i in range(dash_count):
            x_dash = M + (i * 35)
            draw_gap.line([(x_dash, 6), (x_dash + 18, 6)], fill=0, width=2)
        draw_gap.line([(M, 16), (W - M, 16)], fill=0, width=1)
        th_label = 'ต้นขั้วร้าน'
        lw = tsize(th_label, f_tiny)[0]
        draw_gap.text(((W - lw) // 2, 20), th_label, font=f_tiny, fill=0)

        # ต่อภาพ
        combined = Image.new('1', (W, img_main.height + gap_h + img_stub.height), 1)
        combined.paste(img_main, (0, 0))
        combined.paste(img_gap, (0, img_main.height))
        combined.paste(img_stub, (0, img_main.height + gap_h))
        img = combined
    else:
        img = img_main

    # ══════════════════════════════════════════════════════════
    # 2. แปลงเป็น ESC/POS raster
    # ══════════════════════════════════════════════════════════
    pixels = list(img.getdata())
    wb = (W + 7) // 8
    raster = bytearray()
    for row in range(img.height):
        for bc in range(wb):
            bv = 0
            for bit in range(8):
                idx = row * W + bc * 8 + bit
                if idx < len(pixels) and pixels[idx] == 0:
                    bv |= (1 << (7 - bit))
            raster.append(bv)

    escpos = bytearray()
    escpos += INIT
    escpos += b'\x1d\x76\x30\x00'
    escpos += bytes([W & 0xFF, (W >> 8) & 0xFF, img.height & 0xFF, (img.height >> 8) & 0xFF])
    escpos += bytes(raster)
    escpos += b'\n\n'
    escpos += CUT
    return bytes(escpos)


def build_escpos_image(text, font_size=15):
    """
    Render receipt text as monochrome bitmap image → ESC/POS raster format

    ใช้เมื่อ printer ไม่มีฟอนต์ไทย — พิมพ์เป็นภาพแทน (100% ได้ทุกภาษา)

    Specs สำหรับ Deli S420 (58mm × 203dpi):
    - กระดาษกว้าง 58mm (2 นิ้ว)
    - พื้นที่พิมพ์จริง 48mm = 384 dots
    - 1 dot = 0.125mm
    """
    if not HAS_PIL:
        raise ImportError("Pillow not installed. Install with: pip install Pillow")

    # ── ขนาด ──────────────────────────────────────────────────
    # 58mm paper @ 203dpi → ใช้งานจริง 384 dots = 48mm
    # เหลือขอบซ้าย-ขวา ด้านละ ~1.25mm
    WIDTH = 384    # ความกว้างภาพพิกเซล
    MARGIN = 10    # ขอบซ้ายพิกเซล
    RIGHT_MARGIN = 10

    # ── ฟอนต์ ──────────────────────────────────────────────────
    font_paths = [
        '/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf',    # ตัวปกติ
        '/usr/share/fonts/truetype/noto/NotoLoopedThai-Regular.ttf', # ตัวมีหัว
        '/usr/share/fonts/truetype/tlwg/Garuda.ttf',                 # Garuda
        '/usr/share/fonts/truetype/tlwg/Loma.ttf',                   # Loma
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ]
    font_normal = None
    font_bold = None
    for fp in font_paths:
        if os.path.exists(fp):
            font_normal = ImageFont.truetype(fp, font_size)
            try:
                font_bold = ImageFont.truetype(fp.replace('Regular', 'Bold').replace('regular', 'bold'), font_size)
            except:
                font_bold = font_normal
            break
    if font_normal is None:
        font_normal = ImageFont.load_default()
        font_bold = font_normal

    font_small = ImageFont.truetype(font_paths[0] if os.path.exists(font_paths[0]) else font_paths[4], max(16, font_size - 4)) \
        if font_normal != ImageFont.load_default() else font_normal

    # ── จัด layout ──────────────────────────────────────────
    lines = text.split('\n')
    line_h = font_size + 6        # ระยะบรรทัด
    line_h_small = line_h - 2     # สำหรับตัวเล็ก

    # คำนวณความสูงโดยประมาณ
    total_h = 20 + len(lines) * line_h + 30
    total_h = max(total_h, 200)

    # ── สร้าง image ──────────────────────────────────────────
    img = Image.new('1', (WIDTH, total_h), 1)  # 1=white
    draw = ImageDraw.Draw(img)

    def draw_text(text, x, y, font_obj=None, fill=0):
        """วาดข้อความ safely รองรับ Unicode"""
        f = font_obj or font_normal
        try:
            draw.text((x, y), text, font=f, fill=fill)
        except Exception:
            safe = text.encode('ascii', errors='replace').decode('ascii')
            draw.text((x, y), safe, font=f, fill=fill)

    def text_width(text, font_obj=None):
        """วัดความกว้างข้อความ"""
        f = font_obj or font_normal
        bbox = draw.textbbox((0, 0), text, font=f)
        return bbox[2] - bbox[0]

    def draw_center(text, y, font_obj=None):
        """วาดกึ่งกลาง"""
        tw = text_width(text, font_obj)
        x = (WIDTH - tw) // 2
        draw_text(text, x, y, font_obj)

    def draw_line(y, char='='):
        """วาดเส้นคั่น"""
        text = char * 50  # over-width → crop auto
        draw_text(text, MARGIN, y, font_small)

    # ── วาดเนื้อหา ──────────────────────────────────────────
    y = 12

    # Header
    draw_center('รักษ์สะอาดรีไซเคิล', y, font_bold); y += line_h
    draw_center('ใบรับซื้อของเก่า', y, font_bold); y += line_h
    draw_line(y, '='); y += line_h - 2

    # Info
    for line in lines:
        s = line.strip()
        if not s:
            y += line_h // 3
            continue
        if s.startswith('=') or s.startswith('-'):
            draw_line(y, s[0]); y += line_h - 2
            continue

        # ชิดซ้าย
        draw_text(s, MARGIN, y, font_small if len(s) > 35 else font_normal)
        y += line_h

    # ── ตัดภาพให้พอดี ────────────────────────────────────────
    # ใช้ y ที่วาดจริง + เผื่อ
    used_height = min(y + 20, total_h)
    img = img.crop((0, 0, WIDTH, used_height))
    pixels = list(img.getdata())

    # ── แปลงเป็น ESC/POS raster bitmap ────────────────────────
    width_bytes = (WIDTH + 7) // 8
    raster_data = bytearray()

    for row in range(img.height):
        for byte_col in range(width_bytes):
            byte_val = 0
            for bit in range(8):
                idx = row * WIDTH + byte_col * 8 + bit
                if idx < len(pixels) and pixels[idx] == 0:
                    byte_val |= (1 << (7 - bit))
            raster_data.append(byte_val)

    w_low = WIDTH & 0xFF
    w_high = (WIDTH >> 8) & 0xFF
    h_low = img.height & 0xFF
    h_high = (img.height >> 8) & 0xFF

    escpos = bytearray()
    escpos += INIT
    escpos += b'\x1d\x76\x30\x00'          # GS v 0 m=0
    escpos += bytes([w_low, w_high, h_low, h_high])
    escpos += bytes(raster_data)
    escpos += b'\n\n\n'                     # feed
    escpos += CUT                           # ตัดกระดาษ

    return bytes(escpos)


def print_direct(text: str, printer_name=None, encoding='cp874', mode='image'):
    """
    High-level print function: format text → ESC/POS → CUPS → printer

    Args:
        text: ข้อความที่จะพิมพ์
        printer_name: ชื่อ printer ใน CUPS
        encoding: 'cp874' (ไทย), 'utf-8', 'ascii'
        mode: 'image' (default, พิมพ์เป็นภาพ — รองรับทุกภาษา), 'text' (encode ด้วย cp874)
    """
    name = printer_name or PRINTER_NAME

    if mode == 'image':
        # Image mode — ใช้ Pillow render ข้อความเป็นภาพ bitmap
        try:
            raw = build_escpos_image(text)
        except ImportError:
            print(f"[Print] Pillow not available, fallback to text mode", file=sys.stderr)
            raw = build_escpos_raw(text, encoding='utf-8')
    else:
        # Text mode — encode ด้วย cp874
        try:
            raw = build_escpos_raw(text, encoding=encoding)
        except (LookupError, UnicodeEncodeError):
            print(f"[Print] Warning: {encoding} not supported, fallback to utf-8", file=sys.stderr)
            raw = build_escpos_raw(text, encoding='utf-8')

    result = print_via_cups(raw, raw_mode=True)
    return result


# ── Main ────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Scrap POS Thermal Printer')
    parser.add_argument('--id', type=int, help='Receipt ID')
    parser.add_argument('--type', choices=['purchase', 'sale'],
                       default='purchase', help='Receipt type')
    parser.add_argument('--preview', action='store_true',
                       help='Show preview only (no print)')
    parser.add_argument('--test', action='store_true',
                       help='Print test page')
    parser.add_argument('--mode', choices=['image', 'text'], default='image',
                       help='Print mode: image avoids Thai codepage issues (default), text uses CP874')

    args = parser.parse_args()

    # ── Test mode ──
    if args.test:
        test_text = """
==========================================
      *** SCRAP POS - TEST ***
==========================================
          Deli S420 Thermal Printer
          Connected Successfully! ✅
          รักษ์สะอาดรีไซเคิล

     วันที่: """ + datetime.now().strftime("%d/%m/%Y %H:%M") + """

          Printer: """ + PRINTER_NAME + """
==========================================


"""
        if args.preview:
            print(test_text)
            return

        result = print_direct(test_text, mode=args.mode)
        print(f"✅ Test page sent: {result}")
        return

    # ── Regular print ──
    receipt_id = args.id or json.loads(sys.stdin.read())['id']
    receipt_type = args.type

    # Fetch data
    if receipt_type == 'purchase':
        data = fetch_purchase_receipt(receipt_id)
    else:
        data = fetch_sale_receipt(receipt_id)

    # Build receipt
    if receipt_type == 'purchase':
        text = build_purchase_receipt(data)
    else:
        text = build_sale_receipt(data)

    if args.preview:
        print(text)
        return

    # Print
    result = print_direct(text, mode=args.mode)
    print(f"✅ Printed {receipt_type} #{receipt_id}: {result}")


if __name__ == '__main__':
    main()
