# Final UI Implementation Spec — Client Feedback Refresh

**Project:** Secondhand POS
**Date:** 2026-07-24
**Decision by:** Dr.solodev (Owner)
**Authorized by:** เทอโบ (CEO)
**Design Input:** design-kreet + ui-designer
**To:** @changful (Engineering)

---

## Change Log

| # | Item | Decision | Source |
|:-:|:-----|:---------|:-------|
| 1 | Text color | `--color-text: #0f172a` (slate-900) | Both teams agree |
| 2 | Text light | `--color-text-light: #475569` (slate-600) | Both teams agree |
| 3 | Text lighter | `--color-text-lighter: #64748b` (slate-500) | Both teams agree |
| 4 | Heading color | `--color-text-heading: #0f172a` (token ใหม่) | UI Designer |
| 5 | Font weight body | **500** | Owner approved |
| 6 | Font weight table | **500** | Owner approved |
| 7 | Font weight label | **600** | Owner approved |
| 8 | Card border | `#d1d5db` + `1px solid` | ✅ Owner selected |
| 9 | Shadow opacity | **2x** current (amber 4%→8%, black 2%→4%) | Owner approved |
| 10 | Accent color | **Teal only** (`#0D9488` family) | ✅ Owner selected |
| 11 | Card variants | Add `.card-accent`, `.card-footer`, `.card-bordered` | UI Designer |
| 12 | Stat cards | Alternating icon colors (amber → teal) | UI Designer |

---

## 1. CSS Tokens — tokens.css

### 1.1 Text Colors (เปลี่ยนทั้งหมด)

```css
--color-text: #0f172a;              /* slate-900 — darker for outdoor readability */
--color-text-heading: #0f172a;       /* NEW — headings use pure dark */
--color-text-light: #475569;         /* slate-600 — was #64748b (FAIL WCAG) */
--color-text-lighter: #64748b;       /* slate-500 — was #94a3b8 */
```

### 1.2 Card & Border Tokens (เพิ่มใหม่)

```css
--color-border-card: #d1d5db;       /* NEW — card border (gray-300) */
--color-accent: #0D9488;             /* NEW — teal-600 */
--color-accent-dark: #0F766E;        /* NEW — teal-700 (hover) */
--color-accent-darker: #115E59;      /* NEW — teal-800 (text on teal bg) */
--color-accent-light: #CCFBF1;       /* NEW — teal-100 (badge bg) */
--color-accent-lighter: #F0FDFA;     /* NEW — teal-50 (table hover) */
```

### 1.3 Shadows (ปรับ opacity)

```css
--shadow-sm: 0 1px 3px rgba(217,119,6,0.08), 0 1px 2px rgba(0,0,0,0.04);
--shadow-md: 0 4px 6px rgba(217,119,6,0.08), 0 2px 4px rgba(0,0,0,0.06);
--shadow-lg: 0 10px 25px rgba(217,119,6,0.10), 0 4px 10px rgba(0,0,0,0.06);
--shadow-xl: 0 20px 40px rgba(120,53,15,0.18), 0 8px 16px rgba(0,0,0,0.08);
```

### 1.4 Font Weight Variables (เพิ่มใหม่)

```css
--font-weight-body: 500;
--font-weight-label: 600;
--font-weight-heading: 700;
--font-weight-table: 500;
--font-weight-bold: 700;
```

---

## 2. Typography — body + headings

### CSS Snippets

```css
/* Body — heavier for outdoor readability */
body {
  font-weight: var(--font-weight-body);
}

/* Headings — use darker heading color */
h1, h2, h3, h4, h5, h6 {
  color: var(--color-text-heading);
}

/* Labels — must be scannable instantly */
label, .form-label, .stat-title {
  font-weight: var(--font-weight-label);
  color: var(--color-text);
}

/* Table body — heavier */
.data-table tbody td {
  font-weight: var(--font-weight-table);
}

/* Card title — heading color */
.card-title {
  color: var(--color-text-heading);
}

/* Stat value — heading color + heavier */
.stat-value {
  color: var(--color-text-heading);
  font-weight: 700;
}
```

---

## 3. Card Redesign — cards.css

### Complete Card Component

```css
/* === Base Card — with border + shadow === */
.card {
  background-color: var(--color-white);
  border: 1px solid var(--color-border-card);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-sm);
  margin-bottom: 20px;
  transition: box-shadow 200ms cubic-bezier(0.16, 1, 0.3, 1),
              transform 200ms cubic-bezier(0.16, 1, 0.3, 1),
              border-color var(--transition);
}

.card:hover {
  box-shadow: var(--shadow-md);
  border-color: var(--color-primary-lighter);
  transform: translateY(-1px);
}

.card-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.card-body {
  padding: 20px;
  overflow-wrap: break-word;
}

.card-footer {                /* NEW */
  padding: 12px 20px;
  border-top: 1px solid var(--color-border);
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
  background-color: var(--color-gray-50);
  border-radius: 0 0 var(--radius-md) var(--radius-md);
}

/* === Card Variants === */

/* Elevated — no border, stronger shadow (modals/popups) */
.card-elevated {
  border: none;
  box-shadow: var(--shadow-lg);
}
.card-elevated:hover {
  box-shadow: var(--shadow-xl);
  transform: translateY(-2px);
  border-color: transparent;
}

/* Flat — border only, no shadow (settings/info) */
.card-flat {
  box-shadow: none;
  border: 1px solid var(--color-border);
  background-color: var(--color-gray-50);
}
.card-flat:hover {
  box-shadow: none;
  transform: none;
  border-color: var(--color-border);
}

/* Minimal — transparent, bottom border only (list items) */
.card-minimal {
  background: transparent;
  box-shadow: none;
  border: none;
  border-bottom: 1px solid var(--color-border);
  border-radius: 0;
  padding: 16px 0;
  margin-bottom: 0;
}
.card-minimal:hover {
  box-shadow: none;
  transform: none;
}

/* Accent — left teal color bar (NEW) */
.card-accent {
  position: relative;
  border-left: 4px solid var(--color-accent);
}
.card-accent:hover {
  border-left-color: var(--color-accent-dark);
}

/* Bordered — stronger border for table containers (NEW) */
.card-bordered {
  border: 1px solid var(--color-border-card);
}
.card-bordered:hover {
  border-color: var(--color-primary-lighter);
}
```

---

## 4. Stat Cards — Alternating Colors

```css
/* Odd cards → amber icon (existing) */
.stats-grid .stat-card:nth-child(odd) .stat-icon {
  background: linear-gradient(135deg, var(--color-primary-light), var(--color-primary-lighter));
  color: var(--color-primary);
}

/* Even cards → teal icon (NEW) */
.stats-grid .stat-card:nth-child(even) .stat-icon {
  background: linear-gradient(135deg, var(--color-accent-lighter), var(--color-accent-light));
  color: var(--color-accent);
}
```

---

## 5. Accent Button & Badge

### 5.1 Accent Button (NEW)

```css
.btn-accent {
  background-color: var(--color-accent);
  color: var(--color-white);
  border: none;
}
.btn-accent:hover:not(:disabled) {
  background-color: var(--color-accent-dark);
  box-shadow: var(--shadow-sm);
}
.btn-accent:active:not(:disabled) {
  background-color: var(--color-accent-darker);
}
```

### 5.2 Accent Badge (NEW)

```css
.badge-accent {
  background-color: var(--color-accent-light);
  color: var(--color-accent-darker);
}
```

---

## 6. Implementation Order

### Phase 1 — Tokens (highest impact, lowest risk)
1. Update `--color-text` → `#0f172a`
2. Update `--color-text-light` → `#475569`
3. Update `--color-text-lighter` → `#64748b`
4. Add `--color-text-heading` → `#0f172a`
5. Add `--color-border-card` → `#d1d5db`
6. Add accent teal tokens
7. Update shadow opacities
8. Add font-weight variables

### Phase 2 — Components
1. Update `body` font-weight → 500
2. Update headings → `--color-text-heading`
3. Rewrite `cards.css` with border + new variants
4. Update label/stat-title font-weight → 600
5. Update `.data-table tbody td` font-weight → 500
6. Add `.btn-accent` variant
7. Add `.badge-accent` variant
8. Update stat-icons with alternating colors

### Phase 3 — Verification
1. Check all pages render correctly
2. Verify responsive breakpoints
3. Run WCAG contrast check
4. Deploy to Railway

---

## 7. Risk Assessment

| Risk | Impact | Mitigation |
|:-----|:-------|:-----------|
| `--color-text` change affects ALL text | High visibility, low breakage | CSS custom property — เปลี่ยนที่เดียวทั้งระบบ |
| Card border 1px อาจเพิ่ม visual clutter | Medium | ใช้ `#d1d5db` subtle, ไม่ใช่ `#9ca3af` |
| New components (btn-accent, badge-accent) | Low | Additive — ไม่มีผลกับ existing styles |
| Font-weight 500 อาจทำให้ text ดูหนาเกิน | Low | Thai script ที่ 500 ยังบางกว่า Latin ที่ 400 |

---

## 8. Rollback Plan

ถ้าลูกค้าไม่ชอบ — revert commit ที่เปลี่ยน `tokens.css` และ `cards.css`
Estimated rollback time: **< 5 minutes**

---

*Spec prepared by CEO เทอโบ | Reviewed & Approved: Dr.solodev*
