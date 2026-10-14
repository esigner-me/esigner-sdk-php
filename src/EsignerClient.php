<?php

declare(strict_types=1);

namespace Esigner;

use Esigner\Service\BalanceService;
use Esigner\Service\DocumentService;
use Esigner\Service\EventService;
use Esigner\Service\SignatureRequestService;

/**
 * The esigner API client (https://esigner.no/dokumentasjon) — the same shape as the Node SDK,
 * @esigner/sdk: one service per resource, responses as arrays exactly as the API documents them.
 *
 *     $esigner = new EsignerClient(getenv('ESIGNER_API_KEY'), ['market' => 'se']);
 *     $request = $esigner->signatureRequests->createWithFiles('contract.pdf', [...]);
 *
 * Needs only ext-curl and ext-json, so it drops into WordPress or any framework.
 */
class EsignerClient
{
    public const VERSION = '0.2.0';

    /** The API's own markets. A key belongs to the market it was created in. */
    public const MARKETS = [
        'no' => 'https://esigner.no/api/v1',
        'dk' => 'https://esigner.dk/api/v1',
        'se' => 'https://esigner.se/api/v1',
        'fi' => 'https://esigner.fi/api/v1',
        'de' => 'https://esigner.de/api/v1',
        'nl' => 'https://esigner.nl/api/v1',
        'uk' => 'https://esigner.uk/api/v1',
    ];

    public readonly DocumentService $documents;
    public readonly SignatureRequestService $signatureRequests;
    public readonly BalanceService $balance;
    public readonly EventService $events;

    /**
     * @param array{market?: string, base_url?: string, max_retries?: int, timeout?: int, transport?: callable} $options
     *   market: which esigner site the key belongs to (default `no`). base_url: a full API base
     *   instead, e.g. a staging host. max_retries: after a network error, 429 or 5xx (default 2).
     *   timeout: seconds per attempt (default 60). transport: for tests.
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (!preg_match('/^sk_(live|test)_/', $apiKey)) {
            throw new \InvalidArgumentException('Esigner: pass an API key (sk_live_… or sk_test_…) — create one under Konto → Utvikler.');
        }
        $market = $options['market'] ?? 'no';
        if (!isset($options['base_url']) && !isset(self::MARKETS[$market])) {
            throw new \InvalidArgumentException("Esigner: unknown market '{$market}'.");
        }
        $http = new HttpClient(
            $apiKey,
            rtrim($options['base_url'] ?? self::MARKETS[$market], '/'),
            $options['max_retries'] ?? 2,
            $options['timeout'] ?? 60,
            $options['transport'] ?? null,
        );
        $this->documents = new DocumentService($http);
        $this->signatureRequests = new SignatureRequestService($http, $this->documents);
        $this->balance = new BalanceService($http);
        $this->events = new EventService($http);
    }
}
