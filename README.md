# azure-blob-upload-poc

Proof of concept: a PHP endpoint that receives a file, stores it in Azure Blob
Storage and returns a public URL that anyone can open without authentication.

```
client ──POST /upload (multipart)──▶ PHP ──PUT blob (HTTPS + SAS)──▶ storage account
   ▲                                  │                              container "public"
   └──────── 201 { "url": ... } ──────┘                              (anonymous read, per blob)
```

The design, its alternatives and the open decisions are in [PLAN.md](PLAN.md).

## Prerequisites

- PHP 8.2 or later with the `curl` and `fileinfo` extensions
- [Composer](https://getcomposer.org/) (autoloader and PHPUnit only; there are no runtime dependencies)
- [Azure CLI](https://learn.microsoft.com/cli/azure/install-azure-cli), logged in
  (`az login`) to a subscription where you can create a resource group
- `openssl` and `curl`

## 1. Deploy the Azure resources

```sh
composer install
./infra/deploy.sh
```

The script:

1. Creates the resource group and deploys `infra/main.bicep`: a dedicated
   storage account and a container with anonymous read access per blob.
2. Creates a stored access policy on the container with permission `c`
   (create only) and an expiry.
3. Issues a service SAS bound to that policy.
4. Writes `.env` (mode `600`, ignored by Git) with the storage settings and a
   generated API key.

Settings, all optional:

| Variable | Default | Meaning |
|---|---|---|
| `RESOURCE_GROUP` | `rg-upload-poc` | Resource group to create |
| `LOCATION` | `brazilsouth` | Azure region |
| `SAS_DAYS` | `30` | Days until the SAS expires |
| `POLICY_NAME` | `upload-policy` | Stored access policy name |

```sh
LOCATION=westeurope SAS_DAYS=7 ./infra/deploy.sh
```

Running it again is safe: it renews the policy expiry, issues a new SAS and
keeps the existing API key. A new stored access policy can take about
30 seconds to become effective; an upload right after the first deployment may
fail with `502` until then.

## 2. Run

```sh
set -a; . ./.env; set +a
php -d upload_max_filesize=10M -d post_max_size=11M -S 127.0.0.1:8080 -t public
```

The two `-d` flags matter: PHP's defaults (2 MB per file) are lower than
`UPLOAD_MAX_BYTES`, and PHP enforces its own limits before the application
runs. Keep `post_max_size` slightly above `UPLOAD_MAX_BYTES` to leave room for
the multipart envelope.

### With Docker

```sh
docker compose up -d --build
```

The container reads `.env`, already has the PHP upload limits set, and
listens on `127.0.0.1:8080`. To upload without loading `.env` into your shell
(run it from the repository directory, where Compose can find the service):

```sh
curl -i -H "X-Api-Key: $(docker compose exec -T app printenv UPLOAD_API_KEY)" \
  -F file=@sample.pdf http://127.0.0.1:8080/upload
```

Stop it with `docker compose down`. After re-running `deploy.sh`, restart the
container (`docker compose up -d --force-recreate`) so it picks up the new SAS.

## 3. Use

```sh
curl -i -H "X-Api-Key: $UPLOAD_API_KEY" -F file=@sample.pdf http://127.0.0.1:8080/upload
```

```
HTTP/1.1 201 Created
Content-Type: application/json

{"url":"https://<account>.blob.core.windows.net/public/2026/10/3f0c6c0e-8a52-4d0b-9a57-0d6c1f1b2a77.pdf","blob":"2026/10/3f0c6c0e-8a52-4d0b-9a57-0d6c1f1b2a77.pdf","contentType":"application/pdf","size":48213}
```

The URL needs no credentials:

```sh
curl -sS -o downloaded.pdf "<url from the response>"
cmp sample.pdf downloaded.pdf && echo identical
```

Checks worth running once against a real deployment:

```sh
# Anonymous listing is not allowed (expects 404 ResourceNotFound).
curl -i "https://$STORAGE_ACCOUNT.blob.core.windows.net/$STORAGE_CONTAINER?restype=container&comp=list"

# The upload credential cannot delete (expects 403).
curl -i -X DELETE "<url from the response>?$STORAGE_SAS_TOKEN"
```

### Request

`POST /upload`, `multipart/form-data`, one file in the field `file`, header
`X-Api-Key`.

### Errors

Every error has the same shape: `{"error": "<code>", "message": "<text>"}`.

| Status | `error` | When |
|---|---|---|
| 400 | `missing_file` | No `file` field in the request |
| 400 | `empty_file` | The file has zero bytes |
| 400 | `invalid_upload` | The upload was interrupted or malformed (including `file[]`) |
| 401 | `unauthorized` | `X-Api-Key` missing or wrong |
| 404 | `not_found` | Any path other than `/upload` |
| 405 | `method_not_allowed` | `/upload` with a method other than `POST` |
| 413 | `file_too_large` | Larger than `UPLOAD_MAX_BYTES` or than PHP's own limits |
| 415 | `unsupported_type` | Detected type is not in `UPLOAD_ALLOWED_MIME` |
| 500 | `server_error` | Missing configuration; details are in the server log only |
| 502 | `storage_error` | Storage rejected the upload; details are in the server log only |

## Configuration

Read from environment variables (see [.env.example](.env.example)).

| Variable | Required | Default |
|---|---|---|
| `STORAGE_ACCOUNT` | yes | |
| `STORAGE_CONTAINER` | yes | |
| `STORAGE_SAS_TOKEN` | yes | |
| `UPLOAD_API_KEY` | yes (every request gets `500` without it) | |
| `UPLOAD_MAX_BYTES` | no | `10485760` |
| `UPLOAD_ALLOWED_MIME` | no | `application/pdf,image/png,image/jpeg` |

`UPLOAD_ALLOWED_MIME` can also enable `image/gif`, `image/webp` and
`text/plain`. Other values have no effect: the service only stores types it
has a file extension for, and it never stores `text/html` or `image/svg+xml`.

## Tests

```sh
composer test
```

The tests run offline: the handler is exercised with an in-memory uploader, and
the storage client with a canned HTTP response.

## Teardown

```sh
./infra/destroy.sh
```

Asks for confirmation, then deletes the resource group and everything in it,
including the uploaded files. Remove `.env` afterwards; its SAS is no longer valid.

## Security notes

- **Public means public.** Anyone with the URL can read the file for as long as
  it exists, and the URL can be forwarded or indexed. Random names prevent
  guessing, not sharing. Do not use this design for personal or financial
  data; see "Open decisions" in [PLAN.md](PLAN.md) for the private alternative.
- The storage account is dedicated to this purpose. Anonymous access is an
  account-level switch and should not be enabled where private data lives.
- The container allows reading a blob by URL, not listing the container.
- The upload credential is a SAS limited to one container and to creating new
  blobs: it cannot overwrite, read, list or delete. To revoke it, delete the
  stored access policy (or re-run `deploy.sh` under a new `POLICY_NAME`); the
  account key does not need to be rotated.
- The SAS stays on the server. It is never sent to the client and never
  written to logs or error messages.
- The endpoint requires an API key so it cannot be used as an open upload
  relay. It is a single shared secret: enough for a proof of concept, not a
  replacement for real authentication. Serve it over HTTPS outside localhost.
- The content type is detected from the file's bytes. The client's file name
  and declared type are ignored, and blob names are generated on the server.
- HTML and SVG are always refused, so the storage domain never serves active
  content.
- PHP receives and buffers the request body before the API key is checked, so
  unauthenticated clients can still send bodies up to `post_max_size`. Keep
  that limit close to `UPLOAD_MAX_BYTES`, and put a rate limit or reverse-proxy
  body limit in front of any real deployment.
- Not included: virus scanning, rate limiting, retention rules. For
  production on Azure, replace the SAS with a managed identity by adding
  another `BlobUploader` implementation.

## Layout

```
infra/main.bicep           storage account and container
infra/deploy.sh            deployment, access policy, SAS, .env
infra/destroy.sh           removes the resource group
public/index.php           front controller: routing, configuration, JSON output
src/UploadHandler.php      validation and response, free of globals
src/BlobUploader.php       storage interface
src/SasBlobUploader.php    Put Blob over cURL with a SAS
src/Config.php             environment variables
tests/                     PHPUnit
```
