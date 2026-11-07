<?php
/** @var App\Core\Paginator $logs @var array<string,mixed> $filters */
$this->layout('layouts/app', ['title' => 'Journal d\'audit', 'breadcrumbs' => [['Administration', '/admin'], ['Journal d\'audit', null]]]);
?>
<div class="kb-page-title"><h1>Journal d'audit</h1></div>
<form class="row g-2 mb-3" method="get">
    <div class="col-sm-6 col-lg-4">
        <label class="visually-hidden" for="action">Type d'action</label>
        <select class="form-select" id="action" name="action" data-autosubmit>
            <option value="">Toutes les actions</option>
            <?php foreach (\App\Modules\Admin\AuditLabels::all() as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $filters['action'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary" type="submit"><i data-lucide="search" aria-hidden="true"></i> Filtrer</button></div>
</form>
<div class="card"><?= $this->partial('admin/_audit_table', ['rows' => $logs->items]) ?></div>
<?= $this->partial('partials/pagination', ['pager' => $logs, 'query' => array_filter($filters)]) ?>
