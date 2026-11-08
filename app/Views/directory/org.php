<?php
/** @var list<array<string,mixed>> $tree @var list<array<string,mixed>> $without @var array<int,array<string,mixed>> $statuses @var int $myDepartment */
$this->layout('layouts/app', ['title' => 'Organigramme', 'breadcrumbs' => [['Annuaire', '/directory'], ['Organigramme', null]]]);
?>
<div class="kb-page-title">
    <h1>Organigramme</h1>
    <a class="btn btn-outline-primary" href="<?= e(url('/directory')) ?>"><i data-lucide="contact" aria-hidden="true"></i> Annuaire</a>
</div>
<div class="kb-org">
    <?php foreach ($tree as $node): ?>
        <?= $this->partial('directory/_org_node', ['node' => $node, 'statuses' => $statuses, 'myDepartment' => $myDepartment]) ?>
    <?php endforeach; ?>
    <?php if ($without !== []): ?>
        <details class="kb-org-node" open<?= $myDepartment === 0 ? ' data-mine' : '' ?>>
            <summary><span class="kb-org-name">Sans service</span> <span class="kb-org-count"><?= count($without) ?> personne(s)</span></summary>
            <ul class="list-unstyled kb-org-people">
                <?php foreach ($without as $m): ?><?= $this->partial('directory/_org_person', ['p' => $m, 'statuses' => $statuses, 'head' => false]) ?><?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>
    <?php if ($tree === [] && $without === []): ?><p class="text-secondary">Aucun service n'est encore défini.</p><?php endif; ?>
</div>
