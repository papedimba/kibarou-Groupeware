<?php /** @var list<array<string,mixed>> $rows */ ?>
<div class="table-responsive">
    <table class="table table-sm mb-0 align-middle kb-table-stack">
        <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>IP</th><th>Détails</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td data-label="Date" class="text-nowrap"><?= e(format_date($r['created_at'], 'd/m/Y H:i:s')) ?></td>
                <td data-label="Utilisateur"><?= e($r['user_name'] ?? ($r['user_id'] ? '#' . $r['user_id'] : '—')) ?></td>
                <td data-label="Action"><code><?= e($r['action']) ?></code></td>
                <td data-label="Objet"><?= $r['entity_type'] ? e($r['entity_type'] . ' #' . $r['entity_id']) : '—' ?></td>
                <td data-label="IP" class="small"><?= e($r['ip_address'] ?? '') ?></td>
                <td data-label="Détails" class="small text-secondary text-break"><?= e(mb_strimwidth((string) $r['details'], 0, 120, '…')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="6" class="text-center text-secondary py-4">Aucune entrée.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
