<?php

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public readonly int $pages;

    /** @param list<array<string,mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->pages = max(1, (int) ceil($total / max(1, $perPage)));
    }

    public static function offset(int $page, int $perPage): int
    {
        return (max(1, $page) - 1) * $perPage;
    }
}
