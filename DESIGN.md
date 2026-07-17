# DESIGN.md — Secondhand POS

**ระบบจัดการร้านรับซื้อของเก่า | Junk Shop POS**
**Google Stitch Format** — สร้างเมื่อ 2026-06-07 | Owner: Dr.solodev

---

## 01 Overview

**Creative North Star:** _"ร้านของเก่าที่ไว้ใจได้"_

- อบอุ่น สะอาดตา ไม่ใช่ร้านเหล็กเก่าๆ — warm amber tones + clean whites
- Typography รับภาษาไทยเต็มที่ (IBM Plex Sans Thai) — ไม่ต้องพึ่ง system font
- UI สำหรับพนักงานหน้าร้าน ไม่ใช่ดีไซเนอร์ — ใหญ่ อ่านง่าย กดถูก
- Micro-interactions ช่วยให้รู้สึก responsive (scale(0.98) ตอนกด, translateY(-1px) ตอน hover)
- ใช้ในที่แสงจ้าได้ — contrast ratio สูง, หลีกเลี่ยง subtle grey ที่อ่านยาก

**Register:** Product (app UI, dashboard, data entry — design serves the task, not the brand)

**Target Users:** พนักงานหน้าร้านรับซื้อของเก่า 4 สาขา จ.สุรินทร์ — ไม่จำเป็นต้องมีประสบการณ์ใช้ POS มาก่อน

**Design Principles:**
1. **Clarity first** — พนักงานต้อง scan หาข้อมูลได้ใน 1-2 วิ
2. **Error prevention** — validation บน frontend ก่อน submit, confirm dialog ก่อน action สำคัญ
3. **Forgiving** — undo/edit/löschen ได้เสมอ (ยกเว้น confirmed transaction)
4. **Mobile-ready** — ใช้ tablet ได้จริงที่หน้าร้าน
5. **Offline-resilient** — UI ต้องไม่พังถ้า network ดีเลย์

---

## 02 Colors

### Brand Palette

| Token | Value | Usage |
|-------|-------|-------|
| `--color-primary` | `#D97706` (amber-600) | ปุ่มหลัก, link, active state, focus ring |
| `--color-primary-dark` | `#B45309` (amber-700) | Hover state ของ primary |
| `--color-primary-darker` | `#92400E` (amber-800) | Text on amber bg |
| `--color-primary-light` | `#FFFBEB` (amber-50) | Table row hover, badge bg |
| `--color-primary-lighter` | `#FEF3C7` (amber-100) | Highlight bg, spinner |

### Neutral Palette

| Token | Value | Usage |
|-------|-------|-------|
| `--color-sidebar` | `#1e293b` (slate-800) | Sidebar background |
| `--color-sidebar-hover` | `#334155` (slate-700) | Sidebar item hover |
| `--color-sidebar-active` | `#0f172a` (slate-900) | Sidebar item active |
| `--color-text` | `#1e293b` (slate-800) | Body text, headings |
| `--color-text-light` | `#64748b` (slate-500) | Secondary text, labels |
| `--color-text-lighter` | `#94a3b8` (slate-400) | Placeholder, disabled |
| `--color-bg` | `#f7f6f3` (warm-gray-50) | Page background |
| `--color-white` | `#ffffff` | Card bg, input bg |
| `--color-border` | `#e2e8f0` (slate-200) | Borders, dividers |

### Semantic Palette

| Token | Value | Usage |
|-------|-------|-------|
| `--color-success` | `#059669` (emerald-600) | Success state, confirmed badge |
| `--color-success-light` | `#D1FAE5` (emerald-100) | Success bg, selected seller |
| `--color-danger` | `#DC2626` (red-600) | Error, delete, cancel |
| `--color-danger-light` | `#FEE2E2` (red-100) | Error bg |
| `--color-warning` | `#D97706` (amber-600) | Warning (same as primary) |
| `--color-warning-light` | `#FEF3C7` (amber-100) | Warning bg, cart summary |
| `--color-info` | `#0284C7` (sky-600) | Info state |
| `--color-info-light` | `#E0F2FE` (sky-100) | Info bg |

### Shadows — Warm-tinted (not pure grey)

| Token | Value | Usage |
|-------|-------|-------|
| `--shadow-sm` | `0 1px 3px rgba(217,119,6,0.04), 0 1px 2px rgba(0,0,0,0.02)` | Default card |
| `--shadow-md` | `0 4px 6px rgba(217,119,6,0.04), 0 2px 4px rgba(0,0,0,0.03)` | Card hover, dropdown |
| `--shadow-lg` | `0 10px 25px rgba(217,119,6,0.06), 0 4px 10px rgba(0,0,0,0.04)` | Notification, dropdown menu |
| `--shadow-xl` | `0 20px 40px rgba(120,53,15,0.15), 0 10px 20px rgba(0,0,0,0.08)` | Login box, modal |

**Key rule:** ทุก shadow ต้องมี amber tint (r:217, g:119, b:6) ปนอยู่เล็กน้อย — ทำให้ shadow ดูอุ่น ไม่ใช่ cement grey

### Do's
- ใช้ `--color-primary` สำหรับ action หลักเท่านั้น (ไม่ใช่ decoration)
- ใช้ semantic colors (`success`/`danger`/`warning`/`info`) ตามความหมาย ไม่ใช่ตามความสวย
- badge text ต้องมี contrast ≥ 4.5:1 กับ bg

### Don'ts
- ห้ามใช้ gradient เป็น decoration (มีได้เฉพาะ login bg)
- ห้ามใช้ `color-primary-light` แทน `color-white`
- ห้ามใส่ shadow บน flat element (card-flat, sidebar)

---

## 03 Typography

### Font Stack

```css
--font-main: 'IBM Plex Sans Thai', sans-serif;
```

**Why IBM Plex Sans Thai:**
- รองรับภาษาไทยครบทุกวรรณยุกต์
- มีน้ำหนัก 300-700 (Thin ถึง Bold)
- legible ที่ small size (13-14px)
- ดู professional ไม่ใช่ "font ฟอนต์"

### Type Scale

| Level | Size | Weight | Line Height | Letter Spacing | Usage |
|-------|------|--------|-------------|----------------|-------|
| h1 | 40px (2.5rem) | 700 | 1.2 | -0.02em | Page title (dashboard) |
| h2 | 32px (2rem) | 600 | 1.2 | -0.02em | Section heading |
| h3 | 24px (1.5rem) | 600 | 1.2 | -0.02em | Card title |
| h4 | 20px (1.25rem) | 600 | 1.2 | -0.02em | Modal title |
| h5 | 18px (1.125rem) | 500 | 1.2 | -0.02em | Subsection |
| h6 | 16px (1rem) | 500 | 1.2 | -0.02em | Small heading |
| body | 16px (1rem) | 400 | 1.6 | normal | Default text |
| body-sm | 14px (0.875rem) | 400 | 1.5 | normal | Table, form label |
| caption | 13px (0.8125rem) | 500 | 1.4 | 0.05em uppercase | Stat title, badge |
| small | 12px (0.75rem) | 400 | 1.4 | normal | Helper text, hint |

### Page Header
```css
.page-header h1 { font-size: 32px; font-weight: 700; letter-spacing: -0.03em; line-height: 1.1; }
.page-header p  { font-size: 15px; font-weight: 400; color: var(--color-text-light); }
```

### Card Title
```css
.card-title { font-size: 18px; font-weight: 600; letter-spacing: -0.01em; }
```

### Stat Card
```css
.stat-title { font-size: 13px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; }
.stat-value { font-size: 28px; font-weight: 700; letter-spacing: -0.02em; }
```

### Tabular Numbers
```css
.tabular-nums, .stat-value, .data-table td, .price, .amount,
[class*="total"], [class*="price"], [class*="cost"], [class*="profit"] {
  font-variant-numeric: tabular-nums;
}
```

### Body Text
- Default: 16px, 400 weight, line-height 1.6
- Table/label: 14px
- หลีกเลี่ยง body text < 13px

### Do's
- Use tabular-nums สำหรับ price, total, profit — ตัวเลขเรียงตรงกัน
- Use uppercase + letter-spacing สำหรับ stat labels (ให้ดูเป็น "label" ชัดๆ)
- h1-h6 letter-spacing -0.02em เสมอ (optical adjustment)

### Don'ts
- ห้ามใช้มากกว่า 2 font families (ปัจจุบันใช้ IBM Plex Sans Thai ครอบคลุมหมด)
- ห้ามใช้ justify alignment ใน text block ไทย (word spacing จะเพี้ยน)
- ห้ามใช้ line-height < 1.4 สำหรับ body text

---

## 04 Elevation & Layering

### Z-Index Stack

| Token | Value | Usage |
|-------|-------|-------|
| `--z-dropdown` | 100 | Dropdown menu, seller search results |
| `--z-sticky` | 200 | Sidebar (fixed) |
| `--z-modal-backdrop` | 1000 | Modal backdrop |
| `--z-modal` | 1100 | Modal content |
| `--z-notification` | 1200 | Notification toast |
| `--z-loading-overlay` | 9999 | Loading spinner overlay |

### Card Elevation Patterns

| Variant | Box Shadow | Border | Usage |
|---------|-----------|--------|-------|
| Default | `--shadow-sm` | none | Most cards |
| Elevated | `--shadow-md` | none | Hover state, popups |
| Flat | none | `1px solid var(--color-border)` | Settings, info panels |
| Minimal | none | none + `border-bottom` | List items, timeline |

### Backdrop Patterns
- Modal backdrop: `rgba(15, 23, 42, 0.6)` + `backdrop-filter: blur(2px)`
- Loading overlay: `rgba(255,255,255,0.7)` + `backdrop-filter: blur(2px)`

### Do's
- Card hover → translateY(-1px) + shadow-md (lift effect)
- Sidebar → always on top of content area (z-sticky)
- Modal → highest interactive layer below notifications

### Don'ts
- อย่าใช้ z-index > 10000 เว้นแต่ loading overlay
- อย่าซ้อน modal ใน modal
- อย่าใช้ position: relative/absolute/fixed โดยไม่กำหนด z-index อย่างชัดเจน

---

## 05 Spacing & Layout

### Spacing Scale

| Token | Rem/Px | Usage |
|-------|--------|-------|
| 0 | 0 | Reset |
| 1 | 4px | Tight gaps |
| 2 | 8px | Small gap, icon margin |
| 3 | 16px | Standard gap, form group margin |
| 4 | 24px | Card padding, section margin |
| 5 | 32px | Page section gap |

### Layout Grid

- **Content max-width:** 1600px (`max-width: 1600px; margin: 0 auto;`)
- **Sidebar width:** 250px (desktop), 70px (collapsed tablet)
- **Content padding:** 24px desktop, 16px tablet, 12px mobile
- **Card padding:** 16px 20px (header), 20px (body)

### Form Layout
- `form-row`: flex, gap 16px
- `form-col`: flex 1 (equal width columns)
- `form-group`: margin-bottom 16px

### Grid Patterns

| Grid | Pattern | Usage |
|------|---------|-------|
| `stats-grid` | `repeat(auto-fill, minmax(240px, 1fr))` gap 20px | Dashboard stat cards |
| `po-grid` | `1fr 1fr` gap 16px | Purchase order 2-col |
| `item-form-grid` | `2fr 1fr 1fr 1fr 1fr` gap 8px | PO item input row |
| `product-grid` | `repeat(auto-fill, minmax(150px, 1fr))` gap 12px | POS terminal |
| `report-types` | `flex` wrap gap 12px | Report type selector |
| `category-filter` | `flex` wrap gap 8px | Category pill buttons |
| `branch-summary-grid` | `repeat(auto-fill, minmax(280px, 1fr))` gap 16px | Branch cards |

### Do's
- ใช้ utility classes (`mt-*`, `mb-*`, `p-*`, `gap-*`) แทน inline style
- content-area > * ต้อง max-width 1600px margin auto
- Stats grid asymmetry: card 1 → translateY(-4px), card 3 → translateY(4px)

### Don'ts
- อย่าใช้ margin-top ติดกัน (ใช้กลไก vertical rhythm: `page-header + .card` margin-top 24px)
- อย่าใช้ gap > 24px ยกเว้น section break

---

## 06 Components

### 6.1 Button

| Variant | Class | BG | Text | Border | Hover |
|---------|-------|----|------|--------|-------|
| Primary | `btn btn-primary` | `--color-primary` | white | none | `--color-primary-dark` |
| Secondary | `btn btn-secondary` | `--color-bg` | `--color-text` | `--color-border` | `bg: #e2e8f0` |
| Success | `btn btn-success` | `--color-success` | white | none | `#047857` |
| Danger | `btn btn-danger` | `--color-danger` | white | none | `#b91c1c` |
| Info | `btn btn-info` | `--color-info` | white | none | `#0369a1` |
| Ghost | `btn btn-ghost` | transparent | `--color-text` | `--color-border` | `bg: var(--color-bg)` + primary border |
| Link | `btn btn-link` | transparent | `--color-primary` | none | underline + dark |

**Sizes:** `btn-sm` (6px 12px, 13px), default (10px 20px, 14px), `btn-lg` (12px 24px, 16px)

**Loading state:** เพิ่ม class `loading` → แสดง spinner แทน text (color: transparent)

**Active state:** `transform: scale(0.98)` — gives physical "pressed" feel

**Disabled:** opacity 0.5, cursor not-allowed, transform none

### 6.2 Card

```html
<div class="card">
  <div class="card-header">
    <h2 class="card-title">Title</h2>
    <div class="card-tools">actions</div>
  </div>
  <div class="card-body">content</div>
</div>
```

**Variants:** `.card-flat` (no shadow, has border), `.card-elevated` (shadow-md, no border), `.card-minimal` (transparent bg, bottom border only)

**Hover:** translateY(-1px), shadow-md, warm-tinted

### 6.3 Table

```html
<div class="table-container">
  <table class="data-table">
    <thead><tr><th>...</th></tr></thead>
    <tbody><tr><td>...</td></tr></tbody>
  </table>
</div>
```

- Header: bg `#f8fafc`, text `--color-text-light`, 13px, 600 weight
- Body: 14px, `--color-text`
- Cell padding: 16px 20px
- Row hover: `--color-primary-light` (amber-50)
- Even row: `#fafafa`
- Border right between columns: `1px solid #f1f5f9`
- Min-width 800px → horizontal scroll on mobile

### 6.4 Form

```html
<div class="form-group">
  <label for="inputId">Label</label>
  <input type="text" id="inputId" class="form-control" placeholder="...">
  <span class="form-error-message">Error text</span>
</div>
```

- Label: 14px, 500 weight, margin-bottom 6px
- Input: padding 10px 14px, 14px, border `--color-border`, bg white
- Focus: border `--color-primary` + box-shadow 0 0 0 3px rgba(217,119,6,0.08)
- Error: border `--color-danger` + bg `#FEF2F2`
- Required: `label.required::after { content: " *" }` red
- Disabled: bg `var(--color-gray-100)`, opacity 0.6

### 6.5 Modal

```html
<div class="modal show">
  <div class="modal-content modal-sm"> <!-- or default max 700px -->
    <div class="modal-header"><h2>Title</h2><button class="close-modal">&times;</button></div>
    <div class="modal-body">...</div>
    <div class="modal-footer">buttons</div>
  </div>
</div>
```

- Backdrop: rgba(15,23,42,0.6) + backdrop-filter blur(2px)
- Content: bg white, radius `--radius-lg`, shadow-xl
- Animation: fadeIn translateY(-10px) → 0, 200ms
- Sizes: `.modal-sm` max 500px, default max 700px
- Margin top: 60px (centered vertically)

### 6.6 Badge

```html
<span class="badge badge-success">ยืนยันแล้ว</span>
```

| Variant | BG | Text | Usage |
|---------|----|------|-------|
| `badge-success` | `#edf3ec` | `#346538` | Confirmed, completed |
| `badge-warning` | `#fbf3db` | `#956400` | Pending, draft |
| `badge-danger` | `#fdebec` | `#9f2f2d` | Cancelled, void |
| `badge-info` | `#e1f3fe` | `#1f6c9f` | Info status |

- Pill shape: border-radius 9999px
- Size: 12px, 500 weight, padding 4px 10px
- Uppercase + letter-spacing 0.03em

### 6.7 Sidebar

```css
.sidebar {
  width: 250px;
  background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
  position: fixed; left: 0; top: 0; height: 100vh;
  z-index: var(--z-sticky);
}
```

- Brand: 18px, white, amber icon
- Menu item: 14px, color `#94a3b8`, left border transparent 3px
- Active: white text, `--color-sidebar-active` bg, left border amber
- Hover: `--color-sidebar-hover` bg, left border amber
- Collapsed (992px): width 70px, hide text, center icons
- Mobile (768px): hidden off-screen (translateX -100%), toggled by hamburger + overlay

### 6.8 Stat Card

```html
<div class="stat-card">
  <div class="stat-icon"><i class="icon-money"></i></div>
  <div class="stat-details">
    <div class="stat-title">YTD PURCHASES</div>
    <div class="stat-value">฿123,456</div>
  </div>
</div>
```

- Icon bg: gradient amber-50 → amber-100
- Icon: 56x56px, rounded `--radius-lg`
- Hover: border amber, translateY(-2px)
- Grid: `repeat(auto-fill, minmax(240px, 1fr))` gap 20px
- Mobile 576px: 2 columns

### 6.9 Notification Toast

```html
<div class="notification notification-success">
  บันทึกสำเร็จ
  <button class="notification-close">&times;</button>
</div>
```

- Position: fixed top-right, z-index 1200
- Width: min 300px, max 400px
- Colors: white bg, 4px left border (semantic color)
- Animation: slide-in from right, 300ms ease
- Has icon via ::before pseudoelement

### 6.10 Tier Button (Price Tier)

> **WF-05 (2026-07-13):** บิล1 เป็นค่าเริ่มต้น — `globalTier.level = 1` auto-select
> Layout: tier group อยู่ระหว่าง `searchSellerInput` กับ `branchSelect`, flex: 1.2

```html
<button class="tier-btn active">
  <span>บิล1</span>
  <span>10.00 ฿</span>
</button>
```

- Flex column: label on top, price below
- Min-width: 100px, padding 10px 18px
- Active: amber lighter bg + focus ring 3px
- Hover: amber-50 bg, amber border
- **Default state:** `active` = บิล1 (ตั้งแต่โหลดหน้า)

### 6.11 Cart Summary

```html
<div class="cart-summary">
  <div>2 ชิ้น (1 รายการ)</div>
  <div><strong>ยอดรวมจ่าย: <span>฿150.00</span></strong></div>
</div>
```

- BG: `--color-warning-light` (#FEF3C7), border `#FDE68A`
- Radius: `--radius-md`
- Mobile: flex column

---

## 07 Interaction States

| Element | Default | Hover | Active/Focus | Disabled |
|---------|---------|-------|-------------|----------|
| Button | per variant | darker bg | scale(0.98) | opacity 0.5 |
| Card | shadow-sm | translateY(-1px) + shadow-md | — | — |
| Table row | — | amber-50 bg | — | — |
| Form input | border #e2e8f0 | — | amber border + 3px ring | opacity 0.6 |
| Sidebar item | #94a3b8 | #334155 bg | #0f172a bg + amber border | — |
| Link | amber | amber-dark | — | — |
| Modal close | #94a3b8 | #1e293b + bg hover | — | — |

**Focus-visible:** ทุก interactive element ต้องมี focus ring:
```css
*:focus-visible {
  outline: 2px solid var(--color-border-focus);
  outline-offset: 2px;
  border-radius: var(--radius-sm);
}
```

**Transition:** `200ms cubic-bezier(0.16, 1, 0.3, 1)` — spring-like ease

---

## 08 Responsive Breakpoints

| Breakpoint | Sidebar | Content | Layout Changes |
|-----------|---------|---------|---------------|
| > 1100px | 250px full | padding 24px | po-grid: 2 col, item-form-grid: 5 col |
| 992-1100px | 70px collapsed | padding 24px | — |
| 768-992px | 70px collapsed | padding 16px | pos-container: column, stats: 1 col, form-row: column |
| 576-768px | off-screen + overlay | padding 16px | product-grid: 2 col, filters: column |
| < 576px | off-screen + overlay | padding 12px | stats: 1 col, table font 12px |

**Mobile sidebar pattern:**
```css
@media (max-width: 768px) {
  .sidebar { transform: translateX(-100%); }
  .sidebar.open { transform: translateX(0); z-index: 1000; }
  .sidebar-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 999; }
  .content-area { margin-left: 0; }
}
```

---

## 09 Iconography

**Icon set:** icomoon (custom icon font, defined in `fonts.css`)

### Commonly Used Icons

| CSS Class | Usage |
|-----------|-------|
| `.icon-cart` | Sidebar brand, PO page |
| `.icon-dashboard` | Dashboard nav |
| `.icon-users` | Sellers nav |
| `.icon-product` | Inventory nav |
| `.icon-stats` | Sales/Sale lots nav |
| `.icon-report` | Reports nav |
| `.icon-store` | Branches nav |
| `.icon-settings` | Settings nav |
| `.icon-money` | Stat: revenue/purchase |
| `.icon-search` | Search input |
| `.icon-additem` | Add button |
| `.icon-delete` | Delete action |
| `.icon-edit` | Edit action |
| `.icon-signout` | Logout |

**Pattern:** All icons prefixed with `icon-`, used as `<i class="icon-xxx"></i>`

**Margin:** Icons before text get `margin-right: 5px` (built into font-face)

---

## 10 Animation & Motion

### Durations & Easing

| Token | Timing |
|-------|--------|
| `--transition` | `200ms ease` (simple fades, color changes) |
| `--transition-slow` | `350ms ease` (sidebar collapse, layout) |
| Spring easing | `200ms cubic-bezier(0.16, 1, 0.3, 1)` (interactive elements) |

### Animation Patterns

| Animation | Trigger | Details |
|-----------|---------|---------|
| Notification slide-in | Creation | `translateX(100%) → 0`, 300ms ease |
| Modal fade-in | Show | `translateY(-10px) → 0` + `opacity 0→1`, 200ms ease |
| Card entrance (scroll) | IntersectionObserver | `fadeUp`: translateY(24px) → 0 + opacity, 600ms spring |
| Stagger grid items | IntersectionObserver | Delays: 0, 80, 160, 240, 320ms... (8 items max) |
| Scale-in | IntersectionObserver | `scale(0.96) → 1` + opacity, 500ms spring |
| Button press | `:active` | `scale(1) → 0.98`, 100ms ease |

### Auto-apply (animations.js)
Elements that auto-get `.animate-on-scroll`: `.card`, `.stat-card`, `.page-header`
Elements that auto-get `.stagger-item`: children of `.stats-grid`, `.po-grid`, `[class*="grid"]`

### Do's
- Keep animations under 600ms (user-facing app, not marketing)
- Use spring easing for interactive feel
- Use IntersectionObserver for scroll (not scroll listeners)

### Don'ts
- อย่า animate layout properties (width, height, top, left) → use transform/opacity
- อย่าใช้ animation วน loop (ยกเว้น loading spinner)
- อย่า delay entrance > 600ms (user จะรอไม่ไหว)

---

## 11 Do's and Don'ts (Anti-patterns)

### ✅ Do's
- ใช้ `tabular-nums` ทุกที่ที่แสดง price/total/profit
- ใช้ `escapeHtml()` ใน JS ทุกครั้งที่ inject user text (มีอยู่ใน inventory.js)
- Sidebar active page → ใช้ class `active` + amber left border
- stat-value: 28px 700 weight — ต้องเด่นที่สุดใน card
- Card hover: subtle lift (translateY -1px) + warm shadow
- Form validation: inline error message (`form-error-message`) ใต้ input
- Confirm dialog ก่อน action สำคัญ (cancel PO, delete sale lot)

### ❌ Don'ts
- ห้ามใช้ gradient backgrounds (ยกเว้น login page และ sidebar)
- ห้ามใช้ glassmorphism (`backdrop-filter: blur()` เฉพาะ modal/loading overlay)
- ห้าม hardcode สีใน HTML/CSS — ใช้ CSS custom properties เสมอ
- ห้ามใช้ `<br>` เพื่อสร้าง spacing — ใช้ `margin`/`padding` utilities
- ห้ามใช้ inline styles (ยกเว้น dashboard chart container height)
- ห้ามใส่ `console.log` ใน production code
- ห้ามใช้ `catch (Exception $e)` — ใช้ `catch (\Throwable $e)` ใน PHP
- ห้ามใช้ `alert()`/`confirm()` native JS — ใช้ modal component
- ห้าม hardcode API URL หรือ credentials — ใช้ config.js + .env

---

## 12 File Structure

```
assets/css/
├── fonts.css        # @font-face + icomoon icon font declarations
├── styles.css       # All component & layout styles (2469 lines)
└── utilities.css    # Spacing, flex, width, grid utility classes

assets/js/
├── config.js        # API path, window.apiPath
├── common.js        # apiRequest(), formatCurrency(), showNotification(), auth
└── animations.js    # IntersectionObserver scroll animations
```

**Note:** CSS monolithic (2469 lines) — ควรแยกเป็น tokens/layout/components เมื่อมีเวลา refactor

---

## Appendix A: CSS Custom Properties Complete

```css
:root {
  /* Colors */
  --color-primary: #D97706;
  --color-primary-dark: #B45309;
  --color-primary-darker: #92400E;
  --color-primary-light: #FFFBEB;
  --color-primary-lighter: #FEF3C7;
  --color-sidebar: #1e293b;
  --color-sidebar-hover: #334155;
  --color-sidebar-active: #0f172a;
  --color-success: #059669;
  --color-success-light: #D1FAE5;
  --color-danger: #DC2626;
  --color-danger-light: #FEE2E2;
  --color-warning: #D97706;
  --color-warning-light: #FEF3C7;
  --color-info: #0284C7;
  --color-info-light: #E0F2FE;
  --color-text: #1e293b;
  --color-text-light: #64748b;
  --color-text-lighter: #94a3b8;
  --color-bg: #f7f6f3;
  --color-white: #ffffff;
  --color-border: #e2e8f0;
  --color-border-focus: #D97706;
  --color-input-bg: #ffffff;

  /* Radii */
  --radius-sm: 4px;
  --radius-md: 8px;
  --radius-lg: 12px;
  --radius-round: 50px;

  /* Shadows (warm-tinted) */
  --shadow-sm: 0 1px 3px rgba(217,119,6,0.04), 0 1px 2px rgba(0,0,0,0.02);
  --shadow-md: 0 4px 6px rgba(217,119,6,0.04), 0 2px 4px rgba(0,0,0,0.03);
  --shadow-lg: 0 10px 25px rgba(217,119,6,0.06), 0 4px 10px rgba(0,0,0,0.04);
  --shadow-xl: 0 20px 40px rgba(120,53,15,0.15), 0 10px 20px rgba(0,0,0,0.08);

  /* Timing */
  --transition: 0.2s ease;
  --transition-slow: 0.35s ease;

  /* Font */
  --font-main: 'IBM Plex Sans Thai', sans-serif;

  /* Layout */
  --sidebar-width: 250px;
  --sidebar-collapsed: 70px;
  --header-height: 60px;

  /* Z-index */
  --z-dropdown: 100;
  --z-sticky: 200;
  --z-modal-backdrop: 1000;
  --z-modal: 1100;
  --z-notification: 1200;
  --z-loading-overlay: 9999;
}
```

---

## Appendix B: Design Vocabulary (for AI Agent prompts)

ใช้คำศัพท์เหล่านี้ตอนสั่ง agent เพื่อความ consistent:

| Command verb | Meaning | CSS target |
|-------------|---------|-----------|
| `typeset` | Fix typography scale, hierarchy, readability | font-size, line-height, letter-spacing |
| `colorize` | Fix color system, contrast, semantic mapping | --color-*, badge, focus ring |
| `layout` | Fix spacing, grid, alignment, rhythm | margin, padding, gap, grid-template |
| `distill` | Remove unnecessary complexity, reduce visual noise | gradients, extra borders, decorative elements |
| `polish` | Fine-tune interactions, motion, micro-states | transition, transform, hover/focus/active |
| `bolder` | Increase visual weight (contrast, size, emphasis) | font-weight, color contrast |
| `quieter` | Decrease visual weight | font-weight, color, opacity |
| `adapt` | Make responsive for target breakpoint | media queries, layout shift |
| `harden` | Fix accessibility, contrast, focus states | :focus-visible, aria-*, WCAG |

---

## Appendix C: Responsive & Mobile Additions (2026-07-12)

### Tablet-Responsive (layout.css — 768-1024px breakpoint)

| Token / Rule | Value | Purpose |
|:-------------|:------|:--------|
| `min-height: 44px` | All interactive elements | Touch target (Apple HIG) |
| `font-size: 16px` | Body text | iOS zoom prevention |
| `priority-2` | Hidden on `<768px` | Column priority hiding |
| `priority-3` | Hidden on `<576px` | Column priority hiding |
| `.modal-fullscreen` | `<640px` | Fullscreen overlay modal |
| `.sidebar-overlay` | 769-1024px | Hamburger toggle sidebar |
| `hamburger threshold` | 768→1024px | common.js config change |

### Mobile PO Wizard (`/mobile/purchase.html`)

A **4-step standalone wizard** designed for mobile-first usage:

| Step | Component | Design Pattern |
|:-----|:----------|:---------------|
| 1. Branch | `<select>` dropdown | Full-width, large touch target |
| 2. Seller | Search + autocomplete + new seller form | Bottom sheet for new seller |
| 3. Items | Catalog search + cart | Bottom sheet for tier prices |
| 4. Review | Summary table + save button | Fixed bottom CTA |

**Design principles applied:**
- Single-column layout (no sidebar, no desktop nav)
- Large touch targets (≥44px everywhere)
- Step indicator at top (1-2-3-4)
- Bottom sheet instead of modal (native feel)
- Fixed bottom CTA button (ไม่พลาด)
- Reuses existing CSS tokens (--color-primary, --color-text, etc.)

---

*Built for SoloCorp OS by Dr.solodev | DESIGN.md v1.1 | 2026-07-12*
*Follows Google Stitch DESIGN.md format — interoperable with DESIGN.md-aware tools*
