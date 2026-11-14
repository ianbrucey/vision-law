# Spec 013 — Complete Theme Token Table (all 25 themes)

**This file is the implementation authority for claim C-02.** The token values below were extracted verbatim on 2026-11-14 from the approved decision mockup (Muse artifact `vision-systems-ui-mockups`, build `246a6cae-dd92-4305-933a-e6989daa2d49`, source `index.html`, THEME TOKENS section). No value has been invented, normalized, or approximated: rgba shorthand (`.045`, `.5`, `.4`) and hex values are exactly as they appear in the source. Where the source defines no rule (for example, a per-theme override), this file says so rather than filling the gap.

## How to read this file

- Each theme section gives the display name and the exact `data-theme` key from the source. Keys are NOT always the display name in slug form: Ocean Deep is `ocean`, Earth Grove is `grove`, Calm Deep Ocean is `calm-ocean`, Sage Mist is `calm-sage`.
- Every theme block defines exactly the same 25 custom properties, in source order: `--bg`, `--bg-deep`, `--wash`, `--glass`, `--glass-2`, `--glass-3`, `--line`, `--line-strong`, `--txt`, `--mut`, `--mut-2`, `--acc`, `--acc-2`, `--acc-soft`, `--acc-line`, `--warn`, `--warn-soft`, `--warn-line`, `--ok`, `--ok-soft`, `--bad`, `--bad-soft`, `--cyan`, `--cyan-soft`, `--shadow`, plus the `color-scheme` declaration.
- No theme block defines a line-height custom property. Reading line-height exists only as family selector rules (see Shared rules).
- Per-theme overrides for `.btn.primary` and `.statusbar` text color are listed under each theme. "None in source" means the decision mockup defines no such rule for that theme; the generic `.btn.primary{color:#fff}` and default status bar color apply there.
- **Ocean Deep (`ocean`) is the default theme** (the source page loads with `data-theme="ocean"`).

## Shared `:root` tokens (not per-theme, verbatim from source)

```css
:root{
  --radius:14px; --sidebar-w:272px; --agent-w:352px; --bottom-h:168px;
  --ease:cubic-bezier(.32,.72,0,1); --dur:.45s;
  --font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
}
```

## Shared family rules (verbatim from source)

Calm reading comfort, applies to every `calm-` theme AND every `usa-` / `earth-` theme (the source applies the same rules under each prefix; the usa-/earth- set is commented "Same reading comfort for Americana & Earth"):

```css
html[data-theme^="calm-"] .doc-page p{line-height:1.72; letter-spacing:.008em}
html[data-theme^="calm-"] .doc-page{padding:24px 26px}
html[data-theme^="calm-"] .msg, html[data-theme^="calm-"] .checkpoint p{line-height:1.62}

html[data-theme^="usa-"] .doc-page p, html[data-theme^="earth-"] .doc-page p{line-height:1.72; letter-spacing:.008em}
html[data-theme^="usa-"] .doc-page, html[data-theme^="earth-"] .doc-page{padding:24px 26px}
html[data-theme^="usa-"] .msg, html[data-theme^="usa-"] .checkpoint p, html[data-theme^="earth-"] .msg, html[data-theme^="earth-"] .checkpoint p{line-height:1.62}
```

Note: Ocean Deep (`ocean`) does NOT receive the calm reading rules; its key does not carry the `calm-` prefix.

Matte family note (source comment, verbatim): "Matte family: solid flat surfaces — wash equals bg so there is no gradient glow, glass tokens are opaque, shadows stay shallow." There is NO backdrop-filter / no-blur override rule for matte themes anywhere in the source; flatness is implemented entirely in the token values (`--wash` equals `--bg`, opaque `--glass` hex values, shallower `--shadow`). Do not add a no-blur rule and call it a transcription.

## Structural tokens (013-D13)

The decision mockup never defined values for three tokens the contract requires. The values below are **new design decisions**, recorded in `decisions.md` as 013-D13 (Ian, 2026-11-14). They are NOT transcribed from the decision mockup and must not be cited as source values. Anchors: `--radius-sm` is the inner radius paired with the source `--radius: 14px`; the duration scale is anchored on the source's single `--dur: .45s`, adopted as `--dur-2`; the `--reading-lh` override value 1.72 matches the source's calm/heritage family selector rules above.

```css
:root{
  --radius-sm:8px;
  --dur-1:.18s; /* micro: hover, chip, tab */
  --dur-2:.45s; /* panels, sheets, drawers */
  --dur-3:.65s; /* entrances, theme cross-fades */
  --reading-lh:1.6; /* base document/body line-height, all themes */
}
/* calm- and usa-/earth- prefixed themes override the reading line-height: */
html[data-theme^="calm-"], html[data-theme^="usa-"], html[data-theme^="earth-"]{
  --reading-lh:1.72;
}
```

---

## Original themes

### Aurora — `aurora`

```css
html[data-theme="aurora"]{
  --bg:#070b16; --bg-deep:#04060d; --wash:#16224a;
  --glass:rgba(255,255,255,.045); --glass-2:rgba(255,255,255,.075); --glass-3:rgba(255,255,255,.03);
  --line:rgba(255,255,255,.09); --line-strong:rgba(255,255,255,.14);
  --txt:#e8ecf5; --mut:#8b94ab; --mut-2:#6d768c;
  --acc:#7c6cff; --acc-2:#4f7cff; --acc-soft:rgba(124,108,255,.14); --acc-line:rgba(124,108,255,.45);
  --warn:#e8a13a; --warn-soft:rgba(232,161,58,.10); --warn-line:rgba(232,161,58,.55);
  --ok:#34c77b; --ok-soft:rgba(52,199,123,.13);
  --bad:#e5534b; --bad-soft:rgba(229,83,75,.13);
  --cyan:#3fc1c9; --cyan-soft:rgba(63,193,201,.12);
  --shadow:0 18px 50px rgba(0,0,0,.45); color-scheme:dark;
}
```

Overrides: none in source.

### Earth Grove — `grove` (picker label "Earth · Grove")

```css
html[data-theme="grove"]{
  --bg:#0c130e; --bg-deep:#070b08; --wash:#223a24;
  --glass:rgba(238,245,230,.05); --glass-2:rgba(238,245,230,.08); --glass-3:rgba(238,245,230,.03);
  --line:rgba(220,235,205,.11); --line-strong:rgba(220,235,205,.18);
  --txt:#edf2e4; --mut:#a3ad97; --mut-2:#7f8a74;
  --acc:#8fb996; --acc-2:#d9a441; --acc-soft:rgba(143,185,150,.14); --acc-line:rgba(143,185,150,.48);
  --warn:#d9a441; --warn-soft:rgba(217,164,65,.11); --warn-line:rgba(217,164,65,.55);
  --ok:#7bc47f; --ok-soft:rgba(123,196,127,.13);
  --bad:#e07a5f; --bad-soft:rgba(224,122,95,.13);
  --cyan:#81b29a; --cyan-soft:rgba(129,178,154,.12);
  --shadow:0 18px 50px rgba(0,0,0,.5); color-scheme:dark;
}
```

Overrides (shared with Midnight): `html[data-theme="grove"] .btn.primary, html[data-theme="midnight"] .btn.primary{color:#0b0f0a}` and the same selector pair for `.statusbar{color:#0b0f0a}`.

### Midnight — `midnight`

```css
html[data-theme="midnight"]{
  --bg:#060608; --bg-deep:#030304; --wash:#171922;
  --glass:rgba(255,255,255,.04); --glass-2:rgba(255,255,255,.07); --glass-3:rgba(255,255,255,.025);
  --line:rgba(255,255,255,.09); --line-strong:rgba(255,255,255,.15);
  --txt:#eceef2; --mut:#8a8f9e; --mut-2:#676c7a;
  --acc:#8e9aaf; --acc-2:#cbd5e1; --acc-soft:rgba(142,154,175,.16); --acc-line:rgba(142,154,175,.5);
  --warn:#eab308; --warn-soft:rgba(234,179,8,.10); --warn-line:rgba(234,179,8,.5);
  --ok:#4ade80; --ok-soft:rgba(74,222,128,.12);
  --bad:#f87171; --bad-soft:rgba(248,113,113,.12);
  --cyan:#94a3b8; --cyan-soft:rgba(148,163,184,.12);
  --shadow:0 18px 50px rgba(0,0,0,.6); color-scheme:dark;
}
```

Overrides (shared with Earth Grove): `.btn.primary{color:#0b0f0a}`, `.statusbar{color:#0b0f0a}` (see Earth Grove).

### Ocean Deep — `ocean` — DEFAULT THEME

```css
html[data-theme="ocean"]{
  --bg:#04141c; --bg-deep:#02090e; --wash:#0a2e44;
  --glass:rgba(210,240,255,.045); --glass-2:rgba(210,240,255,.075); --glass-3:rgba(210,240,255,.03);
  --line:rgba(180,225,245,.11); --line-strong:rgba(180,225,245,.18);
  --txt:#e6f2f7; --mut:#8fb0bf; --mut-2:#6a8a99;
  --acc:#2ec4d6; --acc-2:#2a7fff; --acc-soft:rgba(46,196,214,.13); --acc-line:rgba(46,196,214,.45);
  --warn:#e8a13a; --warn-soft:rgba(232,161,58,.10); --warn-line:rgba(232,161,58,.55);
  --ok:#2ed184; --ok-soft:rgba(46,209,132,.12);
  --bad:#fb7185; --bad-soft:rgba(251,113,133,.12);
  --cyan:#4fd1e8; --cyan-soft:rgba(79,209,232,.12);
  --shadow:0 18px 50px rgba(0,0,0,.5); color-scheme:dark;
}
```

Overrides: `html[data-theme="ocean"] .btn.primary{color:#032630}`; `html[data-theme="ocean"] .statusbar{color:#032630}`.

### Ember — `ember`

```css
html[data-theme="ember"]{
  --bg:#170c07; --bg-deep:#0f0603; --wash:#3d1e0e;
  --glass:rgba(255,235,210,.045); --glass-2:rgba(255,235,210,.075); --glass-3:rgba(255,235,210,.03);
  --line:rgba(255,215,170,.11); --line-strong:rgba(255,215,170,.18);
  --txt:#f7ebe0; --mut:#b8a08c; --mut-2:#8f7d6b;
  --acc:#ff7a45; --acc-2:#e8a13a; --acc-soft:rgba(255,122,69,.13); --acc-line:rgba(255,122,69,.45);
  --warn:#ffb03a; --warn-soft:rgba(255,176,58,.11); --warn-line:rgba(255,176,58,.55);
  --ok:#8bc98a; --ok-soft:rgba(139,201,138,.12);
  --bad:#ef6351; --bad-soft:rgba(239,99,81,.13);
  --cyan:#e8c07a; --cyan-soft:rgba(232,192,122,.12);
  --shadow:0 18px 50px rgba(0,0,0,.5); color-scheme:dark;
}
```

Overrides: none in source.

### Daylight — `daylight`

```css
html[data-theme="daylight"]{
  --bg:#f1efe9; --bg-deep:#e7e4dc; --wash:#dfe4f5;
  --glass:rgba(255,255,255,.72); --glass-2:rgba(255,255,255,.92); --glass-3:rgba(255,255,255,.5);
  --line:rgba(28,35,51,.11); --line-strong:rgba(28,35,51,.18);
  --txt:#1c2333; --mut:#647084; --mut-2:#8a94a6;
  --acc:#6d5cff; --acc-2:#2f6bff; --acc-soft:rgba(109,92,255,.10); --acc-line:rgba(109,92,255,.4);
  --warn:#b7791f; --warn-soft:rgba(183,121,31,.10); --warn-line:rgba(183,121,31,.5);
  --ok:#1a9e57; --ok-soft:rgba(26,158,87,.11);
  --bad:#d64541; --bad-soft:rgba(214,69,65,.10);
  --cyan:#0e8a96; --cyan-soft:rgba(14,138,150,.10);
  --shadow:0 18px 50px rgba(28,35,51,.14); color-scheme:light;
}
```

Overrides: none in source.

## Regal

### Regal · Blue & Gold — `regal`

```css
html[data-theme="regal"]{
  --bg:#0a1628; --bg-deep:#050c17; --wash:#173763;
  --glass:rgba(200,220,255,.05); --glass-2:rgba(200,220,255,.08); --glass-3:rgba(200,220,255,.03);
  --line:rgba(190,210,245,.12); --line-strong:rgba(190,210,245,.2);
  --txt:#eef2fa; --mut:#96a8c4; --mut-2:#6e82a0;
  --acc:#4a90f0; --acc-2:#d4af37; --acc-soft:rgba(74,144,240,.15); --acc-line:rgba(74,144,240,.5);
  --warn:#d4af37; --warn-soft:rgba(212,175,55,.12); --warn-line:rgba(212,175,55,.55);
  --ok:#34c77b; --ok-soft:rgba(52,199,123,.13);
  --bad:#e5534b; --bad-soft:rgba(229,83,75,.13);
  --cyan:#8ab6f5; --cyan-soft:rgba(138,182,245,.13);
  --shadow:0 18px 50px rgba(0,0,0,.5); color-scheme:dark;
}
```

Overrides: none in source.

## Matte family

Flatness is token-level only (see the matte note under Shared family rules). No no-blur override exists in the source for any matte theme.

### Matte Slate — `matte-slate`

```css
html[data-theme="matte-slate"]{
  --bg:#2b3038; --bg-deep:#24282f; --wash:#2b3038;
  --glass:#333943; --glass-2:#3c434f; --glass-3:#2f343d;
  --line:#484f5c; --line-strong:#5a626f;
  --txt:#e8eaee; --mut:#a9aeba; --mut-2:#838a97;
  --acc:#84a6cc; --acc-2:#a9c0da; --acc-soft:rgba(132,166,204,.16); --acc-line:rgba(132,166,204,.55);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.13); --warn-line:rgba(201,168,106,.55);
  --ok:#8fae8b; --ok-soft:rgba(143,174,139,.15);
  --bad:#c4827a; --bad-soft:rgba(196,130,122,.15);
  --cyan:#8fb3b0; --cyan-soft:rgba(143,179,176,.14);
  --shadow:0 10px 28px rgba(0,0,0,.28); color-scheme:dark;
}
```

Overrides: `html[data-theme="matte-slate"] .btn.primary{color:#1e242e}`; `html[data-theme="matte-slate"] .statusbar{color:#1e242e}`.

### Matte Sand — `matte-sand`

```css
html[data-theme="matte-sand"]{
  --bg:#e7e0d0; --bg-deep:#dcd4c0; --wash:#e7e0d0;
  --glass:#efe9da; --glass-2:#f7f2e6; --glass-3:#e9e2d1;
  --line:#cdc3ab; --line-strong:#b3a888;
  --txt:#2f2b23; --mut:#6d675a; --mut-2:#8d8676;
  --acc:#5f7a8c; --acc-2:#9c7c4c; --acc-soft:rgba(95,122,140,.13); --acc-line:rgba(95,122,140,.45);
  --warn:#92702a; --warn-soft:rgba(146,112,42,.12); --warn-line:rgba(146,112,42,.5);
  --ok:#5d7f4c; --ok-soft:rgba(93,127,76,.13);
  --bad:#a44a3f; --bad-soft:rgba(164,74,63,.12);
  --cyan:#527a76; --cyan-soft:rgba(82,122,118,.12);
  --shadow:0 10px 28px rgba(47,43,35,.14); color-scheme:light;
}
```

Overrides: none in source.

### Matte Clay — `matte-clay`

```css
html[data-theme="matte-clay"]{
  --bg:#332a26; --bg-deep:#2a2320; --wash:#332a26;
  --glass:#3d332e; --glass-2:#463a34; --glass-3:#372f2a;
  --line:#574c45; --line-strong:#6b5e56;
  --txt:#f1e9e2; --mut:#b7a89c; --mut-2:#8f8177;
  --acc:#cb8663; --acc-2:#d9b380; --acc-soft:rgba(203,134,99,.16); --acc-line:rgba(203,134,99,.55);
  --warn:#d6ab54; --warn-soft:rgba(214,171,84,.13); --warn-line:rgba(214,171,84,.55);
  --ok:#93a37f; --ok-soft:rgba(147,163,127,.15);
  --bad:#d4705f; --bad-soft:rgba(212,112,95,.15);
  --cyan:#94aca0; --cyan-soft:rgba(148,172,160,.14);
  --shadow:0 10px 28px rgba(0,0,0,.3); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#2b1d13}`; `.statusbar{color:#2b1d13}`.

### Matte Olive — `matte-olive`

```css
html[data-theme="matte-olive"]{
  --bg:#2e3227; --bg-deep:#262922; --wash:#2e3227;
  --glass:#373b2f; --glass-2:#404639; --glass-3:#32362b;
  --line:#4f5443; --line-strong:#626854;
  --txt:#eceee3; --mut:#aeb39d; --mut-2:#858b74;
  --acc:#a9b47f; --acc-2:#cbbd8a; --acc-soft:rgba(169,180,127,.16); --acc-line:rgba(169,180,127,.55);
  --warn:#cfa856; --warn-soft:rgba(207,168,86,.13); --warn-line:rgba(207,168,86,.55);
  --ok:#84b184; --ok-soft:rgba(132,177,132,.14);
  --bad:#cb7f68; --bad-soft:rgba(203,127,104,.15);
  --cyan:#97b09b; --cyan-soft:rgba(151,176,155,.14);
  --shadow:0 10px 28px rgba(0,0,0,.3); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#23281a}`; `.statusbar{color:#23281a}`.

### Matte Dusk — `matte-dusk`

```css
html[data-theme="matte-dusk"]{
  --bg:#2f2b36; --bg-deep:#27242e; --wash:#2f2b36;
  --glass:#38333f; --glass-2:#413c4a; --glass-3:#332f3a;
  --line:#4e4859; --line-strong:#615a6e;
  --txt:#edeaf2; --mut:#aca5b8; --mut-2:#857e93;
  --acc:#ac97c4; --acc-2:#c4a97e; --acc-soft:rgba(172,151,196,.16); --acc-line:rgba(172,151,196,.55);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.13); --warn-line:rgba(201,168,106,.55);
  --ok:#8fae94; --ok-soft:rgba(143,174,148,.15);
  --bad:#c4828a; --bad-soft:rgba(196,130,138,.15);
  --cyan:#97a8bf; --cyan-soft:rgba(151,168,191,.14);
  --shadow:0 10px 28px rgba(0,0,0,.3); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#262033}`; `.statusbar{color:#262033}`.

## Calm Series

All six Calm themes receive the shared calm reading rules (see Shared family rules).

### Calm Deep Ocean — `calm-ocean` (picker label "Calm · Deep Ocean")

```css
html[data-theme="calm-ocean"]{
  --bg:#071e2b; --bg-deep:#05161f; --wash:#0d3247;
  --glass:rgba(205,235,242,.05); --glass-2:rgba(205,235,242,.08); --glass-3:rgba(205,235,242,.03);
  --line:rgba(175,215,228,.12); --line-strong:rgba(175,215,228,.19);
  --txt:#e3edef; --mut:#93adb8; --mut-2:#6d8a97;
  --acc:#5fb0c2; --acc-2:#7fb8a8; --acc-soft:rgba(95,176,194,.13); --acc-line:rgba(95,176,194,.42);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.10); --warn-line:rgba(201,168,106,.48);
  --ok:#6fb997; --ok-soft:rgba(111,185,151,.12);
  --bad:#c98a84; --bad-soft:rgba(201,138,132,.12);
  --cyan:#7fbdc4; --cyan-soft:rgba(127,189,196,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#06222b}`; `.statusbar{color:#06222b}`.

### Lagoon — `calm-lagoon` (picker label "Calm · Lagoon")

```css
html[data-theme="calm-lagoon"]{
  --bg:#0a2324; --bg-deep:#061a1b; --wash:#143c3a;
  --glass:rgba(210,240,232,.05); --glass-2:rgba(210,240,232,.08); --glass-3:rgba(210,240,232,.03);
  --line:rgba(180,225,210,.12); --line-strong:rgba(180,225,210,.19);
  --txt:#e2efec; --mut:#94b3ab; --mut-2:#6e8f88;
  --acc:#6bb8a6; --acc-2:#7aa8c4; --acc-soft:rgba(107,184,166,.13); --acc-line:rgba(107,184,166,.42);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.10); --warn-line:rgba(201,168,106,.48);
  --ok:#7fb98a; --ok-soft:rgba(127,185,138,.12);
  --bad:#c98a84; --bad-soft:rgba(201,138,132,.12);
  --cyan:#84bfb5; --cyan-soft:rgba(132,191,181,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#0a2420}`; `.statusbar{color:#0a2420}`.

### Sage Mist — `calm-sage` (picker label "Calm · Sage Mist"; key is calm-sage, NOT calm-sage-mist)

```css
html[data-theme="calm-sage"]{
  --bg:#1a221e; --bg-deep:#131916; --wash:#2a3a2f;
  --glass:rgba(230,240,228,.05); --glass-2:rgba(230,240,228,.075); --glass-3:rgba(230,240,228,.03);
  --line:rgba(205,225,205,.11); --line-strong:rgba(205,225,205,.18);
  --txt:#e8ede6; --mut:#a2b0a1; --mut-2:#7d8c7e;
  --acc:#9ab8a0; --acc-2:#a8b89a; --acc-soft:rgba(154,184,160,.13); --acc-line:rgba(154,184,160,.44);
  --warn:#c2a66b; --warn-soft:rgba(194,166,107,.10); --warn-line:rgba(194,166,107,.5);
  --ok:#84b88a; --ok-soft:rgba(132,184,138,.12);
  --bad:#c48d82; --bad-soft:rgba(196,141,130,.12);
  --cyan:#97b8a6; --cyan-soft:rgba(151,184,166,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#18251b}`; `.statusbar{color:#18251b}`.

### Dawn Mist — `calm-dawn` (picker label "Calm · Dawn Mist")

```css
html[data-theme="calm-dawn"]{
  --bg:#eef0ea; --bg-deep:#e3e7de; --wash:#dbe6e2;
  --glass:rgba(255,255,255,.68); --glass-2:rgba(255,255,255,.9); --glass-3:rgba(255,255,255,.48);
  --line:rgba(43,58,62,.11); --line-strong:rgba(43,58,62,.18);
  --txt:#2b373a; --mut:#66767a; --mut-2:#8a979a;
  --acc:#5f8696; --acc-2:#7a9a84; --acc-soft:rgba(95,134,150,.11); --acc-line:rgba(95,134,150,.4);
  --warn:#9a7d3a; --warn-soft:rgba(154,125,58,.10); --warn-line:rgba(154,125,58,.45);
  --ok:#4f8560; --ok-soft:rgba(79,133,96,.11);
  --bad:#b06055; --bad-soft:rgba(176,96,85,.10);
  --cyan:#5d8a8c; --cyan-soft:rgba(93,138,140,.10);
  --shadow:0 14px 38px rgba(43,58,62,.13); color-scheme:light;
}
```

Overrides: none in source (uses the default `.btn.primary{color:#fff}` and default status bar color).

### Stone Shore — `calm-stone` (picker label "Calm · Stone Shore")

```css
html[data-theme="calm-stone"]{
  --bg:#1f2629; --bg-deep:#181e20; --wash:#2c383c;
  --glass:rgba(225,235,238,.05); --glass-2:rgba(225,235,238,.08); --glass-3:rgba(225,235,238,.03);
  --line:rgba(195,212,218,.11); --line-strong:rgba(195,212,218,.18);
  --txt:#e6ebec; --mut:#9fb0b5; --mut-2:#778a8f;
  --acc:#8aa8b5; --acc-2:#9ab0a0; --acc-soft:rgba(138,168,181,.13); --acc-line:rgba(138,168,181,.44);
  --warn:#c2a66b; --warn-soft:rgba(194,166,107,.10); --warn-line:rgba(194,166,107,.5);
  --ok:#84b08e; --ok-soft:rgba(132,176,142,.12);
  --bad:#c48d82; --bad-soft:rgba(196,141,130,.12);
  --cyan:#94b5ba; --cyan-soft:rgba(148,181,186,.11);
  --shadow:0 14px 38px rgba(0,0,0,.36); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#1a262b}`; `.statusbar{color:#1a262b}`.

### Twilight Drift — `calm-twilight` (picker label "Calm · Twilight Drift")

```css
html[data-theme="calm-twilight"]{
  --bg:#1c222e; --bg-deep:#151a24; --wash:#29344a;
  --glass:rgba(215,228,245,.05); --glass-2:rgba(215,228,245,.08); --glass-3:rgba(215,228,245,.03);
  --line:rgba(185,202,228,.11); --line-strong:rgba(185,202,228,.18);
  --txt:#e6eaf0; --mut:#9aa8bc; --mut-2:#7786a0;
  --acc:#8aa0b8; --acc-2:#8fb0a4; --acc-soft:rgba(138,160,184,.13); --acc-line:rgba(138,160,184,.44);
  --warn:#c2a66b; --warn-soft:rgba(194,166,107,.10); --warn-line:rgba(194,166,107,.5);
  --ok:#7fb08e; --ok-soft:rgba(127,176,142,.12);
  --bad:#c48d8f; --bad-soft:rgba(196,141,143,.12);
  --cyan:#94aebf; --cyan-soft:rgba(148,174,191,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#1c2533}`; `.statusbar{color:#1c2533}`.

## Americana & Earth (Heritage)

All six themes in this family receive the shared usa-/earth- reading rules (see Shared family rules).

### Liberty Dusk — `usa-liberty`

```css
html[data-theme="usa-liberty"]{
  --bg:#16202e; --bg-deep:#111922; --wash:#22344a;
  --glass:rgba(232,238,245,.05); --glass-2:rgba(232,238,245,.08); --glass-3:rgba(232,238,245,.03);
  --line:rgba(195,212,228,.12); --line-strong:rgba(195,212,228,.19);
  --txt:#e8e6df; --mut:#9aa8b5; --mut-2:#748696;
  --acc:#7aa0c2; --acc-2:#c4a86a; --acc-soft:rgba(122,160,194,.13); --acc-line:rgba(122,160,194,.44);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.10); --warn-line:rgba(201,168,106,.48);
  --ok:#7fb08e; --ok-soft:rgba(127,176,142,.12);
  --bad:#c48a82; --bad-soft:rgba(196,138,130,.12);
  --cyan:#8fb0b8; --cyan-soft:rgba(143,176,184,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#16202e}`; `.statusbar{color:#16202e}`.

### Barn Stone — `usa-barn`

```css
html[data-theme="usa-barn"]{
  --bg:#221c1a; --bg-deep:#1a1513; --wash:#3d2b26;
  --glass:rgba(240,232,220,.05); --glass-2:rgba(240,232,220,.08); --glass-3:rgba(240,232,220,.03);
  --line:rgba(225,210,195,.11); --line-strong:rgba(225,210,195,.18);
  --txt:#ece7dd; --mut:#b0a198; --mut-2:#877a71;
  --acc:#c48a7a; --acc-2:#8aa0b8; --acc-soft:rgba(196,138,122,.13); --acc-line:rgba(196,138,122,.44);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.10); --warn-line:rgba(201,168,106,.48);
  --ok:#8aae8b; --ok-soft:rgba(138,174,139,.12);
  --bad:#c97a6a; --bad-soft:rgba(201,122,106,.12);
  --cyan:#94aeb5; --cyan-soft:rgba(148,174,181,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#22120e}`; `.statusbar{color:#22120e}`.

### Parchment — `usa-parchment`

```css
html[data-theme="usa-parchment"]{
  --bg:#ece8db; --bg-deep:#e0dac8; --wash:#d8dee2;
  --glass:rgba(255,255,255,.68); --glass-2:rgba(255,255,255,.9); --glass-3:rgba(255,255,255,.48);
  --line:rgba(46,52,64,.11); --line-strong:rgba(46,52,64,.18);
  --txt:#2e3440; --mut:#6d726a; --mut-2:#8d9088;
  --acc:#5d7a9a; --acc-2:#a67c52; --acc-soft:rgba(93,122,154,.11); --acc-line:rgba(93,122,154,.4);
  --warn:#9a7d3a; --warn-soft:rgba(154,125,58,.10); --warn-line:rgba(154,125,58,.45);
  --ok:#5d7f56; --ok-soft:rgba(93,127,86,.11);
  --bad:#a45a4e; --bad-soft:rgba(164,90,78,.10);
  --cyan:#5d8a8c; --cyan-soft:rgba(93,138,140,.10);
  --shadow:0 14px 38px rgba(46,52,64,.13); color-scheme:light;
}
```

Overrides: none in source (inherits `.btn.primary{color:#fff}` and the default status bar color).

### Walnut — `earth-walnut`

```css
html[data-theme="earth-walnut"]{
  --bg:#1f1813; --bg-deep:#17120e; --wash:#33261b;
  --glass:rgba(242,235,220,.05); --glass-2:rgba(242,235,220,.08); --glass-3:rgba(242,235,220,.03);
  --line:rgba(228,212,188,.11); --line-strong:rgba(228,212,188,.18);
  --txt:#eee8dd; --mut:#b3a08c; --mut-2:#877763;
  --acc:#c2a07a; --acc-2:#9ab0a0; --acc-soft:rgba(194,160,122,.13); --acc-line:rgba(194,160,122,.44);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.10); --warn-line:rgba(201,168,106,.48);
  --ok:#8fb08a; --ok-soft:rgba(143,176,138,.12);
  --bad:#c48d7a; --bad-soft:rgba(196,141,122,.12);
  --cyan:#a8b5a0; --cyan-soft:rgba(168,181,160,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#241a10}`; `.statusbar{color:#241a10}`.

### Canyon Mesa — `earth-canyon`

```css
html[data-theme="earth-canyon"]{
  --bg:#231a16; --bg-deep:#1b1411; --wash:#3d2920;
  --glass:rgba(242,235,225,.05); --glass-2:rgba(242,235,225,.08); --glass-3:rgba(242,235,225,.03);
  --line:rgba(228,210,190,.11); --line-strong:rgba(228,210,190,.18);
  --txt:#f0e9e0; --mut:#b8a698; --mut-2:#8a7a6d;
  --acc:#c99a7a; --acc-2:#8aa8b5; --acc-soft:rgba(201,154,122,.13); --acc-line:rgba(201,154,122,.44);
  --warn:#c9a86a; --warn-soft:rgba(201,168,106,.10); --warn-line:rgba(201,168,106,.48);
  --ok:#8fb08a; --ok-soft:rgba(143,176,138,.12);
  --bad:#c97f6a; --bad-soft:rgba(201,127,106,.12);
  --cyan:#9ab5b0; --cyan-soft:rgba(154,181,176,.11);
  --shadow:0 14px 38px rgba(0,0,0,.38); color-scheme:dark;
}
```

Overrides: `.btn.primary{color:#2b1a10}`; `.statusbar{color:#2b1a10}`.

### Adobe Dawn — `earth-adobe`

```css
html[data-theme="earth-adobe"]{
  --bg:#ede6da; --bg-deep:#e2d8c6; --wash:#dfe2d8;
  --glass:rgba(255,255,255,.66); --glass-2:rgba(255,255,255,.89); --glass-3:rgba(255,255,255,.46);
  --line:rgba(51,42,34,.11); --line-strong:rgba(51,42,34,.18);
  --txt:#332a22; --mut:#72665a; --mut-2:#94887a;
  --acc:#8a6d52; --acc-2:#6d8a7a; --acc-soft:rgba(138,109,82,.11); --acc-line:rgba(138,109,82,.4);
  --warn:#8f7030; --warn-soft:rgba(143,112,48,.10); --warn-line:rgba(143,112,48,.45);
  --ok:#5d7f4c; --ok-soft:rgba(93,127,76,.11);
  --bad:#a45a4a; --bad-soft:rgba(164,90,74,.10);
  --cyan:#5d8a85; --cyan-soft:rgba(93,138,133,.10);
  --shadow:0 14px 38px rgba(51,42,34,.13); color-scheme:light;
}
```

Overrides: none in source.

## Americana

### Americana · Red, White & Blue — `usa-rwb`

In the source, `--acc` is the flag blue (agent/action), `--acc-2` is flag red `#c8504a`, and `--warn` is a softened flag red `#e07a6a` carrying the decision/checkpoint color. The `usa-` prefix means the shared usa-/earth- reading rules apply to this theme.

```css
html[data-theme="usa-rwb"]{
  --bg:#0a1c38; --bg-deep:#061228; --wash:#1e3a5f;
  --glass:rgba(245,246,248,.055); --glass-2:rgba(245,246,248,.09); --glass-3:rgba(245,246,248,.032);
  --line:rgba(220,230,245,.13); --line-strong:rgba(220,230,245,.21);
  --txt:#f2f4f7; --mut:#a9b7cc; --mut-2:#7d8ea8;
  --acc:#4a86e8; --acc-2:#c8504a; --acc-soft:rgba(74,134,232,.15); --acc-line:rgba(74,134,232,.48);
  --warn:#e07a6a; --warn-soft:rgba(224,122,106,.12); --warn-line:rgba(224,122,106,.55);
  --ok:#6fb08a; --ok-soft:rgba(111,176,138,.12);
  --bad:#e5534b; --bad-soft:rgba(229,83,75,.13);
  --cyan:#a8c6e8; --cyan-soft:rgba(168,198,232,.12);
  --shadow:0 14px 38px rgba(0,0,0,.4); color-scheme:dark;
}
```

Overrides: none in source.

---

## Cross-check note: this table vs. `05-ui-mockup.html` in this folder

`05-ui-mockup.html` (the in-repo landing mockup) carries its own reduced token schema (14 custom properties plus `--reading-lh`) under different keys (`ocean-deep`, `earth-grove`, `calm-deep-ocean`) for 7 of the themes. Its values were compared against this table on 2026-11-14 and they do NOT match, except as follows:

- **Aurora** is the only near-match: every shared value is identical except `--glass-2` (mockup `rgba(255,255,255,.07)` vs. decision mockup `rgba(255,255,255,.075)`).
- **Ocean Deep** (`ocean` here vs. `ocean-deep` there): only `--warn` (`#e8a13a`) and `color-scheme` agree; the accent differs outright (decision mockup `--acc:#2ec4d6` vs. mockup `--acc:#5b8cff`).
- **Regal**: only `--warn` (`#d4af37`) and `color-scheme` agree; the mockup's `--acc-2` is a blue (`#2f5fe0`) where the decision mockup's is the gold (`#d4af37`).
- **Daylight**: only `--glass` (`rgba(255,255,255,.72)`) and `color-scheme` agree.
- **Earth Grove, Calm Deep Ocean, Matte Slate**: no identical custom-property values (all are `color-scheme: dark` in both). The mockup's Calm `--reading-lh:1.72` does match the decision mockup's calm reading rule value (1.72), expressed there as a selector rule rather than a token.
- The mockup also contains a matte-slate no-blur override rule (`backdrop-filter:none; box-shadow:none` on cards) that does not exist in the decision mockup at all.

**Ruling for implementers:** for claim C-02, build from THIS file. The values in `05-ui-mockup.html` are illustrative only and must not be treated as token authority. Reconciling or replacing the mockup's embedded values is follow-up work, not part of this filing.
