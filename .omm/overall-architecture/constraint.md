1. ห้ามแก้ base-pos/ โดยตรง — ต้องผ่าน customizations overlay เสมอ
2. ทุก endpoint ต้องมี requireAuth() — zero tolerance
3. ทุก mutation ต้องอยู่ใน transaction + FOR UPDATE ถ้าเกี่ยวข้อง stock
4. Thai locale ทุกอย่าง (ภาษา, รูปแบบเงิน, วันที่)
5. JWT token key = posToken/posUser (ไม่ใช่ token/user)
6. Migration รันครั้งเดียว — แก้เก่าต้อง docker compose down -v
