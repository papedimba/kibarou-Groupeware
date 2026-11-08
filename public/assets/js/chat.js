// Kibarou Groupware — messagerie : interrogation adaptative, envoi sans rechargement, brouillons.
// Aucun HTML venu du serveur n'est injecté : tout est construit avec textContent et createElement.
(function () {
    'use strict';

    var base = document.body.getAttribute('data-base') || '/';
    var conv = document.querySelector('[data-chat-conversation]');
    var list = document.querySelector('[data-chat-list]');
    var form = document.querySelector('[data-chat-form]');
    var cid = conv ? parseInt(conv.getAttribute('data-chat-conversation'), 10) : 0;
    var me = conv ? parseInt(conv.getAttribute('data-me'), 10) : 0;
    var isGroup = conv ? conv.getAttribute('data-group') === '1' : false;
    var lastEvent = conv ? parseInt(conv.getAttribute('data-last-event'), 10) || 0 : 0;
    var box = conv ? conv.querySelector('[data-chat-messages]') : null;
    var csrf = form ? form.querySelector('input[name=_csrf]').value : '';
    var root = document.querySelector('[data-chat]');
    if (!root) { return; }
    var cfg = root;
    var rhythm = {
        active: parseInt(cfg.getAttribute('data-poll-active'), 10) || 3000,
        idle: parseInt(cfg.getAttribute('data-poll-idle'), 10) || 10000,
        background: parseInt(cfg.getAttribute('data-poll-background'), 10) || 30000
    };
    // Repère commun : dernier évènement connu à l'affichage de la page (toutes conversations).
    var since = parseInt(root.getAttribute('data-since'), 10) || 0;

    function store(k, v) { try { if (v === null || v === '') { window.localStorage.removeItem(k); } else { window.localStorage.setItem(k, v); } } catch (e) { /* stockage indisponible */ } }
    function load(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
    // Textes partis mais non confirmés par le serveur, par conversation.
    var unsentKey = 'kb-unsent-' + cid;
    function unsent() { try { return JSON.parse(load(unsentKey) || '[]'); } catch (e) { return []; } }
    function keep(text) { var l = unsent(); l.push(text); store(unsentKey, JSON.stringify(l)); }
    function forget(text) { var l = unsent(), i = l.indexOf(text); if (i !== -1) { l.splice(i, 1); } store(unsentKey, l.length ? JSON.stringify(l) : ''); }

    // --- Rythme : actif (interaction récente), au repos, ou onglet en arrière-plan ---
    // lastInteraction règle le rythme (l'ouverture de la page compte) ; lastInput, lui, ne compte que les vraies
    // saisies : seul il rend un accusé de lecture « actif » (prolongation de la session).
    var lastInteraction = Date.now(), lastInput = 0;
    // Pas d'évènement « scroll » : le défilement automatique à l'arrivée d'un message n'est pas une interaction
    // (sinon un PC laissé ouvert sur un groupe actif ne se déconnecterait jamais).
    ['keydown', 'pointerdown', 'touchstart', 'wheel'].forEach(function (type) {
        document.addEventListener(type, function () { lastInteraction = lastInput = Date.now(); }, { passive: true });
    });
    function recentlyActive() { return Date.now() - lastInput < 60000; }
    // 3 s conversation ouverte et interaction récente ; 10 s après 1 min ; 30 s après 5 min ou sur la seule liste.
    function delay() {
        var idle = Date.now() - lastInteraction;
        if (!conv || idle >= 300000) { return rhythm.background; }
        return idle < 60000 ? rhythm.active : rhythm.idle;
    }

    // Session expirée : plus d'interrogation, message clair ; les textes en cours restent dans la zone de saisie.
    function sessionExpired() {
        stopped = true;
        if (document.querySelector('[data-chat-expired]')) { return; }
        var n = document.createElement('div');
        n.className = 'alert alert-warning kb-chat-alert'; n.setAttribute('role', 'alert'); n.setAttribute('data-chat-expired', '');
        n.textContent = 'Votre session a expiré. Rechargez la page pour vous reconnecter : votre texte est conservé.';
        (form || root).insertBefore(n, (form || root).firstChild);
    }

    // --- Transport (seul l'interrogation existe en 4a ; un autre transport pourra le remplacer) ---
    var transport = {
        poll: function (from) {
            return fetch(base + 'api/chat/updates?since=' + from, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (r.status === 401 || r.status === 403) { sessionExpired(); throw new Error('stop'); }
                    return r.ok ? r.json() : null;
                });
        }
    };

    var timer = null, stopped = false, inFlight = false, seen = {};
    // Onglet caché : aucune interrogation ; reprise immédiate au retour.
    function schedule(ms) { clearTimeout(timer); if (!stopped && !document.hidden) { timer = setTimeout(tick, ms === undefined ? delay() : ms); } }
    function tick() {
        if (inFlight) { return schedule(); }
        inFlight = true;
        // Recouvrement de 20 évènements : un envoi encore en cours de validation chez un autre utilisateur peut
        // obtenir un identifiant plus petit que le dernier reçu ; les doublons sont écartés par « seen ».
        transport.poll(Math.max(0, since - 20)).then(function (data) {
            if (!data) { return; }
            data.events.forEach(function (ev) {
                if (seen[ev.id]) { return; }
                seen[ev.id] = true;
                since = Math.max(since, ev.id);
                if (ev.conversation_id === cid) { appendEvent(ev, true); }
                if (ev.type === 'renamed') { renamed(ev); }
                updateListItem(ev, data.unread);
            });
            refreshCounts(data.unread);
            if (window.kbSetChatBadge) { window.kbSetChatBadge(data.total_unread); }
        }).catch(function (e) {
            if (e && e.message === 'stop') { stopped = true; }
        }).then(function () { inFlight = false; schedule(); });
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { schedule(0); } });

    // --- Rendu ---
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function hour(iso) { var d = new Date(iso); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function dayLabel(iso) {
        var d = new Date(iso), today = new Date(), y = new Date();
        y.setDate(today.getDate() - 1);
        if (d.toDateString() === today.toDateString()) { return 'Aujourd\'hui'; }
        if (d.toDateString() === y.toDateString()) { return 'Hier'; }
        return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
    }
    // Seuls les liens http(s) deviennent cliquables ; le reste est du texte.
    function fillText(el, text) {
        var re = /\bhttps?:\/\/[^\s<]+/gi, last = 0, m;
        var lines = function (s) {
            s.split('\n').forEach(function (part, i) {
                if (i > 0) { el.appendChild(document.createElement('br')); }
                if (part) { el.appendChild(document.createTextNode(part)); }
            });
        };
        while ((m = re.exec(text)) !== null) {
            var url = m[0].replace(/[.,;:!?)]+$/, '');
            lines(text.slice(last, m.index));
            var a = document.createElement('a');
            a.href = url; a.textContent = url; a.target = '_blank'; a.rel = 'noopener noreferrer';
            el.appendChild(a);
            last = m.index + url.length;
            re.lastIndex = last;
        }
        lines(text.slice(last));
    }
    function systemText(ev) {
        var byMe = ev.actor_id === me, actor = byMe ? 'Vous avez' : ev.actor_name + ' a';
        if (ev.type === 'member_added') { return ev.target_user_id === me ? ev.actor_name + ' vous a ajouté' : actor + ' ajouté ' + ev.target_name; }
        if (ev.type === 'member_left') { return actor + ' quitté le groupe'; }
        if (ev.type === 'renamed') { return actor + ' renommé le groupe en « ' + (ev.detail || '') + ' »'; }
        return '';
    }
    function buildEvent(ev) {
        var li = document.createElement('li');
        li.setAttribute('data-event-id', String(ev.id));
        if (ev.type !== 'message') {
            li.className = 'kb-msg-system';
            li.textContent = systemText(ev) + ' · ' + hour(ev.created_at);
            return li;
        }
        var mine = ev.sender_id === me;
        li.className = 'kb-msg' + (mine ? ' is-mine' : '');
        if (isGroup && !mine) {
            var author = document.createElement('span');
            author.className = 'kb-msg-author'; author.textContent = ev.sender_name || '';
            li.appendChild(author);
        }
        var bubble = document.createElement('div');
        bubble.className = 'kb-msg-bubble';
        if (!mine) {
            var who = document.createElement('span');
            who.className = 'visually-hidden'; who.textContent = (ev.sender_name || '') + ' : ';
            bubble.appendChild(who);
        }
        fillText(bubble, ev.body || '');
        li.appendChild(bubble);
        var t = document.createElement('time');
        t.className = 'kb-msg-time'; t.dateTime = ev.created_at; t.textContent = hour(ev.created_at);
        li.appendChild(t);
        return li;
    }
    function daySeparator(label) {
        var li = document.createElement('li'), span = document.createElement('span');
        li.className = 'kb-msg-day'; li.setAttribute('data-day', label);
        span.textContent = label; li.appendChild(span);
        return li;
    }
    function nearBottom() { return box.scrollHeight - box.scrollTop - box.clientHeight < 120; }
    function scrollBottom() { box.scrollTop = box.scrollHeight; }

    function appendEvent(ev, fromPoll) {
        if (!box || box.querySelector('[data-event-id="' + ev.id + '"]')) { return; }
        var stick = nearBottom() || !fromPoll;
        var empty = box.querySelector('[data-chat-empty]');
        if (empty) { empty.remove(); }
        var days = box.querySelectorAll('.kb-msg-day'), label = dayLabel(ev.created_at);
        if (!days.length || days[days.length - 1].getAttribute('data-day') !== label) { box.appendChild(daySeparator(label)); }
        if (fromPoll && ev.sender_id === me) {
            // Envoi réussi côté serveur malgré une réponse perdue : la bulle « Non envoyé » disparaît.
            var dup = [].filter.call(box.querySelectorAll('.kb-msg.is-failed'), function (b) { return b.dataset.text === ev.body; })[0];
            if (dup) { dup.remove(); forget(ev.body); }
        }
        var pending = box.querySelector('.kb-msg.is-pending');
        box.insertBefore(buildEvent(ev), pending && fromPoll ? pending : null);
        lastEvent = Math.max(lastEvent, ev.id);
        if (stick) { scrollBottom(); }
        if (fromPoll && !document.hidden) { markRead(); }
    }

    var readTimer = null;
    function markRead() {
        clearTimeout(readTimer);
        readTimer = setTimeout(function () {
            var body = new URLSearchParams({ event_id: String(lastEvent), active: recentlyActive() ? '1' : '0', _csrf: csrf });
            fetch(base + 'api/chat/conversations/' + cid + '/read', { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .catch(function () { /* nouvel essai au prochain message */ });
        }, 300);
    }
    document.addEventListener('visibilitychange', function () { if (conv && !document.hidden) { markRead(); } });

    // --- Liste des conversations ---
    function updateListItem(ev, unread) {
        if (!list || ev.type !== 'message') { return; }
        var item = list.querySelector('[data-conversation-id="' + ev.conversation_id + '"]');
        if (!item) {
            // shortcut: conversation inconnue de la page : élément minimal au nom de l'expéditeur ; le nom d'un groupe s'affichera au rechargement.
            var li = document.createElement('li'), a = document.createElement('a');
            a.className = 'kb-chat-item'; a.href = base + 'chat/' + ev.conversation_id; a.setAttribute('data-conversation-id', String(ev.conversation_id));
            var text = document.createElement('span'); text.className = 'kb-chat-item-text';
            var title = document.createElement('span'); title.className = 'kb-chat-item-title'; title.textContent = ev.sender_name || 'Nouvelle conversation';
            var lastLine = document.createElement('span'); lastLine.className = 'kb-chat-item-last';
            var side = document.createElement('span'); side.className = 'kb-chat-item-side';
            text.appendChild(title); text.appendChild(lastLine); a.appendChild(text); a.appendChild(side); li.appendChild(a);
            item = a;
        }
        var lastEl = item.querySelector('.kb-chat-item-last');
        if (lastEl) { lastEl.textContent = (ev.body || '').slice(0, 80); }
        var time = item.querySelector('.kb-chat-item-time');
        if (time) { time.textContent = 'à l\'instant'; }
        list.insertBefore(item.parentNode, list.firstChild);
        list.hidden = false;
        var none = root.querySelector('.kb-chat-empty');
        if (none) { none.remove(); }
    }
    function renamed(ev) {
        var item = list && list.querySelector('[data-conversation-id="' + ev.conversation_id + '"] .kb-chat-item-title');
        if (item) { item.textContent = ev.detail; }
        var title = ev.conversation_id === cid && document.getElementById('chat-title');
        if (title) { title.textContent = ev.detail; }
    }
    function refreshCounts(unread) {
        if (!list) { return; }
        list.querySelectorAll('[data-conversation-id]').forEach(function (item) {
            var id = item.getAttribute('data-conversation-id'), n = id === String(cid) ? 0 : (unread[id] || 0);
            var side = item.querySelector('.kb-chat-item-side'), count = item.querySelector('.kb-chat-count');
            item.classList.toggle('is-unread', n > 0);
            if (n > 0) {
                if (!count) { count = document.createElement('span'); count.className = 'kb-chat-count'; side.appendChild(count); }
                count.textContent = n > 99 ? '99+' : String(n);
                var sr = document.createElement('span'); sr.className = 'visually-hidden'; sr.textContent = ' non lu' + (n > 1 ? 's' : '');
                count.appendChild(sr);
            } else if (count) { count.remove(); }
        });
    }

    // --- Historique plus ancien ---
    if (conv && conv.getAttribute('data-has-more') === '1') {
        var more = document.createElement('li'), btn = document.createElement('button');
        more.className = 'kb-msg-more';
        btn.type = 'button'; btn.className = 'btn btn-light btn-sm'; btn.textContent = 'Afficher les messages précédents';
        more.appendChild(btn); box.insertBefore(more, box.firstChild);
        btn.addEventListener('click', function () {
            var first = box.querySelector('[data-event-id]');
            if (!first) { return; }
            btn.disabled = true;
            box.setAttribute('aria-busy', 'true');
            fetch(base + 'api/chat/conversations/' + cid + '/history?before=' + first.getAttribute('data-event-id'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    btn.disabled = false;
                    // Laisser passer le rendu avant de rétablir l'annonce : l'historique n'est pas lu à voix haute.
                    setTimeout(function () { box.removeAttribute('aria-busy'); }, 0);
                    if (!data) { return; }
                    var height = box.scrollHeight, anchor = more.nextSibling, day = null;
                    // Le séparateur du premier jour déjà affiché est recréé si besoin : on le retire s'il se répète.
                    data.events.forEach(function (ev) {
                        var label = dayLabel(ev.created_at);
                        if (label !== day) { day = label; box.insertBefore(daySeparator(label), anchor); }
                        box.insertBefore(buildEvent(ev), anchor);
                    });
                    if (anchor && anchor.classList && anchor.classList.contains('kb-msg-day') && anchor.getAttribute('data-day') === day) { anchor.remove(); }
                    if (data.events.length < 50) { more.remove(); }
                    box.scrollTop = box.scrollHeight - height;
                });
        });
    }

    // --- Saisie et envoi ---
    if (form && conv) {
        var input = form.querySelector('textarea');
        var draftKey = 'kb-draft-' + cid;
        var grow = function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight + 2, 160) + 'px'; };
        if (!input.value) { input.value = load(draftKey) || ''; }
        grow();
        input.addEventListener('input', function () { store(draftKey, input.value); grow(); });
        // PC : Entrée envoie, Maj+Entrée va à la ligne. Écran tactile : Entrée va à la ligne.
        var touch = window.matchMedia('(pointer: coarse)').matches;
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !touch && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
        });

        var error = document.createElement('div');
        error.className = 'invalid-feedback d-block'; error.setAttribute('role', 'alert'); error.hidden = true;
        form.appendChild(error);

        // Textes partis mais non confirmés : conservés à part jusqu'à la réponse du serveur (rechargement, session expirée).
        var restore = function (text) {
            input.value = input.value ? text + '\n\n' + input.value : text;
            store(draftKey, input.value); grow();
        };
        // Au chargement : un texte resté sans confirmation revient dans la zone de saisie.
        unsent().forEach(function (text) { restore(text); });
        store(unsentKey, '');

        var send = function (text, bubble) {
            error.hidden = true;
            return fetch(base + 'api/chat/conversations/' + cid + '/messages', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ body: text })
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, data: data }; });
            }).then(function (res) {
                if (res.status === 200 && res.data.event) {
                    forget(text);
                    confirm(bubble, res.data.event);
                    return;
                }
                if ([401, 404, 419, 422, 429].indexOf(res.status) !== -1) {
                    // Refus définitif : le texte revient dans la zone de saisie, rien n'est perdu.
                    forget(text);
                    bubble.remove();
                    restore(text);
                    if (res.status === 401 || res.status === 419) { sessionExpired(); return; }
                    error.textContent = res.status === 404 ? 'Vous ne faites plus partie de cette conversation.'
                        : (res.data.errors && res.data.errors.body) || res.data.error || 'Message refusé.';
                    error.hidden = false;
                    return;
                }
                throw new Error('network');
            }).catch(function () { if (bubble.isConnected) { failed(bubble, text); } });
        };
        // La bulle provisoire devient le message confirmé (pas de seconde annonce au lecteur d'écran).
        var confirm = function (bubble, ev) {
            if (box.querySelector('[data-event-id="' + ev.id + '"]')) { bubble.remove(); return; }
            bubble.setAttribute('data-event-id', String(ev.id));
            bubble.classList.remove('is-pending', 'is-failed');
            delete bubble.dataset.text;
            var t = bubble.querySelector('.kb-msg-time');
            t.textContent = hour(ev.created_at); t.dateTime = ev.created_at;
            seen[ev.id] = true;
            lastEvent = Math.max(lastEvent, ev.id);
        };
        var failed = function (bubble, text) {
            bubble.classList.remove('is-pending');
            bubble.classList.add('is-failed');
            var t = bubble.querySelector('.kb-msg-time');
            t.textContent = 'Non envoyé — ';
            var retry = document.createElement('button');
            retry.type = 'button'; retry.className = 'kb-msg-retry'; retry.textContent = 'Réessayer';
            retry.addEventListener('click', function () {
                bubble.classList.remove('is-failed'); bubble.classList.add('is-pending');
                t.textContent = 'Envoi…';
                send(text, bubble);
            });
            t.appendChild(retry);
        };

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = input.value.replace(/\s+$/, '');
            if (text.trim() === '' || stopped) { input.focus(); return; }
            // Bulle provisoire : le texte reste visible même si le réseau du chantier lâche.
            var bubble = buildEvent({ id: 0, type: 'message', sender_id: me, body: text, created_at: new Date().toISOString() });
            bubble.removeAttribute('data-event-id');
            bubble.dataset.text = text;
            bubble.classList.add('is-pending');
            bubble.querySelector('.kb-msg-time').textContent = 'Envoi…';
            var empty = box.querySelector('[data-chat-empty]');
            if (empty) { empty.remove(); }
            box.appendChild(bubble);
            scrollBottom();
            keep(text);
            input.value = ''; store(draftKey, ''); grow();
            input.focus();
            lastInteraction = Date.now();
            send(text, bubble).then(function () { schedule(0); });
        });
    }

    if (box) { scrollBottom(); }
    schedule();
}());
