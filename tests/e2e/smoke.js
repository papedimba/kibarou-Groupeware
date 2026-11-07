// Test de fumée UI (Playwright). Prérequis : serveur lancé, ex.
//   php -S 127.0.0.1:8080 -t public tests/e2e/router.php
// Usage : KB_URL=http://127.0.0.1:8080 KB_EMAIL=... KB_PASSWORD=... node tests/e2e/smoke.js
const { chromium, devices } = require('playwright');
const assert = require('assert');


// Cibles tactiles visibles < 44 px de haut (cases à cocher couvertes par leur ligne .kb-check-row).
async function smallTargets(p) {
  return p.evaluate(() => [...document.querySelectorAll('button, .btn, input:not([type=hidden]):not([type=checkbox]), select, .kb-check-row')]
    .filter(el => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.right > 0 && r.left < innerWidth && el.closest('.dropdown-menu:not(.show)') === null; })
    .filter(el => el.getBoundingClientRect().height < 44)
    .map(el => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} "${(el.innerText || el.value || '').trim().slice(0, 20)}" ${Math.round(el.getBoundingClientRect().height)}px`));
}
// Contraste (WCAG) d'un sélecteur contre le premier fond opaque de ses ancêtres.
async function minContrast(p, selector) {
  return p.evaluate(sel => {
    const rgb = c => (c.match(/[\d.]+/g) || []).map(Number);
    const lum = ([r, g, b]) => [r, g, b].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
      .reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
    let worst = 21;
    for (const el of document.querySelectorAll(sel)) {
      let bg = el; while (bg && (rgb(getComputedStyle(bg).backgroundColor)[3] === 0 || getComputedStyle(bg).backgroundColor === 'transparent')) bg = bg.parentElement;
      const l1 = lum(rgb(getComputedStyle(el).color)), l2 = lum(rgb(bg ? getComputedStyle(bg).backgroundColor : 'rgb(255,255,255)'));
      worst = Math.min(worst, (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05));
    }
    return worst;
  }, selector);
}

(async () => {
  const base = process.env.KB_URL || 'http://127.0.0.1:8080';
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1366, height: 820 } })).newPage();
  const problems = [];
  page.on('console', m => { if (m.type() === 'error') problems.push(m.text()); });
  page.on('pageerror', e => problems.push(e.message));

  await page.goto(base + '/');
  assert.ok(page.url().endsWith('/login'), 'redirection vers /login');
  await page.fill('#email', process.env.KB_EMAIL);
  await page.fill('#password', 'mauvais-mot-de-passe');
  await page.click('button[type=submit]');
  assert.match(await page.locator('.invalid-feedback').innerText(), /incorrect/);
  await page.fill('#password', process.env.KB_PASSWORD);
  await page.click('button[type=submit]');
  await page.waitForURL(base + '/');
  assert.ok(await page.locator('svg.lucide').count() > 5, 'icônes Lucide rendues');

  for (const path of ['/admin', '/admin/users', '/admin/users/create', '/admin/departments', '/admin/positions', '/admin/roles', '/admin/audit', '/profile']) {
    const r = await page.goto(base + path);
    assert.strictEqual(r.status(), 200, path);
  }
  // Tâche 1 : rien de coupé à droite sur ordinateur (sessions du profil)
  await page.goto(base + '/profile');
  const sessionAction = page.locator('table .badge, table button').first();
  const sb = await sessionAction.boundingBox();
  assert.ok(sb && sb.x + sb.width <= 1366, `action de session visible à 1366 px (${sb && sb.x + sb.width})`);

  const mobile = await (await browser.newContext({ ...devices['iPhone 13'] })).newPage();
  mobile.on('console', m => { if (m.type() === 'error') problems.push(m.text()); });
  await mobile.goto(base + '/login');
  assert.ok(await mobile.locator('#email').isVisible(), 'formulaire visible sur mobile');
  // Tâche 2 : cibles tactiles et contrastes sur téléphone
  assert.deepStrictEqual(await smallTargets(mobile), [], 'cibles >= 44 px sur /login');
  assert.ok(await minContrast(mobile, '.kb-brand small') >= 4.5, 'contraste du sous-titre de connexion');
  await mobile.fill('#email', process.env.KB_EMAIL);
  await mobile.fill('#password', process.env.KB_PASSWORD);
  await mobile.click('button[type=submit]');
  await mobile.waitForURL(base + '/');
  const vw = mobile.viewportSize().width;
  for (const path of ['/admin/users', '/admin/users/create', '/profile']) {
    await mobile.goto(base + path);
    assert.deepStrictEqual(await smallTargets(mobile), [], `cibles >= 44 px sur ${path}`);
  }
  assert.ok(await minContrast(mobile, '.kb-nav-title') >= 4.5, 'contraste des titres du menu');
  assert.ok(await minContrast(mobile, '.text-secondary') >= 4.5, 'contraste du texte secondaire');
  // Tâche 1 : le statut des utilisateurs reste visible sur téléphone
  await mobile.goto(base + '/admin/users');
  const status = mobile.locator('tbody .badge').first();
  const st = await status.boundingBox();
  assert.ok(st && st.x >= 0 && st.x + st.width <= vw, `statut visible sur mobile (${st && st.x + st.width} > ${vw})`);

  assert.deepStrictEqual(problems, [], 'aucune erreur console (CSP incluse)');
  await browser.close();
  console.log('Smoke UI OK');
})().catch(e => { console.error(e); process.exit(1); });
