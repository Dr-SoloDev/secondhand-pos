# 🏪 RUNBOOK — ติดตั้งระบบที่ร้าน (Ubuntu Server + Windows 10)
> จัดทำ: 8 ส.ค. 2569 | ใช้เวลารวม ~1 ชั่วโมง | ถ้าติดขัดตรงไหน ถ่ายรูปหน้าจอไว้ก่อน

---

## ต้องเตรียมจากที่บ้าน (เสร็จแล้ว)
- [x] โค้ด push ขึ้น GitHub แล้ว (branch main — commit `9d39242`)
- [x] ไฟล์ `.env.production` (ในโฟลเดอร์แพ็กเกจนี้)
- [x] รหัสผ่าน admin เริ่มต้น: `admin / admin` (ต้องเปลี่ยนทันทีหลัง deploy ครั้งแรก)

---

## STEP 1 — เปิดเครื่อง Ubuntu Server (5 นาที)
```bash
# เช็ค internet
ping -c 3 google.com

# เช็ค/เปิด SSH (สำคัญ — ให้ทีม remote เข้าถึงได้)
sudo apt update && sudo apt install -y openssh-server
sudo systemctl enable --now ssh

# หา IP ของเครื่อง server (จดไว้!)
ip a
```

## STEP 2 — ติดตั้ง Docker (10 นาที)
```bash
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# สำคัญ: ออกจากระบบแล้วเข้าใหม่ (logout/login) หรือรัน: newgrp docker
# ตรวจ: docker version   ← ต้องเห็นเวอร์ชัน ไม่ใช่ permission error
```

## STEP 3 — ติดตั้ง Tailscale (10 นาที — ให้ทีม remote ทำงานจากบ้านได้)
```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up
# มันจะแสดง URL (https://login.tailscale.com/...) — เปิดใน browser, ล็อกอินด้วย email
# ตรวจ: sudo tailscale status   ← ต้องเห็นเครื่องตัวเอง Connected
# จดชื่อเครื่อง + IP tailnet (100.x.x.x) ส่งกลับมา
```

## STEP 4 — เอาโค้ดขึ้นเครื่อง (10 นาที)
```bash
# สร้าง GitHub PAT (Personal Access Token) ครั้งเดียว:
#   1. เปิด https://github.com/settings/tokens (ล็อกอินบัญชี Dr-SoloDev)
#   2. Generate new token → ติ๊กเฉพาะ "repo" → copy token (xxx...)
cd ~
git clone https://<TOKEN>@github.com/Dr-SoloDev/secondhand-pos.git
# หรือถ้าใช้ SSH:  git clone git@github.com:Dr-SoloDev/secondhand-pos.git (ต้องตั้ง deploy key)

cd ~/secondhand-pos/code
```

## STEP 5 — ตั้งค่า .env + เปิดระบบ (15 นาที)
```bash
cd ~/secondhand-pos/code
# เอาไฟล์ .env.production จากแพ็กเกจ (USB/ถ่ายไฟล์) มาวางในโฟลเดอร์นี้ แล้ว:
cp .env.production .env
chmod 600 .env

docker compose up -d
# รอประมาณ 1-2 นาที (DB กำลังรัน migrations)
# ตรวจว่า migration เสร็จครบ:
docker compose logs db | grep -E "Done|เสร็จสมบูรณ์" | tail -5
# ต้องเห็น: "✅ Migration เสร็จสมบูรณ์" และ "Done: 070_add_vehicle_type_to_sellers.sql"
```

## STEP 6 — ล้างข้อมูล demo (1 นาที)
```bash
cd ~/secondhand-pos/code
docker compose exec -T db sh -lc 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -u root < /docker-entrypoint-initdb.d/pos-database/reset-data.sql'
```
> ผลลัพธ์: เหลือ 2 สาขา (สาขา 1 / สาขา 2 ยังไม่ได้ตั้งชื่อ), users เหลือแค่ admin, ทุกตารางข้อมูลว่าง (เริ่มนับ 1)

## STEP 7 — ทดสอบจาก Windows 10 (5 นาที)
1. เปิด **Chrome/Edge** บนเครื่อง Windows 10
2. เข้า `http://<IP-server>:8080/admin/` (IP จาก STEP 1)
3. หน้า login ขึ้น → ล็อกอิน `admin` / `admin`
4. ✅ เข้า dashboard ได้ = พร้อมใช้งาน
5. ⚠️ **เปลี่ยนรหัสผ่าน admin ทันที:** เมนู ผู้ใช้งาน → ปุ่มเปลี่ยนรหัส (แถว admin)

## STEP 8 — เครื่องพิมพ์ใบเสร็จ (ถ้ามี — ไม่บังคับตอนนี้)
- เครื่องพิมพ์ ESC/POS ต่อ **USB กับตัว Ubuntu server** → แจ้งทีม remote ตั้งค่า print server
- หรือต่อกับ Windows 10 (พิมพ์ผ่าน browser) — แจ้งทีม remote เช่นกัน

---

## 📋 ข้อมูลที่ต้องส่งกลับมาที่ทีม (เพื่อตรวจ + ตั้ง remote access)
```
1. IP ของ Ubuntu server (LAN): ______
2. SSH: username ______  password/key ______
3. Tailscale: ชื่อเครื่อง ______  IP tailnet ______  Connected ✅/❌
4. เปิดระบบสำเร็จไหม: admin login ผ่านไหม ✅/❌ (ถ้าไม่ ถ่ายรูป error)
5. ชื่อจริง 2 สาขา + ที่อยู่ (กรอกใน BRANCH-INFO-FORM.md ได้)
6. เครื่องพิมพ์: รุ่น/ต่อกับเครื่องไหน ______
```

---

## 🔧 Troubleshooting ด่วน
| อาการ | วิธีแก้ |
|---|---|
| `docker: permission denied` | ยังไม่ logout/login ใหม่ หลัง `usermod` (STEP 2) |
| หน้าเว็บไม่ขึ้น | เช็ค `docker compose ps` → ทั้ง 2 ตัวต้อง `healthy` |
| login แล้ว error 500 | migration ยังไม่ครบ — รอ STEP 5 แล้วค่อยทดสอบ |
| ไม่เห็น "Migration เสร็จสมบูรณ์" | `docker compose logs db --tail 30` ถ่ายรูปส่งทีม |
| windows เปิดเว็บไม่ได้ | เช็ค IP ถูกต้อง + อยู่เครือข่ายเดียวกัน (LAN เดียวกัน) |
