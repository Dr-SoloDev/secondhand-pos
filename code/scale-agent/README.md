# Scale Agent — Tiger TI-01 RS232 → HTTP

Bridge ให้ `purchase-orders.js` อ่านน้ำหนักสดจากตาชั่ง Tiger TI-01 ผ่าน RS232/USB-RS232

## รันบน Windows 10 หน้าเครื่องชั่ง

```bat
pip install -r requirements.txt
python scale_agent.py                    # auto-scan COM1-COM10
python scale_agent.py --port COM3        # ระบุพอร์ต
python scale_agent.py --mock 12.34       # เทส UI โดยไม่มีตาชั่งจริง
```

## Endpoints

- `GET http://localhost:9130/health` — สถานะเชื่อมต่อ
- `GET http://localhost:9130/weight` — `{ weight, stable, unit, raw, connected, ts }`
- `GET http://localhost:9130/raw` — debug string ดิบจาก RS232

## POS Frontend

`purchase-orders.js` จะ poll `/weight` ทุก 500ms:
- เจอ 3 ครั้งติด → ล็อคช่องน้ำหนัก, โชว์ `🟢 ตาชั่ง`
- ไม่เจอ 3 ครั้งติด → ปลดล็อคเป็นคีย์มือ `⚪ คีย์มือ` (แท็บเล็ต `pos.mkxmeme.xyz` จะอยู่โหมดนี้ตลอด)

## Parser

รองรับ Tiger TI-01 generic: หาเลขทศนิยมตัวแรกในบรรทัด, `ST` = stable, นิ่ง ±0.02 เกิน 1.5 วิ = stable

ดู `raw` ที่ `/raw` เพื่อจูนหน้างาน
