<?php

declare(strict_types=1);

namespace Esigner;

/**
 * Any non-2xx answer from the API. `errorCode` is the API's stable machine-readable code (see the
 * error table in the docs) — named so because PHP's own getCode() is an integer.
 */
final class EsignerException extends \RuntimeException
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $detail,
        public readonly array $body,
    ) {
        parent::__construct($detail !== null ? "{$errorCode}: {$detail}" : $errorCode, $status);
    }

    /** @param array<string, mixed> $body */
    public static function fromResponse(int $status, array $body): self
    {
        $code = is_string($body['error'] ?? null) ? $body['error'] : "http_{$status}";
        $detail = is_string($body['detail'] ?? null) ? $body['detail'] : (is_string($body['message'] ?? null) ? $body['message'] : null);
        return new self($status, $code, $detail, $body);
    }
}
