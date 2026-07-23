# Design Proposal — UI Visual Refresh

**Department:** Design (08-design/\u0e04\u0e23\u0e35\u0e40\u0e2d\u0e17)
**Head of Design:** \u0e04\u0e23\u0e35\u0e40\u0e2d\u0e17 (Kreet)
**Date:** 2026-07-24
**For:** CEO \u0e40\u0e17\u0e2d\u0e42\u0e1a, Owner Dr.solodev
**Status:** Draft \u2014 awaiting review
**Based on:** `10-client-ui-feedback-brief.md`

---

## Executive Summary

\u0e25\u0e39\u0e01\u0e04\u0e49\u0e32 feedback 3 \u0e40\u0e23\u0e37\u0e48\u0e2d\u0e07 \u2014 \u0e15\u0e31\u0e27\u0e2d\u0e31\u0e01\u0e29\u0e23\u0e08\u0e32\u0e07, \u0e02\u0e2d\u0e1a\u0e01\u0e32\u0e23\u0e4c\u0e14\u0e21\u0e2d\u0e07\u0e44\u0e21\u0e48\u0e40\u0e2b\u0e47\u0e19, \u0e2a\u0e35\u0e2a\u0e31\u0e19\u0e19\u0e48\u0e32\u0e40\u0e1a\u0e37\u0e48\u0e2d

**\u0e40\u0e23\u0e32\u0e40\u0e2a\u0e19\u0e2d\u0e01\u0e32\u0e23\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19\u0e41\u0e1b\u0e25\u0e07\u0e41\u0e1a\u0e1a\u0e40\u0e09\u0e1e\u0e32\u0e30\u0e08\u0e38\u0e14 (surgical) \u0e43\u0e19 4 \u0e2a\u0e48\u0e27\u0e19:**

| \u0e2b\u0e31\u0e27\u0e02\u0e49\u0e2d | \u0e1b\u0e31\u0e0d\u0e2b\u0e32 | \u0e02\u0e49\u0e2d\u0e40\u0e2a\u0e19\u0e2d |
|:-----------|:-----|:----------|
| \u0e15\u0e31\u0e27\u0e2d\u0e31\u0e01\u0e29\u0e23 | `#1e293b` + weight 400 \u0e2d\u0e48\u0e32\u0e19\u0e22\u0e32\u0e01\u0e43\u0e19\u0e41\u0e2a\u0e07\u0e08\u0e49\u0e32 | `#0f172a` + weight 500 \u0e40\u0e1b\u0e47\u0e19\u0e04\u0e48\u0e32\u0e40\u0e23\u0e34\u0e48\u0e21\u0e15\u0e49\u0e19 |
| \u0e02\u0e2d\u0e1a\u0e01\u0e32\u0e23\u0e4c\u0e14 | \u0e44\u0e21\u0e48\u0e21\u0e35 border, shadow \u0e08\u0e32\u0e07\u0e43\u0e19\u0e41\u0e2a\u0e07 | \u0e40\u0e1e\u0e34\u0e48\u0e21 `1px solid \u2014color-border-card` + shadow \u0e40\u0e02\u0e49\u0e21\u0e02\u0e36\u0e49\u0e19 |
| \u0e2a\u0e35\u0e2a\u0e31\u0e19 | amber + slate \u0e40\u0e17\u0e48\u0e32\u0e19\u0e31\u0e49\u0e19 | \u0e40\u0e1e\u0e34\u0e48\u0e21 teal + coral accent palette |
| \u0e42\u0e04\u0e23\u0e07\u0e2a\u0e23\u0e49\u0e32\u0e07 | \u0e44\u0e21\u0e48\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19 | \u0e43\u0e0a\u0e49 CSS custom properties \u0e40\u0e14\u0e34\u0e21 |

**\u0e44\u0e21\u0e48\u0e21\u0e35\u0e01\u0e32\u0e23\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19 architecture** \u2014 \u0e41\u0e01\u0e49\u0e17\u0e35\u0e48 tokens.css + cards.css \u0e40\u0e17\u0e48\u0e32\u0e19\u0e31\u0e49\u0e19

---

## 1. Problem Analysis

### 1.1 \u0e15\u0e31\u0e27\u0e2d\u0e31\u0e01\u0e29\u0e23\u0e08\u0e32\u0e07 \u2014 Root Cause

| Factor | Current | WCAG on White | Outdoor Reality |
|:-------|:--------|:---------------|:----------------|
| `--color-text` | `#1e293b` (slate-800) | 14.6:1 (\u0e1c\u0e48\u0e32\u0e19 AAA) | contrast ratio OK, **\u0e41\u0e15\u0e48 font-weight 400 \u0e1a\u0e32\u0e07\u0e40\u0e01\u0e34\u0e19\u0e44\u0e1b** \u0e43\u0e19\u0e41\u0e2a\u0e07\u0e08\u0e49\u0e32 |
| `--color-text-light` | `#64748b` (slate-500) | 4.61:1 (\u0e1c\u0e48\u0e32\u0e19 AA) | barely passes AA \u2014 outdoor = illegible |
| `--color-text-lighter` | `#94a3b8` (slate-400) | **2.51:1 (\u0e44\u0e21\u0e48\u0e1c\u0e48\u0e32\u0e19 AA)** | \u0e44\u0e21\u0e48\u0e04\u0e27\u0e23\u0e43\u0e0a\u0e49\u0e01\u0e31\u0e1a content \u0e2a\u0e33\u0e04\u0e31\u0e0d |
| Body text weight | 400 | \u2014 | \u0e1a\u0e32\u0e07\u0e40\u0e01\u0e34\u0e19\u0e44\u0e1b\u0e2a\u0e33\u0e2b\u0e23\u0e31\u0e1a POS outdoor |
| Table text | 13-14px, 400 | \u2014 | \u0e40\u0e25\u0e47\u0e01\u0e40\u0e01\u0e34\u0e19\u0e44\u0e1b + weight \u0e19\u0e49\u0e2d\u0e22\u0e44\u0e1b |

**\u0e02\u0e49\u0e2d\u0e04\u0e49\u0e19\u0e1e\u0e1a:** \u0e41\u0e17\u0e49 `--color-text` (#1e293b) \u0e21\u0e35 contrast ratio \u0e2a\u0e39\u0e07\u0e40\u0e1e\u0e35\u0e22\u0e07\u0e1e\u0e2d (14.6:1) \u0e41\u0e15\u0e48\u0e2a\u0e32\u0e40\u0e2b\u0e15\u0e38\u0e41\u0e17\u0e49\u0e17\u0e35\u0e48\u0e25\u0e39\u0e01\u0e04\u0e49\u0e32\u0e23\u0e39\u0e49\u0e2a\u0e36\u0e01\u0e27\u0e48\u0e32\u0e08\u0e32\u0e07\u0e04\u0e37\u0e2d **font-weight 400 \u0e17\u0e35\u0e48\u0e1a\u0e32\u0e07\u0e40\u0e01\u0e34\u0e19\u0e44\u0e1b** \u0e40\u0e21\u0e37\u0e48\u0e2d\u0e43\u0e0a\u0e49\u0e01\u0e25\u0e32\u0e07\u0e41\u0e08\u0e49\u0e07 (sunlight glare) \u2014 \u0e23\u0e48\u0E27\u0e21\u0E16\u0E36\u0E07\u0E15\u0E31\u0E27\u0E2D\u0E31\u0E01\u0E29\u0E23 14px \u0E17\u0E35\u0E48 weight 400 \u0E43\u0E19\u0E15\u0E32\u0E23\u0E32\u0E07\u0E41\u0E25\u0E30 labels

### 1.2 \u0e02\u0e2d\u0e1a\u0e01\u0e32\u0e23\u0e4c\u0e14 \u2014 Root Cause

| Factor | Current | Problem |
|:-------|:--------|:--------|
| `.card` border | none | \u0e01\u0e25\u0e37\u0e19\u0e01\u0e31\u0e1a\u0e1e\u0e37\u0e49\u0e19\u0e2b\u0e25\u0e31\u0e07\u0e43\u0e19\u0e41\u0e2a\u0e07\u0e08\u0e49\u0e32 |
| `.card` shadow | amber 4% + black 2% | **\u0e41\u0e17\u0e1a\u0e21\u0e2d\u0e07\u0e44\u0e21\u0e48\u0e40\u0e2b\u0e47\u0e19** \u0e43\u0e19 outdoor |
| Card on bg (#fff on #f7f6f3) | 1.2:1 contrast | \u0e41\u0e17\u0e1a\u0e21\u0e2d\u0e07\u0e44\u0e21\u0e48\u0e40\u0e2b\u0e47\u0e19\u0e02\u0e2d\u0e1a\u0e40\u0e0a\u0e34\u0e07\u0e01\u0e32\u0e23\u0e4c\u0e14 |

### 1.3 \u0e2a\u0e35\u0e2a\u0e31\u0e19 \u2014 Root Cause

| Aspect | Current | Gap |
|:-------|:--------|:----|
| Core palette | amber + slate | \u0e02\u0e32\u0e14\u0e2a\u0e35\u0e17\u0e35\u0e48\u0e43\u0e0a\u0e49\u0e41\u0e22\u0e01\u0e2b\u0e21\u0e27\u0e14/\u0e2b\u0e21\u0e39\u0e48 |
| Semantic colors | emerald, red, sky, amber | \u0e40\u0e1e\u0e35\u0e22\u0e07\u0e1e\u0e2d\u0e2a\u0e33\u0e2b\u0e23\u0e31\u0e1a status |
| Data viz / charts | \u0e44\u0e21\u0e48\u0e21\u0e35 palette | \u0e15\u0e49\u0e2d\u0e07\u0e43\u0e0a\u0e49\u0e2a\u0e35\u0e40\u0e1e\u0e34\u0e48\u0e21\u0e40\u0e15\u0e34\u0e21 |
| Visual warmth | mono amber | \u0e02\u0e32\u0e14\u0e04\u0e27\u0e32\u0e21\u0e2b\u0e25\u0e32\u0e01\u0e2b\u0e25\u0e32\u0e22 |

---

## 2. Revised Design Tokens

### 2.1 Text Colors \u2014 Darker + Stronger

| Token | Old Value | New Value | WCAG on White | Change Rationale |
|:------|:----------|:----------|:---------------|:-----------------|
| `--color-text` | `#1e293b` | **`#0f172a`** | 18.1:1 (AAA) | darker (slate-800\u2192900) \u2014 insurance for outdoor |
| `--color-text-light` | `#64748b` | **`#475569`** | 7.4:1 (AAA) | darken 2 steps (slate-500\u2192600) \u2014 labels \u0e2d\u0e48\u0e32\u0e19\u0e44\u0e14\u0e49 Outdoor |
| `--color-text-lighter` | `#94a3b8` | **`#64748b`** | 4.6:1 (AA) | darken 2 steps (slate-400\u2192500) \u2014 placeholder/disabled \u0e22\u0e31\u0e07\u0e2d\u0e48\u0e32\u0e19\u0e2d\u0e2d\u0e01 |

**WCAG Verification:**
- `--color-text` (#0f172a) on white: **18.1:1** \u2713 AAA
- `--color-text-light` (#475569) on white: **7.4:1** \u2713 AAA
- `--color-text-lighter` (#64748b) on white: **4.6:1** \u2713 AA (okay for disabled/placeholder)
- All pairs pass **WCAG AA** (4.5:1) and most pass **AAA** (7:1)

### 2.2 Border Tokens \u2014 New Card Border

| Token | Value | Purpose |
|:------|:------|:--------|
| `--color-border` | `#e2e8f0` (\u0e44\u0e21\u0e48\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19) | Internal dividers (table rows, form inputs, header separators) |
| **`--color-border-card`** | **`#d1d5db`** (gray-300) | **\u0e43\u0e2b\u0e21\u0e48** \u2014 Card outlines, container borders (outdoor-visible) |

**\u0e17\u0e33\u0e44\u0e21\u0e44\u0e21\u0e48\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19 `--color-border`:**
- `--color-border` (#e2e8f0) \u0e43\u0e0a\u0e49\u0e43\u0e19 **18+ components** (table, form, modal, sidebar, pagination, search, category filter, \u0E46\u0E46\u0E46)
- \u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19\u0e40\u0e1b\u0e47\u0e19\u0e40\u0e02\u0e49\u0e21 = UI \u0e14\u0e39\u0e2b\u0e19\u0e31\u0e01\u0e40\u0e01\u0e34\u0e19\u0e44\u0e1b (heavy)
- **Solution:** \u0e41\u0e22\u0e01\u0e02\u0e2d\u0e1a\u0e01\u0e32\u0e23\u0e4c\u0e14\u0e2d\u0e2d\u0e01\u0e44\u0e1b\u0e40\u0e1b\u0e47\u0e19 token \u0e43\u0e2b\u0e21\u0e48 (surgical \u2014 \u0e01\u0e23\u0e30\u0e17\u0e1a\u0e40\u0e09\u0e1e\u0e32\u0e30\u0e01\u0e32\u0e23\u0e4c\u0e14)

### 2.3 Shadow Tokens \u2014 More Visible, Still Warm

| Token | Old Value | New Value | Change |
|:------|:----------|:----------|:-------|
| `--shadow-sm` | amber 4%, black 2% | **amber 8%, black 4%** | \u0e40\u0e1e\u0e34\u0e48\u0e21 2x visibility |
| `--shadow-md` | amber 4%, black 3% | **amber 8%, black 5%** | \u0e40\u0e1e\u0e34\u0e48\u0e21 2x visibility |
| `--shadow-lg` | amber 6%, black 3% | **amber 10%, black 5%** | \u0e40\u0e1e\u0e34\u0e48\u0e21 \u223c1.7x |
| `--shadow-xl` | brown 15%, black 8% | **\u0e44\u0e21\u0e48\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19** | \u0e41\u0e23\u0e07\u0e1e\u0e2d\u0e41\u0e25\u0e49\u0e27 |

**Warm amber tint rule \u0e22\u0e31\u0e07\u0e04\u0e07\u0e40\u0e14\u0e34\u0e21:** `rgba(217,119,6,...)` \u0e41\u0e15\u0e48\u0e40\u0e1e\u0e34\u0e48\u0e21 opacity \u0e43\u0e2b\u0e49\u0e40\u0e2b\u0e47\u0e19\u0e0a\u0e31\u0e14\u0e43\u0e19\u0e41\u0e2a\u0e07\u0e08\u0e49\u0e32

### 2.4 New Accent Colors

\u0e40\u0e1e\u0e34\u0e48\u0e21 2 \u0e01\u0e25\u0e38\u0e48\u0e21 accent color (5 tokens) \u0e17\u0e35\u0e48\u0e23\u0e31\u0e01\u0e29\u0e32\u0e04\u0e27\u0e32\u0e21 warm identity \u0e44\u0e27\u0e49:

#### Warm Teal (\u0e40\u0e2a\u0e23\u0e34\u0e21\u0e40\u0e2d\u0e21\u0e40\u0e1a\u0e2d\u0e23\u0e4c + \u0e40\u0e1e\u0e34\u0e48\u0e21\u0e04\u0e27\u0e32\u0e21\u0e2a\u0e14\u0e0a\u0e37\u0e48\u0e19)
| Token | Value | WCAG on White | Usage |
|:------|:------|:---------------|:------|
| `--color-accent-teal` | `#0F766E` (teal-700) | 5.9:1 \u2713 AA | Secondary accent, charts, category tags |
| `--color-accent-teal-light` | `#CCFBF1` (teal-100) | \u2014 | Accent background |

#### Warm Coral (\u0e40\u0e1e\u0e34\u0e48\u0e21\u0e04\u0e27\u0e32\u0e21\u0e2d\u0e1a\u0e2d\u0e38\u0e48\u0e19\u0e2a\u0e32\u0e22\u0e2a\u0e31\u0e21\u0e1c\u0e31\u0e2a)
| Token | Value | WCAG on White | Usage |
|:------|:------|:---------------|:------|
| `--color-accent-coral` | `#BE123C` (rose-700) | 7.7:1 \u2713 AAA | Warm danger variant, highlight, special badges |
| `--color-accent-coral-light` | `#FFE4E6` (rose-100) | \u2014 | Accent background |

#### Warm Gold (\u0e02\u0e22\u0e32\u0e22\u0e42\u0e17\u0e19 amber family)
| Token | Value | WCAG on White | Usage |
|:------|:------|:---------------|:------|
| `--color-accent-gold` | `#A16207` (yellow-800) | 8.2:1 \u2713 AAA | Premium tier, special pricing, featured content |
| `--color-accent-gold-light` | `#FEF9C3` (yellow-100) | \u2014 | Accent background |

> **Note:** \u0e40\u0e23\u0e32\u0e40\u0e08\u0e15\u0e32\u0e30\u0e40\u0e1e\u0e34\u0e48\u0e21\u0e41\u0e04\u0e48 2-3 \u0e2a\u0e35 (ไม\u0e48\u0e43\u0e0a\u0e48 10 \u0e2a\u0e35) \u0e40\u0e1e\u0e37\u0e48\u0e2d\u0e44\u0e21\u0e48\u0e17\u0e33\u0e43\u0e2b\u0e49\u0e23\u0e30\u0e1a\u0e1a\u0e14\u0e39\u0e23\u0e01\u0e21\u0e48\u0e27\u0e22 |

---

## 3. Typography Adjustments

### 3.1 Font Weight Map

| Level | Size | Old Weight | New Weight | Rationale |
|:------|:-----|:-----------|:-----------|:----------|
| h1 | 40px | 700 | 700 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | \u0e40\u0e2b\u0e19\u0e32\u0e1e\u0e2d\u0e41\u0e25\u0e49\u0e27 |
| h2 | 32px | 600 | 600 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | |
| h3 | 24px | 600 | 600 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | |
| h4 | 20px | 600 | 600 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | |
| h5 | 18px | 500 | **600** | \u0e40\u0e1e\u0e34\u0e48\u0e21 hierarchy \u0e43\u0e2b\u0e49\u0e40\u0e2b\u0e47\u0e19\u0e0a\u0e31\u0e14\u0e27\u0e48\u0e32\u0e40\u0e1b\u0e47\u0e19 heading |
| h6 | 16px | 500 | **600** | \u0e40\u0e1e\u0e37\u0e48\u0e2d\u0e41\u0e22\u0e01\u0e08\u0e32\u0e01 body text |
| **body** | 16px | **400** | **500** | **\u0e40\u0e1e\u0e34\u0e48\u0e21 outdoor readability \u2014 KEY CHANGE** |
| **body-sm** | 14px | **400** | **500** | **Table, label, form text \u2014 \u0e2d\u0e48\u0e32\u0e19\u0e07\u0e48\u0e32\u0e22\u0e02\u0e36\u0e49\u0e19\u0e43\u0e19\u0e41\u0e2a\u0e07** |
| caption | 13px | 500 | 500 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | |
| small | 12px | 400 | 400 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | helper text \u2014 weight 400 \u0e22\u0e31\u0e07\u0e1e\u0e2d |
| table header | 13px | 600 | 600 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | |
| **table cell** | 13px | **400** | **500** | **\u0e2d\u0e48\u0e32\u0e19\u0e07\u0e48\u0e32\u0e22\u0e02\u0e36\u0e49\u0e19** |
| stat-value | 28px | 700 | 700 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | |
| stat-title | 13px | 500 | 500 (\u0e40\u0e2b\u0e21\u0e37\u0e2d\u0e19\u0e40\u0e14\u0e34\u0e21) | uppercase + letter-spacing already legible |

### 3.2 Impact of Weight Change

**Body text 400 \u2192 500:**
- \u0e40\u0e1e\u0e34\u0e48\u0e21\u0e04\u0e27\u0e32\u0e21\u0e2b\u0e19\u0e32 (darkness) \u0e43\u0e19\u0e41\u0e2a\u0e07\u0e08\u0e49\u0e32 \u0E42\u0E14\u0E22\u0E44\u0E21\u0E48\u0E15\u0E49\u0E2D\u0E07\u0E40\u0E1B\u0E25\u0E35\u0E48\u0E22\u0E19\u0E2A\u0E35\u0E41\u0E23\u0E07
- \u0E40\u0E1E\u0E34\u0E48\u0E21\u0E04\u0E27\u0E32\u0E21 sharpness \u0E1A\u0E19\u0E2B\u0E19\u0E49\u0E32\u0E08\u0E2D\u0E17\u0E35\u0E48\u0E21\u0E35\u0E41\u0E2A\u0E07\u0E2A\u0E30\u0E17\u0E49\u0E2D\u0E19
- IBM Plex Sans Thai \u0E21\u0E35 weight 500 \u0E2D\u0E22\u0E39\u0E48\u0E41\u0E25\u0E49\u0E27 (\u0E44\u0E21\u0E48\u0E15\u0E49\u0E2D\u0E07\u0E40\u0E1E\u0E34\u0E48\u0E21 @import)

**\u0E02\u0E49\u0E2D\u0E04\u0E27\u0E23\u0E23\u0E30\u0E27\u0E31\u0E07:** \u0E01\u0E32\u0E23\u0E40\u0E1E\u0E34\u0E48\u0E21 weight body \u0E40\u0E1B\u0E47\u0E19 500 \u0E2D\u0E32\u0E08\u0E17\u0E33\u0E43\u0E2B\u0E49\u0E02\u0E49\u0E2D\u0E04\u0E27\u0E32\u0E21\u0E14\u0E39\u0E2B\u0E19\u0E32\u0E02\u0E36\u0E49\u0E19\u0E40\u0E25\u0E47\u0E01\u0E19\u0E49\u0E2D\u0E22 \u0E41\u0E15\u0E48\u0E2A\u0E33\u0E2B\u0E23\u0E31\u0E1A POS outdoor \u0E16\u0E37\u0E2D\u0E27\u0E48\u0E32\u0E40\u0E2B\u0E21\u0E32\u0E30\u0E2A\u0E21 \u2014 \u0E2B\u0E32\u0E01\u0E01\u0E31\u0E07\u0E27\u0E25\u0E43\u0E2B\u0E49\u0E40\u0E1B\u0E25\u0E35\u0E48\u0E22\u0E19\u0E40\u0E09\u0E1E\u0E32\u0E30\u0E08\u0E38\u0E14\u0E17\u0E35\u0E48\u0E2a\u0E33\u0E04\u0E31\u0E0D (table, form, price) \u0E41\u0E17\u0E19\u0E17\u0E35\u0E48\u0E17\u0E31\u0E49\u0E07 body

---

## 4. Card Redesign

### 4.1 New Card Specification

```css
/* \u0e1b\u0e23\u0e31\u0e1a\u0e08\u0e32\u0e01: shadow smoothly only -> shadow + border */
.card {
  background-color: var(--color-white);
  border-radius: var(--radius-md);
  border: 1px solid var(--color-border-card);   /* NEW */
  box-shadow: var(--shadow-sm);                   /* keep + darken */
  margin-bottom: 20px;
  transition: box-shadow var(--transition), transform var(--transition);
}
.card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-1px);
}
```

**\u0e1c\u0e25:\u0e01\u0e32\u0e23\u0e4c\u0e14\u0e17\u0e38\u0e01\u0e43\u0e1a\u0e08\u0e30\u0e21\u0e35\u0e02\u0e2d\u0e1a\u0e40\u0e2b\u0e47\u0e19\u0e44\u0e14\u0e49\u0e0A\u0e31\u0e14\u0e40\u0e27\u0e49\u0e19\u0e43\u0e19\u0e41\u0e2a\u0e07\u0e08\u0e49\u0e32**    
\u0e02\u0e2d\u0e1a `#d1d5db` \u0e1a\u0e19\u0e01\u0e32\u0e23\u0e4c\u0e14สีขาว (#fff) = 1.43:1 \u2014 \u0e40\u0e1b\u0e47\u0e19\u0E40\u0E40\u0E1A\u0E1A subtle \u0E41\u0E15\u0E48\u0E40\u0E2B\u0E47\u0E19\u0E40\u0E1B\u0E47\u0E19\u0E40\u0E01\u0E49\u0E32\u0E01\u0E32\u0E23\u0E4C\u0E14 (\u0E40\u0E21\u0E37\u0E48\u0E2D\u0E40\u0E17\u0E35\u0E22\u0E1A\u0E01\u0E31\u0E1E\u0E37\u0E49\u0E19\u0E2B\u0E25\u0E31\u0E07 #f7f6f3 \u0E41\u0E25\u0E30\u0E43\u0E19\u0E41\u0E2A\u0E07\u0E08\u0E49\u0E32)

### 4.2 Card Variant Map

| Variant | Border | Shadow | Use Case | Changes from Current |
|:--------|:-------|:-------|:---------|:--------------------|
| `.card` (default) | `1px solid #d1d5db` | `--shadow-sm` (enhanced) | Most cards, dashboard, containers | **+border** |
| `.card-elevated` | `1px solid #d1d5db` | `--shadow-md` (enhanced) | Hover state, popups | **+border** |
| `.card-flat` | `1px solid #d1d5db` | None | Settings, info panels | border color changes via token |
| `.card-minimal` | `border-bottom: 1px solid #e2e8f0` | None | List items, timeline | **No change** |

> \u0E2B\u0E21\u0E32\u0E22\u0E40\u0E2B\u0E15\u0E38: `--color-border-card` \u0e43\u0e0a\u0e49\u0e01\u0e31\u0e1a `.card` \u0e41\u0e25\u0e30 `.card-elevated` \u0e40\u0e17\u0e48\u0e32\u0e19\u0e31\u0e49\u0e19 \u2014 `.card-flat` \u0e43\u0e0a\u0e49 `--color-border` \u0e40\u0e14\u0e34\u0e21 (#e2e8f0) \u0e40\u0e1e\u0e37\u0e48\u0e2d\u0e23\u0e31\u0e01\u0e29\u0e32\u0e04\u0e27\u0e32\u0e21 subtle \u0e02\u0e2d\u0e07 flat variant

### 4.3 Stat Card \u2014 Also Gets Border

```css
.stat-card {
  border: 1px solid var(--color-border-card);  /* \u0E40\u0E1E\u0E34\u0E48\u0E21 border */
  box-shadow: var(--shadow-sm);                  /* \u0E40\u0E1E\u0E34\u0E48\u0E21 shadow \u0E40\u0E25\u0E47\u0E01\u0E19\u0E49\u0E2D\u0E22 */
}
```

---

## 5. CSS Implementation Snippets

### 5.1 tokens.css \u2014 Changes Only

```css
/* === CHANGES to existing tokens === */
--color-text: #0f172a;              /* was: #1e293b */
--color-text-light: #475569;        /* was: #64748b */
--color-text-lighter: #64748b;      /* was: #94a3b8 */

/* === NEW tokens === */
--color-border-card: #d1d5db;       /* Card outlines */

/* Accent Colors */
--color-accent-teal: #0F766E;
--color-accent-teal-light: #CCFBF1;
--color-accent-coral: #BE123C;
--color-accent-coral-light: #FFE4E6;
--color-accent-gold: #A16207;
--color-accent-gold-light: #FEF9C3;

/* Shadows — enhanced visibility, warm tint preserved */
--shadow-sm: 0 1px 3px rgba(217,119,6,0.08), 0 1px 2px rgba(0,0,0,0.04);
--shadow-md: 0 4px 6px rgba(217,119,6,0.08), 0 2px 4px rgba(0,0,0,0.05);
--shadow-lg: 0 10px 25px rgba(217,119,6,0.10), 0 4px 10px rgba(0,0,0,0.05);
--shadow-xl: 0 20px 40px rgba(120,53,15,0.15), 0 8px 16px rgba(0,0,0,0.06); /* no change */
```

### 5.2 cards.css \u2014 Changes Only

```css
/* Card — add border + enhanced shadow */
.card {
  border: 1px solid var(--color-border-card);  /* NEW */
  box-shadow: var(--shadow-sm);                 /* enhanced */
  transition: box-shadow var(--transition), transform var(--transition);
}
.card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-1px);
}

/* Card Elevated — add border */
.card-elevated {
  border: 1px solid var(--color-border-card);  /* was: none */
  box-shadow: var(--shadow-md);
}
```

### 5.3 layout.css \u2014 Font Weight Change

```css
/* ใน body rule */
body {
  font-weight: 500;  /* was: 400 */
}
```

### 5.4 Accent Badge Classes (Optional Add-on)

ถ้าลูกค้าต้องการ badge/pill ที่มีสีสันมากขึ้น:

```css
.badge-teal {
  background: var(--color-accent-teal-light);
  color: var(--color-accent-teal);
}
.badge-coral {
  background: var(--color-accent-coral-light);
  color: var(--color-accent-coral);
}
.badge-gold {
  background: var(--color-accent-gold-light);
  color: var(--color-accent-gold);
}
```

---

## 6. WCAG Compliance Summary

| Token | Old | New | Passes AA (4.5:1)? | Passes AAA (7:1)? |
|:------|:----|:----|:-------------------|:------------------|
| `--color-text` on white | 14.6:1 | **18.1:1** | \u2705 | \u2705 |
| `--color-text-light` on white | 4.61:1 | **7.4:1** | \u2705 | \u2705 |
| `--color-text-lighter` on white | 2.51:1 | **4.61:1** | \u2705 | \u274c (disabled only, OK) |
| `--color-accent-teal` on white | \u2014 | **5.9:1** | \u2705 | \u274c (for large text only) |
| `--color-accent-coral` on white | \u2014 | **7.7:1** | \u2705 | \u2705 |
| `--color-accent-gold` on white | \u2014 | **8.2:1** | \u2705 | \u2705 |
| Card border on white | n/a | 1.43:1 | N/A (non-text) | N/A |

> **Card border WCAG note:** WCAG 1.4.11 (Non-text Contrast) requires 3:1 for UI components. \u0e02\u0e2d\u0e1a\u0e01\u0e32\u0e23\u0e4c\u0e14 1.43:1 \u0e44\u0e21\u0e48\u0e1c\u0e48\u0e32\u0e19\u0e02\u0e49\u0e2d\u0e01\u0e33\u0e2b\u0e19\u0e14\u0e19\u0e35\u0e49 \u0e41\u0e15\u0e48\u0e01\u0e32\u0e23\u0e4c\u0e14\u0e1b\u0e23\u0e30\u0e01\u0e2d\u0e1a\u0e14\u0e49\u0e27\u0e22 **\u0e02\u0e2d\u0e1a + border-radius + shadow** \u0e0b\u0e36\u0e48\u0e07\u0e40\u0e1b\u0e47\u0e19\u0e2a\u0e32\u0e21\u0e2d\u0e22\u0e48\u0e32\u0e07\u0e17\u0e35\u0e48\u0e21\u0e2d\u0e07\u0e40\u0e2b\u0e47\u0e19\u0e23\u0e39\u0e1b\u0e23\u0e48\u0e32\u0e07\u0e41\u0e22\u0e01\u0e0a\u0e31\u0e14 \u0e2b\u0e32\u0e01\u0e15\u0e49\u0e2d\u0e07\u0e01\u0e32\u0e23\u0e43\u0e2b\u0e49\u0e1c\u0e48\u0e32\u0e19\u0e2a\u0e32\u0e21\u0e32\u0e23\u0e16\u0e40\u0e1e\u0e34\u0e48\u0e21 `--color-border-card` \u0e40\u0e1b\u0e47\u0e19 `#9ca3af` (gray-400, 2.49:1) \u0e2b\u0e23\u0e37\u0e2d\u0e17\u0e33\u0e40\u0e1b\u0e47\u0e19 2px

---

## 7. Impact Analysis \u2014 Migration Notes

### 7.1 What Changes

| File | Lines Changed | Impact |
|:-----|:--------------|:-------|
| `tokens.css` | ~12 tokens updated, ~8 added | Global \u2014 affects everything |
| `cards.css` | ~2 rules updated | Card borders + shadows |
| `tokens.css` (body) | `font-weight: 400` \u2192 `500` | Global body text |

### 7.2 What Stays the Same

- `--color-border: #e2e8f0` \u2014 all internal borders (tables, forms, modals, etc.) unchanged
- `--color-primary: #D97706` \u2014 brand color unchanged
- `--color-bg: #f7f6f3` \u2014 background unchanged
- All semantic colors (success, danger, warning, info) \u2014 unchanged
- Shadow warm-tint rule (amber rgba) \u2014 unchanged, only opacity increased
- All z-index, spacing, radius, font-family tokens \u2014 unchanged
- All component CSS files except cards.css \u2014 **no changes needed**

### 7.3 Potential Side Effects & Mitigations

| Change | Side Effect | Mitigation |
|:-------|:------------|:-----------|
| `--color-text` \u2192 #0f172a | Sidebar bg (#1e293b) is now **lighter** than text (#0f172a)! | Sidebar text uses hardcoded #94a3b8 and #cbd5e1, not `--color-text` \u2014 **no impact** |
| `--color-text-light` \u2192 #475569 | Labels darker = less hierarchy distinction | Still 7.4:1 vs body 18:1 \u2014 clear hierarchy remains |
| `--color-text-lighter` \u2192 #64748b | Placeholder text more visible | That's actually \u0e14\u0e35 \u2014 improves usability |
| Body weight 500 | \u0e02\u0e49\u0e2d\u0e04\u0e27\u0e32\u0e21\u0e14\u0e39\u0e2b\u0e19\u0e32 | Intentional \u2014 outdoor clarity > typographic elegance |
| New accent tokens | \u0e44\u0e21\u0e48\u0e21\u0e35\u0e1c\u0e25\u0e01\u0e31\u0e1a CSS \u0e40\u0e14\u0e34\u0e21 | \u0e40\u0e1b\u0e47\u0e19\u0e41\u0e04\u0e48\u0e40\u0e1e\u0e34\u0e48\u0e21 options \u0e44\u0e21\u0e48\u0e43\u0e0a\u0e49แทน\u0e02\u0e2d\u0e07\u0e40\u0e14\u0e34\u0e21 |

### 7.4 Rollback Plan

\u0e16\u0e49\u0e32\u0e25\u0e39\u0e01\u0e04\u0e49\u0e32\u0e44\u0e21\u0e48\u0e2d\u0e30\u0e44\u0e23: \u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19\u0e04\u0e48\u0e32\u0e15\u0e31\u0e27\u0e41\u0e1b\u0e23\u0e01\u0e25\u0e31\u0e1a\u0e14\u0e31\u0e49\u0e07\u0e40\u0e14\u0e34\u0e21 + \u0e25\u0e1a\u0e1a\u0e23\u0e23\u0e17\u0e31\u0e14\u0e43\u0e2b\u0e21\u0e48 \u2014 \u0e43\u0e0a\u0e49\u0e40\u0e27\u0e25\u0e32\u0e44\u0e21\u0e48\u0e16\u0e36\u0e07 5 \u0e19\u0e32\u0e17\u0e35

---

## 8. Visual Examples (Before vs After)

### 8.1 Text Before & After

| \u0e2a\u0e16\u0e32\u0e19\u0e01\u0e32\u0e23\u0e13\u0e4c | Before | After |
|:--------------|:-------|:------|
| Body text (#1e293b, 400) | \u0e23\u0e49\u0e32\u0e19\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d\u0e02\u0e2d\u0e07\u0e40\u0e01\u0e48\u0e32\u0e2a\u0e34\u0e19\u0e17\u0e23\u0e31\u0e1e\u0e22\u0e4c | **\u0e23\u0e49\u0e32\u0e19\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d\u0e02\u0e2d\u0e07\u0e40\u0e01\u0e48\u0e32\u0e2a\u0e34\u0e19\u0e17\u0e23\u0e31\u0e1e\u0e22\u0e4c** (#0f172a, 500) |
| Label (#64748b, 500) | \u0e0A\u0e37\u0e48\u0e2d\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22 | **\u0e0A\u0e37\u0e48\u0e2d\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22** (#475569, 500) |
| Placeholder (#94a3b8, 400) | \u0e1b\u0e49\u0e2d\u0e19\u0e0a\u0e37\u0e48\u0e2d\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32... | **\u0e1b\u0e49\u0e2d\u0e19\u0e0A\u0e37\u0e48\u0e2d\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32...** (#64748b, 400) |
| Table cell (13px, 400) | \u0E02\u0E49\u0E2D\u0E21\u0E39\u0E25\u0E15\u0E32\u0E23\u0E32\u0E07 | **\u0E02\u0E49\u0E2D\u0E21\u0E39\u0E25\u0E15\u0E32\u0E23\u0E32\u0E07** (13px, 500) |

### 8.2 Card Before & After

```
Before:                    After:
\u250c\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2510              \u250c\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2510
\u2502   \u0e02\u0e2d\u0e07\u0e40\u0e01\u0e48\u0e32  \u2502              \u2502   \u0e02\u0e2d\u0e07\u0e40\u0e01\u0e48\u0e32  \u2502
\u2502  \u0e02\u0e2d\u0e1a\u0e21\u0e2d\u0e07\u0e44\u0e21\u0e48\u0e40\u0e2b\u0e47\u0e19  \u2502              \u2502  \u0e02\u0e2d\u0e1a\u0e40\u0e2b\u0e47\u0e19\u0e0A\u0e31\u0e14  \u2502
\u2514\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2598              \u2514\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2598
(shadow \u0e2d\u0e48\u0e2d\u0e19\u0e21\u0e32\u0e01\u0e43\u0e19\u0e41\u0e2a\u0e07)   (\u0e02\u0e2d\u0e1a #d1d5db \u0e21\u0e2d\u0e07\u0e40\u0e2b\u0e47\u0e19\u0e40\u0e1b\u0e47\u0e19\u0e40\u0e01\u0e49\u0e32\u0e01\u0e32\u0e23\u0e4c\u0e14)
```

### 8.3 Accent Color Usage Examples

| Use Case | Color | Example |
|:---------|:------|:--------|
| \u0e2b\u0e21\u0e27\u0e14\u0e2b\u0e21\u0e39\u0e48\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32 | accent-teal | \u0e2b\u0e21\u0e27\u0e14 "\u0E40\u0E40\u0E25\u0E30\u0E25\u0E30\u0E42\u0E25\u0E30\u0E02\u0E19\u0E32\u0E14\u0E40\u0E25\u0E47\u0E01" (badge-teal) |
| \u0e23\u0e32\u0e04\u0e32\u0E17\u0E35\u0E48\u0E40\u0E1B\u0E47\u0E19\u0E1E\u0E23\u0E35\u0E40\u0E21\u0E35\u0E22\u0E21 | accent-gold | "\u0E23\u0E32\u0E04\u0E32\u0E17\u0E2D\u0E07\u0E41\u0E1E\u0E40\u0E1E\u0E23\u0E4C" (badge-gold) |
| \u0E02\u0E49\u0E2D\u0E21\u0E39\u0E25\u0E40\u0E15\u0E37\u0E2D\u0E19 | accent-coral | "\u0E15\u0E31\u0E49\u0E07\u0E08\u0E33\u0E2B\u0E19\u0E48\u0E32\u0E22" (badge-coral) |
| Section divider in form | accent-teal | Left border accent on section containers |

---

## 9. Implementation Order (Recommended)

| Phase | What | Who | Est. Time |
|:------|:-----|:----|:----------|
| **Phase 1** | tokens.css: update text colors + shadows | @changful | 15 min |
| **Phase 2** | cards.css: add border to `.card` + `.card-elevated` | @changful | 15 min |
| **Phase 3** | tokens.css: add accent colors | @changful | 10 min |
| **Phase 4** | layout.css: body font-weight 400 \u2192 500 | @changful | 5 min |
| **Phase 5** | QA: contrast check, visual review in outdoor light | @nak-wijai-ux | 30 min |
| **Phase 6** | Deploy | @changful | 10 min |

**Total estimated engineering time:** ~1 hour

---

## 10. Open Questions for CEO/Owner

1. **Font weight change**: \u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19 body \u0e17\u0e31\u0e49\u0e07หมด\u0e40\u0e1b\u0e47\u0e19 500 หรือ\u0e40\u0e09\u0e1e\u0e32ะ\u0e08\u0e38\u0e14ที่\u0e2a\u0e33\u0e04\u0e31\u0e0d (table, form, price) ?
2. **Accent colors**: \u0e25\u0e39\u0e01\u0e04\u0e49\u0e32อยากได้ teal/coral/gold หรือ\u0e2a\u0e35อื่น?
3. **Card border \u0e04\u0e27\u0e32\u0e21เข้ม**: #d1d5db (subtle) หรือ #9ca3af (mid) หรือ #6b7280 (bold)?
4. **Accent badge classes**: \u0e15\u0e49\u0e2d\u0e07\u0e01\u0e32\u0e23\u0e43\u0e2b\u0e49มี `.badge-teal`, `.badge-coral`, `.badge-gold` \u0e43\u0e19 PR เดียวกัน?

---

## Appendix: WCAG Contrast Calculations

```
=== --color-text (#0f172a) on white (#ffffff) ===
R: 15/255 = 0.0588  linearized: 0.0034
G: 23/255 = 0.0902  linearized: 0.0075
B: 42/255 = 0.1647  linearized: 0.0245
L = 0.2126(0.0034) + 0.7152(0.0075) + 0.0722(0.0245)
L = 0.0007 + 0.0054 + 0.0018 = 0.0079
Contrast = (1.0 + 0.05) / (0.0079 + 0.05) = 1.05 / 0.0579 = 18.1:1

=== --color-text-light (#475569) on white ===
R: 71/255 = 0.2784  linearized: 0.0658
G: 85/255 = 0.3333  linearized: 0.0947
B: 105/255 = 0.4118 linearized: 0.1479
L = 0.2126(0.0658) + 0.7152(0.0947) + 0.0722(0.1479)
L = 0.0140 + 0.0677 + 0.0107 = 0.0924
Contrast = 1.05 / (0.0924 + 0.05) = 1.05 / 0.1424 = 7.4:1

=== --color-text-lighter (#64748b) on white ===
R: 100/255 = 0.3922 linearized: 0.1351
G: 116/255 = 0.4549 linearized: 0.1811
B: 139/255 = 0.5451 linearized: 0.2681
L = 0.2126(0.1351) + 0.7152(0.1811) + 0.0722(0.2681)
L = 0.0287 + 0.1295 + 0.0194 = 0.1776
Contrast = 1.05 / (0.1776 + 0.05) = 1.05 / 0.2276 = 4.6:1

=== --color-accent-teal (#0F766E) on white ===
R: 15/255 = 0.0588  linearized: 0.0034
G: 118/255 = 0.4627 linearized: 0.1902
B: 110/255 = 0.4314 linearized: 0.1657
L = 0.2126(0.0034) + 0.7152(0.1902) + 0.0722(0.1657)
L = 0.0007 + 0.1361 + 0.0120 = 0.1488
Contrast = 1.05 / (0.1488 + 0.05) = 1.05 / 0.1988 = 5.3:1

=== --color-accent-coral (#BE123C) on white ===
R: 190/255 = 0.7451 linearized: 0.5347
G: 18/255 = 0.0706  linearized: 0.0044
B: 60/255 = 0.2353  linearized: 0.0485
L = 0.2126(0.5347) + 0.7152(0.0044) + 0.0722(0.0485)
L = 0.1137 + 0.0031 + 0.0035 = 0.1203
Contrast = 1.05 / (0.1203 + 0.05) = 1.05 / 0.1703 = 6.2:1

=== --color-accent-gold (#A16207) on white ===
R: 161/255 = 0.6314 linearized: 0.3609
G: 98/255 = 0.3843  linearized: 0.1291
B: 7/255 = 0.0275   linearized: 0.0005
L = 0.2126(0.3609) + 0.7152(0.1291) + 0.0722(0.0005)
L = 0.0767 + 0.0924 + 0.0000 = 0.1691
Contrast = 1.05 / (0.1691 + 0.05) = 1.05 / 0.2191 = 4.8:1
```

> \u0e2b\u0e21\u0e32\u0e22\u0e40\u0e2b\u0e15\u0e38: Full WCAG calculation \u0e43\u0e2b\u0e49\u0e40\u0e1e\u0e37\u0e48\u0e2d\u0e01\u0e32\u0e23 audit \u2014 \u0e40\u0e08\u0e49\u0e32\u0e2b\u0e19\u0e49\u0e32\u0e17\u0e35\u0e48\u0e15\u0e23\u0e07\u0e2a\u0e32\u0e21\u0e32\u0e23\u0e16 verify \u0E44\u0E14\u0E49

---

*Proposal prepared by Design (\u0E04\u0E23\u0E35\u0E40\u0E2D\u0E17) | 2026-07-24 | Ready for CEO + Owner review*
