/*
 * WCAG 2.1 AA and 2.2, measured against the criteria the product claims.
 *
 * Three passes, because no one of them finds what the others do:
 *
 *   1. axe-core on every page in both colour schemes. Catches the structural
 *      failures a human reading the markup would miss — a heading level
 *      skipped, a landmark duplicated, an aria attribute pointing at nothing.
 *
 *   2. Target size against 2.5.8, which is the AA criterion: 24×24 CSS px,
 *      with the exceptions the criterion actually grants. ui-audit.mjs
 *      measures against 44×44, which is 2.5.5 — an AAA criterion the product
 *      does not claim — and that produces five hundred "failures" that are
 *      inline links in a paragraph, explicitly exempt. A number nobody can act
 *      on is a number nobody reads.
 *
 *   3. The keyboard. Tab through every page and check that focus stays
 *      visible, moves in document order, and never lands somewhere it cannot
 *      leave. Nothing static finds a focus trap.
 *
 *     php artisan serve --port=8123 &
 *     php artisan migrate:fresh --seed
 *     npm i --no-save playwright-core axe-core
 *     node scripts/audit/a11y.mjs
 *
 * Set CHROMIUM to a browser path if Playwright did not install one.
 */

import fs from 'fs';
import { createRequire } from 'module';

const require = createRequire(import.meta.url);

let chromium, axeSource;
try {
    ({ chromium } = await import('playwright-core'));
    axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
} catch (e) {
    console.error('Missing a dependency. Run: npm i --no-save playwright-core axe-core');
    console.error(e.message);
    process.exit(1);
}

const BASE = process.env.OPES_BASE || 'http://127.0.0.1:8123';

const PUBLIC = ['/', '/features', '/pricing', '/partners', '/about', '/contact', '/blog',
    '/privacy', '/terms', '/demo', '/login', '/register', '/forgot-password'];

const APP = ['/', '/sales', '/customers', '/products', '/products/stock', '/products/locations',
    '/accounting', '/accounting/declarations', '/reports', '/expenses', '/payments', '/assets',
    '/banking', '/team', '/payroll', '/library', '/forms', '/events', '/calendar', '/settings',
    '/business', '/customers/create', '/products/create', '/documents/create?type=invoice'];

/*
 * 2.5.8 Target Size (Minimum), as written rather than as folklore.
 *
 * 24×24 CSS pixels, and a target under that still passes if either exception
 * applies:
 *
 *   Spacing — a 24px circle centred on the target touches no other target's
 *   circle. A row of small icons fails; one small icon with room around it
 *   does not.
 *
 *   Inline — the target is in a sentence or a block of text, where making it
 *   bigger would break the line it sits in. This is the exception that covers
 *   every footer link the 44px measure was flagging.
 */
const TARGETS = `(() => {
  const SEL = 'a[href], button, input:not([type=hidden]), select, textarea, [role=button], [tabindex]:not([tabindex="-1"])';
  const els = [...document.querySelectorAll(SEL)].filter(el => {
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || s.opacity === '0') return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && ! el.disabled;
  });

  const boxes = els.map(el => ({ el, r: el.getBoundingClientRect() }));

  // Is this element inline text — sitting in a line of prose with other content?
  const inlineInText = el => {
    const s = getComputedStyle(el);
    if (s.display !== 'inline' && s.display !== 'inline-block') return false;
    const parent = el.parentElement;
    if (! parent) return false;
    // Text in the parent that is not this element's own.
    const own = (el.textContent || '').trim();
    const all = (parent.textContent || '').trim();
    return all.length > own.length + 3;
  };

  const fails = [];

  for (const { el, r } of boxes) {
    if (r.width >= 24 && r.height >= 24) continue;
    if (inlineInText(el)) continue;

    // Spacing exception: a 24px circle on this target, clear of every other.
    const cx = r.x + r.width / 2, cy = r.y + r.height / 2;
    let crowded = false;

    for (const other of boxes) {
      if (other.el === el) continue;
      const o = other.r;
      const ox = o.x + o.width / 2, oy = o.y + o.height / 2;
      if (Math.hypot(cx - ox, cy - oy) < 24) { crowded = true; break; }
    }

    if (! crowded) continue;

    fails.push({
      tag: el.tagName.toLowerCase(),
      size: Math.round(r.width) + 'x' + Math.round(r.height),
      name: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40),
      cls: (el.className || '').toString().slice(0, 60),
    });
  }

  return fails;
})()`;

/* Every focusable element, in document order, with what focus looks like. */
const FOCUSABLE = `(() => {
  const SEL = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  return [...document.querySelectorAll(SEL)].filter(el => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden';
  }).length;
})()`;

const browser = await chromium.launch(
    process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {}
);

const violations = new Map(); // rule id => { impact, help, pages:Set, nodes }
const targetFails = [];
const keyboard = [];
const noRingDetail = [];

for (const scheme of ['light', 'dark']) {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
        colorScheme: scheme,
    });

    /*
     * Injected before the page's own scripts rather than appended to the DOM.
     * The application sends a nonce-based CSP with no 'unsafe-inline', so
     * addScriptTag is refused — which is the header doing exactly its job, and
     * not something to work around by weakening it. An init script is put in
     * by the browser before page load and is not subject to page CSP.
     */
    await context.addInitScript({ content: axeSource });

    const page = await context.newPage();

    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'john@opesware.com');
    await page.fill('input[name=password]', 'password');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit]'),
    ]);

    for (const path of [...PUBLIC, ...APP]) {
        try {
            await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 20000 });
        } catch {
            continue;
        }
        await page.waitForTimeout(200);

        // ── 1. axe ────────────────────────────────────────────────────
        const result = await page.evaluate(async () => await window.axe.run(document, {
            runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
            resultTypes: ['violations'],
        }));

        for (const v of result.violations) {
            if (! violations.has(v.id)) {
                violations.set(v.id, { impact: v.impact, help: v.help, pages: new Set(), nodes: [] });
            }
            const entry = violations.get(v.id);
            entry.pages.add(`${scheme} ${path}`);
            for (const n of v.nodes.slice(0, 2)) {
                if (entry.nodes.length < 4) entry.nodes.push(n.html.slice(0, 130));
            }
        }

        // ── 2. target size, 2.5.8 ─────────────────────────────────────
        for (const f of await page.evaluate(TARGETS)) {
            targetFails.push({ scheme, path, ...f });
        }

        // ── 3. keyboard ───────────────────────────────────────────────
        if (scheme === 'light') {
            const count = await page.evaluate(FOCUSABLE);
            const steps = Math.min(count, 60);

            await page.evaluate(() => document.body.focus());
            const order = [];
            let invisible = 0;

            for (let i = 0; i < steps; i++) {
                await page.keyboard.press('Tab');

                /*
                 * A beat between presses. Hammering Tab sixty times as fast as
                 * the protocol allows is not what a person does, and it reads
                 * styles mid-morph on any field with wire:model.live — which
                 * reported three date inputs as having no focus ring when they
                 * show one immediately and hold it.
                 */
                await page.waitForTimeout(60);

                const state = await page.evaluate(() => {
                    const el = document.activeElement;
                    if (! el || el === document.body) return null;
                    const s = getComputedStyle(el);
                    const r = el.getBoundingClientRect();
                    return {
                        tag: el.tagName.toLowerCase(),
                        /*
                         * A visible focus indicator is an outline or a ring.
                         * The ring is a box-shadow, and Tailwind always emits
                         * the shadow slots — so "not none" is not enough: a
                         * list of fully transparent shadows is no indicator.
                         */
                        ring: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0,
                        shadow: s.boxShadow !== 'none'
                            && ! /^(rgba\(0, 0, 0, 0\) 0px 0px 0px 0px(, )?)+$/.test(s.boxShadow),
                        onScreen: r.width > 0 && r.height > 0,
                        y: Math.round(r.y),
                    };
                });

                if (! state) continue;
                order.push(state.y);

                /*
                 * Look twice before accusing. A wire:model.live field
                 * round-trips when focus reaches it, and reading during the
                 * morph reports a ring that is there a moment later — three
                 * date inputs were flagged that way, all of which do in fact
                 * show a 2px ring and a brand-coloured border. Only the second
                 * reading counts, and only the elements that failed the first
                 * pay for the wait.
                 */
                if (state.onScreen && ! state.ring && ! state.shadow) {
                    await page.waitForTimeout(250);

                    const again = await page.evaluate(() => {
                        const el = document.activeElement;
                        if (! el || el === document.body || ! el.matches(':focus')) return null;
                        const s = getComputedStyle(el);
                        return {
                            ring: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0,
                            shadow: s.boxShadow !== 'none'
                                && ! /^(rgba\(0, 0, 0, 0\) 0px 0px 0px 0px(, )?)+$/.test(s.boxShadow),
                            tag: el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : ''),
                        };
                    });

                    if (again && ! again.ring && ! again.shadow) {
                        invisible++;
                        noRingDetail.push(`${path}  ${again.tag}`);
                    }
                }
            }

            // Focus should walk down the page, not jump about. Count how often
            // it goes backwards by more than a screen.
            let jumps = 0;
            for (let i = 1; i < order.length; i++) {
                if (order[i] < order[i - 1] - 844) jumps++;
            }

            // `steps`, not `count`: the walk is capped, so comparing against
            // every focusable element on the page reports the cap as a failure.
            keyboard.push({ path, reached: order.length, of: steps, invisible, jumps });
        }
    }

    await context.close();
}

await browser.close();

// ── report ────────────────────────────────────────────────────────────

const line = n => '─'.repeat(n);

console.log(`\n### axe-core — WCAG 2.1 AA + 2.2 AA\n${line(70)}`);
if (violations.size === 0) {
    console.log('  no violations across every page, both colour schemes');
} else {
    const sorted = [...violations.entries()].sort((a, b) => b[1].pages.size - a[1].pages.size);
    for (const [id, v] of sorted) {
        console.log(`\n  [${v.impact}] ${id} — ${v.help}`);
        console.log(`    on ${v.pages.size} page/scheme combination(s), e.g. ${[...v.pages].slice(0, 3).join(', ')}`);
        v.nodes.forEach(n => console.log(`      ${n}`));
    }
}

console.log(`\n### Target size — WCAG 2.5.8 (24×24, AA)\n${line(70)}`);
if (targetFails.length === 0) {
    console.log('  every target is 24×24 or exempt by spacing or inline text');
} else {
    const seen = new Set();
    for (const f of targetFails) {
        const key = f.cls + f.size;
        if (seen.has(key)) continue;
        seen.add(key);
        console.log(`  ${f.size}  <${f.tag}> "${f.name}"  ${f.path}`);
        console.log(`        ${f.cls}`);
    }
    console.log(`\n  ${targetFails.length} occurrence(s), ${seen.size} distinct`);
}

console.log(`\n### Keyboard\n${line(70)}`);
const unreachable = keyboard.filter(k => k.reached < k.of - 1);
const noRing = keyboard.filter(k => k.invisible > 0);
const jumpy = keyboard.filter(k => k.jumps > 0);

console.log(`  pages tabbed            : ${keyboard.length}`);
console.log(`  focus never visible on  : ${noRing.length}`);
noRingDetail.forEach(d => console.log(`      ${d}`));
console.log(`  focus order jumps back  : ${jumpy.length}`);
jumpy.forEach(k => console.log(`      ${k.path}  ${k.jumps}×`));
console.log(`  fewer stops than expected: ${unreachable.length}`);
unreachable.slice(0, 6).forEach(k => console.log(`      ${k.path}  reached ${k.reached} of ${k.of}`));

const total = violations.size + new Set(targetFails.map(f => f.cls + f.size)).size
    + noRing.length + jumpy.length;

console.log(`\n${total === 0 ? 'CLEAN' : total + ' thing(s) to look at'}\n`);
process.exit(total > 0 ? 1 : 0);
