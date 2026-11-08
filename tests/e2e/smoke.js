// Test de fumée UI (Playwright). Prérequis : serveur lancé, ex.
//   php -S 127.0.0.1:8080 -t public tests/e2e/router.php
// Usage : KB_URL=http://127.0.0.1:8080 KB_EMAIL=... KB_PASSWORD=... node tests/e2e/smoke.js
const { chromium, devices } = require('playwright');
const assert = require('assert');
const { execFileSync } = require('child_process');


// Cibles tactiles visibles < 44 px de haut (cases à cocher couvertes par leur ligne .kb-check-row ;
// boutons radio .btn-check masqués, la cible est leur libellé .btn).
async function smallTargets(p) {
  return p.evaluate(() => [...document.querySelectorAll('button, .btn, input:not([type=hidden]):not([type=checkbox]):not(.btn-check), select, .kb-check-row')]
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

// Contraste non textuel (WCAG 1.4.11) : bordure d'un contrôle contre le fond de son conteneur.
async function minBorderContrast(p, selector) {
  return p.evaluate(sel => {
    const rgb = c => (c.match(/[\d.]+/g) || []).map(Number);
    const lum = ([r, g, b]) => [r, g, b].map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; })
      .reduce((a, v, i) => a + v * [0.2126, 0.7152, 0.0722][i], 0);
    let worst = 21;
    for (const el of document.querySelectorAll(sel)) {
      if (!el.getClientRects().length) continue;
      let bg = el.parentElement; while (bg && (rgb(getComputedStyle(bg).backgroundColor)[3] === 0 || getComputedStyle(bg).backgroundColor === 'transparent')) bg = bg.parentElement;
      const l1 = lum(rgb(getComputedStyle(el).borderTopColor)), l2 = lum(rgb(bg ? getComputedStyle(bg).backgroundColor : 'rgb(255,255,255)'));
      worst = Math.min(worst, (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05));
    }
    return worst;
  }, selector);
}

(async () => {
  const base = process.env.KB_URL || 'http://127.0.0.1:8080';
  // Chromium complet (et non le « headless shell ») : seul capable d'afficher les notifications.
  const browser = await chromium.launch({ channel: 'chromium' });
  const desktop = await browser.newContext({ viewport: { width: 1366, height: 820 } });
  await desktop.grantPermissions(['notifications'], { origin: base });
  const page = await desktop.newPage();
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
  // Tâche 4 : pas de double envoi sur réseau lent
  await mobile.route('**/login', r => r.request().method() === 'POST' ? setTimeout(() => r.continue(), 800) : r.continue());
  // noWaitAfter : vérifier le bouton de CETTE page, avant que la navigation n'aboutisse.
  await mobile.click('button[type=submit]', { noWaitAfter: true });
  assert.ok(await mobile.locator('button[type=submit]').isDisabled(), 'bouton désactivé pendant l\'envoi');
  await mobile.waitForURL(base + '/');
  await mobile.unroute('**/login');
  const vw = mobile.viewportSize().width;
  for (const path of ['/admin/users', '/admin/users/create', '/profile']) {
    await mobile.goto(base + path);
    assert.deepStrictEqual(await smallTargets(mobile), [], `cibles >= 44 px sur ${path}`);
  }
  await mobile.goto(base + '/admin/users/create');
  const borders = await minBorderContrast(mobile, '.form-control, .form-select, .form-check-input');
  assert.ok(borders >= 3, `bordures des champs et cases >= 3:1 (${borders.toFixed(2)})`);
  assert.ok(await minContrast(mobile, '.kb-nav-title') >= 4.5, 'contraste des titres du menu');
  assert.ok(await minContrast(mobile, '.text-secondary') >= 4.5, 'contraste du texte secondaire');
  await mobile.goto(base + '/admin/audit?action=auth.login_failed');
  assert.ok(await mobile.locator('.badge-alert').count() > 0, 'badge d\'alerte présent');
  assert.ok(await minContrast(mobile, '.badge-alert') >= 4.5, 'contraste du badge d\'alerte');
  // Lot 2 : annuaire sur téléphone — filtre instantané (accents ignorés), cibles tactiles, statut du jour.
  await mobile.goto(base + '/directory');
  const before = await mobile.locator('.kb-person:visible').count();
  await mobile.fill('[data-directory-filter]', 'keita');
  const after = await mobile.locator('.kb-person:visible').count();
  assert.ok(before > 1 && after === 1, `filtre instantané (${before} → ${after})`);
  assert.match(await mobile.locator('.kb-person:visible').innerText(), /Keïta/);
  for (const path of ['/directory', '/directory/org', '/directory/1']) {
    await mobile.goto(base + path);
    assert.deepStrictEqual(await smallTargets(mobile), [], `cibles >= 44 px sur ${path}`);
  }
  await mobile.goto(base + '/');
  await mobile.click('label[for=home-st-office]');
  await Promise.all([mobile.waitForNavigation(), mobile.click('button:has-text("Enregistrer le statut")')]);
  await mobile.goto(base + '/directory');
  const me = mobile.locator('.kb-person', { hasText: 'Pape Dimba' });
  assert.match(await me.innerText(), /Au bureau/, 'statut visible dans l\'annuaire');

  // Tâche 1 : le statut des utilisateurs reste visible sur téléphone
  await mobile.goto(base + '/admin/users');
  const status = mobile.locator('tbody .badge').first();
  const st = await status.boundingBox();
  assert.ok(st && st.x >= 0 && st.x + st.width <= vw, `statut visible sur mobile (${st && st.x + st.width} > ${vw})`);

  // Tâche 4 : confirmation nommée dans une fenêtre de l'application ; Annuler n'envoie rien
  await page.goto(base + '/admin/users?q=mariam');
  await page.click('text=Cissé Mariam');
  await page.click('button:has-text("Désactiver")');
  const dialog = page.locator('#kb-confirm');
  await dialog.waitFor({ state: 'visible' });
  assert.match(await dialog.innerText(), /Mariam Cissé/);
  await dialog.locator('button:has-text("Annuler")').click();
  await dialog.waitFor({ state: 'hidden' });
  await page.reload();
  assert.ok(await page.locator('button:has-text("Désactiver")').isVisible(), 'compte toujours actif après Annuler');
  await page.click('button:has-text("Lien de réinitialisation")');
  await dialog.waitFor({ state: 'visible' });
  // La confirmation ne vaut qu'une fois (retour arrière depuis le cache du navigateur).
  await page.route('**/reset-link', r => setTimeout(() => r.continue(), 3000));
  await dialog.locator('[data-kb-confirm-ok]').click({ noWaitAfter: true });
  assert.strictEqual(await page.evaluate(() => document.querySelector('form[action$="/reset-link"]').dataset.confirmed), undefined, 'confirmation consommée');
  await page.waitForNavigation();
  await page.unroute('**/reset-link');
  assert.match(await page.locator('.alert').first().innerText(), /réinitialisation/, 'Confirmer envoie le formulaire');

  // Lot 3 : la cloche se met à jour sans rechargement et l'alerte passe par le service worker.
  await page.goto(base + '/');
  await page.waitForFunction(async () => !!(await navigator.serviceWorker.getRegistration()), null, { timeout: 10000 });
  const title = 'Smoke ' + Date.now();
  execFileSync('php', ['-r', `require 'vendor/autoload.php'; $a = App\\Core\\App::boot(getcwd());
    $id = (int) $a->db()->value('SELECT id FROM users WHERE email = ?', [getenv('KB_EMAIL')]);
    $a->notifier()->notify($id, 'presence.changed', getenv('KB_TITLE'), '/profile');`], { env: { ...process.env, KB_TITLE: title } });
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await page.waitForFunction(() => !document.querySelector('[data-unread-badge]').hidden, null, { timeout: 10000 });
  // waitForFunction ne sait pas attendre une condition asynchrone : boucle explicite.
  let shown = false;
  for (let i = 0; i < 20 && !shown; i++) {
    shown = await page.evaluate(async t => (await (await navigator.serviceWorker.ready).getNotifications()).some(n => n.body.includes(t)), title);
    if (!shown) await page.waitForTimeout(500);
  }
  assert.ok(shown, 'alerte affichée par le service worker');

  assert.deepStrictEqual(problems, [], 'aucune erreur console (CSP incluse)');
  await browser.close();
  console.log('Smoke UI OK');
})().catch(e => { console.error(e); process.exit(1); });
