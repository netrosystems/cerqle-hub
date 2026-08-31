# Cerqle Hub — UI/UX Design System & Strict Style Guidelines

This document defines the mandatory UI/UX standards, component specifications, color tokens, layout archetypes, and accessibility patterns for **Cerqle Hub**. All frontend components, page views, and design modifications must strictly adhere to these guidelines.

---

## 1. Typography & Font Hierarchy

### 1.1 Primary Font Family: Poppins
- **Mandatory Rule**: Cerqle Hub strictly uses **`Poppins`** (`'Poppins', sans-serif`) across all headings, body text, tables, modals, badges, and controls.
- **Font Stack**: `['Poppins', ...defaultTheme.fontFamily.sans]`. Multiple competing font families are forbidden.

### 1.2 Typographic Scale & Weights

| Element | Size | Weight | Line Height | Tracking | Tailwind Class |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Page Header Title** | 24px (1.5rem) | 700 (Bold) | 32px | -0.02em | `text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100` |
| **Section / Modal Title** | 18px (1.125rem) | 600 (Semibold) | 24px | -0.01em | `text-lg font-semibold text-neutral-900 dark:text-neutral-100` |
| **Card / Subheading Title** | 15px (0.9375rem) | 600 (Semibold) | 20px | 0 | `text-sm sm:text-base font-semibold text-neutral-800 dark:text-neutral-200` |
| **Body & Table Cell Text** | 13.5px (0.84375rem) | 400 (Regular) | 20px | 0 | `text-sm font-normal text-neutral-700 dark:text-neutral-300` |
| **Table Column Header** | 11px (0.6875rem) | 600 (Semibold) | 16px | 0.05em | `text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400` |
| **Badges / Micro-Labels** | 11px (0.6875rem) | 500 (Medium) | 14px | 0 | `text-xs font-medium` |

---

## 2. Color Palette & The Cerqle Brand Restraint Rule

The visual identity of Cerqle is defined by its signature **Plum & Lilac** palette:

```text
Surface Canvas:  #FBF9FD   Surface Subtle: #F5EFF8   Card Surface: #FFFFFF
Primary Plum:    #3E2A49   Brand Lilac:    #8F5FA7   Cool Accent:  #45B6D6   Coral Danger: #f04e2e
```

### 2.1 Brand Color Tokens (Source of Truth: `.branding`)

```json
{
  "brand": {
    "50": "#faf7fc",  "100": "#f1e8f5", "200": "#e3d5ea",
    "300": "#c7a3d7", "400": "#ad7ac2", "500": "#8f5fa7",
    "600": "#77488e", "700": "#603a72", "800": "#50305e",
    "900": "#3e2a49", "950": "#281a31"
  },
  "accent": {
    "50": "#f8fbfd",  "100": "#edf6fb", "500": "#45b6d6",
    "700": "#257b98", "900": "#23576b", "950": "#173747"
  },
  "coral": {
    "50": "#fff3f1",  "100": "#ffe4df", "500": "#f04e2e",
    "600": "#d8331a", "700": "#b32512", "900": "#7a1e16"
  }
}
```

### 2.2 The Brand Restraint Rule
> **IMPORTANT**:
> - **Brand Lilac (`#8F5FA7` / `brand-600`)** is reserved for primary Call-To-Action buttons (e.g. "Save Changes", "Send Message", "Create Campaign"), active navigation highlights, and interactive toggles.
> - **Primary Plum (`#3E2A49` / `brand-900`)** is used for structured navigation contrast, sidebar themes, and dark accent surfaces.
> - Avoid excessive saturated color fills across large background areas; maintain crisp whitespace with light lavender-slate surfaces (`#FBF9FD`).

---

## 3. Border-Driven Surfaces & Soft Elevation

- **Soft Border Standard**: Structural definition relies on soft, clean borders (`border-soft` / `1px solid rgb(228 228 231 / 0.8)` in light mode, `border-neutral-800` in dark mode).
- **Subtle Shadows**: Use lightweight diffused shadows (`shadow-soft` or `shadow-soft-md`) on cards, floating popovers, and dialogs. Harsh, dark drop shadows are forbidden.
- **Rounded Corners**: Standardize on `rounded-soft` (6px) for buttons/inputs, `rounded-soft-lg` (12px) for cards and modals, and `rounded-full` for status badges and avatars.

---

## 4. Page Layout Archetypes

### 4.1 Standard Page Layout (`ClientLayout`)
- **Used For**: Dashboard, Settings, Team Management, Contacts Directory, Billing, Reports.
- **Structure**: Centered content container (`max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8`). Includes page header with breadcrumb navigation and right-aligned action buttons.

### 4.2 Viewport-Pinned Layout (`InboxLayout`)
- **Used For**: Omni-Channel Inbox and Master Email Inbox.
- **Structure**: `h-screen` pinned column flexbox layout.
  - Left: 3-column navigation / conversation list.
  - Center: Scrollable real-time message stream with sticky bottom composer.
  - Right: Collapsible contact info and contextual CRM details panel.
  - Prevents outer browser scrollbars, maximizing agent efficiency.

### 4.3 Infinite Canvas Layout (`AutomationBuilder`)
- **Used For**: XYFlow Visual Workflow Automation Engine.
- **Structure**: Full-screen interactive canvas with drag-and-drop node sidebar, mini-map, zoom controls, and floating step inspector panel.

---

## 5. UI Component Primitives & Composite Standards

### 5.1 Button Variants (`Button.jsx`)
- **`primary`**: `bg-brand-600 text-white hover:bg-brand-700 shadow-soft` — Used for the main action on a page.
- **`secondary`**: `bg-neutral-100 text-neutral-800 hover:bg-neutral-200` — Used for secondary actions.
- **`ghost`**: `bg-transparent text-neutral-700 hover:bg-neutral-100` — Used for inline toolbars and list actions.
- **`danger`**: `bg-coral-500 text-white hover:bg-coral-600` — Used for irreversible delete actions.
- **`outline`**: `border-neutral-300 text-neutral-700 hover:bg-neutral-50` — Used for filter triggers and downloads.

### 5.2 Status Badges & Semantic Pills (`Badge.jsx`)

| Semantic Status | Light Mode Classes | Dark Mode Classes | Use Cases |
| :--- | :--- | :--- | :--- |
| **Brand / Active** | `bg-brand-50 text-brand-700 border-brand-200` | `dark:bg-brand-900/30 dark:text-brand-300 dark:border-brand-700` | Active channels, Pro plan, Verified |
| **Success / Synced** | `bg-emerald-50 text-emerald-700 border-emerald-200` | `dark:bg-emerald-900/30 dark:text-emerald-300 dark:border-emerald-700` | Delivered, Resolved, Connected, Paid |
| **Warning / Pending** | `bg-amber-50 text-amber-700 border-amber-200` | `dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700` | Snoozed, Quota Warning, Pending OAuth |
| **Danger / Failed** | `bg-coral-50 text-coral-800 border-coral-200` | `dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800` | Failed delivery, Expired token, Canceled |
| **Neutral / Default** | `bg-neutral-100 text-neutral-700 border-neutral-200` | `dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700` | Draft, Archived, Unassigned |

### 5.3 Modal Dialogs (`Modal.jsx`) & Slide-Out Drawers (`Drawer.jsx`)
- **Modals**: Powered by `@headlessui/react` `Dialog` with backdrop blur (`backdrop-blur-[2px] bg-neutral-900/40`), smooth scale transitions, and trapped focus.
- **Drawers**: Right-side sliding panel (`w-screen max-w-md`) for quick contact editing, message template previews, and filter trays.
- **Dismissal**: `Escape` key and backdrop clicks dismiss the dialog gracefully.

---

## 6. Table & Pagination Standards

1. **Table Container**: Encapsulated in `Card` surface with `overflow-x-auto`.
2. **Column Headers**: Styled in `text-xs font-semibold uppercase tracking-wider text-neutral-500` with subtle border-b.
3. **Empty States (`EmptyState.jsx`)**: When no data matches the filter, display an illustrated empty state with an actionable primary button (e.g. "Create your first segment").
4. **Pagination Bar (`Pagination.jsx`)**:
   - Numbered buttons with active state in Brand Lilac (`bg-brand-600 text-white`).
   - Explicit `Previous` and `Next` text labels.
   - Rows-per-page selector with clear range indicator (e.g. `Showing 1–25 of 142 contacts`).

---

## 7. Dark Mode & Accessibility (a11y)

1. **Dark Mode Integration**: Full class-based dark mode (`dark:bg-neutral-900`, `dark:border-neutral-800`, `dark:text-neutral-100`).
2. **Focus Visibility**: Visible focus rings on all interactive elements (`focus:ring-2 focus:ring-brand-500/30 focus:ring-offset-1`).
3. **Screen Reader Support**: All icon-only buttons require an `aria-label` attribute (e.g. `aria-label={t('common.close')}`).
4. **Internationalization (i18n)**: All UI strings must be resolved via `useTranslation()` (`t('nav.inbox')`, `t('common.save')`) to support multi-language localizations.
