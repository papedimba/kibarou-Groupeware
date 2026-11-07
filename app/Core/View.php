<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Gabarits PHP natifs. Toute variable affichée doit passer par e().
 * Une vue peut déclarer un layout via $this->layout('layouts/app').
 */
final class View
{
    private ?string $layout = null;
    /** @var array<string,mixed> */
    private array $layoutData = [];
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(private readonly string $dir)
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $this->layout = null;
        $content = $this->renderFile($template, $data);
        while ($this->layout !== null) {
            $layout = $this->layout;
            $data = [...$data, ...$this->layoutData, 'content' => $content];
            $this->layout = null;
            $this->layoutData = [];
            $content = $this->renderFile($layout, $data);
        }
        return $content;
    }

    /** @param array<string,mixed> $data variables transmises au layout (title, breadcrumbs...) */
    public function layout(string $name, array $data = []): void
    {
        $this->layout = $name;
        $this->layoutData = $data;
    }

    /** @param array<string,mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $saved = $this->layout;
        $out = $this->renderFile($template, $data);
        $this->layout = $saved;
        return $out;
    }

    /** @param array<string,mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        if (!preg_match('#^[a-z0-9_/-]+$#', $template)) {
            throw new RuntimeException("Nom de vue invalide : $template");
        }
        $file = $this->dir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Vue introuvable : $template");
        }
        extract([...$this->shared, ...$data], EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
