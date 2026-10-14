<?php

declare(strict_types=1);

namespace Esigner;

/**
 * The esigner API client (https://esigner.no/dokumentasjon). Needs only ext-curl and ext-json, so it
 * drops into WordPress or any framework without pulling in an HTTP library.
 *
 * Every POST carries an Idempotency-Key that stays the same across its retries; network errors, 429
 * and 5xx are retried with backoff (honouring Retry-After). Responses come back as arrays shaped
 * exactly as the API documents them.
 */
final class Client
{
    public const VERSION = '0.1.0';

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

    private string $baseUrl;
    private int $maxRetries;
    private int $timeout;

    /**
     * @param array{market?: string, base_url?: string, max_retries?: int, timeout?: int} $options
     */
    public function __construct(private readonly string $apiKey, array $options = [])
    {
        if (!preg_match('/^sk_(live|test)_/', $apiKey)) {
            throw new \InvalidArgumentException('Esigner: pass an API key (sk_live_… or sk_test_…) — create one under Konto → Utvikler.');
        }
        $market = $options['market'] ?? 'no';
        if (!isset($options['base_url']) && !isset(self::MARKETS[$market])) {
            throw new \InvalidArgumentException("Esigner: unknown market '{$market}'.");
        }
        $this->baseUrl = rtrim($options['base_url'] ?? self::MARKETS[$market], '/');
        $this->maxRetries = $options['max_retries'] ?? 2;
        $this->timeout = $options['timeout'] ?? 60;
    }

    /**
     * Upload the file(s) to be signed. Several are merged in the order given; PDF and .docx accepted.
     * $ownerEmail is the sender: the receipt, progress mails and the signed PDF go there.
     *
     * @param list<string>|string $paths
     * @return array<string, mixed> The document (`id`, `filename`, `sizeBytes`, `attachments`).
     */
    public function uploadDocument(array|string $paths, string $ownerEmail, ?string $idempotencyKey = null): array
    {
        // Built by hand: the API reads the field `files` repeated once per file, and curl's array
        // form can't repeat a key (it would send files[0], files[1], which the API ignores).
        $boundary = 'esigner' . bin2hex(random_bytes(12));
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"ownerEmail\"\r\n\r\n{$ownerEmail}\r\n";
        foreach ((array) $paths as $path) {
            if (!is_readable($path)) {
                throw new \InvalidArgumentException("Esigner: cannot read {$path}");
            }
            $type = preg_match('/\.docx$/i', $path) ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf';
            $name = str_replace(['"', "\r", "\n"], '', basename($path));
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"{$name}\"\r\nContent-Type: {$type}\r\n\r\n"
                . file_get_contents($path) . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        return $this->request('POST', '/documents', multipart: ['boundary' => $boundary, 'body' => $body], idempotencyKey: $idempotencyKey);
    }

    /**
     * Send an uploaded document for signing. $params as in the docs: `documentId`, `signers`, and
     * optionally `useSubscription`, `signingEids`, `returnUrl`, `notifySigners`, …
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed> The signature request.
     */
    public function createSignatureRequest(array $params, ?string $idempotencyKey = null): array
    {
        return $this->request('POST', '/signature_requests', json: $params, idempotencyKey: $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieveSignatureRequest(string $id): array
    {
        return $this->request('GET', '/signature_requests/' . rawurlencode($id));
    }

    /**
     * Stop a request before anyone has signed; whatever it drew (quota, payment) comes back.
     *
     * @return array<string, mixed>
     */
    public function cancelSignatureRequest(string $id, ?string $reason = null): array
    {
        return $this->request('POST', '/signature_requests/' . rawurlencode($id) . '/cancel', json: $reason !== null ? ['reason' => $reason] : new \stdClass());
    }

    /** The signed PDF's bytes. Position 0 is the merged document (or the first of several). */
    public function downloadSignedDocument(string $id, int $position = 0): string
    {
        return $this->request('GET', '/signature_requests/' . rawurlencode($id) . "/signed_documents/{$position}", raw: true);
    }

    /**
     * The same events delivered to your webhooks, newest first. Pass the page's `next_cursor`
     * back as `cursor` for the next one.
     *
     * @param array{limit?: int, cursor?: string, type?: string} $params
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function listEvents(array $params = []): array
    {
        return $this->request('GET', '/events', query: $params);
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|\stdClass|null $json
     * @param array{boundary: string, body: string}|null $multipart
     */
    private function request(
        string $method,
        string $path,
        ?array $query = null,
        array|\stdClass|null $json = null,
        ?array $multipart = null,
        ?string $idempotencyKey = null,
        bool $raw = false,
    ): mixed {
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: esigner-sdk-php/' . self::VERSION,
        ];
        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?? bin2hex(random_bytes(16)));
        }
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($multipart !== null) {
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $multipart['boundary'];
            $body = $multipart['body'];
        }

        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($url);
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$responseHeaders): int {
                    $p = strpos($line, ':');
                    if ($p !== false) {
                        $responseHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                    return strlen($line);
                },
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $result = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                if ($attempt < $this->maxRetries) {
                    usleep(self::backoffMs($attempt) * 1000);
                    continue;
                }
                throw new \RuntimeException("Esigner: request failed: {$error}");
            }
            if ($status >= 200 && $status < 300) {
                return $raw ? $result : json_decode((string) $result, true, 512, JSON_THROW_ON_ERROR);
            }
            if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                $after = $responseHeaders['retry-after'] ?? null;
                $ms = ($after !== null && is_numeric($after)) ? min(60000, (int) $after * 1000) : self::backoffMs($attempt);
                usleep($ms * 1000);
                continue;
            }
            $decoded = json_decode((string) $result, true);
            throw EsignerException::fromResponse($status, is_array($decoded) ? $decoded : []);
        }
    }

    private static function backoffMs(int $attempt): int
    {
        return min(8000, 500 * (2 ** $attempt)) + random_int(0, 250);
    }
}
