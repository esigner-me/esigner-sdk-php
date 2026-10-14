<?php

declare(strict_types=1);

namespace Esigner\Service;

use Esigner\HttpClient;

/** The same events delivered to your webhooks, newest first — for backfilling after downtime. */
final class EventService
{
    /** @internal */
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * One page. `type` narrows to one event type (see Webhook::EVENT_TYPES).
     *
     * @param array{limit?: int, cursor?: string, type?: string} $params
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function list(array $params = []): array
    {
        return $this->http->request('GET', '/events', query: $params);
    }

    /**
     * Every event (optionally of one type), fetching pages as you iterate.
     *
     * @param array{limit?: int, type?: string} $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function all(array $params = []): \Generator
    {
        yield from Paginator::each(fn (?string $cursor) => $this->list($params + ['cursor' => $cursor]));
    }
}
