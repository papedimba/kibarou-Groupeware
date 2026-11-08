<?php /** @var array<string,mixed> $node @var array<int,array<string,mixed>> $statuses @var int $myDepartment */ ?>
<details class="kb-org-node" id="service-<?= (int) $node['id'] ?>" open<?= $node['id'] === $myDepartment ? ' data-mine' : '' ?>>
    <summary><span class="kb-org-name"><?= e($node['name']) ?></span> <span class="kb-org-count"><?= count($node['members']) + ($node['manager'] !== null ? 1 : 0) ?> personne(s)</span></summary>
    <ul class="list-unstyled kb-org-people">
        <?php if ($node['manager'] !== null): ?><?= $this->partial('directory/_org_person', ['p' => $node['manager'], 'statuses' => $statuses, 'head' => true]) ?><?php endif; ?>
        <?php foreach ($node['members'] as $m): ?><?= $this->partial('directory/_org_person', ['p' => $m, 'statuses' => $statuses, 'head' => false]) ?><?php endforeach; ?>
    </ul>
    <?php foreach ($node['children'] as $child): ?>
        <?= $this->partial('directory/_org_node', ['node' => $child, 'statuses' => $statuses, 'myDepartment' => $myDepartment]) ?>
    <?php endforeach; ?>
</details>
