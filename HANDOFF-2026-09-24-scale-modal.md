# Handoff — Scale Modal + Auto-connect (Tiger TI-01) — 2026-09-24

> สำหรับ agent opencode ที่จะมาทำต่อพรุ่งนี้เช้า (2026-09-25) ที่ร้าน — อ่านไฟล์นี้แล้วลุย deploy + เทสกับตาชั่งจริงได้เลย ไม่ต้องถามเจ้าของซ้ำ

## 0. บริบทโครงการ
- **Secondhand POS** ร้านรับซื้อของเก่า 4 สาขา สุรินทร์ — `code/` overlay 2 ชั้น `base-pos/` (core) + `customizations/` (edit หลัก), Router รวมศูนย์ `code/base-pos/api/Router.php`
- **Scale Phase 2** Tiger TI-01 RS232 — Web Serial (Chrome/Edge) เป็นหลัก + HTTP Agent `localhost:9130` fallback + manual (แท็บเล็ต)
- **สาขาหน้าร้านใช้ Chrome/Edge ล้วน** — ต้องการ “เสียบแล้วเปิดเลย (Auto)” ครั้งแรกมี popup ขออนุญาต ครั้งต่อไป `getPorts()` ต่อเองเงียบ ถ้าไม่ต่อต้องคีย์มือได้ปกติ
- **Server prod** `ragsaaadserver` Tailscale `100.91.242.99` user `ragsaaad_v1` path `~/secondhand-pos/code`

## 1. วันนี้ทำอะไร (2026-09-24) — Muse Spark (build mode)
1. รีวิว scale integration แบบ read-only (D — Scale integration):
   - `code/base-pos/assets/js/scale-bridge.js:1` — dual path Web Serial + HTTP Agent, parser `parseScaleLine`, stable detection `±0.02/1.5s`, `initScaleBridge()` + `updateScaleUI()` ล็อค `itemQuantity.readOnly`
   - `code/customizations/api/Controllers/ScaleController.php:1` — 8 routes `scale/*` ใน `code/base-pos/api/Router.php:291`
   - `code/customizations/api/Models/ScaleDevice.php`, `ScaleReading.php` + migrations `076_add_scale_mode_to_branches.sql`, `077_create_scale_devices.sql`, `078_create_scale_readings.sql`, `079_add_scale_fields_to_po_items.sql`
   - `code/customizations/api/Controllers/PurchaseOrdersController.php:228` + `Models/PurchaseOrder.php:290` — provenance `weight_source/scale_device_id/scale_raw_kg/scale_stable/captured_at/override_reason`
   - `code/scale-agent/scale_agent.py:1` + `test_scale_parser.py` — RS232→HTTP 9130
2. ออกแบบ PR แยก: **PR แรก = Web Serial modal + auto-connect + คีย์มือไม่บล็อค** (PR 2 ค่อยทำ UNIQUE/CORS/parser parity)
3. Implement PR แรก — แก้ไฟล์เดียว `code/base-pos/assets/js/scale-bridge.js:44`:
   - เพิ่ม `ensureScaleConnectModal()`, `showScaleConnectModal()`, `hideScaleConnectModal()` — สร้าง `div#scaleConnectModal` ด้วย JS (overlay `fixed inset-0 bg-black/50 z-9999` + card ขาว 420px) ไม่ต้องแก้ HTML
   - แก้ `initScaleBridge()` — หลัง `tryAutoConnectSerial()` ถ้า `!autoOk && !connected` → `setTimeout 400ms → showScaleConnectModal()` (ให้ badge ขึ้นก่อน)
   - `openSerialPort()` + `serial 'connect'` → `hideScaleConnectModal(false)` ปิดอัตโนมัติเมื่อต่อสำเร็จ
   - `Esc` + คลิกพื้นหลัง + ปุ่ม `ไว้ทีหลัง` → `hideScaleConnectModal(true)` + `sessionStorage scale_modal_dismissed=1` ไม่เด้งซ้ำจน reload
   - ปุ่ม `เชื่อมตาชั่ง` ที่เดิมใน `scaleStatusBadge` ยังคงไว้ — modal เรียก `window.scaleBridge.connect()` ตัวเดียวกัน
   - Export `window.scaleBridge.showModal/hideModal` เพิ่ม
   - ยืนยัน `itemQuantity.readOnly=true` เฉพาะ `connected`, ถ้า `!connected` → `readOnly=false` คีย์มือได้ปกติ
4. Verify: `node --check base-pos/assets/js/scale-bridge.js` OK, `php -l` ผ่านทุกไฟล์ scale, `docker compose exec -T db bash 99-run-migrations.sh` ทดสอบ local ผ่าน `075..079` แล้ว
5. ตรวจ prod ผ่าน Tailscale `ssh ragsaaad_v1@100.91.242.99`:
   - Prod `main @ 2a239ac` clean, `scale-bridge.js` 463 บรรทัด (ยังไม่มี modal), `purchase-orders.js` มี `scaleBridge.init()` แล้ว
   - DB `schema_migrations` มี `075..079` ครบ, `branches.scale_mode=auto` ทั้ง 4 สาขา, `scale_devices` มี `TIGER-PROD-01` (branch 1)
   - Containers Healthy, `curl auth/verify → 401` ปกติ
6. เจ้าของตัดสินใจ: **commit+push คืนนี้, deploy พรุ่งนี้เช้าที่ร้านพร้อมเช็คตาชั่งจริง** (อยู่บ้านไม่มีตาชั่งเทสไม่ได้)

## 2. สถานะปัจจุบัน (ก่อนปิดงาน 2026-09-24 เย็น)
- **Dev** `drsolodev-lenovo-z580` — `code/base-pos/assets/js/scale-bridge.js` 548 บรรทัด (มี modal) ยังไม่ได้ push ตอนสร้างไฟล์นี้ (จะ push หลังสร้างเอกสาร)
- **Prod** `ragsaaadserver` — `2a239ac` ไม่มี modal, DB พร้อมแล้ว
- Deploy จะ block ถ้ายังมี `git diff` — ต้อง commit+push ก่อน `bash deploy.sh`

## 3. เป้าหมายและแผนงาน
- **เป้าหมาย Phase 1 (PR นี้):** ครั้งแรกเด้ง modal กลางจอ บังคับเห็น ให้กด `เชื่อมตาชั่ง` (Chrome `requestPort()` popup) ครั้งต่อไป `getPorts()` ต่อเองเงียบ = เสียบแล้วเปิดเลย, ไม่ต่อก็คีย์มือได้ปกติ (ไม่บล็อค)
- **Phase 2 (PR ถัดไป):** แก้ `UNIQUE (branch_id, code)` แทน `UNIQUE code` (077), CORS `*` → `localhost:8080`, parser parity JS/Python, `GET /scale/stats`, ลด `pollAgent` ถี่
- **Deploy วันพรุ่งนี้:** JS-only, ไม่ต้อง migrate ซ้ำ, rollback ง่าย `git reset --hard HEAD@{1} && docker compose build web && up -d`

## 4. พรุ่งนี้ต้องทำอะไร (2026-09-25 เช้า ที่ร้าน)

### 4.1 ขั้นตอนปฏิบัติ (รันตามลำดับ)
```bash
# 1. บนเครื่อง dev หรือบน server ตรวจว่า code ใหม่มาถึงแล้ว
ssh ragsaaad_v1@100.91.242.99
cd ~/secondhand-pos
git fetch
git log --oneline -5   # ต้องเห็น commit feat(scale): modal ครั้งแรก...

# 2. Deploy (บน server)
cd ~/secondhand-pos/code
bash deploy.sh
# จะ: backup DB → git pull → docker compose build --no-cache web → up -d → run migrations (จะ Skip 075..079) → healthcheck auth/verify 401
# ถ้าอยากเร็ว JS-only: docker compose build web && docker compose up -d

# 3. ตรวจหลัง deploy
docker compose ps                    # ต้อง Healthy ทั้ง db/web
docker compose exec -T web curl -s -o /dev/null -w "%{http_code}" http://localhost:80/api/index.php/auth/verify  # 401 = OK
grep -n scaleConnectModal ~/secondhand-pos/code/base-pos/assets/js/scale-bridge.js  # ต้องเจอ modal code
wc -l ~/secondhand-pos/code/base-pos/assets/js/scale-bridge.js  # ต้อง 548 บรรทัด
```

### 4.2 เทสกับตาชั่ง Tiger TI-01 จริง (Chrome หน้าร้าน)
1. ล้าง permission ก่อน: Chrome → Settings → Privacy → Site settings → Serial ports → ลบ `...` (หรือ `chrome://settings/content/serialPorts`)
2. เปิด `purchase-orders` → ต้องเห็น **modal กลางจอ** `⚖️ เชื่อมตาชั่ง Tiger TI-01` → พิมพ์ `itemQuantity` ต้องยังพิมพ์ได้ (กด `ไว้ทีหลัง` ปิดได้, Esc/พื้นหลังก็ปิดได้)
3. กด `เชื่อมตาชั่ง` ใน modal → popup Chrome เลือก COM → badge เปลี่ยน `🟢 ตาชั่ง xx.xx กก. ●นิ่ง/○รอ` + `itemQuantity` ล็อคเขียว `#f0fdf4`
4. Reload หน้า → **ไม่เด้ง modal** ต้องต่อเองเงียบ `🟢`
5. ถอดสาย RS232 → badge กลับ `⚪ คีย์มือ` + input ปลดล็อค คีย์มือได้ทันที ไม่เด้ง modal ซ้ำใน session
6. เพิ่มสินค้า → `จับน้ำหนัก` / รอ auto-capture 1.5s → บันทึกบิล → ตรวจ `purchase_order_items.weight_source='scale'` และ `scale_readings` มี log

### 4.3 ถ้ามีปัญหา → Rollback ทันที
```bash
cd ~/secondhand-pos/code
git reset --hard HEAD@{1}
docker compose build web
docker compose up -d --force-recreate
# หรือ git reset --hard 2a239ac && docker compose up -d
```

## 5. ไฟล์ที่เกี่ยวข้อง (ให้ agent พรุ่งนี้อ่านก่อน)
- `code/base-pos/assets/js/scale-bridge.js:44` — modal + init + serial
- `code/base-pos/assets/js/purchase-orders.js:167` — `scaleBridge.init()` wiring
- `code/customizations/api/Controllers/ScaleController.php:19` — scale mode/devices/health
- `code/customizations/database/migrations/076_add_scale_mode_to_branches.sql` — `scale_mode` auto
- `code/deploy.sh:14` — deploy flow + healthcheck
- `code/docker/mysql-init.sh` + `customizations/database/run-migrations.sh:129` — migration runner

## 6. คำสั่งลัดสำหรับ agent พรุ่งนี้
```
# อ่านบริบท
cat HANDOFF-2026-09-24-scale-modal.md
cat AGENTS.md
# ตรวจ diff ที่จะ deploy
git log --oneline -5
git diff origin/main --stat
# ตรวจ prod
ssh ragsaaad_v1@100.91.242.99 "cd ~/secondhand-pos && git status --short && wc -l code/base-pos/assets/js/scale-bridge.js && docker compose -f code/docker-compose.yml ps"
```

## 7. หมายเหตุ
- เจ้าของอยู่บ้านคืนนี้ ไม่มีตาชั่งเทส — จึงเลื่อน deploy มาพรุ่งนี้เช้าที่ร้าน
- Untracked ไฟล์ `manifest.json`, `sw.js`, `favicon.ico`, `print_v2_tmp.py` ยังไม่ต้อง deploy (deploy.sh จะ ignore)
- อย่าอธิบายเรื่องเดิมซ้ำ — อ่านไฟล์นี้แล้วทำตาม 4.1-4.2 ได้เลย

---
สร้างโดย Muse Spark — 2026-09-24 เย็น — commit ต่อไปคือ `feat(scale): modal ครั้งแรกบังคับเห็น + auto-connect ...`
