# NAS Setup Guide — Secondhand POS

## สิ่งที่ต้องมี

- **NAS 1 เครื่อง** (แนะนำ Synology DS124 หรือ DS220+ มือสอง ~5,000-10,000฿)
- **สายแลน** ต่อ NAS เข้า switch/router เดียวกับ server ที่รัน Docker
- **server** ที่รัน Docker Compose (Ubuntu)

---

## ขั้นตอน

### 1. ตั้งค่า NFS บน NAS

#### Synology DSM

1. เปิด Control Panel → File Services → NFS ✅ Enable NFS service
2. เปิด Control Panel → Shared Folder
   - สร้างโฟลเดอร์ `secondhand-pos`
   - ข้างในสร้าง `uploads/`
3. คลิกขวาที่ `secondhand-pos` → Edit → NFS Permissions → Create
   - Hostname or IP: `*` (หรือ IP ของ Docker host)
   - Privilege: Read/Write
   - Squash: No mapping
   - Security: sys
   - ✅ Apply

#### QNAP / TrueNAS / อื่นๆ

- เปิด NFS service
- Export path `/volume1/secondhand-pos/uploads` (หรือตามแต่ระบบ)
- ให้สิทธิ์ RW แก่ IP ของ Docker host

### 2. หา IP ของ NAS

```bash
# บน Docker host
ping nas.local
# หรือเช็คจาก router admin page
# สมมติว่าได้ 192.168.1.100
```

### 3. ตั้งค่า .env

```bash
cd /path/to/code
echo 'NAS_IP=192.168.1.100' >> .env
```

### 4. ทดสอบ NFS mount

```bash
# ทดสอบว่า Docker host ติดต่อ NAS ได้
showmount -e 192.168.1.100
# ควรเห็น: /volume1/secondhand-pos/uploads *
```

### 5. รัน Docker Compose

```bash
# หยุด container เดิม
docker compose down

# รันใหม่ด้วย NAS volume
docker compose -f docker-compose.yml -f docker-compose.nas.yml up -d

# เช็คว่า mount สำเร็จ
docker exec scrap-pos-web ls -la /var/www/html/uploads
# ควรเห็นโฟลเดอร์จาก NAS
```

### 6. ย้ายรูปเดิม (ถ้ามี)

```bash
# ถ้ามีรูปใน local volume อยู่แล้ว ให้ย้ายไป NAS
docker exec scrap-pos-web cp -r /var/www/html/uploads/* /var/www/html/uploads/
```

---

## การ Backup

NAS backup ด้วย Hyper Backup (Synology) หรือ rsync ไปที่อื่น:

```bash
# backup ไป external HDD
rsync -av /volume1/secondhand-pos/ /volumeUSB1/backups/secondhand-pos/
```

---

## Maintenance

### เช็คพื้นที่เหลือ

```bash
docker exec scrap-pos-web df -h /var/www/html/uploads
```

### เปลี่ยน IP NAS

```bash
# แก้ .env
sed -i 's/NAS_IP=.*/NAS_IP=192.168.1.200/' .env

# recreate volume
docker compose -f docker-compose.yml -f docker-compose.nas.yml down
docker compose -f docker-compose.yml -f docker-compose.nas.yml up -d
```

---

## Troubleshooting

| ปัญหา | สาเหตุ | วิธีแก้ |
|:------|:-------|:--------|
| `scrap-pos-web` ไม่ start | NFS volume mount ไม่ติด | เช็ค `docker compose logs web` ดู error |
| Permission denied | NFS export ไม่ให้สิทธิ์ RW | แก้ NFS permission บน NAS |
| รูป upload ไม่ได้ | `uploads/` folder ไม่มี write permission | `docker exec scrap-pos-web chmod 755 /var/www/html/uploads` |
| ช้าเวลาอัปโหลด | NFS ผ่าน WiFi | ใช้สายแลนแทน |
