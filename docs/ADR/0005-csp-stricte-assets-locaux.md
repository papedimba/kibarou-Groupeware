# ADR 0005 — CSP stricte et assets auto-hébergés

- Statut : accepté (07/11/2026)

## Contexte
Prévenir le XSS et ne pas dépendre de CDN tiers pour un intranet.

## Décision
Bootstrap 5.3.3, Lucide 0.460.0 et Inter (variable) sont copiés dans `public/assets/vendor` (licences jointes).
CSP : `default-src 'self'`, aucun `unsafe-inline` ni `unsafe-eval`. Aucun script ni attribut `style` inline dans les vues ;
les comportements passent par des attributs `data-*` gérés dans `public/assets/js/app.js`.

## Conséquences
- (+) Un XSS résiduel ne peut ni exécuter de script inline ni exfiltrer vers un domaine tiers.
- (−) Mise à jour manuelle des bibliothèques front (copie de fichiers versionnés).
