<?php

declare(strict_types=1);

namespace Esigner\Service;

use Esigner\HttpClient;

final class DocumentService
{
    /** @internal */
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * Upload the file(s) to be signed — step one of every request. Several are merged in the order
     * given; PDF and .docx are accepted (Word is converted to PDF on upload).
     *
     * Each file is a path, or ['data' => bytes, 'filename' => 'x.pdf', 'contentType' => optional].
     * `ownerEmail` is the sender: the receipt, progress mails and the signed PDF go there — a
     * platform sending for its own customers puts the customer's address here.
     *
     * @param string|array{data: string, filename: string, contentType?: string}|list<string|array{data: string, filename: string, contentType?: string}> $files
     * @param array{ownerEmail: string, templateSlug?: string} $params
     * @param array{idempotency_key?: string, timeout?: int} $opts
     * @return array{id: string, filename: string, sizeBytes: int, attachments: list<array<string, mixed>>}
     */
    public function upload(string|array $files, array $params, array $opts = []): array
    {
        if (!isset($params['ownerEmail'])) {
            throw new \InvalidArgumentException('Esigner: ownerEmail is required — the sender who receives the receipt and the signed PDF.');
        }
        $list = is_string($files) || isset($files['data']) ? [$files] : $files;
        // Built by hand: the API reads the field `files` repeated once per file, and curl's array
        // form can't repeat a key (it would send files[0], files[1], which the API ignores).
        $boundary = 'esigner' . bin2hex(random_bytes(12));
        $body = self::field($boundary, 'ownerEmail', (string) $params['ownerEmail']);
        if (isset($params['templateSlug'])) {
            $body .= self::field($boundary, 'templateSlug', (string) $params['templateSlug']);
        }
        foreach ($list as $file) {
            if (is_string($file)) {
                if (!is_readable($file)) {
                    throw new \InvalidArgumentException("Esigner: cannot read {$file}");
                }
                $file = ['data' => (string) file_get_contents($file), 'filename' => basename($file)];
            }
            $name = str_replace(['"', "\r", "\n"], '', $file['filename']);
            $type = $file['contentType'] ?? (preg_match('/\.docx$/i', $name)
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                : 'application/pdf');
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files\"; filename=\"{$name}\"\r\nContent-Type: {$type}\r\n\r\n{$file['data']}\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        return $this->http->request('POST', '/documents', multipart: ['boundary' => $boundary, 'body' => $body], opts: $opts);
    }

    private static function field(string $boundary, string $name, string $value): string
    {
        return "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
}
