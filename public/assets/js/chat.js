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

    // --- Rythme : actif (interaction récente), au repos, ou onglet en arrière-plan ---
    var lastInteraction = Date.now();
    ['keydown', 'pointerdown', 'touchstart', 'wheel', 'scroll'].forEach(function (type) {
        (type === 'scroll' && box ? box : document).addEventListener(type, function () { lastInteraction = Date.now(); }, { passive: true });
    });
    function recentlyActive() { return Date.now() - lastInteraction < 60000; }
    function delay() { return document.hidden ? rhythm.background : (recentlyActive() ? rhythm.active : rhythm.idle); }

    // --- Transport (seul l'interrogation existe en 4a ; un autre transport pourra le remplacer) ---
    var transport = {
        poll: function (from) {
            return fetch(base + 'api/chat/updates?since=' + from, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) {
                    if (r.status === 401 || r.status === 403) { throw new Error('stop'); }
                    return r.ok ? r.json() : null;
                });
        }
    };

    var timer = null, stopped = false, inFlight = false;
    function schedule(ms) { clearTimeout(timer); if (!stopped) { timer = setTimeout(tick, ms === undefined ? delay() : ms); } }
    function tick() {
        if (inFlight) { return schedule(); }
        inFlight = true;
        transport.poll(since).then(function (data) {
            if (!data) { return; }
            data.events.forEach(function (ev) {
                since = Math.max(since, ev.id);
                if (ev.conversation_id === cid) { appendEvent(ev, true); }
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
        if (ev.type === 'renamed') { return actor + ' renommé le groupe'; }
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
            fetch(base + 'api/chat/conversations/' + cid + '/history?before=' + first.getAttribute('data-event-id'), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    btn.disabled = false;
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
                    bubble.remove();
                    appendEvent(res.data.event, false);
                    return;
                }
                if (res.status === 422 || res.status === 429 || res.status === 404) {
                    // Refus définitif : le texte revient dans la zone de saisie pour être corrigé.
                    bubble.remove();
                    if (!input.value) { input.value = text; store(draftKey, text); grow(); }
                    error.textContent = (res.data.errors && res.data.errors.body) || res.data.error || 'Message refusé.';
                    error.hidden = false;
                    return;
                }
                throw new Error('network');
            }).catch(function () { failed(bubble, text); });
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
            if (text.trim() === '') { input.focus(); return; }
            // Bulle provisoire : le texte reste visible même si le réseau du chantier lâche.
            var bubble = buildEvent({ id: 'p' + Date.now(), type: 'message', sender_id: me, body: text, created_at: new Date().toISOString() });
            bubble.removeAttribute('data-event-id');
            bubble.classList.add('is-pending');
            bubble.querySelector('.kb-msg-time').textContent = 'Envoi…';
            var empty = box.querySelector('[data-chat-empty]');
            if (empty) { empty.remove(); }
            box.appendChild(bubble);
            scrollBottom();
            input.value = ''; store(draftKey, ''); grow();
            input.focus();
            lastInteraction = Date.now();
            send(text, bubble).then(function () { schedule(0); });
        });
    }

    if (box) { scrollBottom(); }
    schedule();
}());
