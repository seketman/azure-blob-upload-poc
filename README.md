# azure-blob-upload-poc

Proof of concept: a PHP endpoint that receives a file, stores it in Azure Blob
Storage and returns a public URL that anyone can open without authentication.

## What it does

```
client ──POST /upload (multipart)──▶ PHP ──PUT blob (HTTPS + SAS)──▶ storage account
   ▲                                  │                              container "public"
   └──────── 201 { "url": ... } ──────┘                              (anonymous read, per blob)
```

1. A client sends a file to `POST /upload` with an API key.
2. The service checks the key, the size and the real content type of the file.
3. It uploads the file to a blob container under a random, server-generated
   name.
4. It answers with the public URL of the stored file.

```sh
curl -H "X-Api-Key: <key>" -F file=@sample.pdf http://127.0.0.1:8080/upload
```

```json
{
  "url": "https://<account>.blob.core.windows.net/public/2026/10/3f0c6c0e-8a52-4d0b-9a57-0d6c1f1b2a77.pdf",
  "blob": "2026/10/3f0c6c0e-8a52-4d0b-9a57-0d6c1f1b2a77.pdf",
  "contentType": "application/pdf",
  "size": 48213
}
```

By default it accepts PDF, PNG and JPEG files up to 10 MB.

The service has no runtime dependencies: it talks to the Blob REST API with
cURL. The design, its alternatives and the open decisions are in
[PLAN.md](PLAN.md).

## Quick start

Running it locally takes three steps:

| Step | What | Where |
|---|---|---|
| 1 | Create the Azure resources and the `.env` file | [Step 1](#step-1-create-the-azure-resources) |
| 2 | Start the service | [Docker](#option-a-docker-any-operating-system), [macOS](#option-b-macos), [Linux](#option-c-linux) or [Windows](#option-d-windows) |
| 3 | Upload a file | [Step 3](#step-3-upload-a-file) |

Docker is the shortest path on every operating system: it needs no local PHP.

## Step 1: create the Azure resources

This step is the same for every way of running the service. It needs:

- An Azure subscription where you can create a resource group.
- The [Azure CLI](https://learn.microsoft.com/cli/azure/install-azure-cli),
  logged in with `az login`.
- A Bash shell with `openssl`. macOS and Linux have both. On Windows, use
  [WSL](https://learn.microsoft.com/windows/wsl/install) and install the Azure
  CLI inside it. To run the service natively afterwards, clone the repository
  on the Windows filesystem (for example `C:\src`) and run the script from WSL
  through `/mnt/c/src/azure-blob-upload-poc`, so that `.env` is visible to
  both WSL and PowerShell.

```sh
git clone https://github.com/seketman/azure-blob-upload-poc.git
cd azure-blob-upload-poc
./infra/deploy.sh
```

The script:

1. Creates the resource group and deploys `infra/main.bicep`: a dedicated
   storage account and a container with anonymous read access per blob.
2. Creates a stored access policy on the container with permission `c`
   (create only) and an expiry.
3. Issues a service SAS bound to that policy.
4. Writes `.env` in the repository root (mode `600`, ignored by Git) with the
   storage settings and a generated API key.

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

Running it again is safe: it extends the expiry of the stored access policy,
which the SAS inherits, writes the SAS to `.env` again and keeps the existing
API key. Restart the service afterwards so it reads `.env`. With Docker, use
`docker compose up -d --force-recreate`; a plain `docker compose restart`
keeps the old environment.

A new stored access policy can take about 30 seconds to become effective; an
upload right after the first deployment may fail with `502` until then.

### Without the script

If the storage account already exists, copy [.env.example](.env.example) to
`.env` and fill in the account, the container, a SAS token with create
permission on that container, and an API key of your choice.

## Step 2: start the service

All options serve `http://127.0.0.1:8080` and read their configuration from
the `.env` file created in step 1.

### Option A: Docker (any operating system)

Requires Docker with Compose v2 (Docker Desktop on Windows and macOS, Docker
Engine with the Compose plugin on Linux).

```sh
docker compose up -d --build
```

The image already has the PHP settings from the other options built in.
Useful commands:

```sh
docker compose logs -f     # follow the server log
docker compose down        # stop
docker compose up -d --force-recreate   # reload .env after re-running deploy.sh
```

### Option B: macOS

Install PHP and Composer, for example with [Homebrew](https://brew.sh/):

```sh
brew install php composer
```

Then, from the repository root:

```sh
composer install --no-dev
set -a; . ./.env; set +a
php -d upload_max_filesize=10M -d post_max_size=11M -d display_errors=0 -S 127.0.0.1:8080 -t public
```

### Option C: Linux

Install PHP 8.2 or later with the `curl` extension, and Composer. On Debian or
Ubuntu:

```sh
sudo apt install php-cli php-curl composer unzip
```

Then, from the repository root:

```sh
composer install --no-dev
set -a; . ./.env; set +a
php -d upload_max_filesize=10M -d post_max_size=11M -d display_errors=0 -S 127.0.0.1:8080 -t public
```

On other distributions, install the equivalent packages; `php -m` must list
`curl` and `fileinfo`.

### Option D: Windows

There are two ways. Inside **WSL**, follow [Option C](#option-c-linux)
unchanged.

To run it **natively in PowerShell**:

1. Install PHP 8.2 or later from
   [windows.php.net](https://windows.php.net/download) and add its folder to
   `PATH`.
2. In the PHP folder, copy `php.ini-development` to `php.ini` and enable these
   lines by removing the leading `;`:

   ```ini
   extension_dir = "ext"
   extension=curl
   extension=fileinfo
   extension=openssl
   ```

3. Install [Composer](https://getcomposer.org/download/).
4. From the repository root:

   ```powershell
   composer install --no-dev

   Get-Content .env | Where-Object { $_ -match '^\s*[^#\s].*=' } | ForEach-Object {
       $name, $value = $_ -split '=', 2
       Set-Item -Path "Env:$($name.Trim())" -Value $value.Trim().Trim("'").Trim('"')
   }

   php -d upload_max_filesize=10M -d post_max_size=11M -d display_errors=0 -S 127.0.0.1:8080 -t public
   ```

The `Get-Content` block loads `.env` into the current PowerShell session; run
it again in every new window.

### About the PHP flags

The three `-d` flags matter. The two size flags are needed because PHP's
defaults (2 MB per file) are lower than `UPLOAD_MAX_BYTES`, and PHP enforces
its own limits before the application runs. Keep `post_max_size` slightly
above `UPLOAD_MAX_BYTES` to leave room for the multipart envelope.

`display_errors=0` keeps PHP warnings out of the response. Without it, a
request over `post_max_size` is answered with `200` and an HTML warning
instead of the JSON error. The Docker image sets all three.

`--no-dev` skips PHPUnit, which is only needed to run the [tests](#tests).

## Step 3: upload a file

The request needs the API key that `deploy.sh` wrote to `.env`. Use any PDF,
PNG or JPEG file; the examples assume `./sample.pdf`.

**macOS, Linux, WSL** (in a shell where `.env` is loaded, as in step 2):

```sh
curl -i -H "X-Api-Key: $UPLOAD_API_KEY" -F file=@sample.pdf http://127.0.0.1:8080/upload
```

**Windows PowerShell** (in a session where `.env` is loaded, as in option D):

```powershell
curl.exe -i -H "X-Api-Key: $env:UPLOAD_API_KEY" -F "file=@sample.pdf" http://127.0.0.1:8080/upload
```

**Docker**, without loading `.env` into the shell. Run it from the repository
directory, where Compose can find the service. It works in Bash and in
PowerShell (use `curl.exe` there):

```sh
curl -i -H "X-Api-Key: $(docker compose exec -T app printenv UPLOAD_API_KEY)" -F file=@sample.pdf http://127.0.0.1:8080/upload
```

A successful upload answers `201 Created` with the JSON shown at the top. Open
the `url` in a browser, or download it and compare:

```sh
curl -sS -o downloaded.pdf "<url from the response>"
cmp sample.pdf downloaded.pdf && echo identical
```

## API reference

`POST /upload`, `multipart/form-data`, one file in the field `file`, header
`X-Api-Key`.

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
| 500 | `server_error` | Missing configuration or an unexpected error; details are in the server log only |
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

To raise `UPLOAD_MAX_BYTES` above 10 MB, raise the PHP limits with it: the two
size `-d` flags in step 2, or the `upload_max_filesize` and `post_max_size`
values in the `Dockerfile`.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `401 unauthorized` although the key is set | The key reached the server empty. Check that `.env` is loaded in the shell running `curl`. With the Docker command, `no configuration file provided: not found` means it was run outside the repository directory, and `service "app" is not running` means the container is stopped: run `docker compose up -d`. |
| `413 file_too_large`, or `400 missing_file` with the file attached, for a file under 10 MB | The server was started without the `-d upload_max_filesize` and `-d post_max_size` flags, so PHP's defaults apply: 2 MB per file and 8 MB per request. A file over 2 MB gets the `413`; a request over 8 MB is discarded whole and gets the `400`, or the `413` if its total size also exceeds `UPLOAD_MAX_BYTES`. |
| `200` with an HTML `Warning` before the JSON | The server was started without `-d display_errors=0`. |
| `415 unsupported_type` | The type is detected from the file's bytes, not from its name. Only the types in `UPLOAD_ALLOWED_MIME` are accepted. |
| `500 server_error` | A required variable is missing or invalid. The server log names the storage variables and `UPLOAD_MAX_BYTES`. If the log is silent, `UPLOAD_API_KEY` is empty or not set. An `Unhandled error` line means an unexpected failure rather than configuration. |
| `502 storage_error` right after the first deployment | The access policy is not effective yet. Wait 30 seconds and retry. |
| `502 storage_error` later on | The server log has the Azure error code. `AuthenticationFailed` usually means the SAS expired: re-run `./infra/deploy.sh` and restart the service. |
| `502` on Windows with `cURL error 60` in the log | PHP has no CA bundle. Download [cacert.pem](https://curl.se/docs/caextract.html) and set `curl.cainfo` to its full path in `php.ini`. |
| `composer install` reports a missing `ext-dom` | The development dependencies need more extensions. Use `--no-dev` to run the service, or see [Tests](#tests). |

## Checking the security properties

Worth running once against a real deployment, in a shell where `.env` is
loaded:

```sh
# Anonymous listing is not allowed (expects 404 ResourceNotFound).
curl -i "https://$STORAGE_ACCOUNT.blob.core.windows.net/$STORAGE_CONTAINER?restype=container&comp=list"

# The upload credential cannot delete (expects 403).
curl -i -X DELETE "<url from the response>?$STORAGE_SAS_TOKEN"
```

## Tests

```sh
composer install
composer test
```

The tests run offline: the handler is exercised with an in-memory uploader, and
the storage client with a canned HTTP response.

PHPUnit needs the `dom`, `mbstring` and `xml` extensions. On Debian or Ubuntu:
`sudo apt install php-xml php-mbstring`. On Windows, also enable
`extension=mbstring` in `php.ini`.

## Teardown

```sh
./infra/destroy.sh
```

Asks for confirmation, then deletes the resource group and everything in it,
including the uploaded files. Remove `.env` afterwards; its SAS is no longer
valid.

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
  stored access policy (`az storage container policy delete`); the account key
  does not need to be rotated. Re-running `deploy.sh` under a new
  `POLICY_NAME` creates a second policy and leaves the first one, and any SAS
  issued from it, valid.
- The SAS stays on the server. It is never sent to the client and never
  written to logs or error messages.
- The endpoint requires an API key so it cannot be used as an open upload
  relay. It is a single shared secret: enough for a proof of concept, not a
  replacement for real authentication. Serve it over HTTPS outside localhost.
- The content type is detected from the file's bytes. The client's file name
  and declared type are ignored, and blob names are generated on the server.
- The service always refuses HTML and SVG. Storage does not enforce this: the
  SAS can create a blob of any type, so a leaked SAS could put active content
  on the storage domain. Treat the SAS as a secret.
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
Dockerfile, compose.yaml   container setup
```
