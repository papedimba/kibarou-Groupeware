// Kibarou Groupware — messagerie : pièces jointes (réduction des photos, envoi un par un avec progression)
// et visionneuse de photos. Construction DOM uniquement (aucun innerHTML avec des données).
(function () {
    'use strict';

    var base = document.body.getAttribute('data-base') || '/';
    var conv = document.querySelector('[data-chat-conversation]');
    var form = document.querySelector('[data-chat-form]');
    var MAX_FILES = 10, MAX_BYTES = 6 * 1024 * 1024, SIDE = 2000;
    var HEAVY = 'Cette photo est trop lourde (6 Mo au maximum). Prenez-la en qualité réduite ou envoyez-la depuis un autre appareil.';
    var items = [];

    function icon(name) {
        var i = document.createElement('i');
        i.setAttribute('data-lucide', name); i.setAttribute('aria-hidden', 'true');
        return i;
    }
    function drawIcons() { if (window.lucide) { window.lucide.createIcons(); } }
    function sizeLabel(bytes) {
        if (bytes < 1024) { return bytes + ' octets'; }
        if (bytes < 1048576) { return Math.round(bytes / 1024) + ' Ko'; }
        return (bytes / 1048576).toFixed(1).replace('.', ',').replace(',0', '') + ' Mo';
    }

    // --- Photos : réduites dans le navigateur (2 000 px au grand côté) ; le serveur revérifie et réencode. ---
    function shrink(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) { return Promise.resolve(file); }
        return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
            var ratio = Math.min(1, SIDE / Math.max(bmp.width, bmp.height));
            if (ratio === 1 && file.size <= MAX_BYTES) { return file; }
            var canvas = document.createElement('canvas');
            canvas.width = Math.round(bmp.width * ratio); canvas.height = Math.round(bmp.height * ratio);
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
            return new Promise(function (done) {
                canvas.toBlob(function (blob) {
                    done(blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
                }, 'image/jpeg', 0.85);
            });
        }).catch(function () { return file; });
    }

    if (!conv || !form) { return; }
    var cid = conv.getAttribute('data-chat-conversation');
    var csrf = form.querySelector('input[name=_csrf]').value;
    var tray = form.querySelector('[data-chat-tray]');
    var attach = form.querySelector('[data-chat-attach]');
    var input = form.querySelector('[data-chat-file]');
    attach.hidden = false;
    // Annonces pour les lecteurs d'écran : fin d'envoi ou échec de chaque fichier.
    var status = document.createElement('p');
    status.className = 'visually-hidden'; status.setAttribute('aria-live', 'polite'); status.setAttribute('data-chat-tray-status', '');
    form.appendChild(status);
    function announce(text) { status.textContent = text; }
    function changed() { form.dispatchEvent(new Event('kb-attachments-change')); }

    function render(item) {
        var li = item.el || document.createElement('li');
        item.el = li;
        li.textContent = '';
        li.className = 'kb-tray-item is-' + item.state;
        // Taille réellement envoyée (après réduction) : utile au test de fumée et au diagnostic.
        if (item.file) { li.setAttribute('data-bytes', String(item.file.size)); }
        if (item.kind === 'image' && (item.preview || item.thumb_url)) {
            var img = document.createElement('img');
            img.src = item.preview || item.thumb_url; img.alt = '';
            li.appendChild(img);
        } else {
            li.appendChild(icon(/\.xlsx$/i.test(item.name) ? 'file-spreadsheet' : 'file-text'));
        }
        var text = document.createElement('span');
        text.className = 'kb-tray-text';
        var name = document.createElement('span');
        name.className = 'kb-tray-name'; name.textContent = item.name;
        var status = document.createElement('span');
        status.className = 'kb-tray-status';
        status.textContent = item.state === 'uploading' ? 'Envoi… ' + item.progress + ' %'
            : item.state === 'failed' ? item.error : item.state === 'done' ? sizeLabel(item.size) : 'En attente';
        text.appendChild(name); text.appendChild(status);
        li.appendChild(text);
        if (item.state === 'uploading') {
            var bar = document.createElement('progress');
            bar.max = 100; bar.value = item.progress; bar.setAttribute('aria-label', 'Envoi de ' + item.name);
            text.appendChild(bar);
        }
        if (item.state === 'failed' && item.retry) {
            var retry = document.createElement('button');
            retry.type = 'button'; retry.className = 'btn btn-link btn-sm kb-tray-retry'; retry.textContent = 'Réessayer';
            retry.addEventListener('click', function () { upload(item); });
            li.appendChild(retry);
        }
        var remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'kb-tray-remove';
        remove.setAttribute('aria-label', 'Retirer ' + item.name);
        remove.appendChild(icon('x'));
        // Une pièce déjà envoyée mais retirée devient orpheline : le serveur l'efface au bout de 24 h.
        remove.addEventListener('click', function () { drop(item); });
        li.appendChild(remove);
        if (!li.parentNode) { tray.appendChild(li); }
        tray.hidden = items.length === 0;
        drawIcons();
    }

    function drop(item) {
        if (item.xhr) { item.xhr.abort(); }
        if (item.preview) { URL.revokeObjectURL(item.preview); }
        items.splice(items.indexOf(item), 1);
        item.el.remove();
        tray.hidden = items.length === 0;
        // xhr.abort() ne déclenche ni onload ni onerror : il faut prévenir le formulaire ici.
        changed();
        input.focus();
    }

    function upload(item) {
        item.state = 'uploading'; item.progress = 0; render(item);
        var data = new FormData();
        data.append('file', item.file, item.file.name);
        data.append('_csrf', csrf);
        var xhr = new XMLHttpRequest();
        item.xhr = xhr;
        xhr.open('POST', base + 'api/chat/conversations/' + cid + '/attachments');
        xhr.setRequestHeader('Accept', 'application/json');
        // Progression : mise à jour en place (reconstruire l'élément ferait perdre le focus clavier).
        xhr.upload.onprogress = function (e) {
            if (!e.lengthComputable || !item.el) { return; }
            item.progress = Math.round(e.loaded / e.total * 100);
            var bar = item.el.querySelector('progress'), st = item.el.querySelector('.kb-tray-status');
            if (bar) { bar.value = item.progress; }
            if (st) { st.textContent = 'Envoi… ' + item.progress + ' %'; }
        };
        xhr.onload = function () {
            item.xhr = null;
            var res = {};
            try { res = JSON.parse(xhr.responseText); } catch (e) { /* réponse illisible */ }
            if (xhr.status === 200 && res.attachment) {
                item.id = res.attachment.id; item.kind = res.attachment.kind; item.size = res.attachment.size;
                item.thumb_url = res.attachment.thumb_url; item.state = 'done';
            } else {
                item.state = 'failed';
                item.retry = xhr.status === 0 || xhr.status >= 500 || xhr.status === 429;
                item.error = xhr.status === 401 || xhr.status === 419 ? 'Session expirée : rechargez la page.'
                    : (res.errors && res.errors.file) || res.error || 'Envoi impossible.';
            }
            render(item);
            announce(item.state === 'done' ? item.name + ' envoyé.' : item.name + ' : ' + item.error);
            changed();
        };
        xhr.onerror = function () {
            item.xhr = null; item.state = 'failed'; item.retry = true; item.error = 'Réseau indisponible.';
            render(item);
            announce(item.name + ' : ' + item.error);
            changed();
        };
        xhr.send(data);
    }

    function add(files) {
        [].slice.call(files).forEach(function (file) {
            if (items.length >= MAX_FILES) {
                var full = { name: file.name, kind: 'file', state: 'failed', error: 'Dix pièces jointes au plus par message.', retry: false };
                items.push(full); render(full);
                return;
            }
            var item = { name: file.name, kind: /^image\//.test(file.type) ? 'image' : 'file', state: 'waiting', progress: 0 };
            items.push(item);
            render(item);
            shrink(file).then(function (ready) {
                if (items.indexOf(item) === -1) { return; }
                if (ready.size > MAX_BYTES) {
                    item.state = 'failed'; item.retry = false;
                    item.error = item.kind === 'image' ? HEAVY : 'Ce fichier est trop lourd (6 Mo au maximum).';
                    render(item);
                    return;
                }
                item.file = ready; item.name = ready.name;
                if (item.kind === 'image') { item.preview = URL.createObjectURL(ready); }
                upload(item);
            });
        });
        form.dispatchEvent(new Event('kb-attachments-change'));
    }

    input.addEventListener('change', function () { add(input.files); input.value = ''; });

    window.kbChatAttachments = {
        // Pièces prêtes à partir avec le message.
        pending: function () {
            return items.filter(function (i) { return i.state === 'done'; })
                .map(function (i) { return { id: i.id, kind: i.kind, name: i.name, size: i.size, thumb_url: i.thumb_url }; });
        },
        busy: function () { return items.some(function (i) { return i.state === 'uploading' || i.state === 'waiting'; }); },
        // Une pièce en échec n'est jamais abandonnée en silence : l'envoi est bloqué tant qu'elle reste là.
        failed: function () { return items.some(function (i) { return i.state === 'failed'; }); },
        clear: function () { items.slice().forEach(function (i) { if (i.preview) { URL.revokeObjectURL(i.preview); } }); items = []; tray.textContent = ''; tray.hidden = true; },
        // Pièces déjà envoyées d'un message resté sans confirmation (rechargement de la page).
        restore: function (list) {
            (list || []).forEach(function (a) {
                var item = { id: a.id, kind: a.kind, name: a.name, size: a.size, thumb_url: a.thumb_url, state: 'done' };
                items.push(item); render(item);
            });
        }
    };

    // --- Visionneuse : une photo en grand, précédente / suivante parmi celles du message. ---
    var viewer = null, shown = [], index = 0, opener = null;
    function show(i) {
        index = (i + shown.length) % shown.length;
        viewer.querySelector('img').src = shown[index].href;
        viewer.querySelector('[data-viewer-count]').textContent = shown.length > 1 ? (index + 1) + ' / ' + shown.length : '';
        viewer.querySelectorAll('[data-viewer-nav]').forEach(function (b) { b.hidden = shown.length < 2; });
    }
    function close() {
        viewer.hidden = true;
        document.body.classList.remove('kb-viewer-open');
        if (opener) { opener.focus(); }
    }
    function button(label, name, onClick, attr) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'kb-viewer-btn'; b.setAttribute('aria-label', label);
        if (attr) { b.setAttribute(attr, ''); }
        b.appendChild(icon(name));
        b.addEventListener('click', function (e) { e.stopPropagation(); onClick(); });
        return b;
    }
    function build() {
        viewer = document.createElement('div');
        viewer.className = 'kb-viewer'; viewer.hidden = true;
        viewer.setAttribute('role', 'dialog'); viewer.setAttribute('aria-modal', 'true'); viewer.setAttribute('aria-label', 'Photo');
        var img = document.createElement('img');
        img.alt = 'Photo en grand';
        var count = document.createElement('span');
        count.className = 'kb-viewer-count'; count.setAttribute('data-viewer-count', '');
        var closeBtn = button('Fermer', 'x', close);
        closeBtn.classList.add('kb-viewer-close');
        var prev = button('Photo précédente', 'chevron-left', function () { show(index - 1); }, 'data-viewer-nav');
        var next = button('Photo suivante', 'chevron-right', function () { show(index + 1); }, 'data-viewer-nav');
        prev.classList.add('kb-viewer-prev'); next.classList.add('kb-viewer-next');
        [img, count, prev, next, closeBtn].forEach(function (el) { viewer.appendChild(el); });
        viewer.addEventListener('click', function (e) { if (e.target === viewer) { close(); } });
        viewer.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); }
            if (e.key === 'ArrowLeft') { show(index - 1); }
            if (e.key === 'ArrowRight') { show(index + 1); }
            // Focus gardé dans la visionneuse.
            if (e.key === 'Tab') {
                var f = [].filter.call(viewer.querySelectorAll('button'), function (b) { return !b.hidden; });
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
        document.body.appendChild(viewer);
        drawIcons();
    }
    conv.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('[data-photo]');
        if (!link || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
        e.preventDefault();
        if (!viewer) { build(); }
        opener = link;
        shown = [].slice.call(link.closest('.kb-msg').querySelectorAll('[data-photo]'));
        viewer.hidden = false;
        document.body.classList.add('kb-viewer-open');
        show(shown.indexOf(link));
        viewer.querySelector('.kb-viewer-close').focus();
    });
}());
