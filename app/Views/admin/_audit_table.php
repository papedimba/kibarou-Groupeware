<?php
/** @var list<array<string,mixed>> $rows */
use App\Modules\Admin\AuditLabels;
?>
<div class="table-responsive">
    <table class="table table-sm mb-0 align-middle kb-table-stack">
        <thead><tr><th>Date</th><th>Action</th><th>Par</th><th>Concerne</th><th>Détails</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <?php $action = (string) $r['action']; ?>
            <tr>
                <td data-label="Date" class="text-nowrap"><?= e(format_date($r['created_at'])) ?></td>
                <td class="kb-cell-title">
                    <?php if (AuditLabels::isAlert($action)): ?><span class="badge badge-alert"><?= e(AuditLabels::label($action)) ?></span>
                    <?php else: ?><?= e(AuditLabels::label($action)) ?><?php endif; ?>
                </td>
                <td data-label="Par"><?= e(AuditLabels::actor($action, $r['user_name'] ?? null)) ?></td>
                <td data-label="Concerne">
                    <?php if (!empty($r['target_name'])): ?>
                        <?php if ($r['entity_type'] === 'user' && can('users.view')): ?><a href="<?= e(url('/admin/users/' . (int) $r['entity_id'])) ?>"><?= e($r['target_name']) ?></a><?php else: ?><?= e($r['target_name']) ?><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td data-label="Détails" class="small text-secondary">
                    <?php foreach (AuditLabels::details($r['details'] !== null ? (string) $r['details'] : null) as [$k, $v]): ?>
                        <span class="d-block"><?= e($k) ?> : <?= e($v) ?></span>
                    <?php endforeach; ?>
                    <?php if (!empty($r['ip_address'])): ?><span class="d-block">Adresse IP : <?= e($r['ip_address']) ?></span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="5" class="text-center text-secondary py-4">Aucune entrée.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
