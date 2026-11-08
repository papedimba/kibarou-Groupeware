// Service worker d'affichage seulement (lot 3) : montre les alertes et gère le clic.
// Pas de cache ni de push : le lot 10 enrichira ce fichier.
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) || '/notifications';
    // Liens internes uniquement.
    if (url.charAt(0) !== '/' || url.charAt(1) === '/') { url = '/notifications'; }
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if (new URL(list[i].url).origin === self.location.origin) {
                return list[i].focus().then(function (c) { return c.navigate(url); });
            }
        }
        return self.clients.openWindow(url);
    }));
});
