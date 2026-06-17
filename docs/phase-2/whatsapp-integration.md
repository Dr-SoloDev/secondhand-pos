# WhatsApp Integration - Overview

## 🎯 วัตถุประสงค์

เชื่อม Secondhand POS System เข้ากับ WhatsApp ผ่าน [Baileys](https://github.com/WhiskeySockets/Baileys) เพื่อให้เจ้าของร้านและพนักงานได้รับการแจ้งเตือนและสามารถควบคุมระบบผ่าน WhatsApp ได้แบบ real-time

## 📊 Benefits

### สำหรับเจ้าของร้าน:
- 📱 **รับการแจ้งเตือนทันที** - ไม่ต้องเปิดคอมเช็คระบบตลอดเวลา
- 💰 **รู้ผลการดำเนินงาน** - ยอดรับซื้อ, ยอดขาย, กำไร แบบ real-time
- 📊 **Query ข้อมูลได้** - พิมพ์คำสั่งง่ายๆ ได้ทันที (เช่น "ยอดขายวันนี้")
- 🚨 **แจ้งเตือนสำคัญ** - Lot ที่ขายได้, ขาดทุน, สต็อกผิดปกติ

### สำหรับพนักงาน:
- 👥 **กลุ่มสื่อสาร** - แต่ละสาขามีกลุ่มของตัวเอง
- ✅ **เช็คอิน/เช็คเอาท์** - บันทึกเวลาทำงานผ่าน WhatsApp
- 📋 **ดูข้อมูลสาขา** - ยอดขาย, สต็อก, เป้าหมาย

### สำหรับลูกค้า:
- 🤖 **Chatbot** - ตอบคำถามอัตโนมัติ 24/7
- 📦 **ติดตามสถานะ** - เช็คสถานะการซื้อ/ขาย
- 🛍️ **สอบถามสินค้า** - ดูสินค้าที่มีขาย, ราคา

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    WhatsApp Servers                      │
└─────────────────────────────────────────────────────────┘
                            ↕ WebSocket (wss://)
┌─────────────────────────────────────────────────────────┐
│                  Baileys Client (Node.js)                │
│  - Session Management                                    │
│  - Message Handling                                      │
│  - Media Processing                                      │
│  - Event Emitter                                         │
└─────────────────────────────────────────────────────────┘
                            ↕ HTTP/REST
┌─────────────────────────────────────────────────────────┐
│              POS System (PHP Backend)                    │
│  - Webhook receiver                                      │
│  - Command dispatcher                                    │
│  - Report generator                                      │
└─────────────────────────────────────────────────────────┘
                            ↕ MySQL
┌─────────────────────────────────────────────────────────┐
│                      Database                            │
│  - sale_lots, purchase_orders                            │
│  - whatsapp_sessions, whatsapp_messages                  │
└─────────────────────────────────────────────────────────┘
```

---

## 🔧 Technology Stack

### Backend Service (Node.js)
- **Baileys** - WhatsApp Web API client
- **Express.js** - REST API server
- **Socket.IO** (optional) - Real-time updates to web dashboard
- **MySQL** - Session & message storage
- **PM2** - Process management

### Integration Layer (PHP)
- **Webhook receiver** - รับ events จาก Node.js service
- **Command parser** - แปลงคำสั่งจาก WhatsApp เป็น actions
- **Report generator** - สร้างรายงานสำหรับส่งทาง WhatsApp

---

## 📁 File Structure

```
scrap-pos/
├── services/
│   └── whatsapp/                      # WhatsApp Service (Node.js)
│       ├── package.json
│       ├── index.js                   # Main entry point
│       ├── config/
│       │   ├── default.json           # Configuration
│       │   └── database.js            # DB connection
│       ├── src/
│       │   ├── baileys/
│       │   │   ├── client.js          # Baileys initialization
│       │   │   ├── session.js         # Session management
│       │   │   └── handlers/
│       │   │       ├── messages.js    # Message handler
│       │   │       ├── connection.js  # Connection handler
│       │   │       └── events.js      # Event handler
│       │   ├── api/
│       │   │   ├── server.js          # Express server
│       │   │   └── routes/
│       │   │       ├── webhook.js     # Receive from POS
│       │   │       ├── send.js        # Send messages
│       │   │       └── status.js      # Service status
│       │   ├── commands/
│       │   │   ├── parser.js          # Parse commands
│       │   │   └── handlers/
│       │   │       ├── sales.js       # Sales commands
│       │   │       ├── purchase.js    # Purchase commands
│       │   │       └── report.js      # Report commands
│       │   └── notifications/
│       │       ├── lot-sale.js        # Lot sale notifications
│       │       ├── daily-report.js    # Daily reports
│       │       └── alerts.js          # Alert notifications
│       ├── storage/
│       │   └── auth_info/             # Baileys session data
│       └── logs/
│           └── whatsapp.log
│
├── code/customizations/api/
│   ├── Controllers/
│   │   └── WhatsAppController.php     # Webhook handler
│   └── Models/
│       ├── WhatsAppSession.php
│       └── WhatsAppMessage.php
│
└── code/customizations/database/migrations/
    ├── 022_create_whatsapp_tables.sql
    └── 023_add_whatsapp_settings.sql
```

---

## 🗄️ Database Schema

### WhatsApp Session Table
```sql
CREATE TABLE whatsapp_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(100) UNIQUE NOT NULL,
    phone_number VARCHAR(20),
    is_active BOOLEAN DEFAULT TRUE,
    qr_code TEXT,
    last_connected_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### WhatsApp Message Log
```sql
CREATE TABLE whatsapp_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(100),
    message_id VARCHAR(100),
    from_jid VARCHAR(100),
    to_jid VARCHAR(100),
    message_type ENUM('text', 'image', 'document', 'audio', 'video'),
    content TEXT,
    media_url VARCHAR(500),
    direction ENUM('inbound', 'outbound'),
    status ENUM('sent', 'delivered', 'read', 'failed'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES whatsapp_sessions(session_id)
);
```

### WhatsApp Subscribers
```sql
CREATE TABLE whatsapp_subscribers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    jid VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(100),
    role ENUM('owner', 'manager', 'staff', 'customer'),
    branch_id INT,
    is_active BOOLEAN DEFAULT TRUE,
    subscribed_events JSON,  -- ['lot_sale', 'daily_report', 'alerts']
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);
```

---

## 🔐 Authentication & Session

### QR Code Login
```javascript
// Baileys auto-generates QR code
sock.ev.on('connection.update', (update) => {
    const { connection, qr } = update
    
    if (qr) {
        // ส่ง QR code ไปที่ web dashboard
        // หรือ save เป็นไฟล์ให้ scan ผ่าน admin panel
        saveQRCode(qr)
    }
})
```

### Pairing Code (ไม่ต้อง scan QR)
```javascript
const number = '66812345678' // เบอร์เจ้าของร้าน
const code = await sock.requestPairingCode(number)
console.log('Pairing code:', code) // ABCD-EFGH
```

### Session Persistence
```javascript
import { useMultiFileAuthState } from '@whiskeysockets/baileys'

const { state, saveCreds } = await useMultiFileAuthState('./storage/auth_info')
const sock = makeWASocket({ auth: state })

// Auto-save credentials
sock.ev.on('creds.update', saveCreds)
```

---

## 📬 Message Types & Handlers

### 1. Text Messages
```javascript
sock.ev.on('messages.upsert', async ({ messages }) => {
    for (const m of messages) {
        const text = m.message?.conversation || ''
        const from = m.key.remoteJid
        
        // Parse command
        const command = parseCommand(text)
        await handleCommand(command, from)
    }
})
```

### 2. Image Messages (รูปสลิป, รูปสินค้า)
```javascript
if (m.message?.imageMessage) {
    const buffer = await downloadMediaMessage(m, 'buffer', {})
    await processImage(buffer, from)
}
```

### 3. Location Messages
```javascript
if (m.message?.locationMessage) {
    const { degreesLatitude, degreesLongitude } = m.message.locationMessage
    // Save location or process
}
```

---

## 🎯 Core Features Implementation

### Feature 1: Lot Sale Notification
📄 **ดูรายละเอียดใน:** [lot-sale-notification.md](./features/lot-sale-notification.md)

**Trigger:** เมื่อ `sale_lots.status` เปลี่ยนเป็น `'confirmed'`

**Flow:**
```
POS Backend (PHP)
  └─> Webhook POST /api/whatsapp/notify/lot-sale
       └─> WhatsApp Service (Node.js)
            └─> Format message
                 └─> Send via Baileys
```

---

### Feature 2: Daily Purchase Report
📄 **ดูรายละเอียดใน:** [daily-purchase-report.md](./features/daily-purchase-report.md)

**Trigger:** Cron job ทุกวัน 20:00 น.

**Flow:**
```
Cron Job (Node.js)
  └─> Query POS API GET /api/reports/purchase-summary
       └─> Format report
            └─> Send to owner JID
```

---

### Feature 3: Customer Service Bot
📄 **ดูรายละเอียดใน:** [customer-service-bot.md](./features/customer-service-bot.md)

**Trigger:** เมื่อได้รับข้อความจากเบอร์ที่ไม่รู้จัก

---

### Feature 4: Team Communication
📄 **ดูรายละเอียดใน:** [team-communication.md](./features/team-communication.md)

**Features:**
- สร้างกลุ่มพนักงานแต่ละสาขา
- เช็คอิน/เช็คเอาท์
- ดูยอดขายสาขา

---

## 🚀 Deployment

### 1. Setup Node.js Service

```bash
cd services/whatsapp
npm install
cp config/default.json.example config/default.json
# แก้ไข config (DB, API endpoint)
npm start
```

### 2. PM2 Process Manager

```bash
pm2 start index.js --name whatsapp-service
pm2 save
pm2 startup
```

### 3. Nginx Reverse Proxy (ถ้าต้องการ)

```nginx
location /whatsapp-api/ {
    proxy_pass http://localhost:3001/;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection 'upgrade';
    proxy_set_header Host $host;
    proxy_cache_bypass $http_upgrade;
}
```

---

## 🔒 Security Considerations

### 1. Authentication
- ใช้ JWT token สำหรับ webhook calls
- Verify webhook signature (HMAC)
- Whitelist IP addresses

### 2. Rate Limiting
- จำกัด message per minute (WhatsApp limit ~65 msg/min)
- Queue system สำหรับ bulk notifications

### 3. Data Privacy
- Encrypt session data at rest
- ไม่ log sensitive messages (เบอร์โทร, ข้อมูลลูกค้า)
- GDPR compliance (ถ้ามีลูกค้าต่างประเทศ)

---

## 📊 Monitoring & Logging

### Metrics to Track:
- 📈 Connection uptime
- 📬 Messages sent/received per day
- ⚠️ Failed messages
- 🕐 Response time
- 👥 Active subscribers

### Log Levels:
- `ERROR` - Connection failures, send failures
- `WARN` - Reconnections, rate limit warnings
- `INFO` - Messages sent/received
- `DEBUG` - Full message payloads (dev only)

---

## 💡 Best Practices

1. **Always use `getMessage` for replies & polls**
   ```javascript
   const sock = makeWASocket({
       getMessage: async (key) => {
           return await getMessageFromDatabase(key)
       }
   })
   ```

2. **Save credentials on every update**
   ```javascript
   sock.ev.on('creds.update', saveCreds)
   ```

3. **Handle reconnections gracefully**
   ```javascript
   if (connection === 'close') {
       const shouldReconnect = 
           lastDisconnect?.error?.output?.statusCode !== DisconnectReason.loggedOut
       if (shouldReconnect) connectToWhatsApp()
   }
   ```

4. **Use store for message history**
   ```javascript
   import { makeInMemoryStore } from '@whiskeysockets/baileys'
   const store = makeInMemoryStore({})
   store.bind(sock.ev)
   ```

5. **Implement message queue for bulk sends**
   - ใช้ Bull Queue หรือ BullMQ
   - Rate limit 60 messages/minute
   - Retry failed messages

---

## 🧪 Testing

### Unit Tests
```javascript
// Test command parser
test('parse sales command', () => {
    const result = parseCommand('ยอดขายวันนี้')
    expect(result).toEqual({ type: 'sales', period: 'today' })
})
```

### Integration Tests
```javascript
// Test webhook endpoint
test('POST /webhook/lot-sale sends notification', async () => {
    const response = await request(app)
        .post('/webhook/lot-sale')
        .send({ lot_id: 123 })
    expect(response.status).toBe(200)
})
```

### Manual Testing Checklist
- [ ] QR code login works
- [ ] Pairing code login works
- [ ] Lot sale notification sent correctly
- [ ] Daily report arrives at 20:00
- [ ] Commands work (ยอดขายวันนี้, etc.)
- [ ] Customer bot responds correctly
- [ ] Group creation works
- [ ] Check-in/out works

---

## 📚 References

- [Baileys Documentation](https://baileys.wiki/)
- [Baileys GitHub](https://github.com/WhiskeySockets/Baileys)
- [WhatsApp Business API](https://developers.facebook.com/docs/whatsapp)
- [Node.js Best Practices](https://github.com/goldbergyoni/nodebestpractices)

---

**Status:** 📝 Documentation Phase  
**Ready for Implementation:** Phase 2  
**Estimated Development Time:** 4-6 weeks
