<?php

declare(strict_types=1);

namespace Esigner\Service;

use Esigner\HttpClient;

/**
 * Signature requests. Params and responses are the API's own (see the docs): `documentId`,
 * `signers`, and optionally `useSubscription`, `paymentMethod`, `signingEids`, `returnUrl`,
 * `notifySigners`, `notifySender`, `copyEmails`, `deadlineDays`, `password`, `sealFooter`, …
 */
final class SignatureRequestService
{
    /** @internal */
    public function __construct(private readonly HttpClient $http, private readonly DocumentService $documents)
    {
    }

    /**
     * Send an uploaded document for signing.
     *
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, timeout?: int} $opts
     * @return array<string, mixed> The signature request: `id`, `status`, `signers` (each with
     *         `sign_url`), `signing_eids`, `return_url`, `payment`, …
     */
    public function create(array $params, array $opts = []): array
    {
        return $this->http->request('POST', '/signature_requests', json: $params, opts: $opts);
    }

    /**
     * Upload and send in one call. $params are create()'s plus `ownerEmail` (and `templateSlug`).
     *
     * @param string|array<mixed> $files As DocumentService::upload().
     * @param array<string, mixed> $params
     * @param array{idempotency_key?: string, timeout?: int} $opts
     * @return array<string, mixed>
     */
    public function createWithFiles(string|array $files, array $params, array $opts = []): array
    {
        $upload = ['ownerEmail' => $params['ownerEmail'] ?? null];
        if (isset($params['templateSlug'])) {
            $upload['templateSlug'] = $params['templateSlug'];
        }
        unset($params['ownerEmail'], $params['templateSlug']);
        $doc = $this->documents->upload($files, $upload, isset($opts['timeout']) ? ['timeout' => $opts['timeout']] : []);
        return $this->create(['documentId' => $doc['id']] + $params, $opts);
    }

    /** @return array<string, mixed> */
    public function retrieve(string $id): array
    {
        return $this->http->request('GET', '/signature_requests/' . rawurlencode($id));
    }

    /**
     * Stop a request before anyone has signed; whatever it drew (quota, payment) comes back.
     *
     * @param array{reason?: string} $params
     * @param array{idempotency_key?: string, timeout?: int} $opts
     * @return array{signature_request: array<string, mixed>, already_cancelled: bool, released_signatures: int, refunded_ore: int, returned_to_balance_ore: int}
     */
    public function cancel(string $id, array $params = [], array $opts = []): array
    {
        return $this->http->request('POST', '/signature_requests/' . rawurlencode($id) . '/cancel', json: $params ?: new \stdClass(), opts: $opts);
    }

    /**
     * The signed PDF(s) of a completed request, with their positions.
     *
     * @return array{data: list<array<string, mixed>>, download_expires_at: ?string}
     */
    public function listSignedDocuments(string $id): array
    {
        return $this->http->request('GET', '/signature_requests/' . rawurlencode($id) . '/signed_documents');
    }

    /** The signed PDF's bytes. Position 0 is the merged document (or the first of several). */
    public function downloadSignedDocument(string $id, int $position = 0): string
    {
        return $this->http->request('GET', '/signature_requests/' . rawurlencode($id) . "/signed_documents/{$position}", raw: true);
    }
}
