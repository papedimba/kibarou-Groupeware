// Service worker d'affichage seulement (lot 3) : montre les alertes et gère le clic.
// Pas de cache ni de push : le lot 10 enrichira ce fichier.
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var fallback = self.registration.scope + 'notifications';
    var url = fallback;
    try {
        // Liens de l'intranet uniquement : même origine que le service worker.
        var target = new URL((event.notification.data && event.notification.data.url) || fallback, self.location.origin);
        if (target.origin === self.location.origin) { url = target.href; }
    } catch (e) { url = fallback; }
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if (new URL(list[i].url).origin === self.location.origin) {
                // focus() ou navigate() peuvent être refusés : on ouvre alors une nouvelle fenêtre.
                return list[i].focus().then(function (c) { return c.navigate(url); }).catch(function () { return self.clients.openWindow(url); });
            }
        }
        return self.clients.openWindow(url);
    }));
});
