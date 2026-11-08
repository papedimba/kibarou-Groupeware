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
    var convType = conv ? conv.getAttribute('data-type') || 'direct' : 'direct';
    var lastEvent = conv ? parseInt(conv.getAttribute('data-last-event'), 10) || 0 : 0;
    var box = conv ? conv.querySelector('[data-chat-messages]') : null;
    // Jeton pris dans n'importe quel formulaire de la messagerie (un salon archivé n'a pas de zone de saisie).
    var csrf = (document.querySelector('[data-chat] input[name=_csrf]') || { value: '' }).value;
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
    // Entrée : {text, client_id, attachments, reply_to} ; l'ancien format (simple texte, lot 4a) reste lisible.
    var unsentKey = 'kb-unsent-' + cid;
    function unsent() {
        try { return JSON.parse(load(unsentKey) || '[]').map(function (u) { return typeof u === 'string' ? { text: u } : u; }); } catch (e) { return []; }
    }
    function keep(entry) { var l = unsent(); l.push(entry); store(unsentKey, JSON.stringify(l)); }
    function forget(clientId) {
        var l = unsent().filter(function (u) { return u.client_id !== clientId; });
        store(unsentKey, l.length ? JSON.stringify(l) : '');
    }
    function newClientId() {
        var b = new Uint8Array(16);
        window.crypto.getRandomValues(b);
        return [].map.call(b, function (x) { return (x < 16 ? '0' : '') + x.toString(16); }).join('');
    }
    var EDIT_WINDOW = 15 * 60 * 1000;
    var REACTIONS = ['👍', '✅', '👀', '🙏', '❤️', '😂'];

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
            return fetch(base + 'api/chat/updates?since=' + from + (cid ? '&open=' + cid : ''), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
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
            if (data.reads) { reads = data.reads; renderSeen(); }
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
                // Le « \n » après <br> garde le texte d'origine (textContent) pour la modification.
                if (i > 0) { el.appendChild(document.createElement('br')); el.appendChild(document.createTextNode('\n')); }
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
    // Même règle que chat_system_text() (app/Core/helpers.php).
    function systemText(ev) {
        var word = convType === 'group' ? 'groupe' : 'salon';
        var actor = ev.actor_id === me ? 'Vous avez' : ev.actor_name + ' a';
        var target = ev.target_user_id === me ? 'Vous avez' : ev.target_name + ' a';
        var auto = ev.actor_id === null;
        if (ev.type === 'member_added') {
            if (auto || ev.actor_id === ev.target_user_id) { return target + ' rejoint le ' + word; }
            return ev.target_user_id === me ? ev.actor_name + ' vous a ajouté' : actor + ' ajouté ' + ev.target_name;
        }
        if (ev.type === 'member_left') { return (auto ? target : actor) + ' quitté le ' + word; }
        if (ev.type === 'renamed') { return auto ? 'Le salon a été renommé en « ' + (ev.detail || '') + ' »' : actor + ' renommé le ' + word + ' en « ' + (ev.detail || '') + ' »'; }
        if (ev.type === 'archived') { return auto ? 'Le salon a été archivé' : actor + ' archivé le salon'; }
        if (ev.type === 'unarchived') { return auto ? 'Le salon a été rouvert' : actor + ' rouvert le salon'; }
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
        return renderMessage(ev, li);
    }
    function icon(name) {
        var i = document.createElement('i');
        i.setAttribute('data-lucide', name); i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = text; }
        return n;
    }
    function sizeLabel(bytes) {
        if (bytes < 1024) { return bytes + ' octets'; }
        if (bytes < 1048576) { return Math.round(bytes / 1024) + ' Ko'; }
        return (bytes / 1048576).toFixed(1).replace('.', ',').replace(',0', '') + ' Mo';
    }
    // Même structure que app/Views/chat/_message.php.
    function bubbleFor(ev, sender) {
        var mine = ev.sender_id === me, bubble = el('div', 'kb-msg-bubble');
        if (!mine) { bubble.appendChild(el('span', 'visually-hidden', sender + ' : ')); }
        if (ev.deleted) {
            bubble.appendChild(el('em', 'kb-msg-deleted', 'Message supprimé'));
            return bubble;
        }
        if (ev.reply_to) {
            var r = ev.reply_to, quote = el('a', 'kb-msg-reply');
            quote.href = '#m' + r.id; quote.setAttribute('data-reply-to', String(r.id));
            if (r.hidden) { quote.textContent = 'Message antérieur à votre arrivée'; }
            else if (r.deleted) { quote.textContent = 'Message supprimé'; }
            else { quote.appendChild(el('strong', '', r.sender_name || '')); quote.appendChild(document.createTextNode(' ' + (r.excerpt || ''))); }
            bubble.appendChild(quote);
        }
        var atts = ev.attachments || [];
        var photos = atts.filter(function (a) { return a.kind === 'image'; }), files = atts.filter(function (a) { return a.kind !== 'image'; });
        if (photos.length) {
            var grid = el('div', 'kb-msg-photos kb-msg-photos-' + Math.min(photos.length, 4));
            photos.forEach(function (p, i) {
                var a = el('a', i < 4 ? 'kb-msg-photo' : 'kb-msg-photo-extra');
                a.href = p.url; a.setAttribute('data-photo', '');
                if (i < 4) {
                    if (i === 3 && photos.length > 4) { a.setAttribute('data-more', '+' + (photos.length - 4)); }
                    var img = document.createElement('img');
                    img.src = p.thumb_url; img.alt = 'Photo envoyée par ' + sender; img.loading = 'lazy';
                    if (p.width) { img.width = p.width; img.height = p.height; }
                    a.appendChild(img);
                    grid.appendChild(a);
                } else { a.hidden = true; a.textContent = 'Photo'; bubble.appendChild(a); }
            });
            bubble.insertBefore(grid, bubble.querySelector('.kb-msg-photo-extra'));
        }
        files.forEach(function (f) {
            var a = el('a', 'kb-msg-file');
            a.href = f.url; a.setAttribute('download', '');
            a.appendChild(icon(/\.xlsx$/i.test(f.name) ? 'file-spreadsheet' : 'file-text'));
            var t = el('span', 'kb-msg-file-text');
            t.appendChild(el('span', 'kb-msg-file-name', f.name));
            t.appendChild(el('span', 'kb-msg-file-size', sizeLabel(f.size) + ' · Télécharger'));
            a.appendChild(t);
            bubble.appendChild(a);
        });
        if (ev.body) { var text = el('div', 'kb-msg-text'); fillText(text, ev.body); bubble.appendChild(text); }
        return bubble;
    }
    function reactionsFor(list) {
        if (!list || !list.length) { return null; }
        var wrap = el('div', 'kb-msg-reactions');
        list.forEach(function (r) {
            var b = el('button', 'kb-reaction');
            b.type = 'button'; b.setAttribute('data-react', r.emoji); b.setAttribute('aria-pressed', r.mine ? 'true' : 'false');
            b.title = r.names.join(', ');
            var shown = el('span', '', r.emoji + ' ' + r.count); shown.setAttribute('aria-hidden', 'true');
            b.appendChild(shown);
            b.appendChild(el('span', 'visually-hidden', r.emoji + ' : ' + r.names.join(', ')));
            wrap.appendChild(b);
        });
        return wrap;
    }
    function actionsButton() {
        var b = el('button', 'kb-msg-actions-btn');
        b.type = 'button'; b.setAttribute('data-msg-actions', ''); b.setAttribute('aria-label', 'Actions sur ce message');
        b.hidden = false;
        b.appendChild(icon('ellipsis'));
        return b;
    }
    function renderMessage(ev, li) {
        var mine = ev.sender_id === me, sender = ev.sender_name || '';
        li.className = 'kb-msg' + (mine ? ' is-mine' : '');
        if (ev.message_id) { li.id = 'm' + ev.message_id; li.setAttribute('data-message-id', String(ev.message_id)); }
        li.setAttribute('data-mine', mine ? '1' : '0');
        li.setAttribute('data-created', ev.created_at);
        li.setAttribute('data-deleted', ev.deleted ? '1' : '0');
        if (mine && ev.client_id) { li.setAttribute('data-client-id', ev.client_id); }
        if (isGroup && !mine) { li.appendChild(el('span', 'kb-msg-author', sender)); }
        li.appendChild(bubbleFor(ev, sender));
        var meta = el('span', 'kb-msg-meta'), t = el('time', 'kb-msg-time', hour(ev.created_at));
        t.dateTime = ev.created_at;
        meta.appendChild(t);
        if (ev.edited_at && !ev.deleted) { meta.appendChild(el('span', 'kb-msg-edited', 'modifié')); }
        meta.appendChild(actionsButton());
        li.appendChild(meta);
        var reactions = reactionsFor(ev.reactions);
        if (reactions) { li.appendChild(reactions); }
        if (window.lucide) { window.lucide.createIcons(); }
        return li;
    }
    // Modification, suppression ou réaction : la bulle existante est mise à jour en place.
    function updateMessage(ev) {
        var li = box && box.querySelector('.kb-msg[data-message-id="' + ev.message_id + '"]');
        if (!li) { return; }
        var hidden = li.querySelector('.kb-msg-bubble > .visually-hidden');
        var sender = hidden ? hidden.textContent.replace(/ : $/, '') : '';
        li.querySelector('.kb-msg-bubble').replaceWith(bubbleFor(ev, sender));
        li.setAttribute('data-deleted', ev.deleted ? '1' : '0');
        var meta = li.querySelector('.kb-msg-meta'), edited = meta.querySelector('.kb-msg-edited');
        if (ev.edited_at && !ev.deleted && !edited) { meta.insertBefore(el('span', 'kb-msg-edited', 'modifié'), meta.querySelector('[data-msg-actions]')); }
        if (ev.deleted && edited) { edited.remove(); }
        var old = li.querySelector('.kb-msg-reactions'), fresh = reactionsFor(ev.reactions);
        if (old) { old.remove(); }
        if (fresh) { li.appendChild(fresh); }
        if (window.lucide) { window.lucide.createIcons(); }
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
        if (ev.type === 'message_edited' || ev.type === 'message_deleted' || ev.type === 'reaction') { updateMessage(ev); return; }
        var stick = nearBottom() || !fromPoll;
        var empty = box.querySelector('[data-chat-empty]');
        if (empty) { empty.remove(); }
        var days = box.querySelectorAll('.kb-msg-day'), label = dayLabel(ev.created_at);
        if (!days.length || days[days.length - 1].getAttribute('data-day') !== label) { box.appendChild(daySeparator(label)); }
        if (fromPoll && ev.sender_id === me) {
            // Envoi réussi côté serveur malgré une réponse perdue : la bulle « Non envoyé » disparaît.
            var dup = ev.client_id && box.querySelector('.kb-msg.is-failed[data-client-id="' + ev.client_id + '"]');
            if (dup) { dup.remove(); forget(ev.client_id); }
        }
        var pending = box.querySelector('.kb-msg.is-pending');
        box.insertBefore(buildEvent(ev), pending && fromPoll ? pending : null);
        lastEvent = Math.max(lastEvent, ev.id);
        if (stick) { scrollBottom(); }
        if (fromPoll && !document.hidden) { markRead(); }
    }

    // --- Accusés de lecture (même règle que ChatRepository::seen) ---
    var reads = null;
    function renderSeen() {
        if (!box || !reads) { return; }
        var mine = [].filter.call(box.querySelectorAll('.kb-msg[data-mine="1"][data-event-id]'), function (li) { return li.getAttribute('data-deleted') !== '1'; });
        var readers = function (eventId) {
            return reads.filter(function (r) { return r.last_read_event_id >= eventId && r.entered_event_id < eventId; });
        };
        var target = null, names = [];
        if (convType === 'direct') {
            for (var i = mine.length - 1; i >= 0 && !target; i--) {
                if (readers(parseInt(mine[i].getAttribute('data-event-id'), 10)).length) { target = mine[i]; }
            }
        } else if (mine.length) {
            var last = mine[mine.length - 1];
            names = readers(parseInt(last.getAttribute('data-event-id'), 10)).map(function (r) { return r.name; });
            if (names.length) { target = last; }
        }
        var current = box.querySelector('[data-seen]');
        var label = convType === 'direct' ? 'Vu' : 'Vu par ' + names.length;
        if (current && target && current.closest('.kb-msg') === target && current.textContent.indexOf(label) !== -1) { return; }
        box.querySelectorAll('[data-seen], .kb-msg-seen-list').forEach(function (n) { n.remove(); });
        if (!target) { return; }
        var meta = target.querySelector('.kb-msg-meta'), seen;
        if (names.length) {
            seen = el('button', 'kb-msg-seen', '· ' + label);
            seen.type = 'button'; seen.setAttribute('aria-expanded', 'false'); seen.title = names.join(', ');
            seen.appendChild(el('span', 'visually-hidden', ' : ' + names.join(', ')));
        } else {
            seen = el('span', 'kb-msg-seen', '· ' + label);
        }
        seen.setAttribute('data-seen', '');
        meta.insertBefore(seen, meta.querySelector('[data-msg-actions]'));
    }
    // Liste des lecteurs au toucher (groupes et salons).
    if (box) {
        box.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('button[data-seen]');
            if (!btn) { return; }
            var li = btn.closest('.kb-msg'), open = li.querySelector('.kb-msg-seen-list');
            if (open) { open.remove(); btn.setAttribute('aria-expanded', 'false'); return; }
            var list = el('ul', 'kb-msg-seen-list');
            list.setAttribute('aria-label', 'Lu par');
            btn.title.split(', ').forEach(function (n) { list.appendChild(el('li', '', n)); });
            li.appendChild(list);
            btn.setAttribute('aria-expanded', 'true');
        });
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
        var first = (ev.attachments || [])[0];
        if (lastEl) { lastEl.textContent = ev.body ? ev.body.slice(0, 80) : first ? (first.kind === 'image' ? 'Photo' : 'Fichier : ' + first.name) : ''; }
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
        var sendBtn = form.querySelector('.kb-chat-send');
        var files = window.kbChatAttachments || null;
        var banner = form.querySelector('[data-chat-banner]'), bannerText = form.querySelector('[data-chat-banner-text]');
        var draftKey = 'kb-draft-' + cid;
        var replyTo = null, editing = null;
        // Un message peut ne contenir que des pièces jointes : le contrôle « requis » reste pour l'envoi sans JavaScript.
        input.removeAttribute('required');
        var grow = function () { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight + 2, 160) + 'px'; };
        if (!input.value) { input.value = load(draftKey) || ''; }
        grow();
        input.addEventListener('input', function () { if (!editing) { store(draftKey, input.value); } grow(); });
        // PC : Entrée envoie, Maj+Entrée va à la ligne. Écran tactile : Entrée va à la ligne.
        var touch = window.matchMedia('(pointer: coarse)').matches;
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !touch && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
            if (e.key === 'Escape' && (replyTo || editing)) { cancelMode(); }
        });
        var syncSend = function () { sendBtn.disabled = !!(files && files.busy()); };
        form.addEventListener('kb-attachments-change', syncSend);

        var error = document.createElement('div');
        error.className = 'invalid-feedback d-block'; error.setAttribute('role', 'alert'); error.hidden = true;
        form.appendChild(error);
        var showError = function (data, fallback) {
            var errs = (data && data.errors) || {};
            error.textContent = errs.body || errs.attachments || errs.reply_to || errs.emoji || (data && data.error) || fallback;
            error.hidden = false;
        };

        // Bandeau « Réponse à … » ou « Modification du message ».
        var setMode = function (text) { bannerText.textContent = text; banner.hidden = false; };
        var cancelMode = function () {
            if (editing) { input.value = load(draftKey) || ''; grow(); }
            replyTo = null; editing = null; banner.hidden = true;
        };
        form.querySelector('[data-chat-banner-cancel]').addEventListener('click', function () { cancelMode(); input.focus(); });

        var restore = function (entry) {
            input.value = input.value ? entry.text + '\n\n' + input.value : (entry.text || '');
            store(draftKey, input.value); grow();
            if (files && entry.attachments) { files.restore(entry.attachments); }
        };
        // Au chargement : un message resté sans confirmation revient (texte, pièces déjà envoyées).
        // Déjà arrivé (présent dans la page) : on l'oublie. Son identifiant n'est repris que s'il est seul et renvoyé tel quel.
        var pendingClientId = null;
        var leftover = unsent().filter(function (entry) { return !(entry.client_id && box.querySelector('[data-client-id="' + entry.client_id + '"]')); });
        leftover.forEach(function (entry) { restore(entry); });
        pendingClientId = leftover.length === 1 ? leftover[0].client_id || null : null;
        var restoredText = leftover.length === 1 ? leftover[0].text || '' : null;
        store(unsentKey, '');

        var post = function (url, payload) {
            return fetch(base + url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(payload)
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (data) { return { status: r.status, data: data }; });
            });
        };
        var send = function (entry, bubble) {
            error.hidden = true;
            return post('api/chat/conversations/' + cid + '/messages', {
                body: entry.text, client_id: entry.client_id, reply_to_id: entry.reply_to ? entry.reply_to.id : null,
                attachment_ids: (entry.attachments || []).map(function (a) { return a.id; })
            }).then(function (res) {
                if (res.status === 200 && res.data.event) {
                    forget(entry.client_id);
                    confirm(bubble, res.data.event);
                    return;
                }
                if ([401, 404, 419, 422, 429].indexOf(res.status) !== -1) {
                    // Refus définitif : le texte et les pièces reviennent dans la zone de saisie, rien n'est perdu.
                    forget(entry.client_id);
                    bubble.remove();
                    restore(entry);
                    if (res.status === 401 || res.status === 419) { sessionExpired(); return; }
                    if (res.status === 404) { error.textContent = 'Vous ne faites plus partie de cette conversation.'; error.hidden = false; return; }
                    showError(res.data, 'Message refusé.');
                    return;
                }
                throw new Error('network');
            }).catch(function () { if (bubble.isConnected) { failed(bubble, entry); } });
        };
        // La bulle provisoire devient le message confirmé (pas de seconde annonce au lecteur d'écran).
        var confirm = function (bubble, ev) {
            if (box.querySelector('[data-event-id="' + ev.id + '"]')) { bubble.remove(); return; }
            bubble.setAttribute('data-event-id', String(ev.id));
            bubble.setAttribute('data-message-id', String(ev.message_id));
            bubble.setAttribute('data-created', ev.created_at);
            bubble.id = 'm' + ev.message_id;
            bubble.classList.remove('is-pending', 'is-failed');
            var t = bubble.querySelector('.kb-msg-time');
            t.textContent = hour(ev.created_at); t.dateTime = ev.created_at;
            seen[ev.id] = true;
            lastEvent = Math.max(lastEvent, ev.id);
        };
        var failed = function (bubble, entry) {
            bubble.classList.remove('is-pending');
            bubble.classList.add('is-failed');
            var t = bubble.querySelector('.kb-msg-time');
            t.textContent = 'Non envoyé — ';
            var retry = document.createElement('button');
            retry.type = 'button'; retry.className = 'kb-msg-retry'; retry.textContent = 'Réessayer';
            retry.addEventListener('click', function () {
                bubble.classList.remove('is-failed'); bubble.classList.add('is-pending');
                t.textContent = 'Envoi…';
                // Même client_id : si le premier envoi était arrivé, le serveur renvoie le message existant.
                send(entry, bubble);
            });
            t.appendChild(retry);
        };

        var saveEdit = function (messageId, text) {
            return post('api/chat/messages/' + messageId + '/edit', { body: text }).then(function (res) {
                if (res.status === 200 && res.data.event) { updateMessage(res.data.event); seen[res.data.event.id] = true; return true; }
                if (res.status === 401 || res.status === 419) { sessionExpired(); return false; }
                showError(res.data, 'Modification impossible.');
                return false;
            }).catch(function () { showError(null, 'Réseau indisponible : la modification n\'a pas été enregistrée.'); return false; });
        };

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = input.value.replace(/\s+$/, '');
            if (stopped) { input.focus(); return; }
            if (editing) {
                if (text.trim() === '') { input.focus(); return; }
                var id = editing;
                saveEdit(id, text).then(function (ok) { if (ok) { cancelMode(); input.focus(); } });
                return;
            }
            if (files && files.busy()) { return; }
            if (files && files.failed()) {
                error.textContent = 'Une pièce jointe n\'a pas pu être envoyée : réessayez-la ou retirez-la avant d\'envoyer le message.';
                error.hidden = false;
                return;
            }
            var attachments = files ? files.pending() : [];
            if (text.trim() === '' && attachments.length === 0) { input.focus(); return; }
            // L'identifiant restauré n'est réutilisé que si le texte n'a pas changé (sinon ce serait un autre message).
            var reuse = pendingClientId && text === (restoredText || '').replace(/\s+$/, '');
            var entry = { text: text, client_id: reuse ? pendingClientId : newClientId(), attachments: attachments, reply_to: replyTo };
            pendingClientId = null; restoredText = null;
            // Bulle provisoire : le texte reste visible même si le réseau du chantier lâche.
            var bubble = buildEvent({
                id: 0, type: 'message', sender_id: me, body: text, created_at: new Date().toISOString(), reply_to: replyTo,
                attachments: attachments.map(function (a) { return { id: a.id, kind: a.kind, name: a.name, size: a.size, thumb_url: a.thumb_url, url: base + 'chat/attachments/' + a.id }; })
            });
            bubble.removeAttribute('data-event-id');
            bubble.setAttribute('data-client-id', entry.client_id);
            bubble.classList.add('is-pending');
            bubble.querySelector('.kb-msg-time').textContent = 'Envoi…';
            var empty = box.querySelector('[data-chat-empty]');
            if (empty) { empty.remove(); }
            box.appendChild(bubble);
            scrollBottom();
            keep(entry);
            input.value = ''; store(draftKey, ''); grow();
            if (files) { files.clear(); }
            replyTo = null; banner.hidden = true;
            input.focus();
            lastInteraction = Date.now();
            send(entry, bubble).then(function () { schedule(0); });
        });

        // --- Actions sur un message : PC au survol (bouton « Actions »), téléphone par appui long ---
        var menu = null, menuFor = null;
        var closeMenu = function () {
            if (!menu) { return; }
            menu.remove(); menu = null;
            var btn = menuFor && menuFor.querySelector('[data-msg-actions]');
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
            menuFor = null;
        };
        var item = function (label, name, attr, value) {
            var b = el('button', 'kb-msg-menu-item');
            b.type = 'button'; b.setAttribute('role', 'menuitem'); b.setAttribute(attr, value);
            if (name) { b.appendChild(icon(name)); }
            b.appendChild(el('span', '', label));
            return b;
        };
        var openMenu = function (li, anchor) {
            closeMenu();
            menuFor = li;
            menu = el('div', 'kb-msg-menu');
            menu.setAttribute('role', 'menu'); menu.setAttribute('aria-label', 'Actions sur le message');
            var deleted = li.getAttribute('data-deleted') === '1';
            if (!deleted) {
                var row = el('div', 'kb-msg-menu-reactions');
                REACTIONS.forEach(function (emo) {
                    var b = el('button', 'kb-msg-menu-react', emo);
                    b.type = 'button'; b.setAttribute('role', 'menuitem'); b.setAttribute('data-menu-react', emo); b.setAttribute('aria-label', 'Réagir ' + emo);
                    row.appendChild(b);
                });
                menu.appendChild(row);
                menu.appendChild(item('Répondre', 'reply', 'data-menu', 'reply'));
            }
            var recent = Date.now() - new Date(li.getAttribute('data-created')).getTime() < EDIT_WINDOW;
            if (li.getAttribute('data-mine') === '1' && !deleted && recent) {
                if (li.querySelector('.kb-msg-text')) { menu.appendChild(item('Modifier', 'pencil', 'data-menu', 'edit')); }
                menu.appendChild(item('Supprimer', 'trash-2', 'data-menu', 'delete'));
            }
            li.appendChild(menu);
            if (anchor) { anchor.setAttribute('aria-expanded', 'true'); }
            if (window.lucide) { window.lucide.createIcons(); }
            var first = menu.querySelector('button');
            if (first) { first.focus(); }
        };
        var react = function (messageId, emoji) {
            post('api/chat/messages/' + messageId + '/reactions', { emoji: emoji }).then(function (res) {
                if (res.status === 200 && res.data.event) { updateMessage(res.data.event); seen[res.data.event.id] = true; }
                else if (res.status === 401 || res.status === 419) { sessionExpired(); }
                else { showError(res.data, 'Réaction impossible.'); }
            }).catch(function () { showError(null, 'Réseau indisponible.'); });
        };
        var deleteForm = document.querySelector('[data-chat-delete]'), toDelete = null;
        deleteForm.addEventListener('submit', function (e) {
            // La fenêtre de confirmation (app.js) a déjà été validée quand cet envoi arrive ici.
            e.preventDefault();
            if (!toDelete) { return; }
            var id = toDelete; toDelete = null;
            post('api/chat/messages/' + id + '/delete', {}).then(function (res) {
                if (res.status === 200 && res.data.event) { updateMessage(res.data.event); seen[res.data.event.id] = true; }
                else if (res.status === 401 || res.status === 419) { sessionExpired(); }
                else { showError(res.data, 'Suppression impossible.'); }
            }).catch(function () { showError(null, 'Réseau indisponible : le message n\'a pas été supprimé.'); });
        });
        var excerptOf = function (li) {
            var text = li.querySelector('.kb-msg-text');
            var t = text ? text.textContent.trim() : li.querySelector('.kb-msg-photo') ? 'Photo' : li.querySelector('.kb-msg-file-name') ? 'Fichier : ' + li.querySelector('.kb-msg-file-name').textContent : '';
            return t.length > 80 ? t.slice(0, 80) + '…' : t;
        };
        var authorOf = function (li) {
            if (li.getAttribute('data-mine') === '1') { return 'vous'; }
            var a = li.querySelector('.kb-msg-author'), hidden = li.querySelector('.kb-msg-bubble > .visually-hidden');
            return a ? a.textContent : hidden ? hidden.textContent.replace(/ : $/, '') : '';
        };

        box.addEventListener('click', function (e) {
            // Premier élément du chemin encore utilisable (voir composedPath plus bas).
            var t = e.composedPath().filter(function (n) { return n.closest && n.isConnected; })[0];
            if (!t) { return; }
            var btn = t.closest('[data-msg-actions]');
            if (btn) { var host = btn.closest('.kb-msg'); if (menuFor === host) { closeMenu(); } else { openMenu(host, btn); } return; }
            var reactBtn = t.closest('.kb-msg-reactions [data-react]');
            if (reactBtn) { react(reactBtn.closest('.kb-msg').getAttribute('data-message-id'), reactBtn.getAttribute('data-react')); return; }
            var choice = t.closest('.kb-msg-menu [data-menu], .kb-msg-menu [data-menu-react]');
            if (!choice || !menuFor) { return; }
            var li = menuFor, id = li.getAttribute('data-message-id');
            closeMenu();
            if (choice.hasAttribute('data-menu-react')) { react(id, choice.getAttribute('data-menu-react')); return; }
            var action = choice.getAttribute('data-menu');
            if (action === 'reply') {
                if (editing) { cancelMode(); }
                replyTo = { id: parseInt(id, 10), sender_name: authorOf(li), excerpt: excerptOf(li) };
                setMode('Réponse à ' + replyTo.sender_name + ' : ' + replyTo.excerpt);
                input.focus();
            } else if (action === 'edit') {
                replyTo = null; editing = id;
                input.value = li.querySelector('.kb-msg-text').textContent; grow();
                setMode('Modification du message');
                input.focus();
            } else if (action === 'delete') {
                toDelete = id;
                deleteForm.requestSubmit();
            }
        });
        // Appui long (500 ms) sur un message : menu d'actions sur téléphone.
        var pressTimer = null;
        box.addEventListener('touchstart', function (e) {
            var li = e.target.closest && e.target.closest('.kb-msg[data-message-id]');
            if (!li || e.target.closest('a, button')) { return; }
            pressTimer = setTimeout(function () { pressTimer = null; openMenu(li, null); }, 500);
        }, { passive: true });
        ['touchend', 'touchmove', 'touchcancel'].forEach(function (type) {
            box.addEventListener(type, function () { clearTimeout(pressTimer); }, { passive: true });
        });
        box.addEventListener('contextmenu', function (e) { if (menu && e.target.closest('.kb-msg')) { e.preventDefault(); } });
        // composedPath : lucide.createIcons() redessine les icônes et peut détacher la cible du clic du document.
        document.addEventListener('click', function (e) {
            var inside = e.composedPath().some(function (n) { return n.matches && n.matches('.kb-msg-menu, [data-msg-actions]'); });
            if (menu && !inside) { closeMenu(); }
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu) { var li = menuFor; closeMenu(); var b = li && li.querySelector('[data-msg-actions]'); if (b && !b.hidden) { b.focus(); } } });
        // Boutons « Actions » des messages rendus par le serveur (souris, écran tactile, lecteur d'écran).
        box.querySelectorAll('[data-msg-actions]').forEach(function (b) { b.hidden = false; });
    }

    if (box) { scrollBottom(); }
    schedule();
}());
