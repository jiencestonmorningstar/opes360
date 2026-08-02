/*
 * What the browser actually paints, checked.
 *
 * tests/Unit/ColourContrastTest.php proves every declared token pair passes
 * WCAG AA. It cannot see a view that puts one token on another that was never
 * paired with it, because that combination exists only once a page renders.
 * This walks every rendered text node on every page, in both themes, resolves
 * the real background by climbing the tree until something opaque is found,
 * and measures.
 *
 * It also collects what is only observable in a browser: console errors, hit
 * areas below the WCAG 2.2 minimum, controls with no accessible name, images
 * with no alt text, internal links that 404, and pages that scroll sideways.
 *
 * Not part of the test suite: it needs a running server, a browser and seeded
 * data, none of which belong in a unit test. Run it before a release.
 *
 *     php artisan serve --port=8123 &
 *     php artisan migrate:fresh --seed
 *     node scripts/audit/ui-audit.mjs
 *
 * Requires playwright-core and a Chromium; set CHROMIUM to override the path.
 * Every count it prints should be zero, except hit areas, where the remainder
 * are checkboxes whose <label> wraps them — there the label is the target.
 */

import fs from 'fs';

/*
 * Imported at run time rather than declared as a dependency. The release build
 * runs `npm ci` on a machine that is often somebody's laptop, and a browser
 * driver is a large thing to make everyone download for a tool used once a
 * release. Install it when you want to run this:
 *
 *     npm i --no-save playwright-core
 */
let chromium;
try {
    ({ chromium } = await import('playwright-core'));
} catch {
    console.error('playwright-core is not installed. Run: npm i --no-save playwright-core');
    process.exit(1);
}

/*
 * Audits what the browser actually paints, not what the tokens promise.
 *
 * The token test proves every declared pair passes AA. It cannot see a view
 * that puts text-faint on bg-fill-brand, because that pair was never declared.
 * This walks every rendered text node, resolves the real background by climbing
 * until something opaque is found, and measures.
 */

const BASE = process.env.OPES_BASE || 'http://127.0.0.1:8123';

const PUBLIC = ['/', '/features', '/pricing', '/partners', '/about', '/contact', '/blog',
  '/privacy', '/terms', '/demo', '/login', '/register', '/forgot-password', '/offline',
  '/business/opesware-technologies'];

const APP = ['/', '/sales', '/customers', '/products', '/business', '/business/stationery?asset=card',
  '/library', '/forms', '/reports', '/accounting', '/payments', '/calendar', '/settings', '/help',
  '/customers/create', '/products/create', '/documents/create?type=invoice'];

const PARTNER = ['/partners/clients', '/partners/earnings'];

const AUDIT = `(() => {
  const lum = (r, g, b) => {
    const f = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const parse = c => {
    const m = c.match(/rgba?\\(([^)]+)\\)/);
    if (!m) return null;
    const p = m[1].split(/[\\s,\\/]+/).filter(Boolean).map(Number);
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
  };
  const over = (fg, bg) => ({
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
    a: 1,
  });
  const bgOf = el => {
    let acc = null;
    for (let n = el; n; n = n.parentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c && c.a > 0) acc = acc ? over(acc, c) : c;
      if (acc && acc.a >= 0.999) return acc;
    }
    const root = parse(getComputedStyle(document.documentElement).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 };
    return acc ? over(acc, root) : root;
  };
  const ratio = (a, b) => {
    const la = lum(a.r, a.g, a.b), lb = lum(b.r, b.g, b.b);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
  };
  const visible = el => {
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || +s.opacity === 0) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };

  const contrast = [], targets = [], names = [], alts = [];
  const seen = new Set();

  for (const el of document.querySelectorAll('body *')) {
    if (!visible(el)) continue;

    const own = [...el.childNodes].filter(n => n.nodeType === 3 && n.textContent.trim())
      .map(n => n.textContent.trim()).join(' ');
    if (own) {
      const s = getComputedStyle(el);
      const fg = parse(s.color);
      if (fg && fg.a > 0.05) {
        const bg = bgOf(el);
        const r = ratio(fg.a < 1 ? over(fg, bg) : fg, bg);
        const px = parseFloat(s.fontSize);
        const bold = (parseInt(s.fontWeight, 10) || 400) >= 700;
        const large = px >= 24 || (bold && px >= 18.66);
        const need = large ? 3 : 4.5;
        if (r < need - 0.005) {
          const key = s.color + '|' + own.slice(0, 30);
          if (!seen.has(key)) {
            seen.add(key);
            contrast.push({ text: own.slice(0, 46), ratio: +r.toFixed(2), need, px: +px.toFixed(1),
              color: s.color, cls: String(el.className || '').slice(0, 60) });
          }
        }
      }
    }

    const tag = el.tagName.toLowerCase();
    if (tag === 'button' || (tag === 'a' && el.hasAttribute('href')) || tag === 'select' ||
        (tag === 'input' && el.type !== 'hidden')) {
      const r = el.getBoundingClientRect();
      const inline = tag === 'a' && getComputedStyle(el).display === 'inline';
      if (!inline && (r.height < 43.5 || r.width < 24)) {
        targets.push({ tag, w: Math.round(r.width), h: Math.round(r.height),
          text: (el.textContent || el.value || '').trim().slice(0, 28), cls: String(el.className || '').slice(0, 50) });
      }
      let name = (el.textContent || '').trim() || el.getAttribute('aria-label') ||
        el.getAttribute('title') || el.getAttribute('placeholder') || '';
      if (!name && el.labels && el.labels.length) name = [...el.labels].map(l => l.textContent.trim()).join(' ');
      if (!name && el.id) name = (document.querySelector('label[for="' + CSS.escape(el.id) + '"]')?.textContent || '').trim();
      if (!name) names.push({ tag, type: el.type || '', cls: String(el.className || '').slice(0, 55) });
    }

    if (tag === 'img' && !el.hasAttribute('alt')) alts.push({ src: (el.getAttribute('src') || '').slice(-40) });
  }

  const links = [...document.querySelectorAll('a[href]')]
    .map(a => a.getAttribute('href'))
    .filter(h => h && !h.startsWith('#') && !h.startsWith('mailto:') && !h.startsWith('tel:') && !/^https?:\\/\\/(?!127\\.0\\.0\\.1)/.test(h));

  const de = document.documentElement;
  return { contrast, targets, names, alts, links,
    overflow: de.scrollWidth > de.clientWidth || window.innerWidth > de.clientWidth };
})()`;

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
const report = { contrast: [], targets: [], names: [], alts: [], console: [], links: new Set(), overflow: [] };

async function visit(page, url, label) {
  const errors = [];
  const onErr = m => { if (m.type() === 'error') errors.push(m.text().slice(0, 140)); };
  page.on('console', onErr);
  const resp = await page.goto(BASE + url, { waitUntil: 'networkidle' }).catch(() => null);
  await page.waitForTimeout(350);
  const r = await page.evaluate(AUDIT).catch(e => ({ error: e.message }));
  page.off('console', onErr);
  if (r?.error) { console.log(`  !! ${label} eval failed: ${r.error}`); return; }
  for (const c of r.contrast) report.contrast.push({ ...c, page: label });
  for (const t of r.targets) report.targets.push({ ...t, page: label });
  for (const n of r.names) report.names.push({ ...n, page: label });
  for (const a of r.alts) report.alts.push({ ...a, page: label });
  for (const l of r.links) report.links.add(l);
  if (r.overflow) report.overflow.push(label);
  for (const e of errors) report.console.push({ page: label, message: e });
  if (resp && resp.status() >= 400) console.log(`  !! ${label} status ${resp.status()}`);
}

async function login(page, email) {
  await page.goto(BASE + '/logout', { waitUntil: 'domcontentloaded' }).catch(() => {});
  await page.goto(BASE + '/login', { waitUntil: 'networkidle' });
  if (!page.url().includes('/login')) return;
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'password');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('button[type=submit]')]);
}

for (const scheme of ['light', 'dark']) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2,
    isMobile: true, hasTouch: true, colorScheme: scheme });
  const page = await ctx.newPage();

  for (const url of PUBLIC) await visit(page, url, `${scheme} ${url}`);

  await login(page, 'john@opesware.com');
  for (const url of APP) await visit(page, url, `${scheme} app ${url}`);

  await ctx.close();

  const ctx2 = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2,
    isMobile: true, hasTouch: true, colorScheme: scheme });
  const page2 = await ctx2.newPage();
  await login(page2, 'secretariat@opesware.com');
  for (const url of PARTNER) await visit(page2, url, `${scheme} sec ${url}`);
  await ctx2.close();
}

const ctx = await browser.newContext();
const broken = [];
for (const href of report.links) {
  const url = href.startsWith('http') ? href : BASE + (href.startsWith('/') ? href : '/' + href);
  const r = await ctx.request.get(url, { maxRedirects: 0 }).catch(() => null);
  const s = r?.status() ?? 0;
  if (s >= 400) broken.push({ href, status: s });
}
await ctx.close();
await browser.close();

const show = (title, rows, fmt) => {
  console.log(`\n### ${title} — ${rows.length}`);
  rows.slice(0, 24).forEach(r => console.log('  ' + fmt(r)));
  if (rows.length > 24) console.log(`  … and ${rows.length - 24} more`);
};

show('Contrast failures (rendered)', report.contrast,
  r => `${r.ratio}:1 (need ${r.need}) ${r.px}px "${r.text}" [${r.page}] ${r.color} .${r.cls}`);
show('Tap targets under 44px', report.targets,
  r => `${r.w}x${r.h} <${r.tag}> "${r.text}" [${r.page}] .${r.cls}`);
show('Controls with no accessible name', report.names,
  r => `<${r.tag} ${r.type}> [${r.page}] .${r.cls}`);
show('Images without alt', report.alts, r => `${r.src} [${r.page}]`);
show('Console errors', report.console, r => `[${r.page}] ${r.message}`);
show('Broken internal links', broken, r => `${r.status} ${r.href}`);
show('Pages still overflowing', report.overflow.map(p => ({ p })), r => r.p);

fs.writeFileSync('audit-report.json', JSON.stringify({ ...report, links: [...report.links], broken }, null, 2));
console.log('\nWrote audit-report.json');
