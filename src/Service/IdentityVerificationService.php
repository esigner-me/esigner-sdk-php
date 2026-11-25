<?php

declare(strict_types=1);

namespace Esigner\Service;

use Esigner\HttpClient;

/**
 * ID verification with a national eID, without a document or a signature. Create one, send the person
 * to `verification_url`, then read the result (or listen for identity.verified / identity.failed).
 */
final class IdentityVerificationService
{
    /** @internal */
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @param array{eids?: list<string>, returnUrl?: string, reference?: string, paymentMethod?: 'auto'|'subscription'|'balance'|'card'|'checkout'} $params
     * @return array<string, mixed> `id`, `status`, `verification_url`, …
     */
    public function create(array $params = []): array
    {
        return $this->http->request('POST', '/identity_verifications', json: $params);
    }

    /** @return array<string, mixed> With `result` (name, birth_date, country, eid, person_id) once `status` is verified. */
    public function retrieve(string $id): array
    {
        return $this->http->request('GET', '/identity_verifications/' . rawurlencode($id));
    }

    /** Close an open verification; an unopened one gets its quota or money back (`payment.refunded`). */
    public function cancel(string $id): array
    {
        return $this->http->request('POST', '/identity_verifications/' . rawurlencode($id) . '/cancel');
    }
}
