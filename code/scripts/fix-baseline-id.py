#!/bin/bash
# Root cause: applyPositionFields() ไม่แนบ baseline_id เข้า response
# getPositionBalances() มี $baseline แต่คืนเฉพาะ balances — test ต้องการ baseline_id
# Fix: เพิ่ม baseline_id + baseline_effective_date ใน applyPositionFields (ผ่าน getPositionBalances)
cd ~/projects/scrap-pos

# 1. getPositionBalances: ส่ง baseline_id กลับมาด้วย (ทั้งกรณีมี/ไม่มี baseline)
python3 - <<'PYEOF'
import re
p = 'code/customizations/api/Models/CashSession.php'
src = open(p).read()

old_ret_none = """        if (!$baseline) {
            return [
                'model_version' => 1,
                'drawer_balance' => 0.0,
                'reserve_balance' => 0.0,
                'business_total_cash' => 0.0,
            ];
        }"""
new_ret_none = """        if (!$baseline) {
            return [
                'model_version' => 1,
                'drawer_balance' => 0.0,
                'reserve_balance' => 0.0,
                'business_total_cash' => 0.0,
            ];
        }
        // expose baseline identity so clients/tests can detect initialized position model
        $baselineMeta = [
            'baseline_id' => (int)$baseline['id'],
            'baseline_effective_date' => $baseline['effective_date'],
        ];"""
assert old_ret_none in src, "anchor1 missing"
src = src.replace(old_ret_none, new_ret_none)

# find the return of getPositionBalances (the one with business_total_cash rounded) and merge meta
old_final = """        return [
            'model_version' => 2,"""
new_final = """        return array_merge([
            'model_version' => 2,"""
assert old_final in src, "anchor2 missing"
src = src.replace(old_final, new_final)

open(p, 'w').write(src)
print("patched anchors OK")
PYEOF

grep -n "baseline_id" code/customizations/api/Models/CashSession.php | head -5