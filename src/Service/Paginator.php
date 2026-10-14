<?php

declare(strict_types=1);

namespace Esigner\Service;

/** @internal Walks `{data, next_cursor}` pages until the cursor runs out. */
final class Paginator
{
    /**
     * @param callable(?string): array{data: list<array<string, mixed>>, next_cursor: ?string} $page
     * @return \Generator<int, array<string, mixed>>
     */
    public static function each(callable $page): \Generator
    {
        $cursor = null;
        do {
            $p = $page($cursor);
            foreach ($p['data'] as $item) {
                yield $item;
            }
            $cursor = $p['next_cursor'] ?? null;
        } while ($cursor !== null && $cursor !== '');
    }
}
