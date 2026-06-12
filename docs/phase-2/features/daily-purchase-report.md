# Daily Purchase Report - สรุปยอดรับซื้อรายวัน

## 🎯 Overview

ระบบจะส่งรายงานสรุปยอดรับซื้อ (Purchase Orders) ทุกวันเวลา **20:00 น.** ไปยัง WhatsApp ของเจ้าของร้าน เพื่อให้รู้ว่าวันนี้รับซื้ออะไรไปบ้าง ใช้เงินไปเท่าไหร่ และแนวโน้มเป็นอย่างไร

## 💡 Business Value

### สำหรับเจ้าของร้าน:
- 💰 **ควบคุมกระแสเงินสด** - รู้ว่าใช้เงินไปเท่าไหร่ต่อวัน
- 📊 **เห็นภาพรวมรายวัน** - ไม่ต้องเปิดระบบเช็คเอง
- 📈 **Compare กับวันก่อน** - รู้ trend ว่าเพิ่มขึ้นหรือลดลง
- 🎯 **Plan การจัดการสต็อก** - รู้ว่ารับซื้อหมวดไหนมากที่สุด
- 🚨 **เตือนความผิดปกติ** - เช่น ใช้เงินมากผิดปกติ, รับซื้อหมวดเดิมซ้ำๆ

### สำหรับการตัดสินใจ:
- 💵 **Cash Flow Management** - วางแผนเงินสำรองให้พอ
- 🏪 **Branch Performance** - สาขาไหนรับซื้อเยอะสุด
- 📦 **Category Strategy** - หมวดไหนควรโฟกัส/ลด
- 👥 **Staff Performance** - พนักงานคนไหนรับซื้อเก่ง

---

## 📊 Report Structure

### Section 1: สรุปภาพรวม (Summary)
- จำนวน PO ทั้งหมด
- จำนวนรายการ (items) รวม
- มูลค่ารวม (บาท)
- เงินสด vs โอนเงิน
- ราคาเฉลี่ยต่อ PO

### Section 2: แยกตามสาขา (By Branch)
- แต่ละสาขารับซื้อเท่าไหร่
- Percentage contribution

### Section 3: แยกตามหมวดหมู่ (By Category)
- หมวดไหนรับซื้อมากที่สุด
- Top 5 categories

### Section 4: แยกตามสภาพ/เกรด (By Tier - ถ้ามี)
- บิล 1 (tier1) กี่รายการ เท่าไหร่
- บิล 2 (tier2) กี่รายการ เท่าไหร่
- บิล 3 (tier3) กี่รายการ เท่าไหร่

### Section 5: รายการใหญ่สุด (Top Purchases)
- Top 5 PO ที่มูลค่าสูงสุด

### Section 6: เปรียบเทียบ (Comparison)
- เทียบกับเมื่อวาน (+/- เท่าไหร่)
- เทียบกับสัปดาห์ที่แล้วในวันเดียวกัน

### Section 7: Insights & Recommendations
- ข้อสังเกตพิเศษ
- คำแนะนำ

---

## 🔄 Report Flow

### 1. Cron Job Scheduler (Node.js)

```javascript
// services/whatsapp/src/cron/daily-purchase-report.js

const cron = require('node-cron')
const { generateAndSendDailyReport } = require('../notifications/daily-purchase-report')

/**
 * Schedule: ทุกวัน 20:00 น.
 * Timezone: Asia/Bangkok
 */
function setupDailyPurchaseReport() {
    // '0 20 * * *' = 20:00 ทุกวัน
    cron.schedule('0 20 * * *', async () => {
        console.log('[CRON] Running daily purchase report...')
        
        try {
            await generateAndSendDailyReport()
            console.log('[CRON] Daily purchase report sent successfully')
        } catch (error) {
            console.error('[CRON] Failed to send daily purchase report:', error)
            
            // แจ้งเตือน error ให้ admin
            await notifyError('daily_purchase_report', error.message)
        }
    }, {
        timezone: 'Asia/Bangkok'
    })
    
    console.log('📅 Daily Purchase Report cron job scheduled (20:00 daily)')
}

module.exports = { setupDailyPurchaseReport }
```

---

### 2. Data Fetching (Query POS API)

```javascript
// services/whatsapp/src/notifications/daily-purchase-report.js

const axios = require('axios')
const config = require('config')
const { getWASocket } = require('../baileys/client')

async function generateAndSendDailyReport() {
    // ดึงข้อมูลจาก POS API
    const reportData = await fetchPurchaseData()
    
    // สร้างข้อความรายงาน
    const message = formatDailyReport(reportData)
    
    // ส่งทาง WhatsApp
    const sock = await getWASocket()
    const ownerJid = config.get('whatsapp.ownerJid')
    
    await sock.sendMessage(ownerJid, { text: message })
    
    // ส่งกราฟ (optional)
    if (reportData.chartUrl) {
        await sock.sendMessage(ownerJid, {
            image: { url: reportData.chartUrl },
            caption: '📊 กราฟยอดรับซื้อรายสาขา'
        })
    }
}

/**
 * ดึงข้อมูลจาก POS Backend
 */
async function fetchPurchaseData() {
    const posApiUrl = config.get('pos.apiUrl')
    const apiKey = config.get('pos.apiKey')
    
    // วันนี้ (00:00 - 23:59)
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const todayStr = today.toISOString().split('T')[0]
    
    // API call: GET /api/reports/purchase-report?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
    const response = await axios.get(`${posApiUrl}/reports/purchase-report`, {
        params: {
            date_from: todayStr,
            date_to: todayStr,
            format: 'json'
        },
        headers: {
            'X-API-Key': apiKey
        }
    })
    
    const todayData = response.data
    
    // ดึงข้อมูลเมื่อวาน
    const yesterday = new Date(today)
    yesterday.setDate(yesterday.getDate() - 1)
    const yesterdayStr = yesterday.toISOString().split('T')[0]
    
    const yesterdayResponse = await axios.get(`${posApiUrl}/reports/purchase-report`, {
        params: {
            date_from: yesterdayStr,
            date_to: yesterdayStr,
            format: 'json'
        },
        headers: {
            'X-API-Key': apiKey
        }
    })
    
    const yesterdayData = yesterdayResponse.data
    
    // ดึงข้อมูลสัปดาห์ที่แล้วในวันเดียวกัน
    const lastWeek = new Date(today)
    lastWeek.setDate(lastWeek.getDate() - 7)
    const lastWeekStr = lastWeek.toISOString().split('T')[0]
    
    const lastWeekResponse = await axios.get(`${posApiUrl}/reports/purchase-report`, {
        params: {
            date_from: lastWeekStr,
            date_to: lastWeekStr,
            format: 'json'
        },
        headers: {
            'X-API-Key': apiKey
        }
    })
    
    const lastWeekData = lastWeekResponse.data
    
    // รวมข้อมูล
    return {
        today: todayData,
        yesterday: yesterdayData,
        lastWeek: lastWeekData,
        date: today
    }
}
```

---

### 3. Report Formatter

```javascript
/**
 * Format รายงานเป็นข้อความ WhatsApp
 */
function formatDailyReport(data) {
    const { today, yesterday, lastWeek, date } = data
    
    // คำนวณ comparison
    const comparison = calculateComparison(today, yesterday, lastWeek)
    
    // สร้าง insights
    const insights = generateInsights(today, comparison)
    
    const thaiDate = date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    })
    
    return `
📊 *สรุปยอดรับซื้อประจำวัน*
📅 ${thaiDate}

━━━━━━━━━━━━━━━━━━━━
💰 *ภาพรวม*
├ 📋 จำนวน PO: ${today.summary.totalPOs} รายการ
├ 📦 จำนวนไอเทม: ${today.summary.totalItems} รายการ
├ 💵 มูลค่ารวม: ${today.summary.totalAmount.toLocaleString('th-TH')} ฿
├ 💰 เงินสด: ${today.summary.cashAmount.toLocaleString('th-TH')} ฿
├ 🏦 โอนเงิน: ${today.summary.transferAmount.toLocaleString('th-TH')} ฿
└ 📊 เฉลี่ยต่อ PO: ${today.summary.avgPerPO.toLocaleString('th-TH')} ฿

━━━━━━━━━━━━━━━━━━━━
🏪 *รายสาขา*
${formatBranchBreakdown(today.byBranch, today.summary.totalAmount)}

━━━━━━━━━━━━━━━━━━━━
📦 *หมวดหมู่ยอดนิยม Top 5*
${formatTopCategories(today.byCategory)}

${today.byTier && today.byTier.length > 0 ? `
━━━━━━━━━━━━━━━━━━━━
💎 *แยกตามเกรด*
${formatTierBreakdown(today.byTier)}
` : ''}
━━━━━━━━━━━━━━━━━━━━
🏆 *รายการใหญ่สุด Top 5*
${formatTopPurchases(today.topPurchases)}

━━━━━━━━━━━━━━━━━━━━
📈 *เปรียบเทียบ*

*เทียบกับเมื่อวาน:*
${formatComparison(comparison.vsYesterday, 'POs', 'amount')}

*เทียบกับสัปดาห์ที่แล้ว (${lastWeek.date}):*
${formatComparison(comparison.vsLastWeek, 'POs', 'amount')}

${insights.length > 0 ? `
━━━━━━━━━━━━━━━━━━━━
💡 *ข้อสังเกต & คำแนะนำ*
${insights.map(i => `• ${i}`).join('\n')}
` : ''}
━━━━━━━━━━━━━━━━━━━━
    `.trim()
}

/**
 * Format breakdown ตามสาขา
 */
function formatBranchBreakdown(branches, totalAmount) {
    return branches
        .sort((a, b) => b.amount - a.amount)
        .map(branch => {
            const percentage = ((branch.amount / totalAmount) * 100).toFixed(1)
            return `├ ${branch.name}: ${branch.amount.toLocaleString('th-TH')} ฿ (${percentage}%)\n  └ ${branch.pos_count} POs`
        })
        .join('\n')
}

/**
 * Format top categories
 */
function formatTopCategories(categories) {
    return categories
        .slice(0, 5)
        .map((cat, index) => {
            const emoji = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'][index]
            return `${emoji} ${cat.category_name}: ${cat.total_kg.toLocaleString('th-TH')} กก. (${cat.total_amount.toLocaleString('th-TH')} ฿)`
        })
        .join('\n')
}

/**
 * Format tier breakdown
 */
function formatTierBreakdown(tiers) {
    const tierNames = {
        'tier1': 'บิล 1',
        'tier2': 'บิล 2',
        'tier3': 'บิล 3'
    }
    
    return tiers
        .map(tier => {
            const name = tierNames[tier.tier] || tier.tier
            return `├ ${name}: ${tier.count} รายการ = ${tier.amount.toLocaleString('th-TH')} ฿`
        })
        .join('\n')
}

/**
 * Format top purchases
 */
function formatTopPurchases(purchases) {
    return purchases
        .slice(0, 5)
        .map((po, index) => {
            return `${index + 1}. ${po.reference_no}\n   ${po.seller_name} • ${po.total_amount.toLocaleString('th-TH')} ฿`
        })
        .join('\n\n')
}

/**
 * คำนวณ comparison
 */
function calculateComparison(today, yesterday, lastWeek) {
    return {
        vsYesterday: {
            posChange: today.summary.totalPOs - yesterday.summary.totalPOs,
            amountChange: today.summary.totalAmount - yesterday.summary.totalAmount,
            percentChange: yesterday.summary.totalAmount > 0
                ? ((today.summary.totalAmount - yesterday.summary.totalAmount) / yesterday.summary.totalAmount * 100).toFixed(1)
                : 0
        },
        vsLastWeek: {
            posChange: today.summary.totalPOs - lastWeek.summary.totalPOs,
            amountChange: today.summary.totalAmount - lastWeek.summary.totalAmount,
            percentChange: lastWeek.summary.totalAmount > 0
                ? ((today.summary.totalAmount - lastWeek.summary.totalAmount) / lastWeek.summary.totalAmount * 100).toFixed(1)
                : 0
        }
    }
}

/**
 * Format comparison text
 */
function formatComparison(comp, posLabel, amountLabel) {
    const posEmoji = comp.posChange > 0 ? '📈' : comp.posChange < 0 ? '📉' : '➡️'
    const amountEmoji = comp.amountChange > 0 ? '📈' : comp.amountChange < 0 ? '📉' : '➡️'
    
    const posSign = comp.posChange > 0 ? '+' : ''
    const amountSign = comp.amountChange > 0 ? '+' : ''
    const percentSign = comp.percentChange > 0 ? '+' : ''
    
    return `
├ ${posEmoji} POs: ${posSign}${comp.posChange} รายการ
└ ${amountEmoji} มูลค่า: ${amountSign}${Math.abs(comp.amountChange).toLocaleString('th-TH')} ฿ (${percentSign}${comp.percentChange}%)
    `.trim()
}

/**
 * สร้าง insights อัตโนมัติ
 */
function generateInsights(today, comparison) {
    const insights = []
    
    // 1. Trend analysis
    if (comparison.vsYesterday.percentChange > 20) {
        insights.push(`📈 ยอดรับซื้อเพิ่มขึ้น ${comparison.vsYesterday.percentChange}% จากเมื่อวาน - แนวโน้มดี`)
    } else if (comparison.vsYesterday.percentChange < -20) {
        insights.push(`📉 ยอดรับซื้อลดลง ${Math.abs(comparison.vsYesterday.percentChange)}% จากเมื่อวาน - ควรสำรวจสาเหตุ`)
    }
    
    // 2. Branch concentration
    const topBranch = today.byBranch[0]
    const topBranchPercent = (topBranch.amount / today.summary.totalAmount * 100).toFixed(1)
    
    if (topBranchPercent > 50) {
        insights.push(`🏪 ${topBranch.name} คิดเป็น ${topBranchPercent}% ของยอดรวม - สาขานี้โดดเด่น`)
    }
    
    // 3. Category concentration
    const topCategory = today.byCategory[0]
    const topCatPercent = (topCategory.total_amount / today.summary.totalAmount * 100).toFixed(1)
    
    if (topCatPercent > 40) {
        insights.push(`📦 ${topCategory.category_name} คิดเป็น ${topCatPercent}% - ครองตลาดหมวดนี้`)
    }
    
    // 4. Cash vs Transfer
    const cashPercent = (today.summary.cashAmount / today.summary.totalAmount * 100).toFixed(1)
    
    if (cashPercent > 70) {
        insights.push(`💰 จ่ายเงินสด ${cashPercent}% - ควรเตรียมสภาพคล่องให้เพียงพอ`)
    }
    
    // 5. Average PO size
    if (today.summary.avgPerPO > 5000) {
        insights.push(`💎 ค่าเฉลี่ยต่อ PO สูง (${today.summary.avgPerPO.toLocaleString('th-TH')} ฿) - รับซื้อรายใหญ่`)
    }
    
    // 6. Consistency check
    if (today.summary.totalPOs < 5) {
        insights.push(`⚠️ จำนวน PO วันนี้น้อย (${today.summary.totalPOs} รายการ) - อาจเป็นวันที่ซบเซา`)
    }
    
    return insights
}

module.exports = {
    generateAndSendDailyReport,
    formatDailyReport,
    fetchPurchaseData
}
```

---

## 📱 Message Example

```
📊 สรุปยอดรับซื้อประจำวัน
📅 13 มิถุนายน 2567

━━━━━━━━━━━━━━━━━━━━
💰 ภาพรวม
├ 📋 จำนวน PO: 24 รายการ
├ 📦 จำนวนไอเทม: 87 รายการ
├ 💵 มูลค่ารวม: 185,400 ฿
├ 💰 เงินสด: 132,500 ฿
├ 🏦 โอนเงิน: 52,900 ฿
└ 📊 เฉลี่ยต่อ PO: 7,725 ฿

━━━━━━━━━━━━━━━━━━━━
🏪 รายสาขา
├ สาขาเซ็นทรัล: 85,200 ฿ (46.0%)
  └ 11 POs
├ สาขาบิ๊กซี: 58,400 ฿ (31.5%)
  └ 8 POs
├ สาขาโลตัส: 28,900 ฿ (15.6%)
  └ 3 POs
├ สาขาแม็คโคร: 12,900 ฿ (7.0%)
  └ 2 POs

━━━━━━━━━━━━━━━━━━━━
📦 หมวดหมู่ยอดนิยม Top 5
🥇 ทองแดง: 285 กก. (88,350 ฿)
🥈 อลูมิเนียม: 420 กก. (35,700 ฿)
🥉 ทองเหลือง: 180 กก. (36,000 ฿)
4️⃣ เหล็กเก่า: 650 กก. (7,800 ฿)
5️⃣ พลาสติก PET: 380 กก. (6,840 ฿)

━━━━━━━━━━━━━━━━━━━━
💎 แยกตามเกรด
├ บิล 1: 52 รายการ = 98,500 ฿
├ บิล 2: 28 รายการ = 68,200 ฿
├ บิล 3: 7 รายการ = 18,700 ฿

━━━━━━━━━━━━━━━━━━━━
🏆 รายการใหญ่สุด Top 5
1. PO-B1-20260613-008
   นายสมชาย ใจดี • 18,500 ฿

2. PO-B1-20260613-015
   บริษัท เก็บขยะ จำกัด • 15,200 ฿

3. PO-B2-20260613-006
   นางสาวสมหญิง รักดี • 12,800 ฿

4. PO-B1-20260613-003
   นายประสิทธิ์ มั่นคง • 11,500 ฿

5. PO-B2-20260613-011
   ร้าน ABC • 10,200 ฿

━━━━━━━━━━━━━━━━━━━━
📈 เปรียบเทียบ

เทียบกับเมื่อวาน:
├ 📈 POs: +3 รายการ
└ 📈 มูลค่า: +24,500 ฿ (+15.2%)

เทียบกับสัปดาห์ที่แล้ว (6 มิ.ย.):
├ 📈 POs: +5 รายการ
└ 📈 มูลค่า: +38,200 ฿ (+26.0%)

━━━━━━━━━━━━━━━━━━━━
💡 ข้อสังเกต & คำแนะนำ
• 📈 ยอดรับซื้อเพิ่มขึ้น 26.0% จากสัปดาห์ที่แล้ว - แนวโน้มดี
• 🏪 สาขาเซ็นทรัล คิดเป็น 46.0% ของยอดรวม - สาขานี้โดดเด่น
• 📦 ทองแดง คิดเป็น 47.7% - ครองตลาดหมวดนี้
• 💰 จ่ายเงินสด 71.5% - ควรเตรียมสภาพคล่องให้เพียงพอ
━━━━━━━━━━━━━━━━━━━━
```

---

## ⚙️ Configuration

### Environment Variables

```bash
# .env (WhatsApp Service)

# Schedule
DAILY_REPORT_TIME=20:00
DAILY_REPORT_TIMEZONE=Asia/Bangkok

# Recipients
OWNER_JID=66812345678@s.whatsapp.net
MANAGER_JIDS=66898765432@s.whatsapp.net,66887654321@s.whatsapp.net

# POS API
POS_API_URL=http://localhost/api
POS_API_KEY=your-secret-key

# Report Options
INCLUDE_CHART=true
TOP_PURCHASES_LIMIT=5
TOP_CATEGORIES_LIMIT=5
```

---

## 🧪 Testing

### Manual Test

```bash
# รันทันที (ไม่ต้องรอ 20:00)
node services/whatsapp/src/notifications/daily-purchase-report.js
```

### Unit Test

```javascript
// __tests__/notifications/daily-purchase-report.test.js

const { formatDailyReport, generateInsights } = require('../../src/notifications/daily-purchase-report')

describe('Daily Purchase Report', () => {
    test('formats report correctly', () => {
        const mockData = {
            today: {
                summary: {
                    totalPOs: 24,
                    totalItems: 87,
                    totalAmount: 185400,
                    // ...
                },
                // ...
            },
            yesterday: { /* ... */ },
            lastWeek: { /* ... */ },
            date: new Date('2026-06-13')
        }
        
        const message = formatDailyReport(mockData)
        
        expect(message).toContain('24 รายการ')
        expect(message).toContain('185,400')
    })
    
    test('generates insights correctly', () => {
        const mockToday = { /* ... */ }
        const mockComparison = { /* ... */ }
        
        const insights = generateInsights(mockToday, mockComparison)
        
        expect(insights).toBeInstanceOf(Array)
        expect(insights.length).toBeGreaterThan(0)
    })
})
```

---

## 🔄 Future Enhancements (Phase 3+)

### 1. Interactive Report
- พิมพ์ "รายละเอียดสาขา 1" → ดูรายละเอียด PO ทั้งหมดของสาขา 1
- พิมพ์ "รายละเอียดหมวด ทองแดง" → ดูว่ารับซื้อจากใครบ้าง

### 2. Weekly/Monthly Report
- รายงานสรุปรายสัปดาห์ (ทุกวันจันทร์)
- รายงานสรุปรายเดือน (วันที่ 1 ของเดือน)

### 3. Custom Schedule
- เจ้าของกำหนดเวลาส่งเองได้ (เช่น 18:00, 22:00)
- เลือกว่าต้องการรับวันไหนบ้าง (เช่น วันจันทร์-ศุกร์)

### 4. Multi-recipient with Permissions
- Manager แต่ละสาขาได้รับรายงานเฉพาะสาขาตัวเอง
- Owner ได้รับรายงานรวมทุกสาขา

### 5. Chart Generation
- สร้างกราฟ (pie chart, bar chart) ส่งไปด้วย
- ใช้ Chart.js + canvas/puppeteer generate รูป

### 6. Anomaly Detection
- แจ้งเตือนทันทีถ้ามีความผิดปกติ (ไม่ต้องรอถึง 20:00)
- เช่น: ใช้เงินเกิน 200,000 ฿ ในวันเดียว

---

## 📊 Monitoring

### Metrics:
- ✅ Reports sent successfully
- ❌ Failed reports
- ⏱️ Generation time
- 📱 Delivery status

### Alerts:
- 🚨 Report generation failed
- 🚨 Report not sent by 20:05

---

**Status:** 📝 Ready for Implementation  
**Priority:** ⭐⭐⭐⭐ (สูง)  
**Estimated Time:** 2-3 days  
**Dependencies:** Lot Sale Notification (แชร์โค้ดร่วมกันได้)
