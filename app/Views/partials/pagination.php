<?php
/** @var App\Core\Paginator $pager @var array<string,mixed> $query paramètres à conserver */
if ($pager->pages <= 1) {
    return;
}
$link = static fn (int $page): string => '?' . http_build_query([...$query, 'page' => $page]);
?>
<nav aria-label="Pagination" class="d-flex justify-content-between align-items-center mt-3">
    <span class="small text-secondary">Page <?= $pager->page ?> sur <?= $pager->pages ?> · <?= $pager->total ?> élément(s)</span>
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item<?= $pager->page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link($pager->page - 1)) ?>">Précédent</a></li>
        <li class="page-item<?= $pager->page >= $pager->pages ? ' disabled' : '' ?>"><a class="page-link" href="<?= e($link($pager->page + 1)) ?>">Suivant</a></li>
    </ul>
</nav>
