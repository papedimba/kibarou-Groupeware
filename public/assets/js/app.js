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

    // Confirmation avant les actions sensibles : <form data-confirm="Message">
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        });
    });

    // Soumission automatique des filtres : <select data-autosubmit>
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });
})();
