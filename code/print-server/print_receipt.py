#!/usr/bin/env python3
"""
Print Receipt for Scrap POS — ส่งใบรับซื้อ/ขายไปยัง Deli S420 Thermal Printer
Usage: echo '{"id": 123, "type": "purchase"}' | python3 print_receipt.py
       python3 print_receipt.py --id 123 --type purchase
"""

import base64
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
IS_WINDOWS = sys.platform.startswith('win')
API_BASE = os.environ.get("PRINT_API_URL", "http://localhost:8080/api/index.php")
# Default printer name per platform: Windows = EasyPrint driver name, Linux = CUPS queue
PRINTER_NAME = os.environ.get(
    "PRINTER_NAME",
    "EasyPrint ES-8804" if IS_WINDOWS else "Deli-S420"
)
W = 32  # ตัวอักษรต่อบรรทัดสำหรับ 58mm thermal (~12cpi)
DEFAULT_SHOP_NAME = 'รักษ์สะอาดรีไซเคิล'
DEFAULT_RECEIPT_WELCOME_MESSAGE = 'บริการดี ราคาดี ตาชั่งมาตรฐาน'
DEFAULT_RECEIPT_FOOTER = 'ขอบคุณที่ใช้บริการ'
# Garuda includes Thai plus ASCII digits/Latin; some Noto Thai installs do not.
# Windows paths (Leelawadee/Tahoma have Thai glyphs) come first when on Windows.
THAI_FONT_PATHS = [
    # Windows 8+ — Leelawadee designed for Thai; Tahoma as fallback
    r'C:\Windows\Fonts\Leelawadee.ttf',
    r'C:\Windows\Fonts\LeelawadeeUI.ttf',
    r'C:\Windows\Fonts\NotoSansThai-Regular.ttf',
    r'C:\Windows\Fonts\tahoma.ttf',
    r'C:\Windows\Fonts\DejaVuSans.ttf',
    # Linux (tlwg/noto)
    '/usr/share/fonts/truetype/tlwg/Garuda.ttf',
    '/usr/share/fonts/truetype/tlwg/Loma.ttf',
    '/usr/share/fonts/truetype/noto/NotoSansThai-Regular.ttf',
    '/usr/share/fonts/truetype/noto/NotoLoopedThai-Regular.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
    '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
]

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


def receipt_value(data, keys, default=''):
    for key in keys:
        value = data.get(key)
        if value is not None and str(value).strip():
            return str(value).strip()
    return default


def append_centered_lines(lines, text):
    for line in str(text).splitlines():
        line = line.strip()
        if line:
            lines.append(center(line))


def first_existing_font_path():
    for path in THAI_FONT_PATHS:
        if os.path.exists(path):
            return path
    return None


def matching_bold_font_path(regular_path):
    candidates = [
        regular_path.replace('-Regular.ttf', '-Bold.ttf'),
        regular_path.replace('.ttf', '-Bold.ttf'),
        regular_path.replace('Sans.ttf', 'Sans-Bold.ttf'),
    ]
    for path in candidates:
        if path != regular_path and os.path.exists(path):
            return path
    return regular_path


def build_purchase_receipt(po):
    """Build receipt text for purchase order (ใบรับซื้อ)"""
    lines = []
    shop_name = receipt_value(po, ['shop_name', 'store_name'], DEFAULT_SHOP_NAME)
    shop_address = receipt_value(po, ['shop_address', 'store_address'])
    tax_id = receipt_value(po, ['tax_id'])
    shop_phone = receipt_value(po, ['shop_phone', 'store_phone', 'branch_phone'])
    welcome_message = receipt_value(po, ['receipt_welcome_message'], DEFAULT_RECEIPT_WELCOME_MESSAGE)
    receipt_footer = receipt_value(po, ['receipt_footer'], DEFAULT_RECEIPT_FOOTER)

    # ── Header ──
    lines.append("=" * W)
    append_centered_lines(lines, shop_name)
    if shop_address:
        append_centered_lines(lines, shop_address)
    tax_phone = ' · '.join(p for p in [
        f"เลขผู้เสียภาษี {tax_id}" if tax_id else '',
        f"โทร {shop_phone}" if shop_phone else ''
    ] if p)
    if tax_phone:
        lines.append(center(tax_phone))
    if welcome_message:
        append_centered_lines(lines, welcome_message)
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
        lines.append(center("*** สินค้ามีค่า ***"))
        lines.append(center("ข้าพเจ้านำสินค้านี้มาโดยสุจริต"))
        lines.append(center("ลายเซ็นผู้ขาย: _______________"))
        lines.append("-" * W)

    # ── Footer ──
    lines.append("=" * W)
    if receipt_footer:
        append_centered_lines(lines, receipt_footer)
    if shop_phone:
        lines.append(center(f"ติดต่อ: {shop_phone}"))
    lines.append("=" * W)

    return "\n".join(lines)


def build_sale_receipt(lot):
    """Build receipt text for sale lot (ใบขาย) — TODO"""
    return "Sale lot receipt — coming soon"


# ── Printing ───────────────────────────────────────────────────────

def build_escpos_raw(text, encoding='cp874'):
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
    # Epson/Deli ESC/POS Thai table: TIS-620 / CP874 = code page 26.
    # ส่งคำสั่งเดียว เพราะการส่ง 10 แล้ว 13 ทำให้เครื่องใช้ตารางสุดท้ายแทน.
    CP874 = ESC + b't\x1a'

    receipt = b""
    receipt += INIT
    receipt += CP874
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
    """Send data to printer via CUPS lp command (Linux)"""
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


def print_via_windows(data: bytes, raw_mode=True):
    """Send raw ESC/POS bytes to printer via Windows spooler (win32print).

    Uses the installed Windows printer driver ("EasyPrint ES-8804") so no
    CUPS/libusb is needed — works with any USB printer Windows can see.
    """
    try:
        import win32print
    except ImportError:
        raise Exception(
            "pywin32 not installed — run install.bat (Windows setup) first"
        )

    printer_name = PRINTER_NAME

    # Fallback: if configured name not found, pick first local printer that looks like a thermal (POS)
    try:
        available = [p[2] for p in win32print.EnumPrinters(
            win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        )]
    except Exception:
        available = []

    if available and printer_name not in available:
        # Try case-insensitive match
        lower = printer_name.lower()
        match = next((p for p in available if p.lower() == lower), None)
        if match:
            printer_name = match
        else:
            thermal = next((p for p in available if any(
                k in p.lower() for k in ('es-88', 'easyprint', 'thermal', 'pos', 'receipt', '88')
            )), None)
            if thermal:
                printer_name = thermal
            else:
                raise Exception(
                    f"ไม่พบเครื่องพิมพ์ '{printer_name}' ใน Windows\n"
                    f"เครื่องพิมพ์ที่พบ: {available or '(ไม่มีเครื่องพิมพ์)'}\n"
                    f"ตรวจว่า Driver ES-8804 ติดตั้งแล้ว และพิมพ์ Test Page สำเร็จ"
                )

    try:
        handle = win32print.OpenPrinter(printer_name)
    except Exception as e:
        raise Exception(f"เปิดเครื่องพิมพ์ '{printer_name}' ไม่สำเร็จ: {e}")

    try:
        try:
            win32print.StartDocPrinter(handle, 1, ("scrap-pos-thermal", None, "RAW"))
            win32print.StartPagePrinter(handle)
            win32print.WritePrinter(handle, data)
            win32print.EndPagePrinter(handle)
            win32print.EndDocPrinter(handle)
        except Exception as e:
            raise Exception(f"พิมพ์ไม่สำเร็จ (RAW job): {e}")
    finally:
        try:
            win32print.ClosePrinter(handle)
        except Exception:
            pass

    return f"sent to '{printer_name}' via Windows spooler"


def _draw_receipt_pil_image(po_data, include_stub=True):
    """
    วาดใบเสร็จเป็น PIL Image กลางสำหรับพิมพ์จริงและ preview

    Returns:
        PIL.Image object
    """
    if not HAS_PIL:
        raise ImportError("Pillow not installed")

    width = 576    # กว้าง 72mm @ 203dpi (80mm thermal: EasyPrint ES-8804)
    margin = 16    # ขอบซ้ายขวา ~2mm
    content_width = width - (2 * margin)

    font_path = first_existing_font_path()
    if not font_path:
        raise ImportError("No usable Thai font found")
    bold_path = matching_bold_font_path(font_path)
    title_font = ImageFont.truetype(bold_path, 30)
    heading_font = ImageFont.truetype(bold_path, 24)
    body_font = ImageFont.truetype(font_path, 22)
    body_bold_font = ImageFont.truetype(bold_path, 22)
    small_font = ImageFont.truetype(font_path, 19)
    small_bold_font = ImageFont.truetype(bold_path, 19)

    measure_image = Image.new('1', (width, 1), 1)
    measure_draw = ImageDraw.Draw(measure_image)

    def text_width(text, font):
        box = measure_draw.textbbox((0, 0), str(text), font=font)
        return box[2] - box[0]

    def safe_float(value):
        try:
            return float(value or 0)
        except (TypeError, ValueError):
            return 0.0

    def fmt_quantity(value):
        return f"{safe_float(value):,.2f}".rstrip('0').rstrip('.')

    def wrap_text(text, font, max_width):
        """ตัดตามช่องว่างก่อน แล้วค่อยตัดรายตัวเมื่อคำยาวเกินหน้ากระดาษ"""
        text = '' if text is None else str(text).strip()
        if not text:
            return []

        def split_long_word(word):
            chunks = []
            current = ''
            for char in word:
                candidate = current + char
                if current and text_width(candidate, font) > max_width:
                    chunks.append(current)
                    current = char
                else:
                    current = candidate
            if current:
                chunks.append(current)
            return chunks

        lines = []
        for raw_line in text.splitlines():
            raw_line = raw_line.strip()
            if not raw_line:
                continue

            current = ''
            for word in raw_line.split():
                candidate = f"{current} {word}".strip()
                if text_width(candidate, font) <= max_width:
                    current = candidate
                    continue

                if current:
                    lines.append(current)
                    current = ''

                if text_width(word, font) <= max_width:
                    current = word
                else:
                    chunks = split_long_word(word)
                    lines.extend(chunks[:-1])
                    current = chunks[-1]
            if current:
                lines.append(current)
        return lines

    def payment_label(value):
        labels = {
            'cash': 'เงินสด',
            'bank': 'โอนธนาคาร',
            'bank_transfer': 'โอนธนาคาร',
            'transfer': 'โอนธนาคาร',
            'qr': 'QR',
            'promptpay': 'พร้อมเพย์',
        }
        return labels.get(str(value or '').lower(), str(value or '-') or '-')

    def branch_label(po):
        name = receipt_value(po, ['branch_name'])
        code = receipt_value(po, ['branch_code'])
        parts = [name]
        if code:
            parts.append(f"สาขา {code}")
        return ' · '.join(part for part in parts if part)

    def render_section(po, section, draw=None):
        """คำนวณและวาดบิล โดยใช้ทางเดิน layout เดียวกันทั้งสองรอบ"""
        y = 12

        def draw_text(text, x, y_pos, font):
            if draw is not None:
                # stroke_width ทำให้ glyph หนาขึ้นแบบคม (ไม่เบลอแบบ blur/dilate)
                draw.text((x, y_pos), str(text), font=font, fill=0, stroke_width=1, stroke_fill=0)

        def draw_wrapped(text, font, y_pos, line_height, align='left', max_width=content_width):
            lines = wrap_text(text, font, max_width)
            for line in lines:
                line_width = text_width(line, font)
                if align == 'center':
                    x = (width - line_width) // 2
                elif align == 'right':
                    x = width - margin - line_width
                else:
                    x = margin
                draw_text(line, x, y_pos, font)
                y_pos += line_height
            return y_pos

        def draw_rule(y_pos, line_width=1):
            if draw is not None:
                draw.line(
                    [(margin, y_pos + 2), (width - margin, y_pos + 2)],
                    fill=0,
                    width=line_width,
                )
            return y_pos + 8

        def draw_pair(left, right, left_font, right_font, y_pos, line_height):
            """วาดสองฝั่งโดยลดพื้นที่ฝั่งซ้ายตามยอดเงินจริง ป้องกันข้อความชนกัน"""
            left = str(left)
            right = str(right)
            right_width = text_width(right, right_font)
            left_width = content_width - right_width - 12

            if left_width < 100:
                y_pos = draw_wrapped(left, left_font, y_pos, line_height)
                return draw_wrapped(right, right_font, y_pos, line_height, align='right')

            left_lines = wrap_text(left, left_font, left_width) or ['']
            for index, line in enumerate(left_lines):
                draw_text(line, margin, y_pos, left_font)
                if index == 0:
                    draw_text(right, width - margin - right_width, y_pos, right_font)
                y_pos += line_height
            return y_pos

        def draw_footer(footer, y_pos):
            """แยกคำขอบคุณ แบรนด์ และประกาศ เพื่อให้ส่วนท้ายมีลำดับชัดเจน"""
            brand_phrase = 'มีคุณจึงมีเรา รักษ์สะอาดรีไซเคิล'
            if brand_phrase not in footer:
                return draw_wrapped(footer, small_font, y_pos, 25, align='center')

            intro, details = footer.split(brand_phrase, 1)
            intro = intro.strip()
            details = details.strip()

            if intro:
                y_pos = draw_wrapped(intro, small_font, y_pos, 25, align='center')
            y_pos += 3
            y_pos = draw_wrapped('มีคุณจึงมีเรา', heading_font, y_pos, 30, align='center')
            y_pos = draw_wrapped('รักษ์สะอาดรีไซเคิล', heading_font, y_pos, 30, align='center')

            if details:
                y_pos += 5
                for paragraph in details.splitlines():
                    paragraph = paragraph.strip()
                    if not paragraph:
                        continue
                    y_pos = draw_wrapped(paragraph, small_font, y_pos, 25, align='center')
                    y_pos += 2
            return y_pos

        shop_name = receipt_value(po, ['shop_name', 'store_name'], DEFAULT_SHOP_NAME)
        shop_address = receipt_value(po, ['shop_address', 'store_address'])
        shop_phone = receipt_value(po, ['shop_phone', 'store_phone', 'branch_phone'])
        tax_id = receipt_value(po, ['tax_id'])
        reference = receipt_value(po, ['reference_no'], '-')
        created = fmt_date(receipt_value(po, ['created_at'], '-'))
        seller_name = receipt_value(po, ['seller_name'], '-')
        seller_id = receipt_value(po, ['seller_id_card'])
        seller_phone = receipt_value(po, ['seller_phone'])
        seller_address = receipt_value(po, ['seller_address'])
        vehicle_type = receipt_value(po, ['vehicle_type'])
        vehicle_plate = receipt_value(po, ['vehicle_plate'])
        cashier = receipt_value(po, ['user_name'], '-')
        items = po.get('items') or []
        is_precious = bool(po.get('is_precious_metal'))

        if section == 'main':
            y = draw_wrapped(shop_name, title_font, y, 36, align='center')
            if shop_address:
                y = draw_wrapped(shop_address, small_font, y, 25, align='center')
            if tax_id:
                y = draw_wrapped(f"เลขประจำตัวผู้เสียภาษี {tax_id}", small_font, y, 25, align='center')
            if shop_phone:
                y = draw_wrapped(f"โทร {shop_phone}", small_font, y, 25, align='center')

            welcome = receipt_value(
                po,
                ['receipt_welcome_message'],
                DEFAULT_RECEIPT_WELCOME_MESSAGE,
            )
            if welcome:
                y += 2
                y = draw_wrapped(welcome, small_font, y, 25, align='center')

            y += 4
            y = draw_wrapped('ใบรับซื้อของเก่า', title_font, y, 36, align='center')
            branch = branch_label(po)
            if branch:
                y = draw_wrapped(branch, body_font, y, 30, align='center')
            y += 2
            y = draw_rule(y, 2)

            y = draw_wrapped(f"เลขที่: {reference}", body_bold_font, y, 30)
            y = draw_wrapped(f"พนักงาน: {cashier}", body_font, y, 30)
            y = draw_wrapped(f"วันที่: {created}", body_font, y, 30)
            y = draw_wrapped(f"ผู้ขาย: {seller_name}", body_bold_font, y, 30)
            if seller_id:
                y = draw_wrapped(f"บัตรประชาชน: {mask_id_card(seller_id)}", small_font, y, 25)
            if seller_phone:
                y = draw_wrapped(f"โทรผู้ขาย: {seller_phone}", small_font, y, 25)
            if seller_address:
                y = draw_wrapped(f"ที่อยู่: {seller_address}", small_font, y, 25)
            vehicle = ' '.join(part for part in [vehicle_type, vehicle_plate] if part)
            if vehicle:
                y = draw_wrapped(f"รถ/ทะเบียน: {vehicle}", small_font, y, 25)

            y += 2
            y = draw_rule(y)
            y = draw_pair('รายการ / จำนวน x ราคา', 'รวม', small_bold_font, small_bold_font, y, 25)
            y = draw_rule(y)

            if not items:
                y = draw_wrapped('- ไม่มีรายการ -', body_font, y + 4, 30, align='center')

            for index, item in enumerate(items, 1):
                item_name = receipt_value(item, ['item_name'], '-')
                quantity = safe_float(item.get('quantity'))
                deduction = safe_float(item.get('weight_deduction'))
                if item.get('net_quantity') not in (None, ''):
                    net_quantity = safe_float(item.get('net_quantity'))
                else:
                    net_quantity = max(0, quantity - deduction)
                unit = receipt_value(item, ['unit'], 'หน่วย')
                unit_price = safe_float(item.get('unit_price'))
                total_price = safe_float(item.get('total_price'))

                y += 3
                y = draw_wrapped(f"{index}. {item_name}", body_bold_font, y, 29)
                if deduction > 0:
                    weight_line = (
                        f"ชั่ง {fmt_quantity(quantity)} {unit}  "
                        f"หัก {fmt_quantity(deduction)} {unit}"
                    )
                    y = draw_wrapped(weight_line, small_font, y, 25)
                price_line = (
                    f"สุทธิ {fmt_quantity(net_quantity)} {unit} x "
                    f"{fmt_money(unit_price)}"
                )
                y = draw_pair(
                    price_line,
                    fmt_money(total_price),
                    small_font,
                    body_bold_font,
                    y,
                    27,
                )
                y = draw_rule(y)

            total = safe_float(po.get('total_amount'))
            y += 2
            y = draw_pair(
                'รวมเงินทั้งสิ้น',
                f"{fmt_money(total)} บาท",
                body_bold_font,
                heading_font,
                y,
                32,
            )
            y += 2
            y = draw_rule(y, 2)
            y = draw_wrapped(f"วิธีชำระเงิน: {payment_label(po.get('payment_method'))}", body_font, y, 30)
            y = draw_wrapped(f"พนักงาน: {cashier}", body_font, y, 30)

            notes = receipt_value(po, ['notes'])
            if notes:
                y = draw_wrapped(f"หมายเหตุ: {notes}", small_font, y, 25)
            if is_precious:
                y += 2
                y = draw_wrapped('เอกสารรับซื้อสินค้ามีค่า', body_bold_font, y, 30, align='center')

            footer = receipt_value(po, ['receipt_footer'], DEFAULT_RECEIPT_FOOTER)
            y += 5
            y = draw_rule(y)
            if footer:
                y = draw_footer(footer, y)
            if shop_phone:
                y = draw_wrapped(f"ติดต่อ {shop_phone}", small_font, y, 25, align='center')
            return y + 14

        # ต้นขั้วร้านเน้นข้อมูลตรวจสอบ ไม่พิมพ์ส่วนหัวและรายละเอียดซ้ำทั้งใบ
        y = draw_wrapped(shop_name, heading_font, y, 30, align='center')
        y = draw_wrapped('ต้นขั้วร้าน', heading_font, y, 30, align='center')
        y += 2
        y = draw_rule(y, 2)
        y = draw_wrapped(f"เลขที่: {reference}", body_bold_font, y, 30)
        y = draw_wrapped(f"วันที่: {created}", small_font, y, 25)
        y = draw_wrapped(f"ผู้ขาย: {seller_name}", body_font, y, 30)
        if seller_id:
            y = draw_wrapped(f"บัตร: {mask_id_card(seller_id)}", small_font, y, 25)
        if vehicle_plate:
            y = draw_wrapped(f"ทะเบียน: {vehicle_plate}", small_font, y, 25)
        y = draw_rule(y)

        for index, item in enumerate(items, 1):
            item_name = receipt_value(item, ['item_name'], '-')
            quantity = safe_float(item.get('quantity'))
            deduction = safe_float(item.get('weight_deduction'))
            if item.get('net_quantity') not in (None, ''):
                net_quantity = safe_float(item.get('net_quantity'))
            else:
                net_quantity = max(0, quantity - deduction)
            unit = receipt_value(item, ['unit'], 'หน่วย')
            item_total = fmt_money(safe_float(item.get('total_price')))

            y = draw_wrapped(f"{index}. {item_name}", small_bold_font, y, 25)
            y = draw_pair(
                f"สุทธิ {fmt_quantity(net_quantity)} {unit}",
                item_total,
                small_font,
                small_bold_font,
                y,
                25,
            )
            y += 2

        y = draw_rule(y)
        y = draw_pair(
            'ยอดรวม',
            f"{fmt_money(safe_float(po.get('total_amount')))} บาท",
            body_bold_font,
            heading_font,
            y,
            31,
        )
        y = draw_wrapped(
            f"ชำระ: {payment_label(po.get('payment_method'))}",
            small_font,
            y,
            25,
        )
        y = draw_wrapped(f"แคชเชียร์: {cashier}", small_font, y, 25)

        if is_precious:
            y += 5
            y = draw_rule(y, 2)
            y = draw_wrapped('คำรับรองของผู้ขาย', body_bold_font, y, 30, align='center')
            declaration = (
                'ข้าพเจ้ายืนยันว่าได้นำสินค้าตามบิลนี้มาโดยสุจริต '
                'และยินยอมให้ร้านบันทึกข้อมูลเพื่อเป็นหลักฐาน'
            )
            y = draw_wrapped(declaration, small_font, y, 25)
            y += 6
            y = draw_wrapped('ลายมือชื่อผู้ขาย', small_font, y, 25)
            y += 80
            if draw is not None:
                draw.line(
                    [(margin + 36, y), (width - margin - 36, y)],
                    fill=0,
                    width=2,
                )
            y += 8
            y = draw_wrapped('หลักฐานที่แนบ', small_font, y, 25)
            y = draw_wrapped('[ ] บัตรประชาชน   [ ] ใบขับขี่', small_font, y, 25)
            y = draw_wrapped('[ ] เอกสารราชการ', small_font, y, 25)
            y = draw_wrapped(
                'ร้านไม่รับซื้อทรัพย์ที่ได้มาโดยผิดกฎหมาย',
                small_bold_font,
                y,
                25,
                align='center',
            )

        y += 3
        y = draw_rule(y, 2)
        return y + 14

    def make_section(section):
        # Supersample 3x: render ทุกอย่างใหญ่ 3 เท่า (font/พิกัด/เส้น) →
        # LANCZOS downscale ครั้งเดียว → threshold ต่ำ (เก็บแกนเข้ม = คมบาง)
        ss = 3
        section_height = render_section(po_data, section, draw=None)
        big = Image.new('L', (width * ss, section_height * ss), 255)
        # วาดด้วย font ss เท่าโดยคูณพิกัดผ่าน wrapper (layout logic ใน render_section ไม่แตะ)
        class ScaledDraw:
            """proxy ของ ImageDraw: คูณ coordinate/font อัตโนมัติ"""
            def __init__(self, d, factor):
                self._d = d
                self._f = factor
                self._font_cache = {}
            def text(self, xy, text, **kw):
                f = kw.pop('font', None)
                if f is not None:
                    key = (f.path, f.size * self._f)
                    if key not in self._font_cache:
                        self._font_cache[key] = ImageFont.truetype(f.path, f.size * self._f)
                    kw['font'] = self._font_cache[key]
                else:
                    kw['font'] = None
                kw.pop('stroke_width', None)   # supersample แทน stroke — ไม่งั้นฟุ้ง
                kw.pop('stroke_fill', None)
                self._d.text(tuple(v * self._f for v in xy), text, **kw)
            def line(self, pts, **kw):
                w = kw.pop('width', 1)
                self._d.line(tuple(tuple(v * self._f for v in p) for p in pts), width=w * self._f, **kw)

        render_section(po_data, section, draw=ScaledDraw(ImageDraw.Draw(big), ss))
        image = big.resize((width, section_height), Image.LANCZOS)
        image = image.point(lambda v: 0 if v < 120 else 255, mode='1')
        return image

    main_image = make_section('main')
    if not include_stub:
        return main_image

    stub_image = make_section('stub')
    gap_height = 42
    gap_image = Image.new('1', (width, gap_height), 1)
    gap_draw = ImageDraw.Draw(gap_image)
    dash_width = 18
    dash_gap = 14
    x = margin
    while x < width - margin:
        gap_draw.line(
            [(x, 12), (min(x + dash_width, width - margin), 12)],
            fill=0,
            width=2,
        )
        x += dash_width + dash_gap
    label = 'ฉีกตามเส้น'
    label_width = text_width(label, small_bold_font)
    gap_draw.text(
        ((width - label_width) // 2, 17),
        label,
        font=small_bold_font,
        fill=0,
    )

    combined = Image.new(
        '1',
        (width, main_image.height + gap_height + stub_image.height),
        1,
    )
    combined.paste(main_image, (0, 0))
    combined.paste(gap_image, (0, main_image.height))
    combined.paste(stub_image, (0, main_image.height + gap_height))
    return combined


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
    img = _draw_receipt_pil_image(po_data, include_stub=include_stub)
    W = img.width

    # ══════════════════════════════════════════════════════════
    # 2. แปลงเป็น ESC/POS 24-dot bit image (ESC *)
    #
    # Deli S420 ระบุรองรับ dot-plot command. สำหรับ ESC * mode 33
    # ค่า n คือจำนวนจุดแนวนอน (384) และข้อมูลเป็นคอลัมน์ละ 3 ไบต์.
    # ══════════════════════════════════════════════════════════
    if hasattr(img, 'get_flattened_data'):
        pixels = list(img.get_flattened_data())
    else:
        pixels = list(img.getdata())
    # ══════════════════════════════════════════════════════════
    # 2. แปลงเป็น ESC/POS raster (GS v 0 — mode 0, single-density)
    #
    # ใช้ GS v 0 แทน ESC * (24-dot bit image): raster mode พิมพ์ภาพต่อเนื่อง
    # ทั้งก้อนโดยไม่มีรอยต่อระหว่างแถบ → แก้เส้นขาดแนวนอน (broken strokes)
    # ไบต์ละ 8 จุดแนวตั้ง, แถวละ ceil(W/8) ไบต์, MSB = จุดซ้ายสุด
    # ══════════════════════════════════════════════════════════
    row_bytes = (W + 7) // 8
    escpos = bytearray(INIT)

    # ── raster payload: แถวละ row_bytes ไบต์ ──
    def raster_rows(top, height):
        data = bytearray()
        for row in range(top, top + height):
            base = row * W
            for byte_i in range(row_bytes):
                value = 0
                for bit in range(8):
                    px = byte_i * 8 + bit
                    if px < W and pixels[base + px] == 0:
                        value |= 1 << (7 - bit)
                data.append(value)
        return bytes(data)

    # แบ่งเป็นหลาย GS v 0 call ทีละ CHUNK_ROWS แถว (กัน firmware buffer limit)
    # การแบ่งเกิดที่ขอบแถว pixel เสมอ → ไม่มีรอยต่อให้เห็น (ต่างจาก ESC *)
    CHUNK_ROWS = 600
    for top in range(0, img.height, CHUNK_ROWS):
        height = min(CHUNK_ROWS, img.height - top)
        escpos += GS + b'v0\x00'
        escpos += bytes([row_bytes & 0xFF, (row_bytes >> 8) & 0xFF])
        escpos += bytes([height & 0xFF, (height >> 8) & 0xFF])
        escpos += raster_rows(top, height)

    # เผื่อพื้นที่ว่างก่อนใบมีดตัด เพื่อไม่ให้ฉีกโดนบรรทัดสุดท้าย
    escpos += FEED + b'\x04'
    escpos += CUT
    return bytes(escpos)


def render_receipt_image_as_png(po_data, include_stub=True):
    """
    วาดใบเสร็จด้วยฟังก์ชันกลาง แล้วส่งคืนเป็น base64 PNG สำหรับ preview
    """
    img = _draw_receipt_pil_image(po_data, include_stub=include_stub)
    buffer = io.BytesIO()
    img.save(buffer, format='PNG')
    return base64.b64encode(buffer.getvalue()).decode('ascii')


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
    font_normal = None
    font_bold = None
    selected_font_path = None
    for fp in THAI_FONT_PATHS:
        if os.path.exists(fp):
            selected_font_path = fp
            font_normal = ImageFont.truetype(fp, font_size)
            try:
                font_bold = ImageFont.truetype(fp.replace('Regular', 'Bold').replace('regular', 'bold'), font_size)
            except:
                font_bold = font_normal
            break
    if font_normal is None:
        font_normal = ImageFont.load_default()
        font_bold = font_normal

    font_small = ImageFont.truetype(selected_font_path, max(16, font_size - 4)) \
        if selected_font_path else font_normal

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


def print_direct(text: str, printer_name=None, encoding='cp874', mode='text'):
    """
    High-level print function: format text → ESC/POS → CUPS → printer

    Args:
        text: ข้อความที่จะพิมพ์
        printer_name: ชื่อ printer ใน CUPS
        encoding: 'cp874' (ไทย), 'utf-8', 'ascii'
        mode: 'text' (default, encode ด้วย cp874), 'image' (พิมพ์เป็นภาพ)
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

    result = print_via_cups(raw, raw_mode=True) if not IS_WINDOWS else print_via_windows(raw, raw_mode=True)
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
    parser.add_argument('--mode', choices=['image', 'text'], default='text',
                       help='Print mode: text uses CP874 (default), image uses bitmap raster')

    args = parser.parse_args()

    # ── Test mode ──
    if args.test:
        test_text = """
==========================================
      *** SCRAP POS - TEST ***
==========================================
          Deli S420 Thermal Printer
          Connected Successfully! ✅
          """ + DEFAULT_SHOP_NAME + """

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
