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

  // Lot 4a : message reçu sans rechargement, brouillon conservé.
  if (process.env.KB_DEMO_EMAIL && process.env.KB_DEMO_PASSWORD) {
    const other = await browser.newContext({ viewport: { width: 1366, height: 820 } });
    const pageB = await other.newPage();
    pageB.on('pageerror', e => problems.push('B: ' + e.message));
    await pageB.goto(base + '/login');
    await pageB.fill('#email', process.env.KB_DEMO_EMAIL);
    await pageB.fill('#password', process.env.KB_DEMO_PASSWORD);
    await pageB.click('button[type=submit]');
    await pageB.waitForURL(base + '/');
    // A ouvre la conversation privée avec B.
    const bUserId = execFileSync('php', ['-r', `require 'vendor/autoload.php'; $a = App\\Core\\App::boot(getcwd());
      echo (int) $a->db()->value('SELECT id FROM users WHERE email = ?', [getenv('KB_DEMO_EMAIL')]);`]).toString().trim();
    await page.goto(base + '/chat/with/' + bUserId);
    const conversationUrl = page.url();
    // Un message reçu (défilement automatique compris) n'est pas une interaction : accusé de lecture passif.
    const reads = [];
    page.on('request', r => { if (r.url().endsWith('/read')) reads.push(r.postData() || ''); });
    await pageB.goto(conversationUrl);
    const text = 'Smoke ' + Date.now();
    await pageB.fill('#chat-body', text);
    await pageB.keyboard.press('Enter');
    await pageB.locator('.kb-msg.is-mine:not(.is-pending)', { hasText: text }).waitFor({ timeout: 5000 });
    await page.locator('.kb-msg:not(.is-mine)', { hasText: text }).waitFor({ timeout: 5000 });
    assert.strictEqual(await pageB.inputValue('#chat-body'), '', 'zone de saisie vidée après envoi');
    await page.waitForTimeout(800);
    assert.ok(reads.length > 0 && reads.every(d => d.includes('active=0')), 'accusé de lecture passif sans interaction : ' + reads.join(' | '));
    // Envoi sans réponse puis rechargement : le texte revient dans la zone de saisie.
    await pageB.route('**/messages', r => r.abort());
    await pageB.fill('#chat-body', 'Perdu ? ' + text);
    await pageB.keyboard.press('Enter');
    await pageB.locator('.kb-msg-retry').waitFor({ timeout: 5000 });
    await pageB.unroute('**/messages');
    await pageB.reload();
    assert.strictEqual(await pageB.inputValue('#chat-body'), 'Perdu ? ' + text, 'texte non confirmé retrouvé après rechargement');
    // Session expirée : message clair, texte conservé.
    await other.clearCookies();
    await pageB.locator('.kb-chat-send').click();
    await pageB.locator('[data-chat-expired]').waitFor({ timeout: 5000 });
    assert.strictEqual(await pageB.inputValue('#chat-body'), 'Perdu ? ' + text, 'texte conservé quand la session a expiré');
    await page.fill('#chat-body', 'Brouillon ' + text);
    await page.reload();
    assert.strictEqual(await page.inputValue('#chat-body'), 'Brouillon ' + text, 'brouillon retrouvé après rechargement');
    await page.fill('#chat-body', '');
    await page.locator('#chat-body').dispatchEvent('input');
    // Lot 4b : photo réduite avant envoi, réponse, réaction, modification, suppression, pas de doublon.
    // B a été déconnecté par le contrôle précédent : nouvelle connexion.
    await pageB.goto(base + '/login');
    await pageB.fill('#email', process.env.KB_DEMO_EMAIL);
    await pageB.fill('#password', process.env.KB_DEMO_PASSWORD);
    await pageB.click('button[type=submit]');
    await pageB.waitForURL(base + '/');
    await pageB.goto(conversationUrl);
    await pageB.fill('#chat-body', '');
    await pageB.locator('#chat-body').dispatchEvent('input');
    await page.goto(conversationUrl);
    const bigPhoto = require('path').join(require('os').tmpdir(), 'kb-smoke-photo.jpg');
    execFileSync('php', ['-r', `$i = imagecreatetruecolor(4000, 3000); for ($k = 0; $k < 300; $k++) imagefilledellipse($i, rand(0, 4000), rand(0, 3000), rand(50, 600), rand(50, 600), imagecolorallocate($i, rand(0, 255), rand(0, 255), rand(0, 255))); imagejpeg($i, getenv('KB_PHOTO'), 95);`], { env: { ...process.env, KB_PHOTO: bigPhoto } });
    const originalSize = require('fs').statSync(bigPhoto).size;
    await pageB.setInputFiles('[data-chat-file]', bigPhoto);
    await pageB.locator('.kb-tray-item.is-done').waitFor({ timeout: 15000 });
    const uploaded = parseInt(await pageB.getAttribute('.kb-tray-item.is-done', 'data-bytes'), 10);
    assert.ok(uploaded > 0 && uploaded < originalSize, `photo réduite avant envoi (${uploaded} < ${originalSize})`);
    const photoText = 'Photo ' + Date.now();
    await pageB.fill('#chat-body', photoText);
    await pageB.keyboard.press('Enter');
    await page.locator('.kb-msg', { hasText: photoText }).locator('.kb-msg-photo img').waitFor({ timeout: 8000 });
    // A répond au message de B.
    const msgOfB = page.locator('.kb-msg', { hasText: photoText });
    await msgOfB.hover();
    await msgOfB.locator('[data-msg-actions]').click();
    await page.locator('.kb-msg-menu [data-menu="reply"]').click();
    const replyText = 'Réponse ' + Date.now();
    await page.fill('#chat-body', replyText);
    await page.keyboard.press('Enter');
    await pageB.locator('.kb-msg', { hasText: replyText }).locator('.kb-msg-reply', { hasText: photoText }).waitFor({ timeout: 8000 });
    // B réagit 👍 au message de A, puis modifie son propre message.
    const msgOfA = pageB.locator('.kb-msg', { hasText: replyText });
    await msgOfA.hover();
    await msgOfA.locator('[data-msg-actions]').click();
    await pageB.locator('.kb-msg-menu [data-menu-react="👍"]').click();
    await page.locator('.kb-msg', { hasText: replyText }).locator('.kb-reaction[data-react="👍"]').waitFor({ timeout: 8000 });
    const mine = pageB.locator('.kb-msg.is-mine', { hasText: photoText });
    await mine.hover();
    await mine.locator('[data-msg-actions]').click();
    await pageB.locator('.kb-msg-menu [data-menu="edit"]').click();
    await pageB.fill('#chat-body', photoText + ' (corrigé)');
    await pageB.keyboard.press('Enter');
    await page.locator('.kb-msg', { hasText: photoText + ' (corrigé)' }).locator('.kb-msg-edited').waitFor({ timeout: 8000 });
    // A supprime sa réponse.
    const own = page.locator('.kb-msg.is-mine', { hasText: replyText });
    await own.hover();
    await own.locator('[data-msg-actions]').click();
    await page.locator('.kb-msg-menu [data-menu="delete"]').click();
    await page.locator('#kb-confirm [data-kb-confirm-ok]').click();
    // Chez B, la bulle reste mais son texte est remplacé par « Message supprimé ».
    await pageB.locator('.kb-msg', { hasText: replyText }).waitFor({ state: 'detached', timeout: 8000 });
    assert.ok(await pageB.locator('.kb-msg[data-deleted="1"] .kb-msg-deleted').count() > 0, 'mention « Message supprimé » chez l\'autre');
    // Réponse perdue puis « Réessayer » : un seul message (même client_id).
    const once = 'Une fois ' + Date.now();
    // La 1re requête atteint le serveur mais sa réponse est perdue.
    let calls = 0;
    await pageB.route('**/messages', async r => {
      calls++;
      if (calls > 1) { await r.continue(); return; }
      // Le message part réellement vers le serveur (fetch de Node, sans proxy), mais la réponse ne revient pas au navigateur.
      const req = r.request();
      await fetch(req.url(), { method: 'POST', headers: await req.allHeaders(), body: req.postDataBuffer() });
      await r.abort();
    });
    await pageB.fill('#chat-body', once);
    await pageB.keyboard.press('Enter');
    // Deux issues correctes : la bulle « Non envoyé » est retirée par l'interrogation suivante (le serveur a le message),
    // ou l'on clique « Réessayer » et le serveur renvoie le message existant (même client_id).
    await pageB.locator('.kb-msg-retry').click({ timeout: 1500 }).catch(() => {});
    await pageB.locator('.kb-msg.is-mine:not(.is-pending):not(.is-failed)', { hasText: once }).waitFor({ timeout: 8000 });
    await pageB.unroute('**/messages');
    await page.locator('.kb-msg', { hasText: once }).first().waitFor({ timeout: 8000 });
    await page.waitForTimeout(4000);
    assert.strictEqual(await page.locator('.kb-msg', { hasText: once }).count(), 1, 'un seul message après Réessayer');
    await other.close();
  } else {
    console.log('Messagerie à deux comptes ignorée : KB_DEMO_EMAIL / KB_DEMO_PASSWORD absents.');
  }

  assert.deepStrictEqual(problems, [], 'aucune erreur console (CSP incluse)');
  await browser.close();
  console.log('Smoke UI OK');
})().catch(e => { console.error(e); process.exit(1); });
