<?php

declare(strict_types=1);

namespace App;

interface BlobUploader
{
    /**
     * Stores a local file as a new blob and returns its public URL.
     *
     * @throws UploadFailedException when the storage service does not accept the blob
     */
    public function upload(string $localPath, string $blobName, string $contentType): string;
}
