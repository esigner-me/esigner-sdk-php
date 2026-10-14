<?php

declare(strict_types=1);

// Dependency-free checks: `composer test` (or `php tests/run.php`). Webhook verification here;
// the HTTP client is exercised against a recording mock server by tests/client.mjs.

require __DIR__ . '/../src/EsignerException.php';
require __DIR__ . '/../src/SignatureVerificationException.php';
require __DIR__ . '/../src/Webhook.php';

use Esigner\SignatureVerificationException;
use Esigner\Webhook;

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? "ok   " : "FAIL ") . $name . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

// Signed exactly as the API signs a delivery (api/src/webhooks/events.ts).
$secret = 'whsec_test_secret';
$body = json_encode(['id' => 'evt_1', 'type' => 'signer.signed', 'livemode' => false, 'created' => 1, 'data' => ['signersSigned' => 1, 'signersTotal' => 3]]);
$sign = fn (int $t, string $b) => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$b}", $secret);

check('verifies a genuine delivery', Webhook::verify($body, $sign(time(), $body), $secret));
check('rejects a tampered body', !Webhook::verify($body . ' ', $sign(time(), $body), $secret));
check('rejects a wrong secret', !Webhook::verify($body, $sign(time(), $body), 'whsec_other'));
check('rejects a stale timestamp', !Webhook::verify($body, $sign(time() - 3600, $body), $secret));
check('rejects a missing header', !Webhook::verify($body, null, $secret));
check('rejects a malformed header', !Webhook::verify($body, 'garbage', $secret));

$event = Webhook::constructEvent($body, $sign(time(), $body), $secret);
check('constructEvent decodes the event', $event['type'] === 'signer.signed' && $event['data']['signersTotal'] === 3);
try {
    Webhook::constructEvent($body, $sign(time(), $body), 'whsec_other');
    check('constructEvent throws on a bad signature', false);
} catch (SignatureVerificationException) {
    check('constructEvent throws on a bad signature', true);
}

exit($failed ? 1 : 0);
