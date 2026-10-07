<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\BlobUploader;
use App\UploadFailedException;

final class FakeBlobUploader implements BlobUploader
{
    /** @var list<array{localPath:string, blobName:string, contentType:string}> */
    public array $uploads = [];

    public ?UploadFailedException $failure = null;

    public function upload(string $localPath, string $blobName, string $contentType): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->uploads[] = ['localPath' => $localPath, 'blobName' => $blobName, 'contentType' => $contentType];

        return 'https://fake.example/public/' . $blobName;
    }
}
