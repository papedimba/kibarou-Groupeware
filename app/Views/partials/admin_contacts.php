<?php /** @var list<array<string,mixed>> $contacts */ ?>
<?php if ($contacts === []): ?>
    <p class="mb-0 text-secondary">Aucun administrateur n'est encore désigné.</p>
<?php else: ?>
    <ul class="list-unstyled mb-0 kb-contacts">
        <?php foreach ($contacts as $c): ?>
            <li>
                <span class="fw-medium"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></span>
                <a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a>
                <?php if (!empty($c['phone'])): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $c['phone'])) ?>"><?= e($c['phone']) ?></a><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
