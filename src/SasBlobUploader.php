<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Uploads through the Blob REST API ("Put Blob") authenticated with a SAS token.
 *
 * The token is a secret: it is only ever placed in the request URL, never in
 * a return value, exception message or log line.
 */
class SasBlobUploader implements BlobUploader
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TRANSFER_TIMEOUT_SECONDS = 120;

    private readonly string $sasToken;

    public function __construct(
        private readonly string $account,
        private readonly string $container,
        string $sasToken,
    ) {
        // Both values become part of the host name and path, so they are
        // checked against the Azure naming rules instead of being escaped.
        if (preg_match('/^[a-z0-9]{3,24}$/', $account) !== 1) {
            throw new InvalidArgumentException('Invalid storage account name.');
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9]|-(?!-)){1,61}[a-z0-9]$/', $container) !== 1) {
            throw new InvalidArgumentException('Invalid container name.');
        }

        $this->sasToken = ltrim(trim($sasToken), '?');
        if ($this->sasToken === '') {
            throw new InvalidArgumentException('The SAS token is empty.');
        }
    }

    public function upload(string $localPath, string $blobName, string $contentType): string
    {
        $size = is_file($localPath) ? filesize($localPath) : false;
        $stream = $size === false ? false : @fopen($localPath, 'rb');
        if ($size === false || $stream === false) {
            throw new UploadFailedException('The local file could not be read.');
        }

        try {
            $response = $this->send(
                $this->blobUrl($blobName) . '?' . $this->sasToken,
                [
                    'x-ms-blob-type: BlockBlob',
                    'x-ms-blob-content-type: ' . $contentType,
                    // Never overwrite: the request fails if the blob already exists.
                    'If-None-Match: *',
                ],
                $stream,
                $size,
            );
        } finally {
            fclose($stream);
        }

        if ($response['transportError'] !== null) {
            throw new UploadFailedException('Storage request failed: ' . $response['transportError']);
        }

        if ($response['status'] !== 201) {
            throw new UploadFailedException(sprintf(
                'Storage rejected the upload: HTTP %d (%s)',
                $response['status'],
                $this->errorCode($response),
            ));
        }

        return $this->blobUrl($blobName);
    }

    /** Public URL of a blob, without credentials. */
    public function blobUrl(string $blobName): string
    {
        $path = implode('/', array_map(rawurlencode(...), explode('/', $blobName)));

        return sprintf('https://%s.blob.core.windows.net/%s/%s', $this->account, $this->container, $path);
    }

    /**
     * Performs the PUT. Kept separate so tests can replace the network call.
     *
     * @param list<string> $headers
     * @param resource $stream
     * @return array{status:int, headers:array<string,string>, body:string, transportError:?string}
     */
    protected function send(string $url, array $headers, $stream, int $size): array
    {
        $responseHeaders = [];

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => $size,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TRANSFER_TIMEOUT_SECONDS,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($curl);
        $errno = curl_errno($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? $body : '',
            // curl_strerror() is a fixed description; curl_error() may echo parts of the URL.
            'transportError' => $errno === 0 ? null : sprintf('cURL error %d (%s)', $errno, curl_strerror($errno)),
        ];
    }

    /**
     * @param array{headers:array<string,string>, body:string} $response
     */
    private function errorCode(array $response): string
    {
        $code = $response['headers']['x-ms-error-code'] ?? '';
        if ($code === '' && preg_match('~<Code>([^<]+)</Code>~', $response['body'], $match) === 1) {
            $code = $match[1];
        }

        // Azure error codes are plain identifiers; anything else is not echoed.
        return preg_match('/^[A-Za-z0-9]{1,80}$/', $code) === 1 ? $code : 'unknown error';
    }
}
