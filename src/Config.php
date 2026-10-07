<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Config
{
    private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    private const DEFAULT_ALLOWED_MIME = 'application/pdf,image/png,image/jpeg';

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        public readonly string $storageAccount,
        public readonly string $storageContainer,
        public readonly string $sasToken,
        public readonly int $maxBytes,
        public readonly array $allowedMimeTypes,
        public readonly ?string $apiKey,
    ) {
    }

    /**
     * @throws RuntimeException when a required variable is missing or invalid
     */
    public static function fromEnvironment(): self
    {
        $maxBytes = self::env('UPLOAD_MAX_BYTES') ?? (string) self::DEFAULT_MAX_BYTES;
        if (!ctype_digit($maxBytes) || (int) $maxBytes < 1) {
            throw new RuntimeException('UPLOAD_MAX_BYTES must be a positive integer.');
        }

        $allowed = explode(',', self::env('UPLOAD_ALLOWED_MIME') ?? self::DEFAULT_ALLOWED_MIME);
        $allowed = array_values(array_filter(array_map(
            static fn (string $type): string => strtolower(trim($type)),
            $allowed,
        )));

        return new self(
            self::required('STORAGE_ACCOUNT'),
            self::required('STORAGE_CONTAINER'),
            self::required('STORAGE_SAS_TOKEN'),
            (int) $maxBytes,
            $allowed,
            // A missing API key is not an error here: the handler fails closed.
            self::env('UPLOAD_API_KEY'),
        );
    }

    private static function required(string $name): string
    {
        return self::env($name) ?? throw new RuntimeException("Missing environment variable: $name");
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || trim($value) === '' ? null : trim($value);
    }
}
