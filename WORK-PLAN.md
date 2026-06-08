# Secondhand POS — Work Plan: CSS Modularization

> แผนงานย่อยสำหรับแยก `styles.css` (2,469 lines) → modular files
> แต่ละ task = 1 context window, independent หรือ dependency ชัดเจน

---

## สถาปัตยกรรมเป้าหมาย

```
assets/css/
├── main.css                 # ← NEW: @import chain (orchestrator)
├── fonts.css                # ← KEEP: icon fonts (941 lines)
├── utilities.css            # ← KEEP: utility classes (260 lines)
├── tokens.css               # ← NEW: :root variables, reset, base, typography, interaction states
├── layout.css               # ← NEW: sidebar, topbar, content-area + responsive
├── animations.css           # ← NEW: scroll animations, keyframes, stagger
└── components/
    ├── buttons.css          # ← NEW: all button variants
    ├── cards.css            # ← NEW: card + stat-card + variants
    ├── forms.css            # ← NEW: form-group, form-control, form-grid, focus states
    ├── tables.css           # ← NEW: data-table, sort, row hover
    ├── badges.css           # ← NEW: badge + tag
    ├── modals.css           # ← NEW: modal + backdrop
    ├── notifications.css    # ← NEW: notification toast
    ├── login.css            # ← NEW: login page
    ├── dashboard.css        # ← NEW: dashboard grid, stat cards, empty states
    ├── pos.css              # ← NEW: POS terminal
    ├── purchase-orders.css  # ← NEW: PO grid, item form, tier buttons, cart
    ├── sale-lots.css        # ← NEW: branch cards, receipt, sale header
    └── reports.css          # ← NEW: report filters, charts, tables
```

### Current loading pattern (13 files to update)

| Page | CSS loaded |
|------|-----------|
| `admin/*.html` (11 หน้า) | `fonts.css`, `styles.css`, `utilities.css` |
| `pos/index.html` | `fonts.css`, `styles.css` |
| `index.html` (root) | `styles.css` |

---

## ภาพรวมความคืบหน้า

```
Phase 0: Analysis    → Task 0.1   [1 task]
Phase 1: Core files  → Tasks 1.1–1.3   [3 tasks]
Phase 2: Components  → Tasks 2.1–2.11  [11 tasks]
Phase 3: Integration → Tasks 3.1–3.2   [2 tasks]
Phase 4: Cleanup     → Tasks 4.1–4.2   [2 tasks]
```

---

## Phase 0 — Analysis (1 task)

### Task 0.1 — Map all selectors in styles.css

**What:** อ่าน `styles.css` ทั้ง 2,469 lines → สร้างตาราง selector → target file

**Deliverable:** อัปเดต WORK-PLAN.md section นี้ให้มี column `→ Target file` ทุก section

**Dependencies:** None

**Command to verify:** `grep -n '^/\*' styles.css` — show all section headers with line numbers

**Selector Map:**

| Lines | Section | Target File |
|-------|---------|-------------|
| 1 | `@import url('...IBM Plex...')` | `tokens.css` |
| 3–49 | `:root { ... }` variables | `tokens.css` |
| 51–53 | `*, *::before, *::after` reset | `tokens.css` |
| 55–58 | `body, h1–h6, p, ol, ul` reset | `tokens.css` |
| 60–69 | `body` base styles | `tokens.css` |
| 71–78 | `a` base styles | `tokens.css` |
| 80–134 | Login Page (`.login-page`, `.login-box`, etc.) | `components/login.css` |
| 136–214 | Layout (`.app-container`, `.sidebar`, `.content-area`) | `layout.css` |
| 216–292 | Top Bar (`.topbar`, `.user-dropdown`) | `layout.css` |
| 294–320 | Card (`.card`) | `components/cards.css` |
| 322–339 | Page Header (`.page-header`) | `layout.css` |
| 341–428 | Buttons (`.btn`, variants) | `components/buttons.css` |
| 429–476 | Table (`.data-table`, sort) | `components/tables.css` |
| 477–501 | Badges (`.badge`, variants) | `components/badges.css` |
| 503–572 | Forms (`.form-group`, `.form-control`, select) | `components/forms.css` |
| 573–604 | Search Bar (`.search-bar`) | `layout.css` |
| 605–617 | Filters (`.filters`) | `layout.css` |
| 618–700 | Notification (`.notification`, `.toast`) | `components/notifications.css` |
| 701–773 | Modal (`.modal`, `.modal-content`) | `components/modals.css` |
| 775–827 | Dashboard (`.stats-grid`, `.stat-card`) | `components/dashboard.css` |
| 828–1057 | POS Terminal (`.pos-container`, `.pos-products`, etc.) | `components/pos.css` |
| 1058–1082 | Empty States (`.empty-state`) | `components/dashboard.css` |
| 1083–1120 | Sale Header (`.sale-header`) | `components/sale-lots.css` |
| 1121–1175 | Receipt (`.receipt`, `.receipt-modal`) | `components/sale-lots.css` |
| 1176–1201 | Category Filter (`.category-filter`) | `layout.css` |
| 1202–1213 | Product Info (`.product-info`) | `components/pos.css` |
| 1214–1226 | Change Calculation (`.change-calculation`) | `components/pos.css` |
| 1227–1232 | Password Fields | `components/forms.css` |
| 1233–1263 | Pagination (`.pagination`) | `layout.css` |
| 1264–1329 | Report Styles | `components/reports.css` |
| 1330–1352 | Category List | `components/dashboard.css` |
| 1353–1412 | Purchase Orders Grid (`.po-grid`, `.cart-summary`) | `components/purchase-orders.css` |
| 1413–1430 | Item Form (`.item-form-grid`) | `components/purchase-orders.css` |
| 1431–1459 | Tier Price Buttons | `components/purchase-orders.css` |
| 1460–1509 | Loading States | `tokens.css` |
| 1510–1516 | Backup Actions | `components/dashboard.css` |
| 1517–1523 | Stock Adjustment | `components/purchase-orders.css` |
| 1524–1531 | Utilities (duplicates) | → `utilities.css` (remove from styles.css) |
| 1533–1711 | Responsive (1100px, 992px, 768px, 576px) | `layout.css` |
| 1713–1737 | Branch Summary Grid | `components/sale-lots.css` |
| 1739–1757 | Form Grid System | `components/forms.css` |
| 1759–1772 | Button Groups | `components/buttons.css` |
| 1774–1805 | Form Field States | `components/forms.css` |
| 1806–1835 | Button Loading State | `components/buttons.css` |
| 1836–1851 | Table Improvements | `components/tables.css` |
| 1853–1863 | Branch Banner | `components/sale-lots.css` |
| 1865 | Text Colors | `tokens.css` |
| 1870–2043 | Responsive Design (tablet/mobile/small) | `layout.css` |
| 2044–2079 | Typography Scale (h1–h6) | `tokens.css` |
| 2081–2085 | Labels & Small Text | `components/forms.css` |
| 2087–2098 | Tabular Figures for Numbers | `tokens.css` |
| 2100–2103 | Medium weight for emphasis | `tokens.css` |
| 2105–2117 | Page headers | `layout.css` |
| 2119–2124 | Card titles | `components/cards.css` |
| 2126–2138 | Stat cards (`.stat-title`, `.stat-value`) | `components/dashboard.css` |
| 2140–2179 | Interaction States (active, spring, focus) | `tokens.css` |
| 2181–2216 | Warm-Tinted Shadows + grain texture | `tokens.css` |
| 2218–2240 | Layout Refinements (breathing room, asymmetry) | `layout.css` |
| 2242–2307 | Scroll-Driven Animations | `animations.css` |
| 2309–2312 | Smooth scroll | `animations.css` |
| 2314–2375 | Component Upgrades (card variants, btn-ghost, btn-link) | ตาม component |
| 2377–2381 | Modal upgrade | `components/modals.css` |
| 2383–2413 | Badge improvements | `components/badges.css` |
| 2415–2422 | Table row hover | `components/tables.css` |
| 2424–2436 | Tag (`.tag`) | `components/badges.css` |
| 2438–2443 | Input focus enhancement | `components/forms.css` |
| 2445–2469 | Better empty states | `components/dashboard.css` |

---

## Phase 1 — Core Files (3 tasks, sequential)

### Task 1.1 — Create `tokens.css`

**What:** รวม selector-map ทั้งหมดที่ target `tokens.css` → สร้างไฟล์ใหม่

**ไฟล์ใหม่:** `assets/css/tokens.css`
**เนื้อหาจาก styles.css (lines):** 1–78, 1460–1509, 1865, 2044–2079, 2087–2103, 2140–2216

**NOTES:**
- `@import url('...IBM Plex...')` อยู่บรรทัด 1 → ย้ายมาไว้บนสุดของ tokens.css
- `:root { }` (lines 3–49) → ย้ายทั้งหมด
- `*`, reset (lines 51–68) → ย้ายทั้งหมด
- `body`, `a` base (lines 60–78) → ย้ายทั้งหมด
- Typography Scale h1–h6 (lines 2044–2079) → ย้าย
- Tabular Figures (lines 2087–2098) → ย้าย
- Interaction States (lines 2140–2179) → ย้าย
- Warm-Tinted Shadows (lines 2181–2216) → ย้าย
- Loading States (lines 1460–1509) → ย้าย
- Text Colors (line 1865) → ย้าย

**Dependencies:** Task 0.1 (map เสร็จแล้ว)
**Estimated lines:** ~250 lines

---

### Task 1.2 — Create `layout.css`

**What:** Layout ทั้งหมด + topbar + responsive breakpoints + page-header + search-bar + filters + pagination

**ไฟล์ใหม่:** `assets/css/layout.css`
**เนื้อหาจาก styles.css (lines):** 136–339, 573–617, 1176–1201, 1233–1263, 1533–1711, 1870–2043, 2105–2117, 2218–2240

**Dependencies:** Task 1.1 (ต้องมี tokens.css เพื่อใช้ `var(--...)`)
**Estimated lines:** ~500 lines

---

### Task 1.3 — Create `animations.css`

**What:** Animation keyframes + scroll-driven animation classes + stagger

**ไฟล์ใหม่:** `assets/css/animations.css`
**เนื้อหาจาก styles.css (lines):** 2242–2312

**Dependencies:** Task 1.1 (ใช้ CSS variables)
**Estimated lines:** ~70 lines

---

## Phase 2 — Component Files (11 tasks, parallelizable)

ทุก task ใน phase นี้ **independent ต่อกัน** — ทำพร้อมกันหรือคนละ session ก็ได้

---

### Task 2.1 — Create `components/buttons.css`

**ไฟล์ใหม่:** `assets/css/components/buttons.css`
**เนื้อหา:** Buttons (341–428) + Button Groups (1759–1772) + Button Loading (1806–1835) + Component Upgrades btn-ghost/btn-link (2352–2375) + `:active` scale (2142–2146) + spring physics (2174–2178)

**Dependencies:** tokens.css
**Estimated lines:** ~120 lines

---

### Task 2.2 — Create `components/cards.css`

**ไฟล์ใหม่:** `assets/css/components/cards.css`
**เนื้อหา:** Card (294–320) + card-title (2119–2124) + card hover (2167–2171) + warm shadow overrides for card (2184–2190) + Card variants flat/elevated/minimal (2316–2333)

**Dependencies:** tokens.css
**Estimated lines:** ~60 lines

---

### Task 2.3 — Create `components/forms.css`

**ไฟล์ใหม่:** `assets/css/components/forms.css`
**เนื้อหา:** Forms (503–572) + Form Grid (1739–1757) + Form Field States (1774–1805) + Labels (2081–2085) + Input focus (2438–2443) + Focus rings (2160–2165) + Password Fields (1227–1232)

**Dependencies:** tokens.css
**Estimated lines:** ~120 lines

---

### Task 2.4 — Create `components/tables.css`

**ไฟล์ใหม่:** `assets/css/components/tables.css`
**เนื้อหา:** Table (429–476) + Table Improvements (1836–1851) + Row hover (2415–2422)

**Dependencies:** tokens.css
**Estimated lines:** ~60 lines

---

### Task 2.5 — Create `components/badges.css`

**ไฟล์ใหม่:** `assets/css/components/badges.css`
**เนื้อหา:** Badges (477–501) + Badge improvements (2383–2413) + Tag (2424–2436)

**Dependencies:** tokens.css
**Estimated lines:** ~60 lines

---

### Task 2.6 — Create `components/modals.css`

**ไฟล์ใหม่:** `assets/css/components/modals.css`
**เนื้อหา:** Modal (701–773) + Modal upgrade (2377–2381)

**Dependencies:** tokens.css
**Estimated lines:** ~80 lines

---

### Task 2.7 — Create `components/notifications.css`

**ไฟล์ใหม่:** `assets/css/components/notifications.css`
**เนื้อหา:** Notification (618–700)

**Dependencies:** tokens.css
**Estimated lines:** ~80 lines

---

### Task 2.8 — Create `components/login.css`

**ไฟล์ใหม่:** `assets/css/components/login.css`
**เนื้อหา:** Login Page (80–134)

**Dependencies:** tokens.css
**Estimated lines:** ~55 lines

---

### Task 2.9 — Create `components/dashboard.css`

**ไฟล์ใหม่:** `assets/css/components/dashboard.css`
**เนื้อหา:** Dashboard (775–827) + Empty States (1058–1082) + Category List (1330–1352) + Backup Actions (1510–1516) + Stat cards title/value (2126–2138) + Stats asymmetry (2234–2240) + Better stat-card (2336–2349) + Better empty states (2445–2469)

**Dependencies:** tokens.css
**Estimated lines:** ~120 lines

---

### Task 2.10 — Create `components/pos.css`

**ไฟล์ใหม่:** `assets/css/components/pos.css`
**เนื้อหา:** POS Terminal (828–1057) + Product Info (1202–1213) + Change Calculation (1214–1226)

**Dependencies:** tokens.css
**Estimated lines:** ~250 lines

---

### Task 2.11 — Create page-specific files (3 files)

**ไฟล์ใหม่:** `components/purchase-orders.css`, `components/sale-lots.css`, `components/reports.css`

**purchase-orders.css:** PO Grid (1353–1412) + Item Form (1413–1430) + Tier Buttons (1431–1459) + Stock Adjustment (1517–1523)

**sale-lots.css:** Sale Header (1083–1120) + Receipt (1121–1175) + Branch Summary (1713–1737) + Branch Banner (1853–1863)

**reports.css:** Report Styles (1264–1329)

**Dependencies:** tokens.css
**Estimated lines:** ~150 lines total

---

## Phase 3 — Integration (2 tasks)

### Task 3.1 — Create `main.css`

**ไฟล์ใหม่:** `assets/css/main.css`
**เนื้อหา:** @import chain ที่ import ทุกไฟล์ในลำดับที่ถูกต้อง

```css
/* Secondhand POS — Main Stylesheet */
@import 'tokens.css';
@import 'layout.css';
@import 'animations.css';
@import 'components/buttons.css';
@import 'components/cards.css';
@import 'components/forms.css';
@import 'components/tables.css';
@import 'components/badges.css';
@import 'components/modals.css';
@import 'components/notifications.css';
@import 'components/login.css';
@import 'components/dashboard.css';
@import 'components/pos.css';
@import 'components/purchase-orders.css';
@import 'components/sale-lots.css';
@import 'components/reports.css';
@import '../fonts.css';        /* ../ เพราะ main.css อยู่ลึกกว่า */
@import '../utilities.css';
```

**Dependencies:** Tasks 1.1–2.11 ทั้งหมด (ทุกไฟล์ต้องมีอยู่จริง)
**Estimated lines:** ~20 lines

---

### Task 3.2 — Update all HTML `<link>` tags

**ไฟล์ที่ต้องแก้ (13 ไฟล์):**

| # | File | Old `<link>` | New `<link>` |
|---|------|-------------|-------------|
| 1 | `admin/index.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 2 | `admin/purchase-orders.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 3 | `admin/sale-lots.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 4 | `admin/inventory.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 5 | `admin/sellers.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 6 | `admin/sales.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 7 | `admin/reports.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 8 | `admin/settings.html` | fonts.css + styles.css | `main.css` |
| 9 | `admin/users.html` | fonts.css + styles.css | `main.css` |
| 10 | `admin/price-tiers.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 11 | `admin/branches.html` | fonts.css + styles.css + utilities.css | `main.css` |
| 12 | `pos/index.html` | fonts.css + styles.css | `main.css` |
| 13 | `index.html` (root) | styles.css | `main.css` |

การเปลี่ยน:
```diff
- <link rel="stylesheet" href="../assets/css/fonts.css">
- <link rel="stylesheet" href="../assets/css/styles.css">
- <link rel="stylesheet" href="../assets/css/utilities.css">
+ <link rel="stylesheet" href="../assets/css/main.css">
```

**ข้อควรระวัง:** root `index.html` ใช้ `assets/css/...` (ไม่มี `../`) — path ต่างกัน!

**Dependencies:** Task 3.1 (main.css ต้อง exist)
**การ verify:** เปิด browser → admin ทุกหน้า render ไม่พัง

---

## Phase 4 — Cleanup (2 tasks)

### Task 4.1 — Remove duplicate utilities from styles.css

ก่อนลบ styles.css ต้องดึง `/* Utilities */` section (lines 1524–1531) ที่ซ้ำกับ utilities.css ออกก่อน → แต่เดี๋ยวไฟล์จะถูกลบอยู่แล้ว เลยไม่ต้องทำ

**Actually:** Styles.css จะถูกลบทั้งไฟล์ → ไม่ต้อง clean ทีละ section

---

### Task 4.2 — Remove old `styles.css` + verify

**What:**
1. ลบ `assets/css/styles.css`
2. เปิด browser เช็คทุกหน้า render ถูกต้อง
3. ใช้ DevTools → Sources → verify ว่าไม่มี 404 สำหรับ `styles.css`
4. ตรวจว่า `main.css` load ครบทุก component

**Dependencies:** Task 3.2 (HTML ต้องไม่ reference styles.css แล้ว)

---

## Dependency Graph (Visual)

```
Task 0.1 (Map)
    │
    ▼
Task 1.1 (tokens.css)
    │
    ├──► Task 1.2 (layout.css)
    ├──► Task 1.3 (animations.css)
    ├──► Task 2.1 (buttons.css)
    ├──► Task 2.2 (cards.css)
    ├──► Task 2.3 (forms.css)
    ├──► Task 2.4 (tables.css)
    ├──► Task 2.5 (badges.css)
    ├──► Task 2.6 (modals.css)
    ├──► Task 2.7 (notifications.css)
    ├──► Task 2.8 (login.css)
    ├──► Task 2.9 (dashboard.css)
    ├──► Task 2.10 (pos.css)
    └──► Task 2.11 (page-specific)
                │
                ▼
          Task 3.1 (main.css)
                │
                ▼
          Task 3.2 (Update HTML)
                │
                ▼
          Task 4.2 (Delete + Verify)
```

---

## สิ่งที่ต้องระวัง

1. **Override order matters:** ถ้า component ไฟล์นึงมา override styles.css selector เดียวกัน → ไฟล์ที่ `@import` ทีหลังสุดจะเป็นตัวชนะ ต้องรักษาลำดับให้เหมือนเดิม
2. **ชื่อไฟล์ tokens.css** ระวังชนกับ CSS `@import url('tokens.css')` path — ใช้ relative path เสมอ
3. **fonts.css** อยู่ใน `assets/css/` → main.css ต้อง import เป็น `'../fonts.css'` (เพราะ main.css อยู่ใน `assets/css/` แล้ว)

---

*Last updated: 2026-06-07 | Plan by Claude Code*
