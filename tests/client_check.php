<?php

declare(strict_types=1);

// Driven by tests/client.mjs against its mock server; prints one JSON line of results.
require __DIR__ . '/../src/EsignerException.php';
require __DIR__ . '/../src/Client.php';

use Esigner\Client;
use Esigner\EsignerException;

$base = $argv[1];
$tmp = sys_get_temp_dir();
file_put_contents("{$tmp}/esigner-a.pdf", '%PDF-a');
file_put_contents("{$tmp}/esigner-b.docx", 'PK-docx');

$c = new Client('sk_test_abc', ['base_url' => $base, 'max_retries' => 2]);
$out = [];
$out['upload'] = $c->uploadDocument(["{$tmp}/esigner-a.pdf", "{$tmp}/esigner-b.docx"], 'post@klubb.se')['id'];
$out['create'] = $c->createSignatureRequest(['documentId' => 'doc_1', 'signers' => [['email' => 'a@x.se']], 'signingEids' => ['se_bankid']])['id'];
$out['retried'] = $c->createSignatureRequest(['documentId' => 'doc_2', 'signers' => [['email' => 'b@x.se']]])['id'];
try {
    $c->retrieveSignatureRequest('missing');
} catch (EsignerException $e) {
    $out['error'] = [$e->status, $e->errorCode];
}
$out['pdf'] = $c->downloadSignedDocument('req_1');
$out['events'] = $c->listEvents(['type' => 'signer.signed'])['next_cursor'];
echo json_encode($out), PHP_EOL;
