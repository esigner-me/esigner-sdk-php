<?php

declare(strict_types=1);

namespace Esigner;

/**
 * The 0.1.0 entry point, kept so code written against it keeps working. New code: EsignerClient
 * and its services (`$esigner->signatureRequests->create(...)`).
 *
 * @deprecated since 0.2.0 — use EsignerClient.
 */
final class Client extends EsignerClient
{
    /**
     * @param list<string>|string $paths
     * @return array<string, mixed>
     */
    public function uploadDocument(array|string $paths, string $ownerEmail, ?string $idempotencyKey = null): array
    {
        return $this->documents->upload($paths, ['ownerEmail' => $ownerEmail], $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : []);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createSignatureRequest(array $params, ?string $idempotencyKey = null): array
    {
        return $this->signatureRequests->create($params, $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : []);
    }

    /** @return array<string, mixed> */
    public function retrieveSignatureRequest(string $id): array
    {
        return $this->signatureRequests->retrieve($id);
    }

    /** @return array<string, mixed> */
    public function cancelSignatureRequest(string $id, ?string $reason = null): array
    {
        return $this->signatureRequests->cancel($id, $reason !== null ? ['reason' => $reason] : []);
    }

    public function downloadSignedDocument(string $id, int $position = 0): string
    {
        return $this->signatureRequests->downloadSignedDocument($id, $position);
    }

    /**
     * @param array{limit?: int, cursor?: string, type?: string} $params
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function listEvents(array $params = []): array
    {
        return $this->events->list($params);
    }
}
