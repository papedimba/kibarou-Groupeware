# Corrections UX du lot 1 — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** corriger les 5 problèmes prioritaires de la critique Impeccable du lot 1 (23/40), mobile et accessibilité d'abord, ton neutre et sobre.

**Architecture :** aucun changement de couche. Correctifs dans les vues (`app/Views`), la feuille `public/assets/css/app.css`, le script `public/assets/js/app.js` (CSP stricte : aucun script ni style inline), deux petites fonctions de présentation dans `app/Core/helpers.php` et une table de libellés d'audit. Rendu serveur conservé.

**Tech Stack :** PHP 8.2 natif, Bootstrap 5.3.3, Lucide, JS natif, PHPUnit 11 + MariaDB, Playwright 1.63.

**Spec :** `.impeccable/critique/2026-11-07T11-14-33Z__app-views.md` (critique) et `PRODUCT.md` (principes : évident pour un non-technicien, téléphone de chantier = PC, confiance visible, sobre). Choix utilisateur : les 5 problèmes, priorité mobile/accessibilité, ton neutre.

## Global Constraints

- Aucun `<script>` inline, aucun attribut `style=` (CSP `script-src 'self'; style-src 'self'`).
- Textes en français ; aucun identifiant technique (slug, code d'action, code de permission, JSON) visible par défaut.
- WCAG 2.1 AA : contraste texte ≥ 4.5:1 ; cibles tactiles ≥ 44×44 px sous 992 px de large pour boutons, champs, cases à cocher et liens d'action.
- Texte courant ≥ 16 px sous 992 px.
- Police Inter et palette du cahier des charges conservées (bleu nuit, blanc, gris clair, bleu, orange pour les alertes).
- Pronom neutre dans les messages sur une personne (« cette personne »), jamais deviné d'après le prénom.
- Tous les tests existants restent verts : `vendor/bin/phpunit`, `node tests/e2e/smoke.js`.

## Review Focus

1. **Double envoi sur réseau lent** : un second clic sur un bouton d'envoi ne renvoie pas le formulaire → test Playwright dans la tâche 4.
2. **Rechargement après création avec mot de passe temporaire** : le récapitulatif s'affiche une seule fois et le mot de passe n'est stocké nulle part en clair (ni session, ni flash) → test d'intégration dans la tâche 4.
3. **Action d'audit inconnue** (ancienne ligne, futur module) : affichage du code brut en repli, sans erreur → test unitaire dans la tâche 3.
4. **Utilisateur sans service, fonction ni responsable** sur l'accueil : affiche « Non renseigné », pas de case vide ni d'erreur → test d'intégration dans la tâche 5.
5. **JavaScript indisponible** : les formulaires de confirmation s'envoient tout de même (la fenêtre de confirmation est une amélioration) → vérifié par construction dans la tâche 4 (le formulaire reste un vrai `<form method="post">`).

---

### Task 1 : tableaux lisibles sur mobile (P0)

**Files :**
- Modify : `public/assets/css/app.css` (classe `.kb-table-stack`)
- Modify : `app/Views/admin/users/index.php`, `app/Views/admin/_audit_table.php`, `app/Views/profile/show.php` (ajout `class="kb-table-stack"` sur `<table>` et `data-label="…"` sur chaque `<td>`)
- Modify : `app/Core/helpers.php` (`device_label`)
- Test : `tests/Unit/HelpersTest.php`, `tests/e2e/smoke.js`

**Interfaces :**
- Produces : `device_label(string $userAgent): string` — « iPhone · Safari », « Android · Chrome », « Windows · Edge », « Mac · Safari », « Linux · Firefox » ; repli « Appareil inconnu ».
- Produces : classe CSS `.kb-table-stack` : sous 768 px, `thead` masqué, chaque `tr` devient une carte, chaque `td` affiche son `data-label` via `::before`.

- [ ] **Step 1 : tests en échec**
  - `HelpersTest::testDeviceLabel` : `device_label('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')` === `'iPhone · Safari'` ; un UA Chrome Android === `'Android · Chrome'` ; un UA Edge Windows === `'Windows · Edge'` ; `''` === `'Appareil inconnu'`.
  - `smoke.js` : sur iPhone 13, `/admin/users` → le texte « Actif » (statut) est visible dans le viewport sans défilement horizontal ; `/profile` à 1366 px → le bouton « Fermer » ou le badge « Cette session » a `boundingBox.x + width <= 1366`.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter testDeviceLabel` → FAIL (fonction indéfinie).
- [ ] **Step 3 :** implémenter `device_label` (détection par `str_contains`, Edge avant Chrome avant Safari), la classe CSS, les attributs `data-label`, et `device_label()` à la place de l'UA brut dans `profile/show.php` et `admin/users/show.php`.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; serveur lancé puis `node tests/e2e/smoke.js` → « Smoke UI OK ».
- [ ] **Step 5 :** commit « Tableaux en cartes sur mobile, appareils lisibles ».

### Task 2 : cibles tactiles, typographie et contrastes (P1)

**Files :**
- Modify : `public/assets/css/app.css`
- Modify : `app/Views/layouts/app.php` (titre de page dans l'en-tête mobile, bouton menu 44×44)
- Modify : `app/Views/admin/users/form.php`, `app/Views/admin/roles/form.php` (cases dans des lignes `label` cliquables)
- Test : `tests/e2e/smoke.js`

**Valeurs fixées :**
- `.kb-nav-title` : `#94a3b8` sur `#0f1f3d` (≈ 6.9:1). `.kb-brand small` sur fond blanc (page de connexion) : `var(--kb-gray-500)`.
- `--kb-gray-500` et `.text-secondary` / `--bs-secondary-color` : `#556275` (≥ 4.5:1 sur `#f5f7fb`).
- `.btn-outline-primary` couleur `var(--kb-blue)` ; `.btn-outline-danger` couleur `#b42332` ; `.kb-avatar` texte `#1d4ed8`.
- Sous 992 px : `body` 1rem ; `.btn, .form-control, .form-select` `min-height: 44px` ; `.form-check-input` 1.25rem ; `.kb-check-row` (ligne de case cliquable) `min-height: 44px`.

- [ ] **Step 1 : test en échec** — `smoke.js` ajoute un contrôle sur iPhone 13 pour `/login`, `/admin/users`, `/admin/users/create`, `/profile` : tout élément visible `button, .btn, input:not([type=hidden]), select, .kb-check-row` a une hauteur ≥ 44 ; tout `.kb-nav-title` et `.kb-brand small` a un contraste ≥ 4.5 (calcul depuis `getComputedStyle`, fond du premier ancêtre opaque).
- [ ] **Step 2 :** lancer `smoke.js` → FAIL (boutons 38 px, contraste 3.4:1).
- [ ] **Step 3 :** appliquer les valeurs ci-dessus ; afficher `$title` dans `.kb-header` sous 992 px (le fil d'Ariane reste au-delà).
- [ ] **Step 4 :** `smoke.js` → OK ; `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Cibles tactiles 44 px, texte 16 px, contrastes AA ».

### Task 3 : journal d'audit en français (P1)

**Files :**
- Create : `app/Modules/Admin/AuditLabels.php`
- Modify : `app/Modules/Admin/AdminController.php` (jointure du nom de la cible, filtre par action exacte), `app/Modules/Users/UsersController.php` (historique : même jointure), `app/Views/admin/_audit_table.php`, `app/Views/admin/audit.php`, `app/Views/admin/roles/index.php` (colonne « Identifiant » retirée), `app/Views/admin/roles/form.php` (codes de permission retirés)
- Test : `tests/Unit/AuditLabelsTest.php`, `tests/Integration/AdminTest.php`

**Interfaces :**
- Produces : `AuditLabels::label(string $action): string` (repli : l'action brute) ; `AuditLabels::isAlert(string $action): bool` (vrai pour `auth.login_failed`, `auth.login_disabled`, `auth.throttled`, `user.disabled`, `user.sessions_revoked`) ; `AuditLabels::all(): array<string,string>` pour la liste déroulante ; `AuditLabels::details(?string $json): list<array{0:string,1:string}>` — paires libellé/valeur lisibles (`email` → « Email », `count` → « Nombre », `changes` → « Champs modifiés » joints par virgule ; clés `roles*`/`permissions*` omises).
- Libellés : auth.login « Connexion », auth.login_failed « Échec de connexion », auth.login_disabled « Connexion refusée (compte désactivé) », auth.throttled « Connexion bloquée (trop de tentatives) », auth.logout « Déconnexion », auth.password_changed « Mot de passe modifié », auth.reset_requested « Réinitialisation demandée », auth.reset_completed « Mot de passe réinitialisé », auth.invitation_sent « Lien d'activation envoyé », install.super_admin_created « Installation : super administrateur créé », profile.updated « Profil modifié », profile.session_revoked « Session fermée par l'utilisateur », user.created « Compte créé », user.updated « Compte modifié », user.enabled « Compte activé », user.disabled « Compte désactivé », user.reset_link_sent « Lien de réinitialisation envoyé », user.sessions_revoked « Sessions fermées », role.saved « Rôle enregistré », role.deleted « Rôle supprimé », department.created « Service créé », department.updated « Service modifié », position.created « Fonction créée », position.updated « Fonction modifiée ».

- [ ] **Step 1 : tests en échec**
  - `AuditLabelsTest::testEveryLoggedActionHasALabel` : extrait par regex de `app/**/*.php` toutes les chaînes d'action passées à `->log(` (plus `auth.reset_requested`, `auth.invitation_sent`, `user.enabled`, `user.disabled`, et `department|position` × `created|updated`) ; chacune `!== AuditLabels::label($a)`.
  - `testUnknownActionFallsBack` : `label('futur.x') === 'futur.x'`, `details(null) === []`, `details('{"email":"a@b.c","roles":[5]}') === [['Email','a@b.c']]`.
  - `AdminTest::testAuditLogIsReadable` : après une connexion, `/admin/audit` contient « Connexion » et le nom de l'utilisateur, ne contient pas `auth.login</code>` ni `{&quot;`.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter Audit` → FAIL.
- [ ] **Step 3 :** implémenter `AuditLabels` ; requêtes d'audit : `LEFT JOIN users t ON a.entity_type = 'user' AND t.id = a.entity_id` → `target_name` ; table : date, auteur, action (libellé, badge orange si `isAlert`), cible (lien vers `/admin/users/{id}` si `users.view`), détails ; filtre `<select name="action">` alimenté par `AuditLabels::all()` (égalité exacte côté SQL) ; IP dans les détails seulement.
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Journal d'audit lisible en français ».

### Task 4 : création de compte et actions sensibles sûres (P1)

**Files :**
- Modify : `app/Views/admin/users/form.php`, `app/Modules/Users/UsersController.php` (`store` rend un récapitulatif), Create : `app/Views/admin/users/handover.php`
- Modify : `app/Views/admin/users/index.php`, `app/Views/admin/users/show.php` (statut réel, confirmations nommées)
- Modify : `app/Views/layouts/app.php` (fenêtre `#kb-confirm` Bootstrap), `app/Views/auth/login.php`, `app/Views/auth/reset.php`, `app/Views/profile/password.php` (bouton « Afficher »), `public/assets/js/app.js`
- Modify : `app/Core/helpers.php` (`user_status`)
- Test : `tests/Integration/AdminTest.php`, `tests/Unit/HelpersTest.php`, `tests/e2e/smoke.js`

**Interfaces :**
- Produces : `user_status(array $user): array{0:string,1:string}` → `['Désactivé','secondary']` si `status = disabled`, `['Pas encore connecté','warning']` si `last_login_at` nul, sinon `['Actif','success']`.
- JS (attributs) : `form[data-confirm]` ouvre `#kb-confirm` avec le texte, « Confirmer » soumet, « Annuler »/Échap ferme ; tout `form` soumis met `disabled` + `aria-busy="true"` sur son bouton `[type=submit]` et ignore un second envoi ; `[data-toggle-password="<id>"]` bascule `type` du champ et `aria-pressed` ; `[data-generate-password="<id>"]` remplit le champ avec 14 caractères via `crypto.getRandomValues` (lettres + chiffres, au moins un de chaque).

- [ ] **Step 1 : tests en échec**
  - `HelpersTest::testUserStatus` : les trois cas ci-dessus.
  - `AdminTest::testTempPasswordHandoverShownOnce` : POST création avec `temp_password` → statut 200, corps contient l'email, le mot de passe et `https://gw.test/login` ; la table `sessions` ne contient pas le mot de passe (`SELECT COUNT(*) FROM sessions WHERE data LIKE ?`) ; GET `/admin/users/{id}` ne contient pas le mot de passe.
  - `AdminTest::testCreateFormDefaultsToCollaborateur` : `/admin/users/create` → la case du rôle `collaborateur` est `checked`.
  - `smoke.js` : sur une fiche utilisateur, clic « Désactiver » → la fenêtre `#kb-confirm` est visible et contient le nom ; « Annuler » → l'utilisateur est toujours actif. Formulaire de connexion : après `click` sur Envoyer, le bouton est `disabled`.
- [ ] **Step 2 :** lancer → FAIL.
- [ ] **Step 3 :** implémenter. Formulaire : mot de passe temporaire `type="text"`, `autocomplete="off"`, bouton « Générer » ; rôles en `label.kb-check-row` ; avertissement sous « Super administrateur » (« Accès complet à toute l'application. ») ; texte de confirmation de désactivation : « Désactiver le compte de {Prénom Nom} ? Cette personne sera déconnectée immédiatement. Vous pourrez réactiver le compte. ».
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS ; `smoke.js` → OK.
- [ ] **Step 5 :** commit « Création de compte sûre, confirmations nommées, anti double envoi ».

### Task 5 : accueil utile et erreurs dans l'interface (P1)

**Files :**
- Modify : `app/Modules/Dashboard/DashboardController.php`, `app/Views/dashboard/index.php`
- Modify : `app/Views/layouts/app.php` (8 entrées « bientôt » retirées)
- Modify : `app/Core/ErrorHandler.php`, Create : `app/Views/errors/app_error.php`
- Test : `tests/Integration/DashboardTest.php`, `tests/Integration/AdminTest.php`

**Interfaces :**
- Consumes : `user_status()` (tâche 4) pour l'alerte « comptes pas encore connectés ».
- Produces : vue `errors/app_error` (variables `status`, `message`, `contacts`), rendue par `ErrorHandler` quand un utilisateur est connecté et que le statut est 403 ou 404 ; `contacts` = utilisateurs actifs ayant le rôle `admin` ou `super_admin` (nom + email).

- [ ] **Step 1 : tests en échec** (`DashboardTest`)
  - `testDashboardShowsMyInformation` : utilisateur avec service « Travaux » et responsable « Diallo Awa » → l'accueil contient « Travaux » et « Awa Diallo » ; utilisateur sans service → « Non renseigné ».
  - `testDashboardShowsAdminContact` : un admin actif existe → l'accueil d'un collaborateur contient son email.
  - `testNavHasNoComingSoonLinks` : l'accueil ne contient pas `aria-disabled="true"` ; contient « Bientôt » une seule fois (ligne récapitulative).
  - `testForbiddenStaysInsideApp` : un directeur sur `/admin` → statut 403, corps contient « Tableau de bord » (navigation) et l'email d'un administrateur.
  - `testAdminDashboardShortcuts` : un admin voit les liens « Nouvel utilisateur » et « Journal d'audit » sur l'accueil.
- [ ] **Step 2 :** `vendor/bin/phpunit --filter Dashboard` → FAIL.
- [ ] **Step 3 :** implémenter : bloc « Mon espace » (service, fonction, responsable, dernière connexion, lien « Mes sessions »), bloc « Besoin d'aide ? » (contacts), pour les détenteurs de `admin.access` : raccourcis + alertes (comptes pas encore connectés, échecs de connexion 24 h, emails en échec) ; ligne « Bientôt : messagerie, calendrier, tâches, projets, documents, annonces, demandes, annuaire. »
- [ ] **Step 4 :** `vendor/bin/phpunit` → PASS.
- [ ] **Step 5 :** commit « Accueil utile, erreurs 403/404 dans l'interface ».

### Task 6 : finition et vérification

- [ ] **Step 1 :** passe `/impeccable polish` sur les vues modifiées (lecture de `reference/polish.md` et `reference/craft-floor.md` avant édition).
- [ ] **Step 2 :** `impeccable detect --json` sur le HTML rendu des 5 pages → aucun `low-contrast` ni `undersized-ui-text` de niveau warning.
- [ ] **Step 3 :** `vendor/bin/phpunit` et `smoke.js` verts ; mise à jour de `PLAN.md` (journal) et `docs/ADMINISTRATION.md` (récapitulatif de création, statuts).
- [ ] **Step 4 :** nouvelle `/impeccable critique` (deux agents) pour mesurer le gain par rapport à 23/40.
- [ ] **Step 5 :** commit et push.
