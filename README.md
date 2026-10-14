# esigner/sdk

The official PHP client for the [esigner](https://esigner.no) API: send PDFs for signing with BankID,
MitID and other national eIDs, get the signed PDF back, and verify webhooks. The same shape as the
Node SDK, [`@esigner/sdk`](https://www.npmjs.com/package/@esigner/sdk).

- Only `ext-curl` and `ext-json` — drops into WordPress or any framework
- Safe retries: every POST carries an idempotency key that stays the same across retries
- Cursor pagination as generators, webhook verification in one call

Full API reference: **https://esigner.no/dokumentasjon**

## Install

```sh
composer require esigner/sdk
```

PHP 8.1 or newer. Create an API key under **Konto → Utvikler** on your esigner site. Keys starting
with `sk_test_` sign against the test environment: the real flow, with test identities, no payment
and no legally binding signature.

## Send a document for signing

```php
use Esigner\EsignerClient;

$esigner = new EsignerClient(getenv('ESIGNER_API_KEY'));     // ['market' => 'se'] for esigner.se

$request = $esigner->signatureRequests->createWithFiles('/path/to/contract.pdf', [
    'ownerEmail'      => 'post@company.no',                     // receives the receipt and the signed PDF
    'signers'         => [
        ['name' => 'Ola Nordmann', 'email' => 'ola@example.no'],
        ['name' => 'Kari Nordmann', 'email' => 'kari@example.no'],
    ],
    'useSubscription' => true,                                   // draw from your plan's quota
    'signingEids'     => ['no_bankid'],                          // optional: Norwegian BankID only
    'returnUrl'       => 'https://example.com/contracts/123/signed',  // optional: where signers land after
]);

echo $request['signers'][0]['sign_url'];
```

Files can be paths or bytes — `['data' => $pdfBytes, 'filename' => 'contract.pdf']` — and several are
merged in the order given. Or upload first and create separately:

```php
$document = $esigner->documents->upload(['a.pdf', 'b.docx'], ['ownerEmail' => 'post@company.no']);
$request  = $esigner->signatureRequests->create(['documentId' => $document['id'], 'signers' => [/* … */]]);
```

Markets: `no`, `dk`, `se`, `fi`, `de`, `nl`, `uk`. A key belongs to the market it was created in.

## Follow it up

```php
$request = $esigner->signatureRequests->retrieve($id);           // status, signers, …

if ($request['status'] === 'completed') {
    $pdf = $esigner->signatureRequests->downloadSignedDocument($id);   // the PDF's bytes
    file_put_contents('signed.pdf', $pdf);
}

$esigner->signatureRequests->cancel($id, ['reason' => 'Wrong version']);   // before anyone signs
$esigner->signatureRequests->listSignedDocuments($id);                     // several files → positions
```

## Balance and events

```php
$esigner->balance->retrieve();                                   // ['balance_ore' => …, 'currency' => …]

foreach ($esigner->balance->allTransactions() as $tx) { /* every page, newest first */ }

foreach ($esigner->events->all(['type' => 'document.completed']) as $event) {
    // the same events your webhook receives — for backfilling after downtime
}
```

## Webhooks (WordPress example)

esigner signs every delivery with `Esigner-Signature: t=<unix>,v1=<hmac>`. Verify against the **raw**
body — a re-encoded JSON body will not match.

```php
use Esigner\SignatureVerificationException;
use Esigner\Webhook;

add_action('rest_api_init', function () {
    register_rest_route('mysite/v1', '/esigner', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {
            try {
                $event = Webhook::constructEvent(
                    $req->get_body(),                          // the raw body
                    $req->get_header('esigner_signature'),
                    getenv('ESIGNER_WEBHOOK_SECRET'),          // whsec_…
                );
            } catch (SignatureVerificationException $e) {
                return new WP_REST_Response(null, 400);
            }
            switch ($event['type']) {
                case 'signer.signed':      /* $event['data']['signersSigned'] of ['signersTotal'] */ break;
                case 'document.completed': /* downloadSignedDocument($event['data']['orderId']) */ break;
            }
            return new WP_REST_Response(null, 200);
        },
    ]);
});
```

`Webhook::EVENT_TYPES` lists them all. Deliveries are retried; dedupe on `$event['id']`.

## Errors

Any non-2xx answer throws `Esigner\EsignerException` with the API's stable code:

```php
use Esigner\EsignerException;

try {
    $esigner->signatureRequests->create([/* … */]);
} catch (EsignerException $e) {
    if ($e->errorCode === 'quota_exceeded') { /* … */ }
    // also $e->status, $e->detail and the full decoded $e->body
}
```

Network errors, `429` and `5xx` are retried twice with backoff.

## Options

```php
new EsignerClient($key, [
    'market'      => 'no',     // which esigner site the key belongs to
    'base_url'    => null,     // or a full API base, e.g. a staging host
    'max_retries' => 2,
    'timeout'     => 60,       // seconds per attempt
]);
```

Every POST takes a last `$opts` argument: `['idempotency_key' => '…']` to make a retry from your side
return the original result too, and `['timeout' => 120]` for one slow call.

## Upgrading from 0.1

`Esigner\Client` and its flat methods (`uploadDocument`, `createSignatureRequest`, …) still work and
are deprecated; `EsignerClient` with its services is the way forward.

## License

MIT
