<?php

declare(strict_types=1);

// Driven by tests/client.mjs against its mock server; prints one JSON line of results.
require __DIR__ . '/bootstrap.php';

use Esigner\Client;
use Esigner\EsignerClient;
use Esigner\EsignerException;

$base = $argv[1];
$tmp = sys_get_temp_dir();
file_put_contents("{$tmp}/esigner-a.pdf", '%PDF-a');

$es = new EsignerClient('sk_test_abc', ['base_url' => $base, 'max_retries' => 2]);
$out = [];
// A path and raw bytes in one upload.
$out['upload'] = $es->documents->upload(["{$tmp}/esigner-a.pdf", ['data' => 'PK-docx', 'filename' => 'b.docx']], ['ownerEmail' => 'post@klubb.se'])['id'];
$out['create'] = $es->signatureRequests->create(['documentId' => 'doc_1', 'signers' => [['email' => 'a@x.se']], 'signingEids' => ['se_bankid']])['id'];
$out['retried'] = $es->signatureRequests->create(['documentId' => 'doc_2', 'signers' => [['email' => 'b@x.se']]], ['idempotency_key' => 'my-key-1'])['id'];
$out['withFiles'] = $es->signatureRequests->createWithFiles(['data' => '%PDF-c', 'filename' => 'c.pdf'], ['ownerEmail' => 'club@x.se', 'signers' => [['email' => 'c@x.se']], 'useSubscription' => true])['id'];
try {
    $es->signatureRequests->retrieve('missing');
} catch (EsignerException $e) {
    $out['error'] = [$e->status, $e->errorCode, $e->body['hint'] ?? null];
}
$out['cancel'] = $es->signatureRequests->cancel('req_1', ['reason' => 'test'])['already_cancelled'];
$out['signedList'] = count($es->signatureRequests->listSignedDocuments('req_1')['data']);
$out['pdf'] = $es->signatureRequests->downloadSignedDocument('req_1');
$out['balance'] = $es->balance->retrieve()['balance_ore'];
$out['transactions'] = array_map(fn ($t) => $t['id'], iterator_to_array($es->balance->allTransactions(['limit' => 2]), false));
$out['events'] = array_map(fn ($e) => $e['id'], iterator_to_array($es->events->all(['type' => 'signer.signed']), false));

// The 0.1.0 entry point still works.
$legacy = new Client('sk_test_abc', ['base_url' => $base]);
$out['legacy'] = $legacy->retrieveSignatureRequest('req_1')['id'];
echo json_encode($out), PHP_EOL;
