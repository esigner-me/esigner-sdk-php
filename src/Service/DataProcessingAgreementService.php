<?php

declare(strict_types=1);

namespace Esigner\Service;

use Esigner\HttpClient;

/**
 * Our data processing agreement (GDPR art. 28), asked for on your company's behalf. We review it and
 * send it for signing to `contact.email`; there is nothing to sign through the API.
 */
final class DataProcessingAgreementService
{
    /** @internal */
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Ask for one. While a request for the organisation is open, the open one comes back instead.
     *
     * @param array{contact: array{name: string, email: string}, company?: array{name: string, org_number: string}, language?: string, instructions?: string} $params
     * @return array<string, mixed> `id`, `status` (received | sent_for_signing | signed | closed), …
     */
    public function request(array $params): array
    {
        return $this->http->request('POST', '/data_processing_agreements', json: $params);
    }

    /** @return array{data: list<array<string, mixed>>} This account's requests, newest first. */
    public function list(): array
    {
        return $this->http->request('GET', '/data_processing_agreements');
    }
}
