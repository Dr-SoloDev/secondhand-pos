# Storage Setup Guide — Secondhand POS

## ทางเลือกที่แนะนำ: Host Directory (ใช้ HDD เดียวกับ server)

**ใช้ directory `/var/data/secondhand-pos/uploads` บนเครื่อง server โดยตรง** — ไม่ต้องซื้ออุปกรณ์เพิ่ม

### ขั้นตอน

```bash
# 1. สร้าง directory บน host
sudo mkdir -p /var/data/secondhand-pos/uploads
sudo chown -R 33:33 /var/data/secondhand-pos/uploads   # uid/gid ของ www-data

# 2. รัน Docker ด้วย production override
cd /path/to/code
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# 3. ทดสอบ
docker exec scrap-pos-web ls -la /var/www/html/uploads
# ควรเห็น directory พร้อมใช้งาน
```

### ข้อดี
- ไม่ต้องซื้อ NAS (~10,000฿)
- ไม่ต้องตั้งค่า NFS
- ความเร็วสูงสุด (local disk)
- `docker compose down -v` **ไม่มีผล** — bind mount ไม่ถูกลบ

### การ Backup

```bash
# สร้าง cron job backup รายวัน
sudo crontab -e
# เพิ่มบรรทัด:
0 3 * * * rsync -av /var/data/secondhand-pos/uploads/ /var/data/backups/secondhand-pos/uploads/
```

หรือ backup ไป cloud:

```bash
# ติดตั้ง rclone ก่อน
rclone sync /var/data/secondhand-pos/uploads/ remote:bucket-name/
```

---

## ทางเลือก 2: NAS (NFS) — เมื่อต้องการแยก storage

ใช้เมื่อมี NAS อยู่แล้ว หรือต้องการ storage ที่แยกจาก server

### สิ่งที่ต้องมี
- NAS 1 เครื่อง (Synology DS124 หรือ DS220+ มือสอง ~5,000-10,000฿)
- server และ NAS อยู่ใน network เดียวกัน

### ตั้งค่า NFS บน NAS

#### Synology DSM
1. Control Panel → File Services → NFS ✅ Enable
2. Control Panel → Shared Folder → สร้าง `secondhand-pos/uploads/`
3. คลิกขวา → Edit → NFS Permissions → Create
   - IP: `*` (หรือ IP ของ Docker host)
   - Privilege: Read/Write, Squash: No mapping

#### QNAP / TrueNAS
- เปิด NFS service
- Export path `/volume1/secondhand-pos/uploads`
- ให้สิทธิ์ RW แก่ IP ของ Docker host

### รันด้วย NAS

```bash
# 1. ตั้งค่า .env
echo 'NAS_IP=192.168.1.100' >> .env

# 2. รัน
docker compose -f docker-compose.yml -f docker-compose.nas.yml up -d

# 3. ตรวจสอบ
docker exec scrap-pos-web ls -la /var/www/html/uploads
```

---

## การย้ายรูปจาก local volume → storage ใหม่

```bash
# ถ้ามีรูปใน container อยู่แล้ว (local volume)
docker exec scrap-pos-web bash -c 'tar czf /tmp/uploads-backup.tar.gz -C /var/www/html/uploads .'
docker cp scrap-pos-web:/tmp/uploads-backup.tar.gz ./uploads-backup.tar.gz

# แก้ docker-compose.yml ชี้ไป storage ใหม่
# รันใหม่
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# แตกไฟล์คืน
docker cp ./uploads-backup.tar.gz scrap-pos-web:/tmp/
docker exec scrap-pos-web bash -c 'tar xzf /tmp/uploads-backup.tar.gz -C /var/www/html/uploads'
```

---

## Troubleshooting

| ปัญหา | สาเหตุ | วิธีแก้ |
|:------|:-------|:--------|
| `scrap-pos-web` ไม่ start | volume mount ไม่ติด | เช็ค `docker compose logs web` ดู error |
| Permission denied | www-data ไม่มีสิทธิ์เขียน | `sudo chown -R 33:33 /var/data/secondhand-pos/uploads` |
| รูป upload ไม่ได้ | ไม่มี write permission | `docker exec scrap-pos-web chmod 755 /var/www/html/uploads` |
| ช้าเวลาอัปโหลด | NFS ผ่าน WiFi | ใช้สายแลนแทน |
