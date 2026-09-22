#!/usr/bin/env python3
"""
Unit tests — Tiger TI-01 parser (scale_agent.py)
รัน: python3 test_scale_parser.py
     pytest test_scale_parser.py -v  (ถ้ามี pytest)
"""
import sys
import os
sys.path.insert(0, os.path.dirname(__file__))

from scale_agent import parse_weight_line

def test_tiger_basic():
    assert parse_weight_line("  12.34 kg") is not None
    w, stable, raw = parse_weight_line("  12.34 kg")
    assert abs(w - 12.34) < 0.001, f"got {w}"
    print("PASS: basic '  12.34 kg'")

def test_tiger_comma():
    w, _, _ = parse_weight_line("  12,34 kg")
    assert abs(w - 12.34) < 0.001
    print("PASS: comma '12,34'")

def test_tiger_st_prefix():
    w, stable, raw = parse_weight_line("ST,GS,  12.34kg")
    assert abs(w - 12.34) < 0.001
    assert stable is True, "ST should be stable"
    print("PASS: ST,GS stable")

def test_tiger_unstable():
    w, stable, raw = parse_weight_line("US,GS,  12.34kg")
    assert stable is False
    print("PASS: US unstable")

def test_tiger_no_unit():
    w, _, _ = parse_weight_line("  5.00")
    assert abs(w - 5.00) < 0.001
    print("PASS: no unit '  5.00'")

def test_tiger_zero():
    w, _, _ = parse_weight_line("  0.00 kg")
    assert w == 0.0
    print("PASS: zero")

def test_tiger_large():
    w, _, _ = parse_weight_line("  1234.56 kg")
    assert abs(w - 1234.56) < 0.001
    print("PASS: large 1234.56")

def test_tiger_invalid():
    assert parse_weight_line("") is None
    assert parse_weight_line("   ") is None
    assert parse_weight_line("ERROR") is None
    print("PASS: invalid returns None")

def test_tiger_negative():
    # ลบควร parse ได้ (เผื่อ tare) แต่ค่าติดลบมากควรกรอง
    r = parse_weight_line(" -0.02 kg")
    assert r is not None
    print("PASS: negative small")

def test_tiger_with_noise():
    w, _, _ = parse_weight_line("\x02  12.34 kg\x03")
    assert abs(w - 12.34) < 0.001
    print("PASS: with control chars")

def run_all():
    tests = [
        test_tiger_basic,
        test_tiger_comma,
        test_tiger_st_prefix,
        test_tiger_unstable,
        test_tiger_no_unit,
        test_tiger_zero,
        test_tiger_large,
        test_tiger_invalid,
        test_tiger_negative,
        test_tiger_with_noise,
    ]
    fails = 0
    for t in tests:
        try:
            t()
        except AssertionError as e:
            print(f"FAIL: {t.__name__}: {e}")
            fails += 1
        except Exception as e:
            print(f"ERROR: {t.__name__}: {e}")
            fails += 1
    print(f"\n{'All passed!' if fails==0 else f'{fails} FAILED'}: {len(tests)-fails}/{len(tests)}")
    return fails

if __name__ == "__main__":
    sys.exit(run_all())
