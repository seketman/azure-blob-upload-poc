<?php

declare(strict_types=1);

namespace App;

use finfo;

/**
 * Validates one uploaded file and stores it. Free of globals so it can be
 * unit-tested: the front controller passes in what it read from the request.
 */
final class UploadHandler
{
    /** Types the service knows how to name. A type must also be in the configured allowlist. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
    ];

    /** Active content: never served from the storage domain, whatever the configuration says. */
    private const NEVER_ALLOWED = ['text/html', 'image/svg+xml'];

    /** @var list<string> */
    private readonly array $allowedMimeTypes;

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        private readonly BlobUploader $uploader,
        private readonly ?string $apiKey,
        private readonly int $maxBytes,
        array $allowedMimeTypes,
    ) {
        $this->allowedMimeTypes = array_map(strtolower(...), $allowedMimeTypes);
    }

    /**
     * @param string|null $apiKey value of the X-Api-Key request header
     * @param array<string,mixed>|null $file the $_FILES entry, or null when the request has none
     * @param int $contentLength request Content-Length; tells an oversized body from a missing file
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(?string $apiKey, ?array $file, int $contentLength): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            return self::error(500, 'server_error', 'The service is not available.');
        }
        if ($apiKey === null || !hash_equals($this->apiKey, $apiKey)) {
            return self::error(401, 'unauthorized', 'A valid X-Api-Key header is required.');
        }

        if ($file === null) {
            // PHP discards the whole body, files included, when it exceeds post_max_size.
            return $contentLength > $this->maxBytes
                ? $this->tooLarge()
                : self::error(400, 'missing_file', 'Send the file as multipart/form-data in the "file" field.');
        }

        $uploadError = $file['error'] ?? null;
        $path = $file['tmp_name'] ?? null;

        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            return $this->tooLarge();
        }
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return self::error(400, 'missing_file', 'Send the file as multipart/form-data in the "file" field.');
        }
        if ($uploadError !== UPLOAD_ERR_OK || !is_string($path) || !is_file($path)) {
            return self::error(400, 'invalid_upload', 'The file was not received correctly.');
        }

        $size = (int) filesize($path);
        if ($size === 0) {
            return self::error(400, 'empty_file', 'The file is empty.');
        }
        if ($size > $this->maxBytes) {
            return $this->tooLarge();
        }

        // Detected from the bytes: the client's declared type and file name are not trusted.
        $contentType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = self::EXTENSIONS[$contentType] ?? null;
        if (
            $extension === null
            || in_array($contentType, self::NEVER_ALLOWED, true)
            || !in_array($contentType, $this->allowedMimeTypes, true)
        ) {
            return self::error(415, 'unsupported_type', 'This file type is not allowed.');
        }

        $blobName = sprintf('%s/%s.%s', gmdate('Y/m'), self::uuidV4(), $extension);

        try {
            $url = $this->uploader->upload($path, $blobName, $contentType);
        } catch (UploadFailedException $exception) {
            error_log('Upload to storage failed: ' . $exception->getMessage());

            return self::error(502, 'storage_error', 'The file could not be stored.');
        }

        return [
            'status' => 201,
            'body' => [
                'url' => $url,
                'blob' => $blobName,
                'contentType' => $contentType,
                'size' => $size,
            ],
        ];
    }

    /**
     * @return array{status:int, body:array{error:string, message:string}}
     */
    public static function error(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $code, 'message' => $message]];
    }

    /**
     * @return array{status:int, body:array{error:string, message:string}}
     */
    private function tooLarge(): array
    {
        return self::error(413, 'file_too_large', sprintf('The file exceeds the limit of %d bytes.', $this->maxBytes));
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
