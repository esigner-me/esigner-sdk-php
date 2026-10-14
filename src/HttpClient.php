<?php

declare(strict_types=1);

namespace Esigner;

/**
 * @internal Every call goes through here: auth, idempotency, retries and errors in one place.
 *
 * Every POST carries an Idempotency-Key that stays the same across its retries; network errors, 429
 * and 5xx are retried with backoff (honouring Retry-After).
 */
final class HttpClient
{
    /** @var (callable(string, string, array<int, string>, ?string): array{0: int, 1: string, 2: array<string, string>})|null */
    private $transport;

    /**
     * @param callable(string, string, array<int, string>, ?string): array{0: int, 1: string, 2: array<string, string>}|null $transport
     *        For tests: (method, url, headers, body) → [status, body, lowercased headers]. Default curl.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $maxRetries,
        private readonly int $timeout,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<string, mixed>|\stdClass|null $json
     * @param array{boundary: string, body: string}|null $multipart
     * @param array{idempotency_key?: string, timeout?: int} $opts
     */
    public function request(
        string $method,
        string $path,
        ?array $query = null,
        array|\stdClass|null $json = null,
        ?array $multipart = null,
        array $opts = [],
        bool $raw = false,
    ): mixed {
        $query = array_filter($query ?? [], static fn ($v) => $v !== null);
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: esigner-sdk-php/' . EsignerClient::VERSION . ' PHP/' . PHP_VERSION,
        ];
        if ($method === 'POST') {
            $headers[] = 'Idempotency-Key: ' . ($opts['idempotency_key'] ?? bin2hex(random_bytes(16)));
        }
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } elseif ($multipart !== null) {
            $headers[] = 'Content-Type: multipart/form-data; boundary=' . $multipart['boundary'];
            $body = $multipart['body'];
        }
        $timeout = $opts['timeout'] ?? $this->timeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                [$status, $result, $responseHeaders] = $this->send($method, $url, $headers, $body, $timeout);
            } catch (\RuntimeException $e) {
                if ($attempt < $this->maxRetries) {
                    usleep(self::backoffMs($attempt) * 1000);
                    continue;
                }
                throw $e;
            }
            if ($status >= 200 && $status < 300) {
                return $raw ? $result : json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            }
            if (($status === 429 || $status >= 500) && $attempt < $this->maxRetries) {
                $after = $responseHeaders['retry-after'] ?? null;
                $ms = ($after !== null && is_numeric($after)) ? min(60000, (int) $after * 1000) : self::backoffMs($attempt);
                usleep($ms * 1000);
                continue;
            }
            $decoded = json_decode($result, true);
            throw EsignerException::fromResponse($status, is_array($decoded) ? $decoded : []);
        }
    }

    /**
     * @param array<int, string> $headers
     * @return array{0: int, 1: string, 2: array<string, string>}
     */
    private function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headers, $body);
        }
        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HEADERFUNCTION => static function ($_, string $line) use (&$responseHeaders): int {
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
            throw new \RuntimeException("Esigner: request failed: {$error}");
        }
        return [$status, (string) $result, $responseHeaders];
    }

    private static function backoffMs(int $attempt): int
    {
        return min(8000, 500 * (2 ** $attempt)) + random_int(0, 250);
    }
}
