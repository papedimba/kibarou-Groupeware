<?php
/** @var App\Core\Paginator $logs @var array<string,mixed> $filters */
$this->layout('layouts/app', ['title' => 'Journal d\'audit', 'breadcrumbs' => [['Administration', '/admin'], ['Journal d\'audit', null]]]);
?>
<div class="kb-page-title"><h1>Journal d'audit</h1></div>
<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-lg-4">
        <label class="visually-hidden" for="action">Action</label>
        <input class="form-control" id="action" name="action" placeholder="Action (ex. auth., user.)" value="<?= e($filters['action']) ?>">
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit"><i data-lucide="search" aria-hidden="true"></i> Filtrer</button></div>
</form>
<div class="card"><?= $this->partial('admin/_audit_table', ['rows' => $logs->items]) ?></div>
<?= $this->partial('partials/pagination', ['pager' => $logs, 'query' => array_filter($filters)]) ?>
