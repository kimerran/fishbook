# BRAND.md — Fishbook Visual & Brand Guidelines

> The Zen Sanctuary, powered by code. Fishbook's design language is a quiet, light-saturated glassmorphism over sage-and-water tones — a digital aquarium that feels like still water under morning fog.

This document is the single source of truth for visual decisions. When `SPEC.md` describes *what* a page does and `AGENT.md` describes *how* to build it, `BRAND.md` decides *how it looks and feels*.

---

## 1. Brand Essence

| | |
|---|---|
| **Name** | Fishbook |
| **Tagline** | *Your Zen Sanctuary, Powered by Code* |
| **Mood** | Serene · contemplative · luminous · weightless |
| **Personality** | Refined, quietly confident, technically literate but never showy |
| **Anti-mood** | Cluttered, saturated, gamified, neon, dark-by-default, "playful aquarium" cliché |

### Voice & Tone
- **Lexicon to lean into:** *sanctuary, atmosphere, habitat, curate, envision, tranquility, flow, sanctuary, canvas, sensory*
- **Lexicon to avoid:** *awesome, epic, level up, dive in, fishy puns, "splash"-themed copy*
- **Sentence shape:** short, declarative, with one evocative noun per sentence. Microcopy stays under ~12 words.
- **Examples**
  - ✅ *"Curate the sensory backdrop of your digital sanctuary."*
  - ✅ *"Describe the ideal environment for your aquatic life."*
  - ❌ *"Splash into the action with awesome new fish!"*
  - ❌ *"You've leveled up your tank — woohoo!"*

---

## 2. Color System

The palette is a Material 3 (M3)-style tonal system seeded from **sage green** (primary) and **muted aquatic blue-grey** (secondary), with a neutral surface family that reads as pale, slightly cool off-white.

### 2.1 Primary — Sage

The signature color. Used for brand wordmark, active nav state, primary buttons, key icons.

| Token | Hex | Where |
|---|---|---|
| `primary` | `#52634e` | Wordmark, active nav text, primary icon stroke |
| `on-primary` | `#ffffff` | Text on `primary` fill |
| `primary-container` | `#a8bba2` | Sage-tinted surfaces, active nav backgrounds |
| `on-primary-container` | `#3b4b38` | Text on `primary-container` |
| `primary-fixed` | `#d5e8cd` | Lighter tonal step |
| `primary-fixed-dim` | `#b9ccb2` | Mid tonal step (used for dark-mode primary) |
| `on-primary-fixed` | `#101f0f` | Highest-contrast text on sage |
| `on-primary-fixed-variant` | `#3a4b38` | Secondary text on sage |
| `inverse-primary` | `#b9ccb2` | Primary on inverse surface |
| `surface-tint` | `#52634e` | Elevation tint overlay |

### 2.2 Secondary — Aquatic Blue-Grey

Cool, water-like. Used for accent tags, supporting icons, info chips.

| Token | Hex | Where |
|---|---|---|
| `secondary` | `#50616a` | Secondary icons, accent text |
| `on-secondary` | `#ffffff` | |
| `secondary-container` | `#d3e5f0` | Tag/pill backgrounds (`Peaceful`, `Adult`, `Schooling` chips) |
| `on-secondary-container` | `#566770` | Tag/pill text |
| `secondary-fixed` | `#d3e5f0` | |
| `secondary-fixed-dim` | `#b7c9d3` | |
| `on-secondary-fixed` | `#0c1e25` | |
| `on-secondary-fixed-variant` | `#384951` | |

### 2.3 Tertiary — Neutral Stone

Quiet supporting color, used sparingly.

| Token | Hex |
|---|---|
| `tertiary` | `#59605d` |
| `on-tertiary` | `#ffffff` |
| `tertiary-container` | `#b1b7b3` |
| `on-tertiary-container` | `#424846` |
| `tertiary-fixed` | `#dee4e0` |
| `tertiary-fixed-dim` | `#c2c8c4` |
| `on-tertiary-fixed` | `#171d1b` |
| `on-tertiary-fixed-variant` | `#424845` |

### 2.4 Surface (Neutral)

The whole UI sits on this family. Backgrounds are almost-white with the faintest cool tint.

| Token | Hex | Use |
|---|---|---|
| `background` | `#f8fafb` | App background (often gradient-radial from this) |
| `surface` | `#f8fafb` | Default surface |
| `surface-bright` | `#f8fafb` | Brightest surface |
| `surface-dim` | `#d8dadb` | Dimmed surface (locked/disabled cards) |
| `surface-container-lowest` | `#ffffff` | Pure white (rarely used directly; usually behind glass) |
| `surface-container-low` | `#f2f4f5` | Subtle elevation |
| `surface-container` | `#eceeef` | Standard container |
| `surface-container-high` | `#e6e8e9` | Elevated container |
| `surface-container-highest` | `#e1e3e4` | Top elevation |
| `surface-variant` | `#e1e3e4` | Variant fills |
| `on-background` | `#191c1d` | Body text |
| `on-surface` | `#191c1d` | Body text |
| `on-surface-variant` | `#444841` | Secondary text, captions |
| `outline` | `#747871` | Strong borders |
| `outline-variant` | `#c4c8bf` | Hairline borders, dividers |
| `inverse-surface` | `#2e3132` | Inverted (toast, tooltip) |
| `inverse-on-surface` | `#eff1f2` | Text on inverted |

### 2.5 Status

| Token | Hex | Use |
|---|---|---|
| `error` | `#ba1a1a` | Errors, destructive actions |
| `on-error` | `#ffffff` | |
| `error-container` | `#ffdad6` | Error banners |
| `on-error-container` | `#93000a` | Error banner text |

> **Success / warning** are not in the base palette. When needed: success uses `primary-container` + `on-primary-container`. Warnings should be added as a new token — do **not** invent ad-hoc orange/yellow inline.

### 2.6 Accessibility

- **Body text** on `surface` / `background`: `on-surface` (`#191c1d`). Contrast ≈ 15.4:1 — well above WCAG AAA.
- **Secondary text** on `surface`: `on-surface-variant` (`#444841`). Contrast ≈ 8.9:1.
- **Caption / label text** must never drop below `on-surface-variant` on light backgrounds.
- **Text over glass panels** (which sit on photographic backgrounds): always pair with the panel's `bg-white/40+` and `backdrop-blur` — never put body text directly over an unblurred image.
- **Text over photographic preset cards** (the dark gradient overlay pattern): white text on `bg-gradient-to-t from-black/60 to-transparent`. Never use white text without this overlay.
- **Focus rings:** `ring-2 ring-primary ring-offset-4 ring-offset-surface-container-lowest`. Every interactive element must have a visible focus state.

---

## 3. Typography

**One typeface for everything: Inter.** Weights `300`, `400`, `500`, `700` (sparingly).

Load from Google Fonts: `Inter:wght@300;400;500;700`.

### 3.1 Type Scale

| Token | Size | Line Height | Letter Spacing | Weight | Use |
|---|---|---|---|---|---|
| `headline-lg` | 32px | 1.2 | 0.02em | **300** (Light) | Page-level h1 on desktop. The defining headline of the brand. |
| `headline-lg-mobile` | 24px | 1.2 | 0.02em | **300** | h1 on mobile |
| `headline-md` | 20px | 1.4 | 0.01em | 400 | Section h2, card titles, wordmark |
| `body-lg` | 16px | 1.6 | 0.01em | **300** (Light) | Lead paragraphs, intros, descriptions |
| `body-md` | 14px | 1.6 | 0.01em | 400 | Default body |
| `label-caps` | 12px | 1.0 | 0.10em | 500 | All UI labels — buttons, nav, chips, tags. **ALWAYS UPPERCASE.** |

> The `300` weight on `headline-lg` and `body-lg` is deliberate and load-bearing for the brand — it produces the "exhaled" feeling that distinguishes Fishbook from typical SaaS. **Never bump these to 400+ to "improve readability"** — improve contrast or size instead.

### 3.2 Rules

- **One headline per view.** Pages have a single `headline-lg`. Don't nest h1s.
- **`label-caps` is uppercase, period.** With `tracking: 0.1em` it reads as elegant; lowercase breaks the rhythm.
- **Italics are reserved for binomial scientific names** (e.g. *Betta splendens*, *Paracheirodon innesi*) on the inventory cards. Don't italicize for emphasis — use weight `500` instead.
- **Numerals are tabular** for stat displays (water temp, pH, fish count). Set `font-variant-numeric: tabular-nums` on stat values.
- **No `text-transform: uppercase` on anything other than `label-caps`.**

### 3.3 Hierarchy Cheat Sheet

```
H1: headline-lg-mobile (mobile) / headline-lg (desktop) — page title
H2: headline-md — section / card
Lead: body-lg — under-h1 paragraph
Body: body-md
Caption / metadata: label-caps in on-surface-variant
Button / nav / tag text: label-caps
```

---

## 4. Layout & Spacing

### 4.1 Tokens

| Token | Value | Use |
|---|---|---|
| `unit` | 8px | Base rhythm unit |
| `gutter` | 24px | Default grid gap |
| `margin-mobile` | 16px | Edge padding on mobile |
| `margin-desktop` | 48px | Edge padding on desktop |
| `container-max-width` | 1200px | Max content width |

Everything spacing-related is a multiple of `unit` (8px). When Tailwind's default scale doesn't line up, use the token: `p-unit`, `gap-gutter`, `px-margin-mobile md:px-margin-desktop`.

### 4.2 Breakpoints

Standard Tailwind:
- `sm`: 640px
- `md`: 768px — **the primary layout flip** (mobile bar → side nav, single column → multi-column)
- `lg`: 1024px — bento-grid spans expand
- `xl`: 1280px

### 4.3 Page Structure (Authed)

```
[ Mobile ]                  [ Desktop ]
┌───────────────────┐       ┌─────┬─────────────────────┐
│ Top bar (glass)   │       │ Side│ (content)           │
├───────────────────┤       │ nav │                     │
│                   │       │ (gl │                     │
│ Content           │       │ ass)│                     │
│                   │       │     │                     │
│                   │       │ 64  │  max-w-1200, mx-auto│
├───────────────────┤       │ rem │                     │
│ Bottom nav (glass)│       │ wide│                     │
└───────────────────┘       └─────┴─────────────────────┘
```

- **Side nav width:** `w-64` (256px), fixed left, `pt-24` to clear top.
- **Side nav internal padding:** `p-6 pt-24`.
- **Mobile top bar height:** `h-16` (64px). Mobile bottom nav: `h-16`.
- **Page content padding-top:** `pt-20 md:pt-12` (or `pt-24` for pages with extra breathing room).

### 4.4 Full-Viewport Aquarium Pages

The `/fish` and `/{owner}/{repo}` pages break the standard layout — they use the full viewport as a canvas. UI elements become **floating glass islands**:

- **Top-right:** floating stats panel (temperature, pH, fish count style).
- **Bottom-center:** floating action dock (Feed · Add Fish · Toggle Stats).
- **Bottom-left:** selected-fish mini-profile.
- Side nav remains (offset content with `md:pl-64` on the centered dock so it stays visually centered relative to the canvas area, not the page).

---

## 5. The Glass System (Signature Visual)

Fishbook **is** glassmorphism. Every panel, nav, and card uses the same base recipe. Consistency here is what makes the product feel like one piece of glass.

### 5.1 Base recipe — `.glass-panel`

```css
.glass-panel {
  background-color: rgba(255, 255, 255, 0.40);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.20);
  box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.04);
}
```

### 5.2 Tier scale

Pick the tier that matches the element's prominence. **Do not invent new opacities.**

| Tier | Background | Blur | Border | Shadow | Use |
|---|---|---|---|---|---|
| `glass-xs` | `bg-white/10` | `backdrop-blur-sm` | — | — | Hover wash on nav items |
| `glass-sm` | `bg-white/20` | `backdrop-blur-md` | `border-white/20` | — | Secondary buttons, inline chips |
| **`glass-md`** | `bg-white/40` | `backdrop-blur-md` | `border-white/20` | `shadow-[0_8px_32px_rgba(0,0,0,0.04)]` | **Default panels, nav bars** |
| `glass-lg` | `bg-white/50` | `backdrop-blur-xl` | `border-white/20` | `shadow-[0_8px_32px_rgba(0,0,0,0.06)]` | Hero panel, large bento cards |
| `glass-overlay` | `bg-gradient-to-t from-black/60 to-transparent` | `backdrop-blur-[2px]` | — | — | Text overlay on photographic cards |

### 5.3 Color-tinted glass

For active or primary affordances, tint the glass with sage:
- Active nav item: `bg-primary/30 text-on-primary-container`
- Primary button (in dock): `bg-primary/30 backdrop-blur-md border border-white/40`
- Hover wash on primary glass: `hover:bg-primary/40`

### 5.4 Rules

1. **Glass needs something underneath.** Every glass panel must sit on top of either a photographic background, a soft gradient, or a decorative blob — never a flat color. If a page would put glass on flat color, switch the page background to `bg-gradient-radial from-#eef3f6 to-transparent` or similar.
2. **Decorative background blobs** are how non-aquarium pages keep glass interesting. Pattern (from the Environment Settings AI card):
   ```html
   <div class="absolute -top-20 -right-20 w-64 h-64 bg-primary-container/20 rounded-full blur-3xl mix-blend-multiply"></div>
   <div class="absolute bottom-0 right-10 w-48 h-48 bg-secondary-container/30 rounded-full blur-2xl mix-blend-multiply"></div>
   ```
   Two blobs, two colors (one sage, one blue-grey), `mix-blend-multiply`, large blur. Positioned outside the panel's overflow-hidden bounds so they bleed into adjacent space.
3. **No solid backgrounds on top-level cards.** If you need solid, the design is wrong — re-tier or change context.
4. **Performance:** `backdrop-filter: blur(...)` is expensive. Limit to ≤ ~8 simultaneous glass panels per viewport. Avoid stacking blurs (glass on glass on glass).
5. **`will-change: backdrop-filter`** is not needed; the browser handles it.

---

## 6. Border Radius

| Token | Value | Use |
|---|---|---|
| `DEFAULT` (`rounded`) | 4px | Hairlines, dividers (rarely needed) |
| `rounded-lg` | 8px | Small chips, input inner radii |
| `rounded-xl` | 12px | **Default panel/card radius** — use this unless told otherwise |
| `rounded-full` | 9999px | Pills, avatars, action dock, icon buttons |

> The brand reads as "soft-cornered, not pillowy." `rounded-xl` (12px) is the workhorse. Avoid `rounded-2xl` and beyond — they feel cartoonish and break the elegance.

---

## 7. Iconography

**Material Symbols Outlined.** Single font. Variation axes used:
- `FILL`: `0` by default; `1` for active/selected state (e.g. active side-nav item).
- `wght`: `400` default.
- `opsz`: `24` default.

### 7.1 Sizing

| Context | Size |
|---|---|
| Inline with `body-md` | 18–20px |
| Inline with `body-lg` / `headline-md` | 24px |
| Hero/feature icon | 28–32px |
| In `rounded-full` action button | 18–24px |

### 7.2 Glyph dictionary (used across the app)

| Where | Glyph |
|---|---|
| Aquarium (nav) | `water_drop` |
| Inventory (nav) | `set_meal` |
| Environment (nav) | `waves` |
| GitHub (nav) | `terminal` |
| Account / profile | `account_circle` |
| Search | `search` |
| Filter | `filter_list` |
| Feed (action) | `restaurant` |
| Add | `add` |
| Toggle / tune | `tune` |
| AI generate | `auto_awesome` |
| Upload | `upload_file` |
| Preset gallery | `gallery_thumbnail` |
| Confirm / active preset | `check_circle` (filled) |
| Lock / undiscovered | `lock` / `lock_open` |
| Info | `info` |
| Move forward | `arrow_forward` |

When adding a new glyph, pick the most generic, most-recognized option in the Material set. Never use two different glyphs for the same concept across the app.

### 7.3 Color

Icons inherit `currentColor`. Default to:
- `text-primary` for the wordmark area and active states
- `text-on-surface-variant` for secondary icons in nav
- `text-on-surface` for body-line icons
- `text-secondary` for accent/info icons

---

## 8. Elevation & Shadow

Glass already implies elevation; explicit shadows are subtle.

| Token | Value | Use |
|---|---|---|
| `shadow-sm` | `0 1px 2px rgba(0,0,0,0.04)` | Small elements (avatar, tag) |
| `shadow-glass` | `0 8px 32px rgba(0,0,0,0.04)` | **Default for glass panels.** Note: use `0.06` for `glass-lg`. |
| `shadow-nav` | `0 -8px 32px rgba(0,0,0,0.04)` | Mobile bottom nav (shadow points up) |

No `shadow-lg`/`shadow-xl`/`shadow-2xl` in this brand. Depth comes from glass tier, not shadow ramp.

---

## 9. Motion & Interaction

### 9.1 Timing

| Speed | Use |
|---|---|
| `duration-200` | Snappy state changes (active → inactive) |
| **`duration-300`** | **Default** for color / background / opacity / transform |
| `duration-700` | Slow, elegant transforms (image hover scale, hero panel) |

Default easing: Tailwind's `ease-out` or `ease-in-out`. No bounce, no spring, no overshoot — this is a Zen brand.

### 9.2 Hover

- **Glass panel** → bump opacity up one tier: `hover:bg-white/50` (from `/40`), `hover:bg-white/60` (from `/50`).
- **Nav item** → `hover:bg-white/20`.
- **Image inside card** → `group-hover:scale-105 duration-700`.
- **Primary glass button** → `hover:bg-primary/40` (from `/30`).
- **Card containing fish/preset** → outer ring on hover: `hover:ring-2 hover:ring-outline-variant hover:ring-offset-2 hover:ring-offset-surface-container-lowest`.

### 9.3 Active / press

Every interactive element gets `active:scale-95 transition-transform`. This is the brand's universal "press" feedback. Apply to:
- All buttons (including icon-only)
- Nav links
- Cards with click behavior

Exception: large panels (hero, bento sections) use `hover:scale-[1.01]` instead — a quieter, "leaning into the user" feel — and skip `active:scale-95`.

### 9.4 Shimmer (used on hero CTA)

```css
@keyframes shimmer { 100% { transform: translateX(200%); } }
```
Triggered on hover with a `bg-gradient-to-r from-transparent via-white/20 to-transparent` overlay inside the button. Use sparingly — **only on the single hero CTA per page**.

### 9.5 Loading / pending

- Skeletons use `bg-surface-container-low` with a subtle `animate-pulse`.
- No spinners with motion-blur, no rainbow loaders. If a long operation (AI generation, GitHub fetch) is running, show a small `auto_awesome` glyph fading in/out at `duration-700` on a sage tint.

### 9.6 Reduced motion

Honor `prefers-reduced-motion: reduce`: disable `scale` transforms, shimmer, and `animate-pulse`. Opacity transitions remain.

---

## 10. Component Patterns

This section codifies the recurring patterns from the mocks. Reuse these — don't reinvent.

### 10.1 Pill / Tag / Chip

```html
<span class="font-label-caps text-label-caps text-on-secondary-container
             bg-secondary-container/30 px-3 py-1 rounded-full border border-white/20">
  Peaceful
</span>
```

Variants:
- **Default (info):** secondary-container background.
- **Active (positive):** `text-primary border-primary/20 bg-primary/5`.
- **Neutral (undiscovered):** `text-on-surface-variant border-outline-variant/20 bg-surface-container/30`.

### 10.2 Primary Button (glass)

Pill-shaped, sage-tinted glass.

```html
<button class="flex items-center gap-2 px-6 py-3 rounded-full
               bg-primary/30 backdrop-blur-md border border-white/40
               text-on-primary-container font-label-caps text-label-caps
               hover:bg-primary/40 active:scale-95
               transition-all duration-300">
  <span class="material-symbols-outlined text-[18px]">restaurant</span>
  Feed
</button>
```

### 10.3 Secondary Button (glass)

Same shape, neutral glass.

```html
<button class="flex items-center gap-2 px-6 py-3 rounded-full
               bg-white/20 border border-white/20
               text-on-surface font-label-caps text-label-caps
               hover:bg-white/40 active:scale-95
               transition-colors duration-300">
  <span class="material-symbols-outlined text-[18px]">add</span>
  Add Fish
</button>
```

### 10.4 Icon Button

```html
<button class="p-2 rounded-full text-primary
               hover:bg-white/10 active:scale-95
               transition-all duration-300">
  <span class="material-symbols-outlined">search</span>
</button>
```

### 10.5 Input

Underline-style on glass. No boxy outlines.

```html
<input
  class="w-full bg-white/20 border-0 border-b border-outline-variant
         py-3 px-4 rounded-t-lg
         text-on-surface placeholder:text-on-surface-variant/50
         font-body-md text-body-md outline-none
         focus:bg-white/40 focus:border-primary focus:ring-0
         transition-all duration-300"
  type="text" placeholder="…" />
```

Search variant: leading icon at `left-0 bottom-2`, transparent background, hairline bottom-border only.

### 10.6 Dropzone

Dashed border, large icon disc, central CTA pill.

```html
<div class="glass-md rounded-xl p-8 border-2 border-dashed border-outline-variant
            hover:border-primary/50 hover:bg-white/50
            transition-all duration-300 cursor-pointer
            flex flex-col items-center text-center min-h-[240px]">
  <div class="w-16 h-16 rounded-full bg-secondary-container/50
              flex items-center justify-center mb-4 text-on-secondary-container">
    <span class="material-symbols-outlined text-[32px]">upload_file</span>
  </div>
  <h3 class="font-headline-md text-headline-md">Custom Canvas</h3>
  <p class="font-body-md text-on-surface-variant mb-4">Drag and drop, or click to browse.</p>
  <div class="font-label-caps text-label-caps text-primary
              bg-primary/10 px-4 py-2 rounded-lg border border-primary/20">
    Select File
  </div>
</div>
```

### 10.7 Modal / Dialog

For the **Fishbook Manager** modal (from `SPEC.md`):
- Backdrop: `bg-black/30 backdrop-blur-sm` over full viewport.
- Panel: `glass-lg` (`bg-white/50`, `backdrop-blur-xl`), `rounded-xl`, max width `max-w-3xl`, `p-8`.
- Title: `headline-md`. Close button: `account_circle`-style icon button top-right.
- Internal scroll: panel `max-h-[80vh] overflow-y-auto`.
- Focus trap required (`role="dialog" aria-modal="true"`).

### 10.8 Floating Stats Panel

The minimal data-readout pattern. Three stats max, with vertical hairline dividers.

```html
<div class="glass-md rounded-xl p-4 flex gap-6 items-center">
  <div class="flex flex-col items-end">
    <span class="font-label-caps text-on-surface-variant opacity-80">Water Temp</span>
    <span class="font-headline-md text-primary tabular-nums">24.5°C</span>
  </div>
  <div class="w-px h-8 bg-white/30"></div>
  <!-- ...next stat... -->
</div>
```

### 10.9 Photographic Card (preset / inventory)

Image + dark-gradient text overlay.

```html
<div class="relative group rounded-xl overflow-hidden cursor-pointer
            hover:ring-2 hover:ring-outline-variant hover:ring-offset-2 transition-all">
  <div class="aspect-video">
    <img class="object-cover w-full h-full
                transition-transform duration-700 group-hover:scale-105"
         src="…" alt="…" />
    <div class="absolute inset-x-0 bottom-0 p-4
                bg-gradient-to-t from-black/60 to-transparent backdrop-blur-[2px]">
      <h3 class="font-body-lg text-white font-medium">Misty Lake</h3>
    </div>
  </div>
</div>
```

Active state: outer `ring-2 ring-primary ring-offset-4 ring-offset-surface-container-lowest` plus a filled `check_circle` glyph in the overlay corner.

### 10.10 Inventory List Row

Glass card, horizontal layout, image + meta + stats + action. Locked variant uses `opacity-70`, a `lock` glyph instead of an image, and outline-only text styling on the title.

(See the Inventory mock for the exact structure — re-use that markup verbatim when implementing `FishManagerModal` rows.)

---

## 11. Imagery & Photography

Fishbook's imagery is the second half of the brand. Glass alone isn't the brand — glass *over the right photograph* is.

### 11.1 Direction

- **Aquarium backgrounds:** macro / mid-shot underwater scenes — clear water, soft caustics, sandy or pebbled bottoms. Subjects (fish) optional but never the focal point.
- **Habitat presets:** wide, calm, atmospheric landscapes — misty lakes at dawn, zen ponds, sun-dappled reefs. Light-mode-friendly: high key, pale palette, no harsh contrast.
- **Fish portraits (inventory):** single subject, macro / mid-shot, blurred background, naturalistic color. No vector illustrations of fish in the main UI (sprite SVGs are the exception, used only inside the animated canvas).
- **Avatars:** soft portraits, neutral expression, muted background. Generated/AI-illustrated is fine if it matches the palette.

### 11.2 Color & light

- High key, soft light, **never high-contrast**.
- Palette must overlap with the brand: cool blues, sage greens, pale whites, occasional warm sand. **Reject** images with saturated reds, yellows, neons, or sunset oranges.
- Slight mist, fog, or atmospheric haze is encouraged — it makes glass panels feel inevitable rather than imposed.

### 11.3 Treatment

- All photographic backgrounds for canvas pages get an ambient overlay: `bg-white/20 backdrop-blur-[4px]` on top, to ensure UI legibility.
- Card images never have filters applied — they stand on their own.
- AI-generated images (Fal AI) should be prompted toward the same direction. **Suggested prompt scaffolding** to surface as quick-pick chips in the Background Generator:
  - *Surrealism · Hyper-realistic · Monochrome · Bioluminescent · Misty · Dappled light · Minimal*
  - Always append (server-side, invisibly): *"…minimalist, light-mode palette, soft pastel sage and aqua tones, ambient lighting, suitable as a calm UI backdrop."*

### 11.4 Alt text

Every image needs an `alt` (or `data-alt` if decorative). For decorative backgrounds, `alt=""` and `role="presentation"` is acceptable. For meaningful images (fish portraits, preset thumbnails), describe the subject and palette in one sentence — both for accessibility and as documentation for what kind of imagery belongs here.

---

## 12. Light Mode First (and Dark Mode Notes)

The brand is **light-mode-first.** Every page in the mocks is light. Dark mode is a future consideration, not a v1 requirement.

If/when dark mode is added:
- Use M3 dark-mode equivalents already implied by `inverse-*` and `*-fixed-dim` tokens (`primary-fixed-dim` `#b9ccb2` becomes the dark primary).
- Glass inverts to `bg-black/40 backdrop-blur-md border-white/10`.
- Surface dark base: `inverse-surface` (`#2e3132`).
- **Do not** ship a half-finished dark mode. The brand depends on light optics — partial implementation reads as broken, not "dark mode."

The HTML scaffolds already include `dark:` Tailwind variants for nav glass (`dark:bg-black/40`). Keep these in place; they're cheap to maintain and ready for the eventual dark-mode pass.

---

## 13. Do / Don't

### ✅ Do
- Use `glass-md` as the default panel; reach for tiers only when prominence demands it.
- Pair every glass panel with something interesting underneath (photo, gradient, blob).
- Keep `headline-lg` and `body-lg` at weight 300. The "exhale" is the brand.
- Uppercase + tracked `label-caps` for every UI label, every time.
- Use Material Symbols only. One glyph per concept across the entire product.
- Honor `prefers-reduced-motion`.
- Add `active:scale-95 transition-transform` to every clickable thing.
- Let the canvas pages be canvases — floating glass islands, not framed dashboards.

### 🚫 Don't
- Don't introduce a new font, even for "fun" copy. Inter or nothing.
- Don't bump body weight to 400+ to "fix readability." Adjust size or contrast.
- Don't use shadows beyond `shadow-sm` / `shadow-glass`. Depth comes from glass, not drop shadows.
- Don't stack glass on glass on glass — pick one layer of glass per panel.
- Don't introduce saturated colors. The palette is muted on purpose.
- Don't use emoji or branded gradients (no rainbow, no purple-to-pink). The Zen mood breaks instantly.
- Don't write playful "splash" / "dive in" copy.
- Don't put white text directly on an unblurred photo. Always overlay a dark gradient or move to glass.
- Don't use `rounded-2xl` or larger. Soft, not pillowy.
- Don't add new opacities (`/35`, `/55`, etc.). Stick to the documented tiers: `/10 /20 /30 /40 /50 /60`.

---

## 14. Implementation Notes

- All design tokens above are already wired into the Tailwind config used by the mocks. Copy that config block verbatim into `frontend/tailwind.config.ts` for v1.
- Re-export `.glass-panel` (and tier variants `glass-sm`, `glass-lg`, `glass-overlay`) as a `@layer components` rule in `frontend/src/styles/globals.css` so they're reusable as utility-like classes.
- Keep this file (`BRAND.md`) under version control alongside `SPEC.md` and `AGENT.md`. Any PR that introduces a new color, weight, radius, opacity tier, or glyph for an existing concept must also update this document and get a design-owner sign-off.
- When in doubt: the brand favors *less*. One headline. One CTA. One color of glass. One photo at a time. Subtract until it breathes.

---

> *Fishbook is what a tide pool would feel like if it ran on Postgres.* — keep that picture in your head before every commit.
