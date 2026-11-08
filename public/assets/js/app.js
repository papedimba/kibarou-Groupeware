// Kibarou Groupware — comportements d'interface communs (aucun script inline : CSP stricte).
(function () {
    'use strict';

    if (window.lucide) {
        window.lucide.createIcons();
    }

    // Menu mobile
    const sidebar = document.querySelector('.kb-sidebar');
    document.querySelectorAll('[data-kb-toggle="sidebar"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const open = sidebar.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    // Fermeture du menu mobile : clic hors du menu ou touche Échap
    function closeSidebar() {
        if (sidebar && sidebar.classList.contains('open')) {
            sidebar.classList.remove('open');
            document.querySelectorAll('[data-kb-toggle="sidebar"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        }
    }
    document.addEventListener('click', function (event) {
        if (sidebar && !sidebar.contains(event.target) && !event.target.closest('[data-kb-toggle="sidebar"]')) {
            closeSidebar();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeSidebar(); }
    });

    // Confirmation des actions sensibles dans une fenêtre de l'application : <form data-confirm="Message">.
    // Sans JavaScript, le formulaire s'envoie normalement.
    var confirmEl = document.getElementById('kb-confirm');
    var pendingForm = null;
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            // La confirmation ne vaut que pour un envoi (sinon un retour arrière la réutiliserait).
            if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
            if (!confirmEl || !window.bootstrap) { return; }
            event.preventDefault();
            event.stopImmediatePropagation();
            pendingForm = form;
            confirmEl.querySelector('[data-kb-confirm-text]').textContent = form.getAttribute('data-confirm');
            var ok = confirmEl.querySelector('[data-kb-confirm-ok]');
            ok.textContent = form.getAttribute('data-confirm-action') || 'Confirmer';
            ok.className = 'btn ' + (form.hasAttribute('data-confirm-danger') ? 'btn-danger' : 'btn-primary');
            window.bootstrap.Modal.getOrCreateInstance(confirmEl).show();
        });
    });
    if (confirmEl) {
        confirmEl.querySelector('[data-kb-confirm-ok]').addEventListener('click', function () {
            if (!pendingForm) { return; }
            pendingForm.dataset.confirmed = '1';
            window.bootstrap.Modal.getOrCreateInstance(confirmEl).hide();
            pendingForm.requestSubmit();
        });
        confirmEl.addEventListener('hidden.bs.modal', function () { pendingForm = null; });
    }

    // Anti double envoi (réseau de chantier lent) : le bouton est désactivé après le premier envoi.
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (event.defaultPrevented) { return; }
            if (form.dataset.sending === '1') { event.preventDefault(); return; }
            form.dataset.sending = '1';
            // shortcut: un bouton submit avec name/value perdrait sa valeur ; aucun n'en a aujourd'hui.
            form.querySelectorAll('[type=submit]').forEach(function (btn) { btn.disabled = true; btn.setAttribute('aria-busy', 'true'); });
        });
    });
    // Retour arrière depuis le cache du navigateur : réactiver les boutons.
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) { return; }
        document.querySelectorAll('form[data-sending]').forEach(function (form) {
            delete form.dataset.sending;
            form.querySelectorAll('[type=submit]').forEach(function (btn) { btn.disabled = false; btn.removeAttribute('aria-busy'); });
        });
    });

    // Afficher / masquer un mot de passe : <button data-toggle-password="id-du-champ">
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-toggle-password'));
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        });
    });

    // Générer un mot de passe temporaire lisible (sans caractères ambigus 0/O, 1/l/I).
    document.querySelectorAll('[data-generate-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var letters = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ', digits = '23456789', all = letters + digits, out;
            do {
                var bytes = new Uint32Array(14);
                window.crypto.getRandomValues(bytes);
                out = Array.prototype.map.call(bytes, function (b) { return all[b % all.length]; }).join('');
            } while (!/[a-zA-Z]/.test(out) || !/[0-9]/.test(out));
            document.getElementById(btn.getAttribute('data-generate-password')).value = out;
        });
    });

    // Copier / imprimer (récapitulatif de création de compte)
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = document.getElementById(btn.getAttribute('data-copy')).textContent;
            navigator.clipboard.writeText(text).then(function () { btn.lastChild.textContent = ' Copié'; });
        });
    });
    document.querySelectorAll('[data-print]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.print(); });
    });

    // Annuaire : filtre instantané sans rechargement (insensible aux accents et à la casse).
    var fold = function (t) { return t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); };
    document.querySelectorAll('[data-directory-filter]').forEach(function (input) {
        var list = document.getElementById(input.getAttribute('data-directory-filter'));
        var empty = document.getElementById(input.getAttribute('data-directory-filter') + '-empty');
        if (!list) { return; }
        input.addEventListener('input', function () {
            var q = fold(input.value), shown = 0;
            list.querySelectorAll('[data-search]').forEach(function (item) {
                var match = q === '' || fold(item.getAttribute('data-search')).indexOf(q) !== -1;
                item.hidden = !match;
                if (match) { shown++; }
            });
            if (empty) { empty.hidden = shown !== 0; }
        });
    });

    // Formulaire de statut : chantier pour « Sur chantier », détails pour chantier / déplacement / congé.
    document.querySelectorAll('[data-presence-form]').forEach(function (form) {
        var details = form.querySelector('[data-presence-details]'), site = form.querySelector('[data-presence-site]');
        var sync = function () {
            var checked = form.querySelector('input[name=status]:checked'), v = checked ? checked.value : '';
            details.hidden = ['site', 'travel', 'leave'].indexOf(v) === -1;
            site.hidden = v !== 'site';
            // Un champ masqué n'est pas envoyé : l'utilisateur ne peut pas enregistrer ce qu'il ne voit pas.
            details.querySelectorAll('input').forEach(function (i) { i.disabled = i.closest('[hidden]') !== null; });
        };
        form.querySelectorAll('input[name=status]').forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    });

    // Organigramme : sur téléphone, seul le service de l'utilisateur reste ouvert (sans JS, tout est ouvert).
    if (window.matchMedia('(max-width: 767.98px)').matches) {
        document.querySelectorAll('.kb-org details:not([data-mine])').forEach(function (d) {
            if (!d.querySelector('[data-mine]')) { d.open = false; }
        });
    }

    // Notifications : interrogation toutes les 60 s (onglet visible seulement), cloche et alertes navigateur.
    var bell = document.querySelector('[data-bell]');
    // Racine de l'application (installation possible dans un sous-dossier).
    var base = document.body.getAttribute('data-base') || '/';
    var registration = null;
    var isIos = /iPhone|iPad|iPod/.test(navigator.userAgent) && !window.navigator.standalone;
    if ('serviceWorker' in navigator && !isIos) {
        navigator.serviceWorker.register(base + 'sw.js', { scope: base }).then(function (r) { registration = r; }).catch(function () { registration = null; });
    }
    function alertUser(item) {
        if (!('Notification' in window) || Notification.permission !== 'granted') { return; }
        var options = { body: item.title, data: { url: item.url || base + 'notifications' }, tag: 'kb-' + item.id };
        if (registration) { registration.showNotification('Kibarou Groupware', options); return; }
        try {
            var n = new Notification('Kibarou Groupware', options);
            n.onclick = function () { window.focus(); window.location.href = options.data.url; };
        } catch (e) { /* navigateur sans prise en charge : la cloche suffit */ }
    }
    if (bell) {
        var badge = bell.querySelector('[data-unread-badge]');
        var lastId = parseInt(document.body.getAttribute('data-last-id') || '0', 10);
        var timer = null, stopped = false;
        var poll = function () {
            if (stopped || document.hidden) { return; }
            fetch(base + 'api/notifications?since=' + lastId, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (r.status === 401 || r.status === 403) { stopped = true; clearInterval(timer); return null; }
                    return r.ok ? r.json() : null;
                })
                .then(function (data) {
                    if (!data) { return; }
                    badge.textContent = data.unread > 99 ? '99+' : String(data.unread);
                    badge.hidden = data.unread === 0;
                    bell.setAttribute('aria-label', 'Notifications, ' + data.unread + ' non lue' + (data.unread > 1 ? 's' : ''));
                    data.items.forEach(function (item) {
                        lastId = Math.max(lastId, item.id);
                        if (item.alert) { alertUser(item); }
                    });
                })
                .catch(function () { /* réseau faible : nouvel essai au prochain passage */ });
        };
        timer = setInterval(poll, 60000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
    }

    // Préférences : activation des alertes du navigateur.
    document.querySelectorAll('[data-enable-alerts]').forEach(function (btn) {
        var status = document.querySelector('[data-alerts-status]');
        var say = function (t) { if (status) { status.textContent = t; } };
        if (isIos) { btn.hidden = true; say('Sur iPhone, les alertes seront disponibles quand l\'intranet pourra être installé sur l\'écran d\'accueil.'); return; }
        if (!('Notification' in window)) { btn.hidden = true; say('Ce navigateur ne prend pas en charge les alertes.'); return; }
        var show = function () {
            if (Notification.permission === 'granted') { btn.hidden = true; say('Les alertes sont activées sur cet appareil.'); }
            else if (Notification.permission === 'denied') { btn.hidden = true; say('Les alertes sont bloquées dans les réglages de ce navigateur pour ce site.'); }
        };
        show();
        btn.addEventListener('click', function () { Notification.requestPermission().then(show); });
    });

    // Soumission automatique des filtres : <select data-autosubmit>
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });
})();
