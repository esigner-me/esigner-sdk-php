<?php

declare(strict_types=1);

namespace Esigner\Service;

use Esigner\HttpClient;

/** The prepaid balance, where the market offers one. Amounts are in minor units (øre). */
final class BalanceService
{
    /** @internal */
    public function __construct(private readonly HttpClient $http)
    {
    }

    /** @return array<string, mixed> `balance_ore`, `currency`, `livemode`, … */
    public function retrieve(): array
    {
        return $this->http->request('GET', '/balance');
    }

    /**
     * One page, newest first. Pass `next_cursor` back as `cursor` for the next.
     *
     * @param array{limit?: int, cursor?: string} $params
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function listTransactions(array $params = []): array
    {
        return $this->http->request('GET', '/balance/transactions', query: $params);
    }

    /**
     * Every transaction, newest first, fetching pages as you iterate.
     *
     * @param array{limit?: int} $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function allTransactions(array $params = []): \Generator
    {
        yield from Paginator::each(fn (?string $cursor) => $this->listTransactions($params + ['cursor' => $cursor]));
    }
}
