// Régressions de la messagerie (relecture finale du lot 4b). Prérequis : serveur lancé, deux comptes.
// Usage : KB_URL=... KB_EMAIL=... KB_PASSWORD=... KB_DEMO_EMAIL=... KB_DEMO_PASSWORD=... node tests/e2e/chat-regressions.js
const { chromium, devices } = require('playwright');
const assert = require('assert');
const fs = require('fs'), os = require('os'), path = require('path');
const { execFileSync } = require('child_process');

const base = process.env.KB_URL || 'http://127.0.0.1:8080';

async function login(browser, email, password, options = { viewport: { width: 1366, height: 820 } }) {
  const ctx = await browser.newContext(options);
  const page = await ctx.newPage();
  page.on('pageerror', e => { throw new Error('Erreur JS : ' + e.message); });
  await page.goto(base + '/login');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('button[type=submit]');
  await page.waitForURL(base + '/');
  return page;
}

async function openMenu(page, li) {
  await li.hover();
  await li.locator('[data-msg-actions]').click();
}

(async () => {
  if (!process.env.KB_DEMO_EMAIL) { console.log('KB_DEMO_EMAIL absent : régressions ignorées.'); return; }
  const browser = await chromium.launch({ channel: 'chromium' });
  const a = await login(browser, process.env.KB_EMAIL, process.env.KB_PASSWORD);
  const bId = execFileSync('php', ['-r', `require 'vendor/autoload.php'; $a = App\\Core\\App::boot(getcwd());
    echo (int) $a->db()->value('SELECT id FROM users WHERE email = ?', [getenv('KB_DEMO_EMAIL')]);`]).toString().trim();
  await a.goto(base + '/chat/with/' + bId);
  const url = a.url();
  const pdf = path.join(os.tmpdir(), 'kb-regression.pdf');
  fs.writeFileSync(pdf, '%PDF-1.4\n' + 'x'.repeat(4000) + '\n%%EOF\n');
  const stamp = Date.now();

  // 1. Retirer un fichier pendant son envoi réactive « Envoyer ».
  await a.route('**/attachments', () => { /* jamais de réponse : envoi « en cours » */ });
  await a.setInputFiles('[data-chat-file]', pdf);
  await a.locator('.kb-tray-item.is-uploading').waitFor();
  assert.ok(await a.locator('.kb-chat-send').isDisabled(), 'Envoyer désactivé pendant l\'envoi');
  await a.locator('.kb-tray-remove').click();
  assert.ok(!(await a.locator('.kb-chat-send').isDisabled()), '1. Envoyer réactivé après retrait du fichier');
  await a.unroute('**/attachments');

  // 3. Un fichier en échec bloque l'envoi au lieu de disparaître.
  await a.route('**/attachments', r => r.abort());
  await a.setInputFiles('[data-chat-file]', pdf);
  await a.locator('.kb-tray-item.is-failed').waitFor();
  await a.fill('#chat-body', 'Avec pièce en échec ' + stamp);
  await a.keyboard.press('Enter');
  await a.waitForTimeout(500);
  assert.strictEqual(await a.locator('.kb-msg', { hasText: 'Avec pièce en échec ' + stamp }).count(), 0, '3. message non parti');
  assert.ok(await a.locator('.kb-tray-item.is-failed').count() === 1, '3. pièce en échec conservée');
  assert.match(await a.locator('[data-chat-form] [role=alert]').last().innerText(), /pièce jointe/i, '3. message d\'erreur');
  await a.unroute('**/attachments');
  await a.locator('.kb-tray-remove').click();
  await a.fill('#chat-body', '');

  // 13. La progression d'envoi est annoncée (zone lue par les lecteurs d'écran).
  await a.setInputFiles('[data-chat-file]', pdf);
  await a.locator('.kb-tray-item.is-done').waitFor();
  assert.match(await a.locator('[data-chat-tray-status]').innerText(), /envoyé/i, '13. annonce de fin d\'envoi');
  await a.locator('.kb-tray-remove').click();

  // 4. Modifier un message envoyé depuis la page garde ses retours à la ligne.
  await a.fill('#chat-body', '');
  await a.locator('#chat-body').type('Ligne 1 ' + stamp);
  await a.keyboard.press('Shift+Enter');
  await a.locator('#chat-body').type('Ligne 2');
  await a.keyboard.press('Enter');
  const multi = a.locator('.kb-msg.is-mine', { hasText: 'Ligne 1 ' + stamp });
  await multi.locator('.kb-msg-time', { hasNotText: 'Envoi' }).waitFor();
  await openMenu(a, multi);
  await a.locator('.kb-msg-menu [data-menu="edit"]').click();
  assert.strictEqual(await a.inputValue('#chat-body'), 'Ligne 1 ' + stamp + '\nLigne 2', '4. retours à la ligne conservés en modification');

  // 6. Passer de « Modifier » à « Répondre » ne garde pas le texte modifié.
  await openMenu(a, multi);
  await a.locator('.kb-msg-menu [data-menu="reply"]').click();
  assert.strictEqual(await a.inputValue('#chat-body'), '', '6. zone de saisie rendue au brouillon (vide)');
  await a.locator('[data-chat-banner-cancel]').click();

  // 2. Après rechargement, un message déjà arrivé n'est pas remis dans la zone de saisie.
  const lost = 'Arrivé quand même ' + stamp;
  await a.route('**/api/chat/updates*', r => r.abort());
  await a.route('**/messages', async r => {
    const req = r.request();
    await fetch(req.url(), { method: 'POST', headers: await req.allHeaders(), body: req.postDataBuffer() });
    await r.abort();
  });
  await a.fill('#chat-body', lost);
  await a.keyboard.press('Enter');
  await a.locator('.kb-msg-retry').waitFor();
  await a.unroute('**/messages');
  await a.unroute('**/api/chat/updates*');
  await a.reload();
  await a.locator('.kb-msg', { hasText: lost }).waitFor();
  assert.strictEqual(await a.inputValue('#chat-body'), '', '2. message déjà arrivé non remis dans la saisie');
  await a.fill('#chat-body', 'Nouveau ' + stamp);
  await a.keyboard.press('Enter');
  await a.locator('.kb-msg.is-mine:not(.is-pending)', { hasText: 'Nouveau ' + stamp }).waitFor({ timeout: 8000 });

  // 5. Sur téléphone, le bouton « Actions » est visible (un appui long sur une photo ouvre le menu du système).
  const phone = await login(browser, process.env.KB_EMAIL, process.env.KB_PASSWORD, devices['iPhone 13']);
  await phone.goto(url);
  const btn = phone.locator('.kb-msg [data-msg-actions]').last();
  assert.ok(await btn.isVisible(), '5. bouton Actions visible sur téléphone');
  const box = await btn.boundingBox();
  assert.ok(box.height >= 44 && box.width >= 44, '5. cible de 44 px');
  await btn.click();
  await phone.locator('.kb-msg-menu').waitFor();

  await browser.close();
  console.log('Régressions messagerie OK');
})().catch(e => { console.error(e); process.exit(1); });
