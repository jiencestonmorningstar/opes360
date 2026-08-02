/*
 * The same application, in three engines.
 *
 * ui-audit.mjs answers "does this page paint correctly" and runs in Chromium
 * only, which is the engine least likely to be the problem. This one answers
 * the narrower question Chromium cannot: does anything break in Gecko or
 * WebKit — a layout that only reflows in one engine, a CSS feature Chromium
 * has and Safari does not, a script error in Firefox alone.
 *
 * It probes, in each engine rather than from a support table:
 *   · every page for console errors and sideways scroll
 *   · the CSS features the built bundle actually uses
 *   · the storage the offline queue depends on
 *   · the geometry of the layout anchors, compared across engines, so a break
 *     that only happens in one shows up as a number rather than a feeling
 *
 * Engines that are not installed are skipped with a line saying so — never
 * silently, because "the sweep passed" must not be able to mean "the sweep
 * only ran Chromium".
 *
 *     php artisan serve --port=8123 &
 *     php artisan migrate:fresh --seed
 *     npx playwright install firefox webkit     # once
 *     node scripts/audit/engines.mjs
 *
 * Requires playwright-core; set OPES_BASE to point elsewhere, and
 * OPES_CHROMIUM / OPES_FIREFOX / OPES_WEBKIT to use a browser Playwright did
 * not install itself.
 */

let pw;
try {
    pw = await import('playwright-core');
} catch {
    console.error('playwright-core is not installed. Run: npm i --no-save playwright-core');
    process.exit(1);
}

const BASE = process.env.OPES_BASE || 'http://127.0.0.1:8123';

const PUBLIC = ['/', '/features', '/pricing', '/about', '/contact', '/login', '/register'];

const APP = ['/', '/sales', '/customers', '/products', '/products/stock', '/products/locations',
    '/accounting', '/accounting/declarations', '/reports', '/expenses', '/payments', '/assets',
    '/banking', '/team', '/payroll', '/library', '/forms', '/events', '/calendar', '/settings'];

/*
 * Asked of the engine itself. A support table says what a version ought to do;
 * this says what the browser in front of us does, which is the only claim
 * worth putting in a release note.
 */
const PROBE = `(() => {
  const css = (prop, value) => { try { return CSS.supports(prop, value); } catch { return false; } };
  return {
    // Everything the built stylesheet leans on.
    'color-mix()':        css('color', 'color-mix(in srgb, red, blue)'),
    'oklch()':            css('color', 'oklch(50% 0.1 200)'),
    ':has()':             (() => { try { document.querySelector(':has(*)'); return true; } catch { return false; } })(),
    '@property':          typeof CSS !== 'undefined' && typeof CSS.registerProperty === 'function',
    'backdrop-filter':    css('backdrop-filter', 'blur(2px)') || css('-webkit-backdrop-filter', 'blur(2px)'),
    'position:sticky':    css('position', 'sticky'),
    'aspect-ratio':       css('aspect-ratio', '1/1'),
    'gap in flex':        css('gap', '1rem'),
    // What the offline queue is built on. Losing either is losing a sale.
    'indexedDB':          typeof indexedDB !== 'undefined',
    'serviceWorker':      'serviceWorker' in navigator,
    'localStorage':       (() => { try { localStorage.setItem('_p','1'); localStorage.removeItem('_p'); return true; } catch { return false; } })(),
    // Input types the forms use. A browser without them shows a text box,
    // which still works — worth knowing, not worth blocking on.
    'input[type=date]':   (() => { const i = document.createElement('input'); i.type='date'; return i.type === 'date'; })(),
    'input[type=number]': (() => { const i = document.createElement('input'); i.type='number'; return i.type === 'number'; })(),
  };
})()`;

/* Layout anchors, measured so the same page in two engines can be compared. */
const GEOMETRY = `(() => {
  const box = sel => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const r = el.getBoundingClientRect();
    return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)];
  };
  return {
    h1: box('h1'),
    firstCard: box('.card'),
    main: box('main') || box('body > div'),
    scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
  };
})()`;

/*
 * The executable overrides exist because a machine often has a browser that
 * playwright-core did not put there — a distribution Chromium, or one from a
 * different Playwright version. Without them the sweep skips an engine that is
 * sitting on the disk.
 */
const engines = [
    ['chromium', pw.chromium, process.env.OPES_CHROMIUM],
    ['firefox', pw.firefox, process.env.OPES_FIREFOX],
    ['webkit', pw.webkit, process.env.OPES_WEBKIT],
];

const results = {};
const skipped = [];
let problems = 0;

for (const [name, type, executablePath] of engines) {
    let browser;

    try {
        browser = await type.launch(executablePath ? { executablePath } : {});
    } catch (e) {
        skipped.push([name, String(e.message).split('\n')[0].slice(0, 120)]);
        continue;
    }

    console.log(`\n═══ ${name} ${browser.version()} ${'═'.repeat(Math.max(0, 46 - name.length))}`);

    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: name !== 'firefox', // Firefox has no touch emulation
        hasTouch: name !== 'firefox',
    });
    const page = await context.newPage();

    const errors = [];
    page.on('console', m => m.type() === 'error' && errors.push(m.text().slice(0, 160)));
    page.on('pageerror', e => errors.push('PAGEERROR ' + e.message.slice(0, 160)));

    // Feature probe, once, on a page that has the stylesheet.
    await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
    const features = await page.evaluate(PROBE);

    console.log('\n  features');
    for (const [feature, ok] of Object.entries(features)) {
        console.log(`    ${ok ? '·' : '✗'} ${feature}${ok ? '' : '   NOT SUPPORTED'}`);
        if (!ok) problems++;
    }

    // Sign in, then sweep.
    await page.fill('input[name=email]', 'john@opesware.com');
    await page.fill('input[name=password]', 'password');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit]'),
    ]);

    const geometry = {};
    const overflow = [];
    const failed = [];

    for (const path of [...PUBLIC, ...APP]) {
        errors.length = 0;

        let status = 0;
        try {
            const response = await page.goto(BASE + path, { waitUntil: 'networkidle', timeout: 20000 });
            status = response ? response.status() : 0;
        } catch (e) {
            failed.push([path, String(e.message).split('\n')[0].slice(0, 80)]);
            continue;
        }

        await page.waitForTimeout(250);

        const g = await page.evaluate(GEOMETRY);
        geometry[path] = g;

        if (g.scroll[0] > g.scroll[1]) {
            overflow.push([path, `${g.scroll[0]} > ${g.scroll[1]}`]);
        }

        if (status >= 400) {
            failed.push([path, 'HTTP ' + status]);
        }

        if (errors.length) {
            failed.push([path, errors[0]]);
        }
    }

    results[name] = geometry;

    console.log(`\n  swept ${PUBLIC.length + APP.length} pages`);
    console.log(`    sideways scroll : ${overflow.length}`);
    overflow.forEach(([p, d]) => console.log(`        ${p}  ${d}`));
    console.log(`    errors / 4xx    : ${failed.length}`);
    failed.forEach(([p, d]) => console.log(`        ${p}  ${d}`));

    problems += overflow.length + failed.length;

    await browser.close();
}

/*
 * The cross-engine part: the same page measured in two engines. Small
 * differences are font metrics and are expected; a card in a different place
 * is a layout that only holds together in one engine.
 */
const seen = Object.keys(results);

if (seen.length > 1) {
    console.log(`\n═══ geometry, ${seen[0]} vs the rest ${'═'.repeat(30)}`);

    const TOLERANCE = 12; // px — font metrics differ between engines
    let drifted = 0;

    for (const other of seen.slice(1)) {
        for (const path of Object.keys(results[seen[0]])) {
            const a = results[seen[0]][path];
            const b = results[other][path];
            if (!a || !b) continue;

            for (const key of ['h1', 'firstCard', 'main']) {
                if (!a[key] || !b[key]) continue;

                const delta = a[key].map((v, i) => Math.abs(v - b[key][i]));
                const worst = Math.max(...delta);

                if (worst > TOLERANCE) {
                    console.log(`    ${path}  ${key}  ${seen[0]}=${a[key]}  ${other}=${b[key]}`);
                    drifted++;
                }
            }
        }
    }

    console.log(`\n    anchors more than ${TOLERANCE}px apart: ${drifted}`);
    problems += drifted;
}

if (skipped.length) {
    console.log(`\n═══ NOT RUN ${'═'.repeat(48)}`);
    skipped.forEach(([name, why]) => console.log(`    ${name}: ${why}`));
    console.log('\n    Install them with:  npx playwright install firefox webkit');
    console.log('    A sweep that only ran Chromium has not tested anything Chromium is not.');
}

console.log(`\n${problems === 0 && skipped.length === 0 ? 'CLEAN' : `${problems} to look at, ${skipped.length} engine(s) not run`}\n`);

process.exit(problems > 0 ? 1 : 0);
