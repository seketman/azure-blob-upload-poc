<?php

declare(strict_types=1);

namespace App\Tests;

use App\Tests\Support\FakeBlobUploader;
use App\UploadFailedException;
use App\UploadHandler;
use PHPUnit\Framework\TestCase;

final class UploadHandlerTest extends TestCase
{
    private const API_KEY = 'test-api-key';
    private const MAX_BYTES = 1024;
    private const BLOB_NAME = '~^\d{4}/\d{2}/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.%s$~';

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    private const HTML = "<!DOCTYPE html>\n<html><head><title>x</title></head><body><script>alert(1)</script></body></html>\n";
    private const SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';

    private FakeBlobUploader $uploader;

    /** @var list<string> */
    private array $tempFiles = [];

    private string|false $previousErrorLog;

    private string $logFile;

    protected function setUp(): void
    {
        $this->uploader = new FakeBlobUploader();
        // Keep the handler's error_log() output out of the test run and readable by assertions.
        $this->logFile = $this->tempFile('');
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string) $this->previousErrorLog);
        array_map(unlink(...), array_filter($this->tempFiles, is_file(...)));
    }

    public function testStoresAPdfAndReturnsItsUrl(): void
    {
        $file = $this->upload(self::PDF, 'report.pdf');

        $response = $this->handler()->handle(self::API_KEY, $file, 500);

        self::assertSame(201, $response['status']);
        $body = $response['body'];
        self::assertMatchesRegularExpression(sprintf(self::BLOB_NAME, 'pdf'), $body['blob']);
        self::assertStringStartsWith(gmdate('Y/m') . '/', $body['blob']);
        self::assertSame('https://fake.example/public/' . $body['blob'], $body['url']);
        self::assertSame('application/pdf', $body['contentType']);
        self::assertSame(strlen(self::PDF), $body['size']);
        self::assertSame(
            [['localPath' => $file['tmp_name'], 'blobName' => $body['blob'], 'contentType' => 'application/pdf']],
            $this->uploader->uploads,
        );
    }

    public function testExtensionComesFromTheDetectedTypeNotTheClient(): void
    {
        $file = $this->upload(base64_decode(self::PNG_BASE64), 'document.pdf', 'application/pdf');

        $response = $this->handler()->handle(self::API_KEY, $file, 500);

        self::assertSame(201, $response['status']);
        self::assertSame('image/png', $response['body']['contentType']);
        self::assertMatchesRegularExpression(sprintf(self::BLOB_NAME, 'png'), $response['body']['blob']);
    }

    public function testClientFileNameNeverAppearsInTheBlobName(): void
    {
        $file = $this->upload(self::PDF, '../../secret-invoice-name.pdf');

        $response = $this->handler()->handle(self::API_KEY, $file, 500);

        self::assertSame(201, $response['status']);
        self::assertStringNotContainsString('secret-invoice-name', $response['body']['blob']);
        self::assertStringNotContainsString('..', $response['body']['blob']);
        self::assertStringNotContainsString('secret-invoice-name', $response['body']['url']);
    }

    public function testEachUploadGetsADifferentBlobName(): void
    {
        $handler = $this->handler();

        $first = $handler->handle(self::API_KEY, $this->upload(self::PDF), 500);
        $second = $handler->handle(self::API_KEY, $this->upload(self::PDF), 500);

        self::assertNotSame($first['body']['blob'], $second['body']['blob']);
    }

    public function testRejectsAMissingApiKey(): void
    {
        $response = $this->handler()->handle(null, $this->upload(self::PDF), 500);

        $this->assertError(401, 'unauthorized', $response);
    }

    public function testRejectsAWrongApiKey(): void
    {
        $response = $this->handler()->handle('wrong-key', $this->upload(self::PDF), 500);

        $this->assertError(401, 'unauthorized', $response);
    }

    public function testFailsClosedWhenNoApiKeyIsConfigured(): void
    {
        foreach ([null, ''] as $configuredKey) {
            $handler = new UploadHandler($this->uploader, $configuredKey, self::MAX_BYTES, ['application/pdf']);

            $this->assertError(500, 'server_error', $handler->handle('', $this->upload(self::PDF), 500));
            $this->assertError(500, 'server_error', $handler->handle(null, $this->upload(self::PDF), 500));
        }
    }

    public function testRejectsARequestWithoutAFile(): void
    {
        $this->assertError(400, 'missing_file', $this->handler()->handle(self::API_KEY, null, 0));

        $noFile = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
        $this->assertError(400, 'missing_file', $this->handler()->handle(self::API_KEY, $noFile, 200));
    }

    public function testRejectsAnEmptyFile(): void
    {
        $response = $this->handler()->handle(self::API_KEY, $this->upload(''), 200);

        $this->assertError(400, 'empty_file', $response);
    }

    public function testRejectsAFailedUpload(): void
    {
        $file = ['name' => 'a.pdf', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0];

        $this->assertError(400, 'invalid_upload', $this->handler()->handle(self::API_KEY, $file, 200));
    }

    public function testRejectsAMultiFileField(): void
    {
        $file = ['name' => ['a.pdf'], 'type' => [''], 'tmp_name' => ['/tmp/x'], 'error' => [UPLOAD_ERR_OK], 'size' => [1]];

        $this->assertError(400, 'invalid_upload', $this->handler()->handle(self::API_KEY, $file, 200));
    }

    public function testRejectsAFileLargerThanTheLimit(): void
    {
        $file = $this->upload(self::PDF . str_repeat('x', self::MAX_BYTES));

        $this->assertError(413, 'file_too_large', $this->handler()->handle(self::API_KEY, $file, 2000));
    }

    public function testAcceptsAFileExactlyAtTheLimit(): void
    {
        $file = $this->upload(self::PDF . str_repeat('x', self::MAX_BYTES - strlen(self::PDF)));

        self::assertSame(201, $this->handler()->handle(self::API_KEY, $file, 2000)['status']);
    }

    public function testRejectsAFilePhpRefusedForItsSize(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $error) {
            $file = ['name' => 'big.pdf', 'type' => '', 'tmp_name' => '', 'error' => $error, 'size' => 0];

            $this->assertError(413, 'file_too_large', $this->handler()->handle(self::API_KEY, $file, 200));
        }
    }

    public function testTreatsADiscardedOversizedBodyAsTooLarge(): void
    {
        // What PHP leaves behind when the body exceeds post_max_size: no $_FILES entry at all.
        $response = $this->handler()->handle(self::API_KEY, null, self::MAX_BYTES + 1);

        $this->assertError(413, 'file_too_large', $response);
    }

    public function testRejectsATypeThatIsNotConfigured(): void
    {
        $file = $this->upload("just some plain text\n", 'notes.txt', 'text/plain');

        $this->assertError(415, 'unsupported_type', $this->handler()->handle(self::API_KEY, $file, 200));
    }

    public function testRejectsAConfiguredTypeTheServiceCannotName(): void
    {
        $handler = $this->handler(['application/zip', 'application/x-empty', 'application/octet-stream']);
        $file = $this->upload("\x00\x01\x02\x03binary", 'blob.bin');

        $this->assertError(415, 'unsupported_type', $handler->handle(self::API_KEY, $file, 200));
    }

    public function testRejectsHtmlDisguisedAsPdf(): void
    {
        $file = $this->upload(self::HTML, 'invoice.pdf', 'application/pdf');

        $this->assertError(415, 'unsupported_type', $this->handler()->handle(self::API_KEY, $file, 200));
    }

    public function testRejectsHtmlAndSvgEvenWhenConfigured(): void
    {
        $handler = $this->handler(['application/pdf', 'text/html', 'image/svg+xml']);

        $this->assertError(415, 'unsupported_type', $handler->handle(self::API_KEY, $this->upload(self::HTML), 200));
        $this->assertError(415, 'unsupported_type', $handler->handle(self::API_KEY, $this->upload(self::SVG), 200));
    }

    public function testAcceptsAnOptionalTypeOnlyWhenConfigured(): void
    {
        $handler = $this->handler(['TEXT/PLAIN']);

        $response = $handler->handle(self::API_KEY, $this->upload("just some plain text\n"), 200);

        self::assertSame(201, $response['status']);
        self::assertStringEndsWith('.txt', $response['body']['blob']);
    }

    public function testReportsAStorageFailureWithoutLeakingDetail(): void
    {
        $this->uploader->failure = new UploadFailedException('Storage rejected the upload: HTTP 403 (AuthorizationFailure)');

        $response = $this->handler()->handle(self::API_KEY, $this->upload(self::PDF), 200);

        $this->assertError(502, 'storage_error', $response);
        self::assertStringNotContainsString('AuthorizationFailure', $response['body']['message']);
        self::assertStringContainsString('HTTP 403 (AuthorizationFailure)', (string) file_get_contents($this->logFile));
    }

    public function testDoesNotCallStorageWhenValidationFails(): void
    {
        $this->handler()->handle('wrong-key', $this->upload(self::PDF), 200);
        $this->handler()->handle(self::API_KEY, $this->upload(self::HTML), 200);

        self::assertSame([], $this->uploader->uploads);
    }

    /**
     * @param list<string> $allowed
     */
    private function handler(array $allowed = ['application/pdf', 'image/png', 'image/jpeg']): UploadHandler
    {
        return new UploadHandler($this->uploader, self::API_KEY, self::MAX_BYTES, $allowed);
    }

    /**
     * Builds a $_FILES-style entry backed by a real temporary file.
     *
     * @return array{name:string, type:string, tmp_name:string, error:int, size:int}
     */
    private function upload(string $content, string $clientName = 'file.bin', string $clientType = 'application/octet-stream'): array
    {
        return [
            'name' => $clientName,
            'type' => $clientType,
            'tmp_name' => $this->tempFile($content),
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ];
    }

    private function tempFile(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload-test-');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array{status:int, body:array<string,mixed>} $response
     */
    private function assertError(int $status, string $code, array $response): void
    {
        self::assertSame($status, $response['status']);
        self::assertSame($code, $response['body']['error']);
        self::assertSame(['error', 'message'], array_keys($response['body']));
        self::assertNotSame('', $response['body']['message']);
    }
}
