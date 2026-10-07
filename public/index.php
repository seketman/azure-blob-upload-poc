<?php

declare(strict_types=1);

use App\Config;
use App\SasBlobUploader;
use App\UploadHandler;

require __DIR__ . '/../vendor/autoload.php';

/**
 * @param array{status:int, body:array<string,mixed>} $response
 * @param array<string,string> $headers
 */
function respond(array $response, array $headers = []): never
{
    http_response_code($response['status']);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header("$name: $value");
    }
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path !== '/upload') {
    respond(UploadHandler::error(404, 'not_found', 'Unknown route.'));
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(UploadHandler::error(405, 'method_not_allowed', 'Use POST.'), ['Allow' => 'POST']);
}

try {
    $config = Config::fromEnvironment();
    $uploader = new SasBlobUploader($config->storageAccount, $config->storageContainer, $config->sasToken);
} catch (Throwable $exception) {
    error_log('Configuration error: ' . $exception->getMessage());
    respond(UploadHandler::error(500, 'server_error', 'The service is not available.'));
}

$file = $_FILES['file'] ?? null;
// Guards against a tmp_name that did not come from PHP's own upload handling.
// Done here, not in the handler, because it only works within a real HTTP upload.
if (
    is_array($file)
    && ($file['error'] ?? null) === UPLOAD_ERR_OK
    && !(is_string($file['tmp_name'] ?? null) && is_uploaded_file($file['tmp_name']))
) {
    $file = null;
}

$handler = new UploadHandler($uploader, $config->apiKey, $config->maxBytes, $config->allowedMimeTypes);

respond($handler->handle(
    $_SERVER['HTTP_X_API_KEY'] ?? null,
    is_array($file) ? $file : null,
    (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
));
