<?php

declare(strict_types=1);

namespace App\Tests;

use App\SasBlobUploader;
use App\UploadFailedException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Offline tests only: the network call is replaced by a canned response.
 */
final class SasBlobUploaderTest extends TestCase
{
    private const SAS = 'sv=2024-11-04&si=upload-policy&sr=c&sig=SECRETSIGNATURE%3D';

    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'sas-test-');
        file_put_contents($this->file, 'hello');
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testBuildsThePublicUrlWithoutCredentials(): void
    {
        $uploader = new SasBlobUploader('stuploadtest', 'public', self::SAS);

        self::assertSame(
            'https://stuploadtest.blob.core.windows.net/public/2026/10/a%20b.pdf',
            $uploader->blobUrl('2026/10/a b.pdf'),
        );
    }

    #[DataProvider('sasTokenSpellings')]
    public function testSendsTheSasOnceWhateverItsLeadingCharacter(string $token): void
    {
        $uploader = $this->uploaderReturning(['status' => 201], $token);

        $url = $uploader->upload($this->file, '2026/10/abc.pdf', 'application/pdf');

        self::assertSame('https://stuploadtest.blob.core.windows.net/public/2026/10/abc.pdf', $url);
        self::assertSame($url . '?' . self::SAS, $uploader->requests[0]['url']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sasTokenSpellings(): array
    {
        return [
            'bare' => [self::SAS],
            'leading question mark' => ['?' . self::SAS],
            'surrounding whitespace' => [" ?" . self::SAS . "\n"],
        ];
    }

    public function testSendsACreateOnlyBlockBlobRequest(): void
    {
        $uploader = $this->uploaderReturning(['status' => 201]);

        $uploader->upload($this->file, '2026/10/abc.png', 'image/png');

        self::assertSame(
            ['x-ms-blob-type: BlockBlob', 'x-ms-blob-content-type: image/png', 'If-None-Match: *'],
            $uploader->requests[0]['headers'],
        );
        self::assertSame(5, $uploader->requests[0]['size']);
    }

    public function testReportsStatusAndErrorCodeFromTheHeader(): void
    {
        $uploader = $this->uploaderReturning([
            'status' => 403,
            'headers' => ['x-ms-error-code' => 'AuthorizationFailure'],
        ]);

        $exception = $this->uploadExpectingFailure($uploader);

        self::assertSame('Storage rejected the upload: HTTP 403 (AuthorizationFailure)', $exception->getMessage());
    }

    public function testReportsTheErrorCodeFromTheXmlBody(): void
    {
        $uploader = $this->uploaderReturning([
            'status' => 409,
            'body' => '<?xml version="1.0"?><Error><Code>BlobAlreadyExists</Code><Message>x</Message></Error>',
        ]);

        self::assertStringContainsString(
            'HTTP 409 (BlobAlreadyExists)',
            $this->uploadExpectingFailure($uploader)->getMessage(),
        );
    }

    public function testTreatsAnyStatusOtherThan201AsFailure(): void
    {
        $exception = $this->uploadExpectingFailure($this->uploaderReturning(['status' => 200]));

        self::assertStringContainsString('HTTP 200 (unknown error)', $exception->getMessage());
    }

    public function testFailureMessagesNeverContainTheSasOrRequestUrl(): void
    {
        $responses = [
            ['status' => 403, 'headers' => ['x-ms-error-code' => 'AuthenticationFailed']],
            // A service echoing the request back must not get the signature into our message.
            ['status' => 403, 'headers' => ['x-ms-error-code' => 'sig=SECRETSIGNATURE%3D']],
            ['status' => 400, 'body' => '<Error><Code>' . self::SAS . '</Code></Error>'],
            ['status' => 0, 'transportError' => 'cURL error 28 (Timeout was reached)'],
        ];

        foreach ($responses as $response) {
            $message = $this->uploadExpectingFailure($this->uploaderReturning($response))->getMessage();

            self::assertStringNotContainsString('SECRETSIGNATURE', $message);
            self::assertStringNotContainsString('sig=', $message);
            self::assertStringNotContainsString('blob.core.windows.net', $message);
        }
    }

    public function testFailsWithoutARequestWhenTheLocalFileIsMissing(): void
    {
        $uploader = $this->uploaderReturning(['status' => 201]);

        try {
            $uploader->upload($this->file . '-missing', '2026/10/abc.pdf', 'application/pdf');
            self::fail('Expected UploadFailedException');
        } catch (UploadFailedException $exception) {
            self::assertSame('The local file could not be read.', $exception->getMessage());
        }
        self::assertSame([], $uploader->requests);
    }

    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidSettings(string $account, string $container, string $token): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SasBlobUploader($account, $container, $token);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'account that would change the host' => ['evil.example/x', 'public', self::SAS],
            'account with uppercase' => ['StUpload', 'public', self::SAS],
            'container with a path' => ['stuploadtest', 'public/../other', self::SAS],
            'empty token' => ['stuploadtest', 'public', ' ? '],
        ];
    }

    private function uploadExpectingFailure(SasBlobUploader $uploader): UploadFailedException
    {
        try {
            $uploader->upload($this->file, '2026/10/abc.pdf', 'application/pdf');
        } catch (UploadFailedException $exception) {
            return $exception;
        }

        self::fail('Expected UploadFailedException');
    }

    /**
     * @param array{status?:int, headers?:array<string,string>, body?:string, transportError?:?string} $response
     */
    private function uploaderReturning(array $response, string $token = self::SAS): SasBlobUploader
    {
        return new class ('stuploadtest', 'public', $token, $response) extends SasBlobUploader {
            /** @var list<array{url:string, headers:list<string>, size:int}> */
            public array $requests = [];

            /**
             * @param array<string,mixed> $response
             */
            public function __construct(string $account, string $container, string $token, private array $response)
            {
                parent::__construct($account, $container, $token);
            }

            protected function send(string $url, array $headers, $stream, int $size): array
            {
                $this->requests[] = ['url' => $url, 'headers' => $headers, 'size' => $size];

                return $this->response + ['status' => 0, 'headers' => [], 'body' => '', 'transportError' => null];
            }
        };
    }
}
