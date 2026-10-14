<?php

declare(strict_types=1);

namespace Esigner;

/**
 * Webhook verification. esigner signs every delivery with
 * `Esigner-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with your endpoint secret>`.
 * Pass the body EXACTLY as received (file_get_contents('php://input')) — re-encoded JSON won't match.
 */
final class Webhook
{
    /** Every event type the API delivers. */
    public const EVENT_TYPES = [
        'document.completed',
        'document.signing_failed',
        'document.cancelled',
        'signer.signed',
        'signer.declined',
        'order.captured',
    ];

    /** True when the signature matches and the timestamp is within $toleranceSec. */
    public static function verify(string $payload, ?string $header, string $secret, int $toleranceSec = 300): bool
    {
        if ($header === null || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$k, $v] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$k] = $v;
        }
        $t = $parts['t'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (!ctype_digit($t) || $v1 === '') {
            return false;
        }
        if (abs(time() - (int) $t) > $toleranceSec) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        return hash_equals($expected, $v1);
    }

    /**
     * Verify, then decode. The event is an array: `id`, `type` (e.g. "signer.signed",
     * "document.completed"), `livemode`, `created` and `data`.
     *
     * @return array<string, mixed>
     * @throws SignatureVerificationException
     */
    public static function constructEvent(string $payload, ?string $header, string $secret, int $toleranceSec = 300): array
    {
        if (!self::verify($payload, $header, $secret, $toleranceSec)) {
            throw new SignatureVerificationException('esigner webhook signature did not verify (wrong secret, a re-encoded body, or a stale timestamp)');
        }
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new SignatureVerificationException('esigner webhook body is not a JSON object');
        }
        return $event;
    }
}
