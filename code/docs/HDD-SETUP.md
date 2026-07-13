# HDD Setup Guide — เพิ่ม HDD 1TB สำหรับเก็บรูป

## ขั้นตอน

### 1. เสียบ HDD เข้า server

- SATA: เสียบสายไฟ + สาย SATA เข้า motherboard
- USB: เสียบเข้ากับ port USB 3.0

### 2. ตรวจสอบว่า HDD รู้จัก

```bash
lsblk
# หรือ
sudo fdisk -l
# ดูขนาด — ควรเห็น /dev/sdb หรือ /dev/sdc ขนาด ~1TB
```

สมมติว่าเห็น `/dev/sdb`

### 3. 🔴 ลบข้อมูลเก่าทิ้ง

> **⚠️ สำคัญ: ตรวจสอบให้แน่ใจว่า `/dev/sdb` คือ HDD 1TB ที่ต้องการลบจริงๆ**
> ใช้ `lsblk` ดูขนาดให้แน่ใจ — ถ้าลบผิดดิสก์ ข้อมูลหายหมด!

```bash
# เช็คอีกครั้งก่อนลบ — ดูชื่อ+ขนาดให้ชัวร์
sudo fdisk -l /dev/sdb

# ลบ partition table + data ทั้งหมด
sudo wipefs -a /dev/sdb

# หรือถ้าต้องการ fast wipe (แค่ลบ partition table)
sudo dd if=/dev/zero of=/dev/sdb bs=1M count=10 status=progress
```

### 4. สร้าง partition ใหม่

```bash
sudo fdisk /dev/sdb
# พิมพ์ n → p → 1 → enter → enter → w
```

### 5. format filesystem

```bash
sudo mkfs.ext4 /dev/sdb1
```

### 6. mount

```bash
# หา UUID ของ drive
sudo blkid /dev/sdb1
# สมมติได้ UUID="abc123-..."

# สร้าง mount point
sudo mkdir -p /mnt/data

# mount ทดสอบ
sudo mount /dev/sdb1 /mnt/data

# เช็คว่า mount แล้ว
df -h /mnt/data
```

### 7. เพิ่มใน /etc/fstab (auto-mount เวลา reboot)

```bash
# backup ก่อน
sudo cp /etc/fstab /etc/fstab.bak

# เพิ่มบรรทัด (ใช้ UUID ที่ได้จาก blkid)
echo 'UUID=abc123-... /mnt/data ext4 defaults 0 2' | sudo tee -a /etc/fstab

# ทดสอบ fstab
sudo mount -a
```

### 8. สร้างโฟลเดอร์ uploads

```bash
sudo mkdir -p /mnt/data/secondhand-pos/uploads

# ตั้งสิทธิ์ให้ www-data (uid 33)
sudo chown -R 33:33 /mnt/data/secondhand-pos/uploads
```

### 9. รัน Docker ด้วย path ใหม่

```bash
cd /path/to/code

# ถ้ามีรูปเก่าใน ./uploads ให้ย้ายไป HDD ก่อน
docker exec scrap-pos-web bash -c 'tar czf /tmp/uploads-backup.tar.gz -C /var/www/html/uploads .'
docker cp scrap-pos-web:/tmp/uploads-backup.tar.gz .
sudo tar xzf uploads-backup.tar.gz -C /mnt/data/secondhand-pos/uploads/

# รันใหม่
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

---

## เช็คว่าทำงาน

```bash
# ดูว่ารูปไปเก็บที่ HDD จริง
docker exec scrap-pos-web df -h /var/www/html/uploads
# ควรเห็น /dev/sdb1 mount ที่ /mnt/data

# ลองอัปโหลดรูปผ่านเว็บแล้วเช็ค
ls -la /mnt/data/secondhand-pos/uploads/purchase-orders/
```

---

## Troubleshooting

| ปัญหา | สาเหตุ | วิธีแก้ |
|:-------|:-------|:--------|
| `fdisk` ไม่เห็น HDD | สายไม่เสียบหรือ driver ไม่มี | เช็ค `dmesg \| tail` ดู error |
| mount error: wrong fs type | ยังไม่ format | `sudo mkfs.ext4 /dev/sdb1` |
| permission denied | สิทธิ์ folder ไม่ถูก | `sudo chown -R 33:33 /mnt/data/secondhand-pos` |
| container restart ไม่ติด | mount หายเพราะยังไม่ได้ fstab | `sudo mount -a` แล้ว restart container |
