# Browsers

What this application needs from a browser, established from what it actually
ships rather than from a framework's stated baseline — the two are not the same,
and the difference here is several years of devices.

Run `node scripts/audit/engines.mjs` to check a browser in front of you rather
than trusting this file.

---

## 1. The short answer

| Engine  | Works from | Why that version |
| ------- | ---------- | ---------------- |
| Chrome / Edge / Samsung Internet | **80** | optional chaining in the bundle |
| Safari (macOS and iOS) | **13.1** | same |
| Firefox | **74** | same |

Everything below those versions fails on JavaScript, not on styling. Everything
above them renders and works; some versions lose small cosmetic touches, listed
in §3.

That floor is much lower than Tailwind v4's own stated baseline of Safari 16.4 /
Chrome 111 / Firefox 128, and the reason is worth knowing.

---

## 2. Why the CSS floor is not Tailwind's floor

Tailwind v4 leans on `oklch()`, `color-mix()` and `@property`, which is where
its baseline comes from. This application uses almost none of that in anger:

- **The palette is hex.** Every colour token in `resources/css/app.css` is
  declared as `#rrggbb` in the `@theme` block. The 52 `oklch()` values in the
  built stylesheet are Tailwind's *default* palette variables — `--color-amber-200`
  and its neighbours — which the application never references. A browser that
  cannot parse `oklch()` drops those declarations and every visible colour still
  resolves. Verified: **zero** rules in the built stylesheet paint a literal
  `oklch()` into a real property.

- **Every `color-mix()` is guarded.** All 114 uses sit inside
  `@supports (color: color-mix(in lab, red, red))`. Tailwind emits a plain
  fallback outside the guard, so opacity modifiers (`bg-brand/25`) degrade to
  the solid colour rather than to nothing.

- **`backdrop-filter` carries its prefix.** All three uses ship
  `-webkit-backdrop-filter` alongside, which is what Safari needs.

So the stylesheet degrades rather than breaks. That was checked against the
built bundle, not assumed.

---

## 3. What is lost, and where

Nothing here stops anybody doing their work. Listed so a support conversation
about "it looks slightly different on my phone" has an answer.

| Feature | Missing below | What is lost |
| ------- | ------------- | ------------ |
| `@property` | Safari 16.4, Firefox 128, Chrome 85 | Some transitions snap instead of easing. |
| `:has()` | Safari 15.4, Firefox 121, Chrome 105 | Three `has-checked:` utilities: a checked option does not tint its own label. The checkbox still works and is still readable. |
| `color-mix()` | Safari 16.2, Firefox 113, Chrome 111 | Opacity modifiers render at full opacity. |
| `backdrop-filter` | Safari 9, Firefox 103, Chrome 76 | A blurred overlay becomes a plain translucent one. |

---

## 4. Offline, and the one thing to tell iOS users

The offline queue is IndexedDB plus a service worker. Both are old and
universal — Safari 11.3, Chrome 45, Firefox 44.

The caveat is not support, it is **eviction**. Safari's tracking prevention
deletes script-writable storage for a site not visited in seven days, and that
storage is where an unsynced sale sits. Since iOS 15.4 a web app added to the
Home Screen is exempt.

> **On iPhone, install it.** Share → *Add to Home Screen*, and open it from that
> icon. A sale taken offline in a Safari tab that is then left alone for a week
> can be evicted before it syncs; the same sale in the installed app cannot.

The manifest declares `display: standalone`, so it installs properly on both
iOS and Android.

Service workers on iOS run only in Safari and in installed web apps — never in
Chrome or Firefox for iOS, which are Safari shells without that permission. Those
browsers work fine online; they simply have no offline mode.

---

## 5. Form controls that look different and behave the same

- `<input type="date">` — a wheel on iOS, a text field with a picker on Android
  and desktop, and no picker at all in Firefox before 109. Every one of them
  submits the same `YYYY-MM-DD`, which is all the server reads.
- `<input type="number">` with `inputmode="decimal"` — spinners on desktop, a
  numeric keypad on a phone. Firefox shows no spinners; typing works.

Neither has a fallback worth building. A browser that does not understand the
type renders a plain text box, and the server validates the value either way.

---

## 6. What has actually been verified

| Engine | Verified | How |
| ------ | -------- | --- |
| Chromium 141 | ✅ 27 pages, 0 console errors, 0 sideways scroll, all 13 feature probes pass | `scripts/audit/engines.mjs` |
| Firefox | ❌ **not run** | Playwright's browser CDN is unreachable from the build environment, and no distribution Firefox is installable there |
| WebKit | ❌ **not run** | same |

The analysis in §2 and §3 is static — it reads the built stylesheet and the
bundle, which is real evidence about what is shipped but is not the same as
watching Gecko and WebKit paint it. **Treat Firefox and Safari as unverified
until the sweep has been run on a machine that can install them:**

```bash
npm i --no-save playwright-core
npx playwright install firefox webkit
php artisan serve --port=8123 &
php artisan migrate:fresh --seed
node scripts/audit/engines.mjs
```

It exits non-zero on anything it does not like, and prints a line for every
engine it could not start — a sweep that quietly ran one engine is worse than no
sweep, because it reads like coverage.
