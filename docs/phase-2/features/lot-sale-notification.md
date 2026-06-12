# Lot Sale Notification - แจ้งเตือนการขาย Lot แบบ Real-time

## 🎯 Overview

เมื่อมีการขาย Lot สำเร็จ (sale_lot status = 'confirmed') ระบบจะส่งการแจ้งเตือนไปยัง WhatsApp ของเจ้าของร้านทันที พร้อมข้อมูลครบถ้วนเกี่ยวกับ Lot ที่ขาย กำไร และ performance metrics

## 💡 Business Value

### สำหรับเจ้าของร้าน:
- 📊 **รู้ผลการดำเนินงานทันที** - ไม่ต้องเปิดคอม/เว็บเช็ค
- 💰 **เห็นกำไรชัดเจน** - รู้ทันทีว่ากำไรเท่าไหร่
- ⚡ **วัด Performance** - Lot ไหนขายได้เร็ว/ช้า, กำไรสูง/ต่ำ
- 🎯 **ตัดสินใจได้ไว** - เห็น pattern ของ Lot ที่ประสบความสำเร็จ
- 🚨 **รับรู้ปัญหา** - ถ้าขาดทุนหรือขายช้าเกินไป

### สำหรับการวิเคราะห์:
- 📈 **Identify best-selling categories** - หมวดไหนกำไรดี
- 🐌 **Spot slow-moving inventory** - Lot ที่อยู่นานเกินไป
- 💎 **Recognize high-margin products** - สินค้าที่กำไรสูง
- ⚠️ **Catch losses early** - แจ้งเตือนเมื่อขาดทุน

---

## 📊 Data Model

### Input: Sale Lot Data

```typescript
interface LotSaleNotificationPayload {
    // Lot Information
    lot_id: number
    reference_no: string              // SO-B1-20260613-001
    
    // Sale Details
    sale_date: string                 // ISO 8601 datetime
    buyer_name: string
    buyer_phone?: string
    
    // Financial
    total_amount: number              // ราคาขาย
    total_cost: number                // ต้นทุน (FIFO)
    profit: number                    // กำไร (calculated)
    profit_margin: number             // % กำไร
    
    // Additional Costs (Phase 2+)
    expenses?: {
        transport?: number
        labor?: number
        other?: number
    }
    
    // Items
    items: Array<{
        category_name: string
        quantity_kg: number
        unit_price: number
        subtotal: number
        fifo_cost: number
    }>
    
    // Metadata
    branch: {
        id: number
        name: string
        code: string
    }
    created_by: string                // พนักงานที่สร้าง Lot
    
    // Performance Metrics
    inventory_age_days?: number       // อายุเฉลี่ยของสินค้าใน Lot (Phase 2)
}
```

---

## 🔔 Notification Flow

### 1. Trigger Point (PHP Backend)

```php
// code/customizations/api/Controllers/SaleLotsController.php

public function confirmSaleLot($id) {
    try {
        // อัพเดตสถานะ
        $result = $this->saleLotModel->confirmSaleLot($id);
        
        if ($result['success']) {
            // ดึงข้อมูล Lot พร้อม items
            $lot = $this->saleLotModel->getSaleLotWithDetails($id);
            
            // ส่ง webhook ไปยัง WhatsApp Service
            $this->sendWhatsAppNotification('lot_sale', $lot);
            
            return $this->response->success([
                'message' => 'Sale lot confirmed successfully',
                'lot' => $lot
            ]);
        }
    } catch (Exception $e) {
        return $this->response->error($e->getMessage());
    }
}

private function sendWhatsAppNotification($type, $data) {
    $whatsappServiceUrl = getenv('WHATSAPP_SERVICE_URL') ?: 'http://localhost:3001';
    $apiKey = getenv('WHATSAPP_API_KEY');
    
    $ch = curl_init("{$whatsappServiceUrl}/webhook/notify");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'type' => $type,
        'data' => $data
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "X-API-Key: {$apiKey}"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        error_log("WhatsApp notification failed: {$response}");
    }
}
```

---

### 2. Webhook Receiver (Node.js Service)

```javascript
// services/whatsapp/src/api/routes/webhook.js

const express = require('express')
const router = express.Router()
const { sendLotSaleNotification } = require('../../notifications/lot-sale')
const { verifyApiKey } = require('../../middleware/auth')

router.post('/notify', verifyApiKey, async (req, res) => {
    try {
        const { type, data } = req.body
        
        if (type === 'lot_sale') {
            await sendLotSaleNotification(data)
            return res.json({ success: true, message: 'Notification sent' })
        }
        
        res.status(400).json({ error: 'Unknown notification type' })
    } catch (error) {
        console.error('Webhook error:', error)
        res.status(500).json({ error: error.message })
    }
})

module.exports = router
```

---

### 3. Notification Generator

```javascript
// services/whatsapp/src/notifications/lot-sale.js

const { getWASocket } = require('../baileys/client')
const config = require('config')

/**
 * ส่งการแจ้งเตือนการขาย Lot
 */
async function sendLotSaleNotification(lotData) {
    const sock = await getWASocket()
    const ownerJid = config.get('whatsapp.ownerJid') // '66812345678@s.whatsapp.net'
    
    // คำนวณ metrics
    const profitMargin = ((lotData.profit / lotData.total_cost) * 100).toFixed(2)
    const profitEmoji = getProfitEmoji(parseFloat(profitMargin))
    
    // สร้างข้อความ
    const message = formatLotSaleMessage(lotData, profitMargin, profitEmoji)
    
    // ส่งข้อความ
    await sock.sendMessage(ownerJid, { text: message })
    
    // ถ้ากำไรสูงมาก (>50%) ส่งการแจ้งเตือนพิเศษ
    if (parseFloat(profitMargin) > 50) {
        await sendHighProfitAlert(sock, ownerJid, lotData, profitMargin)
    }
    
    // ถ้าขาดทุน ส่งการแจ้งเตือนพิเศษ
    if (lotData.profit < 0) {
        await sendLossAlert(sock, ownerJid, lotData, profitMargin)
    }
    
    // บันทึก log
    await logNotification('lot_sale', ownerJid, lotData.lot_id)
}

/**
 * Format ข้อความแจ้งเตือน
 */
function formatLotSaleMessage(lotData, profitMargin, profitEmoji) {
    const itemsList = lotData.items
        .map(item => `  • ${item.category_name}: ${item.quantity_kg} กก. × ${item.unit_price} = ${item.subtotal.toLocaleString('th-TH')} ฿`)
        .join('\n')
    
    return `
${profitEmoji} *ขาย LOT สำเร็จ!*

━━━━━━━━━━━━━━━━━━━━
📦 *${lotData.reference_no}*
📅 ${formatThaiDate(lotData.sale_date)}

━━━━━━━━━━━━━━━━━━━━
💰 *การเงิน*
├ ราคาขาย: ${lotData.total_amount.toLocaleString('th-TH')} ฿
├ ต้นทุน (FIFO): ${lotData.total_cost.toLocaleString('th-TH')} ฿
${lotData.profit >= 0 ? '├ 📈 กำไร:' : '├ 📉 ขาดทุน:'} ${Math.abs(lotData.profit).toLocaleString('th-TH')} ฿
└ 📊 Margin: ${profitMargin}%

━━━━━━━━━━━━━━━━━━━━
📦 *รายการ (${lotData.items.length} หมวดหมู่)*
${itemsList}

━━━━━━━━━━━━━━━━━━━━
👤 *ผู้ซื้อ:* ${lotData.buyer_name}
${lotData.buyer_phone ? `📞 ${lotData.buyer_phone}\n` : ''}🏪 *สาขา:* ${lotData.branch.name}
💼 *สร้างโดย:* ${lotData.created_by}

${getPerformanceNote(profitMargin)}
━━━━━━━━━━━━━━━━━━━━
    `.trim()
}

/**
 * เลือก emoji ตาม profit margin
 */
function getProfitEmoji(profitMargin) {
    if (profitMargin > 50) return '💚💚💚'
    if (profitMargin > 30) return '💚💚'
    if (profitMargin > 15) return '💚'
    if (profitMargin > 0) return '✅'
    if (profitMargin === 0) return '⚪'
    return '💔'
}

/**
 * คำแนะนำตาม performance
 */
function getPerformanceNote(profitMargin) {
    const margin = parseFloat(profitMargin)
    
    if (margin > 50) {
        return '🔥 *EXCELLENT!* กำไรสูงมาก ควรรับซื้อสินค้าประเภทนี้เพิ่ม'
    } else if (margin > 30) {
        return '✨ *VERY GOOD!* กำไรดีมาก'
    } else if (margin > 15) {
        return '👍 *GOOD* กำไรดี'
    } else if (margin > 0) {
        return '✅ *OK* มีกำไร'
    } else if (margin === 0) {
        return '⚪ *BREAK-EVEN* ไม่ได้กำไร ไม่ขาดทุน'
    } else if (margin > -10) {
        return '⚠️ *SMALL LOSS* ขาดทุนเล็กน้อย'
    } else {
        return '💔 *LOSS* ขาดทุน - ควรทบทวนการรับซื้อ'
    }
}

/**
 * แจ้งเตือนพิเศษสำหรับกำไรสูง
 */
async function sendHighProfitAlert(sock, ownerJid, lotData, profitMargin) {
    const message = `
━━━━━━━━━━━━━━━━━━━━
💎 *กำไรสูงพิเศษ!*

LOT: ${lotData.reference_no}
กำไร: ${lotData.profit.toLocaleString('th-TH')} ฿ (${profitMargin}%)

👏 ยอดเยี่ยม! Lot นี้ประสบความสำเร็จมาก

💡 *แนะนำ:*
• รับซื้อสินค้าหมวดหมู่เหล่านี้มากขึ้น
${lotData.items.map(i => `  - ${i.category_name}`).join('\n')}
• ศึกษา pattern ของ Lot นี้
• พิจารณาเพิ่มราคารับซื้อเล็กน้อยเพื่อได้ volume มากขึ้น
━━━━━━━━━━━━━━━━━━━━
    `.trim()
    
    await sock.sendMessage(ownerJid, { text: message })
}

/**
 * แจ้งเตือนพิเศษสำหรับขาดทุน
 */
async function sendLossAlert(sock, ownerJid, lotData, profitMargin) {
    const loss = Math.abs(lotData.profit)
    const lossPercent = Math.abs(parseFloat(profitMargin))
    
    // วิเคราะห์สาเหตุ
    const analysis = analyzeLoss(lotData)
    
    const message = `
⚠️ *แจ้งเตือนการขาดทุน*

LOT: ${lotData.reference_no}
ขาดทุน: ${loss.toLocaleString('th-TH')} ฿ (${lossPercent.toFixed(2)}%)

🔍 *วิเคราะห์สาเหตุที่เป็นไปได้:*
${analysis.join('\n')}

💡 *คำแนะนำ:*
• ทบทวนราคารับซื้อสำหรับหมวดหมู่เหล่านี้
${lotData.items.map(i => `  - ${i.category_name}: รับซื้อ ${(i.fifo_cost / i.quantity_kg).toFixed(2)} ฿/กก. ขาย ${i.unit_price} ฿/กก.`).join('\n')}
• พิจารณาหาตลาดที่จ่ายราคาสูงกว่า
• อาจต้องปรับกลยุทธ์การรับซื้อ
    `.trim()
    
    await sock.sendMessage(ownerJid, { text: message })
}

/**
 * วิเคราะห์สาเหตุการขาดทุน
 */
function analyzeLoss(lotData) {
    const reasons = []
    
    // เช็คแต่ละหมวดหมู่
    for (const item of lotData.items) {
        const itemMargin = ((item.subtotal - item.fifo_cost) / item.fifo_cost * 100)
        
        if (itemMargin < -20) {
            reasons.push(`• ${item.category_name}: รับซื้อแพงเกินไป (ขาดทุน ${Math.abs(itemMargin).toFixed(1)}%)`)
        } else if (itemMargin < 0) {
            reasons.push(`• ${item.category_name}: ขายถูกเกินไป หรือ รับซื้อแพงไป`)
        }
    }
    
    // ถ้าไม่มีเหตุผลเฉพาะ
    if (reasons.length === 0) {
        reasons.push('• ราคาตลาดตกต่ำ หรือคู่แข่งให้ราคาสูงกว่า')
    }
    
    return reasons
}

/**
 * Format วันที่เป็นภาษาไทย
 */
function formatThaiDate(dateString) {
    const date = new Date(dateString)
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

/**
 * บันทึก log notification
 */
async function logNotification(type, recipient, reference_id) {
    // TODO: บันทึกลง database
    console.log(`[NOTIFICATION] ${type} sent to ${recipient} for ${reference_id}`)
}

module.exports = {
    sendLotSaleNotification,
    formatLotSaleMessage,
    getProfitEmoji
}
```

---

## 📱 Message Examples

### ตัวอย่างที่ 1: กำไรดี (30%)

```
💚💚 ขาย LOT สำเร็จ!

━━━━━━━━━━━━━━━━━━━━
📦 SO-B1-20260613-001
📅 13 มิถุนายน 2567 14:30

━━━━━━━━━━━━━━━━━━━━
💰 การเงิน
├ ราคาขาย: 85,000 ฿
├ ต้นทุน (FIFO): 65,000 ฿
├ 📈 กำไร: 20,000 ฿
└ 📊 Margin: 30.77%

━━━━━━━━━━━━━━━━━━━━
📦 รายการ (5 หมวดหมู่)
  • พลาสติก PET: 250 กก. × 18 = 4,500 ฿
  • เหล็กเก่า: 500 กก. × 12 = 6,000 ฿
  • อลูมิเนียม: 180 กก. × 85 = 15,300 ฿
  • ทองแดง: 95 กก. × 310 = 29,450 ฿
  • ทองเหลือง: 150 กก. × 200 = 30,000 ฿

━━━━━━━━━━━━━━━━━━━━
👤 ผู้ซื้อ: บริษัท รีไซเคิล จำกัด
📞 02-123-4567
🏪 สาขา: สาขาเซ็นทรัล
💼 สร้างโดย: สมชาย

✨ VERY GOOD! กำไรดีมาก
━━━━━━━━━━━━━━━━━━━━
```

### ตัวอย่างที่ 2: กำไรสูงมาก (55%)

```
💚💚💚 ขาย LOT สำเร็จ!

━━━━━━━━━━━━━━━━━━━━
📦 SO-B2-20260613-002
📅 13 มิถุนายน 2567 16:45

━━━━━━━━━━━━━━━━━━━━
💰 การเงิน
├ ราคาขาย: 62,000 ฿
├ ต้นทุน (FIFO): 40,000 ฿
├ 📈 กำไร: 22,000 ฿
└ 📊 Margin: 55.00%

━━━━━━━━━━━━━━━━━━━━
📦 รายการ (3 หมวดหมู่)
  • ทองแดง: 120 กก. × 320 = 38,400 ฿
  • ทองเหลือง: 80 กก. × 205 = 16,400 ฿
  • สแตนเลส: 50 กก. × 145 = 7,250 ฿

━━━━━━━━━━━━━━━━━━━━
👤 ผู้ซื้อ: บริษัท เมทัลรีไซเคิล
🏪 สาขา: สาขาบิ๊กซี
💼 สร้างโดย: สมหญิง

🔥 EXCELLENT! กำไรสูงมาก ควรรับซื้อสินค้าประเภทนี้เพิ่ม
━━━━━━━━━━━━━━━━━━━━
```

**ตามด้วยข้อความพิเศษ:**

```
━━━━━━━━━━━━━━━━━━━━
💎 กำไรสูงพิเศษ!

LOT: SO-B2-20260613-002
กำไร: 22,000 ฿ (55.00%)

👏 ยอดเยี่ยม! Lot นี้ประสบความสำเร็จมาก

💡 แนะนำ:
• รับซื้อสินค้าหมวดหมู่เหล่านี้มากขึ้น
  - ทองแดง
  - ทองเหลือง
  - สแตนเลส
• ศึกษา pattern ของ Lot นี้
• พิจารณาเพิ่มราคารับซื้อเล็กน้อยเพื่อได้ volume มากขึ้น
━━━━━━━━━━━━━━━━━━━━
```

### ตัวอย่างที่ 3: ขาดทุน (-15%)

```
💔 ขาย LOT สำเร็จ!

━━━━━━━━━━━━━━━━━━━━
📦 SO-B3-20260613-003
📅 13 มิถุนายน 2567 18:20

━━━━━━━━━━━━━━━━━━━━
💰 การเงิน
├ ราคาขาย: 34,000 ฿
├ ต้นทุน (FIFO): 40,000 ฿
├ 📉 ขาดทุน: 6,000 ฿
└ 📊 Margin: -15.00%

━━━━━━━━━━━━━━━━━━━━
📦 รายการ (2 หมวดหมู่)
  • พลาสติก HDPE: 350 กก. × 15 = 5,250 ฿
  • เหล็กเก่า: 800 กก. × 36 = 28,800 ฿

━━━━━━━━━━━━━━━━━━━━
👤 ผู้ซื้อ: โรงงาน ABC
🏪 สาขา: สาขาโลตัส
💼 สร้างโดย: สมศรี

💔 LOSS ขาดทุน - ควรทบทวนการรับซื้อ
━━━━━━━━━━━━━━━━━━━━
```

**ตามด้วยข้อความเตือนพิเศษ:**

```
⚠️ แจ้งเตือนการขาดทุน

LOT: SO-B3-20260613-003
ขาดทุน: 6,000 ฿ (15.00%)

🔍 วิเคราะห์สาเหตุที่เป็นไปได้:
• พลาสติก HDPE: รับซื้อแพงเกินไป (ขาดทุน 28.6%)
• เหล็กเก่า: ขายถูกเกินไป หรือ รับซื้อแพงไป

💡 คำแนะนำ:
• ทบทวนราคารับซื้อสำหรับหมวดหมู่เหล่านี้
  - พลาสติก HDPE: รับซื้อ 18.00 ฿/กก. ขาย 15 ฿/กก.
  - เหล็กเก่า: รับซื้อ 40.00 ฿/กก. ขาย 36 ฿/กก.
• พิจารณาหาตลาดที่จ่ายราคาสูงกว่า
• อาจต้องปรับกลยุทธ์การรับซื้อ
```

---

## 🧪 Testing

### Unit Tests

```javascript
// __tests__/notifications/lot-sale.test.js

const { formatLotSaleMessage, getProfitEmoji } = require('../../src/notifications/lot-sale')

describe('Lot Sale Notification', () => {
    test('formats message correctly for profit', () => {
        const lotData = {
            reference_no: 'SO-B1-20260613-001',
            total_amount: 85000,
            total_cost: 65000,
            profit: 20000,
            // ... other fields
        }
        
        const message = formatLotSaleMessage(lotData, '30.77', '💚💚')
        
        expect(message).toContain('SO-B1-20260613-001')
        expect(message).toContain('85,000')
        expect(message).toContain('20,000')
        expect(message).toContain('30.77%')
    })
    
    test('returns correct emoji for profit margin', () => {
        expect(getProfitEmoji(60)).toBe('💚💚💚')
        expect(getProfitEmoji(35)).toBe('💚💚')
        expect(getProfitEmoji(20)).toBe('💚')
        expect(getProfitEmoji(5)).toBe('✅')
        expect(getProfitEmoji(0)).toBe('⚪')
        expect(getProfitEmoji(-10)).toBe('💔')
    })
})
```

### Integration Test

```javascript
// __tests__/integration/webhook-lot-sale.test.js

const request = require('supertest')
const app = require('../../src/api/server')

describe('POST /webhook/notify', () => {
    test('sends lot sale notification', async () => {
        const payload = {
            type: 'lot_sale',
            data: {
                lot_id: 123,
                reference_no: 'SO-B1-20260613-001',
                // ... complete payload
            }
        }
        
        const response = await request(app)
            .post('/webhook/notify')
            .set('X-API-Key', process.env.TEST_API_KEY)
            .send(payload)
        
        expect(response.status).toBe(200)
        expect(response.body.success).toBe(true)
    })
})
```

---

## 📊 Monitoring

### Metrics to Track:
- ✅ Notifications sent (success count)
- ❌ Failed notifications (error count)
- ⏱️ Latency (webhook → WhatsApp sent)
- 📱 Delivery status (sent/delivered/read)

### Alerts:
- 🚨 Notification failure rate > 5%
- 🚨 Latency > 10 seconds
- 🚨 WhatsApp connection down

---

## 🔄 Future Enhancements (Phase 3+)

1. **Inventory Age Tracking**
   - แจ้งว่าสินค้าใน Lot นี้อยู่ในคลังกี่วันโดยเฉลี่ย
   - ใช้ `purchase_order_items.purchase_date` คำนวณ

2. **Historical Comparison**
   - เปรียบเทียบกับ Lot ที่ขายไปในอดีต
   - "กำไรดีกว่าเดือนที่แล้ว 15%"

3. **Predictive Analytics**
   - ทำนายว่า Lot แบบนี้จะขายได้ภายในกี่วัน
   - แนะนำราคาขายที่เหมาะสม

4. **Multi-recipient**
   - ส่งให้ manager แต่ละสาขาด้วย
   - แยกข้อมูลตามสิทธิ์ (owner เห็นทุกอย่าง, manager เห็นแค่สาขาตัวเอง)

5. **Interactive Actions**
   - Reply ข้อความเพื่อดูรายละเอียดเพิ่มเติม
   - พิมพ์ "ดู PO" เพื่อดูว่ารับซื้อมาจาก PO ไหนบ้าง

---

**Status:** 📝 Ready for Implementation  
**Priority:** ⭐⭐⭐⭐⭐ (สูงสุด)  
**Estimated Time:** 2-3 days
