#!/bin/bash
# ============================================================
# session-start.sh — Auto-load project context on session start
# ============================================================
# Hook type: SessionStart
# Output: inject project memory into Claude's context
# ============================================================

set -euo pipefail

PROJECT_ROOT="/home/drsolodev/projects/secondhand-pos"

cat <<EOF
<system-reminder>
📂 PROJECT SESSION STARTED: secondhand-pos

Dr.Solodev กำลังทำงานในโปรเจกต์ POS ร้านรับซื้อของเก่า 4 สาขา (สุรินทร์)

ก่อนเริ่ม กรุณาอ่าน context files เหล่านี้:

1. **AGENT-MEMORY.md** — สถานะโปรเจกต์ + what's done + todo
   $PROJECT_ROOT/AGENT-MEMORY.md

2. **.learnings/LEARNINGS.md** — บทเรียนสะสมจาก sessions ก่อน
   $PROJECT_ROOT/.learnings/LEARNINGS.md

3. **.learnings/ERRORS.md** — errors เก่า อย่าทำซ้ำ
   $PROJECT_ROOT/.learnings/ERRORS.md

4. **.learnings/FEATURE_REQUESTS.md** — สิ่งที่ลูกค้า/Dr.Solodev อยากได้
   $PROJECT_ROOT/.learnings/FEATURE_REQUESTS.md

Quick context:
- Deal closed: 40,000 บาท (18 พ.ค. 2569)
- Tech stack: PHP 8.2 + MySQL 8.0 + Docker (base: goragodwiriya/pos-system)
- Demo: docker compose up -d → http://localhost:8080
- ลูกค้าโดนทิ้งงานมาแล้ว 2 ครั้ง → trust สำคัญที่สุด
- Dr.Solodev mindset: "ขายผลงาน ไม่ได้ขายวิญญาณ"

End-of-Day ritual จะ trigger อัตโนมัติเมื่อ Dr.Solodev บอกพอ/พัก/จบ/เหนื่อย
</system-reminder>
EOF

exit 0
