# esigner/sdk

The official PHP client for the [esigner](https://esigner.no) API: send PDFs for signing with BankID,
MitID and other national eIDs, get the signed PDF back, and verify webhooks.

- Only `ext-curl` and `ext-json` — drops into WordPress or any framework
- Safe retries: every POST carries an idempotency key that stays the same across retries
- Webhook verification in one call

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
use Esigner\Client;

$esigner = new Client(getenv('ESIGNER_API_KEY'));            // ['market' => 'se'] for esigner.se

$document = $esigner->uploadDocument('/path/to/contract.pdf', 'post@company.no');   // the sender

$request = $esigner->createSignatureRequest([
    'documentId'      => $document['id'],
    'signers'         => [['name' => 'Ola Nordmann', 'email' => 'ola@example.no']],
    'useSubscription' => true,                                   // draw from your plan's quota
    'signingEids'     => ['no_bankid'],                          // optional: Norwegian BankID only
    'returnUrl'       => 'https://example.com/contracts/123/signed',  // optional
]);

echo $request['signers'][0]['sign_url'];
```

Then `retrieveSignatureRequest($id)`, `cancelSignatureRequest($id, 'reason')`,
`downloadSignedDocument($id)` (the PDF's bytes) and `listEvents(['type' => 'document.completed'])`.

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
            if ($event['type'] === 'document.completed') {
                // fetch the signed PDF with downloadSignedDocument($event['data']['orderId'])
            }
            return new WP_REST_Response(null, 200);
        },
    ]);
});
```

Events: `signer.signed`, `signer.declined`, `document.completed`, `document.cancelled`,
`document.signing_failed`, `order.captured`. Deliveries are retried; dedupe on `$event['id']`.

## Errors

Any non-2xx answer throws `Esigner\EsignerException` with the API's stable code in `errorCode`
(plus `status`, `detail` and the full decoded `body`). Network errors, `429` and `5xx` are retried
twice with backoff.

## License

MIT
