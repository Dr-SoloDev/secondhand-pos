#!/usr/bin/env python3

import base64
import io
import math
import unittest

from PIL import Image

from print_receipt import (
    CUT,
    FEED,
    INIT,
    _draw_receipt_pil_image,
    render_receipt_image,
    render_receipt_image_as_png,
)


def receipt_data(item_count=1, precious=False, long_text=False):
    items = []
    total = 0.0
    for index in range(item_count):
        quantity = 120.5 + index
        deduction = 0.5 if index % 2 == 0 else 0.0
        net = quantity - deduction
        unit_price = 12.75 + index
        item_total = round(net * unit_price, 2)
        total += item_total
        name = f"ทองแดงปอกเส้นใหญ่คละเกรด รายการทดสอบลำดับที่ {index + 1}"
        if not long_text:
            name = "ทองแดงปอก"
        items.append({
            'item_name': name,
            'quantity': quantity,
            'weight_deduction': deduction,
            'net_quantity': net,
            'unit': 'กก.',
            'unit_price': unit_price,
            'total_price': item_total,
        })

    return {
        'shop_name': 'รักษ์สะอาดรีไซเคิล',
        'shop_address': (
            '123 หมู่ 4 ถนนตัวอย่าง ตำบลในเมือง อำเภอเมือง จังหวัดสุรินทร์ 32000'
            if long_text else 'อำเภอเมือง จังหวัดสุรินทร์'
        ),
        'shop_phone': '044-123-456',
        'tax_id': '1329900123456',
        'receipt_welcome_message': 'บริการดี ราคาดี ตาชั่งมาตรฐาน',
        'receipt_footer': (
            'ขอบคุณที่ใช้บริการ กรุณาตรวจสอบน้ำหนักและยอดเงินก่อนออกจากร้าน'
            if long_text else 'ขอบคุณที่ใช้บริการ'
        ),
        'reference_no': 'PO-B1-20260801-001',
        'created_at': '2026-08-01 14:35:00',
        'branch_name': 'สาขาสุรินทร์',
        'branch_code': 'BR01',
        'seller_name': (
            'นายทดสอบ ชื่อและนามสกุลยาวสำหรับตรวจการตัดบรรทัดภาษาไทย'
            if long_text else 'นายทดสอบ ระบบ'
        ),
        'seller_id_card': '1329900123456',
        'seller_phone': '089-123-4567',
        'seller_address': (
            '99 หมู่ 9 บ้านตัวอย่าง ตำบลตัวอย่าง อำเภอตัวอย่าง จังหวัดสุรินทร์'
            if long_text else 'อำเภอเมือง จังหวัดสุรินทร์'
        ),
        'vehicle_type': 'รถกระบะ',
        'vehicle_plate': 'บก 1234 สุรินทร์',
        'user_name': 'พนักงานทดสอบ',
        'payment_method': 'cash',
        'total_amount': round(total, 2),
        'notes': 'ทดสอบหมายเหตุที่มีข้อความยาวและต้องขึ้นบรรทัดใหม่' if long_text else '',
        'is_precious_metal': precious,
        'items': items,
    }


class ReceiptRenderingTest(unittest.TestCase):
    def test_png_preview_uses_exact_printer_width(self):
        encoded = render_receipt_image_as_png(receipt_data(), include_stub=True)
        image = Image.open(io.BytesIO(base64.b64decode(encoded)))

        self.assertEqual(image.width, 384)
        self.assertGreater(image.height, 500)
        self.assertEqual(image.mode, '1')

    def test_long_receipt_has_dynamic_height_and_bottom_padding(self):
        image = _draw_receipt_pil_image(
            receipt_data(item_count=20, long_text=True),
            include_stub=True,
        )

        self.assertGreater(image.height, 2000)
        bottom_padding = image.crop((0, image.height - 10, 384, image.height))
        self.assertEqual(bottom_padding.getextrema(), (1, 1))

    def test_precious_receipt_reserves_signature_space(self):
        normal = _draw_receipt_pil_image(receipt_data(), include_stub=True)
        precious = _draw_receipt_pil_image(
            receipt_data(precious=True),
            include_stub=True,
        )

        self.assertGreater(precious.height, normal.height + 150)

    def test_branded_footer_gets_dedicated_tagline_layout(self):
        generic_data = receipt_data()
        branded_data = receipt_data()
        branded_data['receipt_footer'] = (
            'ขอบคุณที่ให้เราร่วมทาง มีคุณจึงมีเรา รักษ์สะอาดรีไซเคิล '
            'ขอขอบคุณลูกค้าทุกท่าน\n'
            'ร้านของเราปิดทำการทุกวันพฤหัสบดีนะครับ'
        )

        generic = _draw_receipt_pil_image(generic_data, include_stub=False)
        branded = _draw_receipt_pil_image(branded_data, include_stub=False)

        self.assertGreater(branded.height, generic.height + 100)

    def test_deli_s420_uses_proven_esc_star_band_layout(self):
        data = receipt_data()
        image = _draw_receipt_pil_image(data, include_stub=True)
        raw = render_receipt_image(data, include_stub=True)
        band_count = math.ceil(image.height / 24)
        band_data_size = 384 * 3
        offset = len(INIT)

        for _ in range(band_count):
            self.assertEqual(raw[offset:offset + 5], b'\x1b\x2a\x21\x80\x01')
            offset += 5 + band_data_size
            self.assertEqual(raw[offset:offset + 1], b'\n')
            offset += 1

        self.assertEqual(raw[offset:], FEED + b'\x04' + CUT)
        self.assertNotIn(b'\x1d\x76\x30\x00', raw)


if __name__ == '__main__':
    unittest.main()
