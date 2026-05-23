#!/bin/bash
# ============================================================
# eod-detect.sh — End-of-Day signal detector
# ============================================================
# Hook type: UserPromptSubmit
# Reads: JSON from stdin with user's prompt
# Output: ถ้าเจอ EOD signal → ส่ง system reminder ให้ Claude trigger ritual
# ============================================================

set -euo pipefail

# อ่าน JSON จาก stdin
INPUT=$(cat)

# ดึง user prompt ออกมา (รองรับทั้ง JSON และ plain text)
PROMPT=$(echo "$INPUT" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    print(data.get('prompt') or data.get('user_prompt') or data.get('message', ''))
except Exception:
    sys.stdin.seek(0) if hasattr(sys.stdin, 'seek') else None
    print('')
" 2>/dev/null || echo "")

# ถ้า parse ไม่ได้ ลอง fallback
if [ -z "$PROMPT" ]; then
    PROMPT="$INPUT"
fi

# Lowercase สำหรับ match อังกฤษ
PROMPT_LOWER=$(echo "$PROMPT" | tr '[:upper:]' '[:lower:]')

# สัญญาณ End-of-Day (ภาษาไทย + อังกฤษ)
EOD_PATTERNS=(
    "พอแล้ว"
    "พักก่อน"
    "พักผ่อน"
    "ไปนอน"
    "เหนื่อยแล้ว"
    "จบงานวันนี้"
    "พอแค่นี้"
    "หยุดก่อน"
    "ลาก่อน"
    "ราตรีสวัสดิ์"
    "วันนี้พอ"
    "ปิดเทอมินอล"
    "ปิดเครื่อง"
    "done for today"
    "calling it a night"
    "wrapping up"
    "good night"
    "see you tomorrow"
    "end of day"
    "eod"
)

# ตรวจหา signal
DETECTED=""
for pattern in "${EOD_PATTERNS[@]}"; do
    if echo "$PROMPT_LOWER" | grep -qF "$(echo "$pattern" | tr '[:upper:]' '[:lower:]')"; then
        DETECTED="$pattern"
        break
    fi
done

# ถ้าเจอ → inject reminder ให้ Claude
if [ -n "$DETECTED" ]; then
    cat <<EOF
<system-reminder>
🌙 END-OF-DAY SIGNAL DETECTED: "$DETECTED"

Dr.Solodev กำลังจะปิดงานของวัน — ก่อนตอบเรื่องอื่น กรุณาทำ EOD Ritual ตามที่บันทึกใน feedback memory:

ถาม Dr.Solodev เป็น checklist สั้นๆ ครั้งเดียว:
1. วันนี้ทำอะไรเสร็จ? (จะอัปเดต AGENT-MEMORY.md)
2. เจอปัญหา/ติดขัดอะไรไหม? (จะลง .learnings/ERRORS.md)
3. มี learning อะไรใหม่ที่อยากจำ? (จะลง .learnings/LEARNINGS.md)
4. มี feature ใหม่ที่ลูกค้า/พี่อยากได้? (จะลง .learnings/FEATURE_REQUESTS.md)

หลังบันทึกเสร็จ:
- สรุปสั้นๆ ว่าบันทึกอะไรลงที่ไหน
- ปิดด้วยคำอวยพรให้พักผ่อน
- ห้ามเริ่ม task ใหม่หลังจากนี้

Project paths:
- ~/projects/secondhand-pos/AGENT-MEMORY.md
- ~/projects/secondhand-pos/.learnings/
- Mirror: ~/.openclaw/workspace/projects/secondhand-pos/
</system-reminder>
EOF
fi

exit 0
