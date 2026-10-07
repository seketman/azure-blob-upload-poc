# Plan: public file upload to Azure Blob Storage from PHP

Status: **draft for review** — nothing is provisioned or implemented yet.

## 1. Goal

A PHP proof of concept that:

1. Receives a file over HTTP.
2. Stores it in Azure Blob Storage.
3. Responds with a public URL that anyone can open without authentication.

Out of scope for the POC: user management, a UI, virus scanning, image
processing, CDN, custom domain.

## 2. Target architecture

```
client ──POST /upload (multipart)──▶ PHP POC ──PUT blob (HTTPS + SAS)──▶ Storage account
   ▲                                    │                                 container "public"
   └──────── 201 { "url": ... } ────────┘                                 (anonymous read, per blob)

anyone ──GET https://<account>.blob.core.windows.net/public/<blob>──▶ file
```

- The PHP app is the only writer. It never exposes its credential to the client.
- Readers hit Blob Storage directly; the PHP app is not in the download path.

## 3. Azure resources

| Resource | Setting | Value | Why |
|---|---|---|---|
| Resource group | name | `rg-upload-poc` | Isolated, deletable in one command |
| Storage account | kind / SKU | `StorageV2` / `Standard_LRS` | Cheapest option that supports everything needed |
| | `allowBlobPublicAccess` | `true` | Required for anonymous read; it is `false` by default |
| | `minimumTlsVersion` | `TLS1_2` | Baseline |
| | `supportsHttpsTrafficOnly` | `true` | Baseline |
| | `allowSharedKeyAccess` | `true` for the POC | Needed to issue a service SAS (see 4) |
| Container | name | `public` | |
| | public access level | `blob` | Anonymous read of a blob by URL; **no** anonymous listing |
| Stored access policy | on the container | permissions `c` (create), with expiry | Lets the SAS be revoked without rotating the account key |

A **dedicated** storage account is used on purpose. Enabling anonymous access is
an account-level switch, so it should not be turned on in an account that also
holds private data (logs, application files, a static website).

Provisioning is delivered as code (`infra/main.bicep` plus a short deploy
script), so the environment is reproducible and removable with
`az group delete`.

## 4. How the PHP app authenticates to Storage

| Option | Pros | Cons |
|---|---|---|
| **Service SAS bound to a stored access policy** (recommended for the POC) | Scoped to one container and to "create" only; revocable; works from any host, including a laptop | Is still a secret to store; has an expiry to manage |
| Account key / connection string | Simplest | Full control of the whole account if leaked |
| Managed identity + `Storage Blob Data Contributor` | No secret at all; the right answer for production on Azure | Only works when the app runs on Azure; token acquisition adds code |

Recommendation: SAS for the POC, managed identity when it moves to a hosted
environment. The upload code is written behind a small interface so the
credential strategy can change without touching the endpoint.

With permission `c` only, the credential can create new blobs but cannot
overwrite, read, list or delete existing ones.

## 5. PHP implementation

- PHP 8.2+, no framework. Runs with `php -S` locally.
- Upload uses the Blob REST API directly (`Put Blob`: one `PUT` with header
  `x-ms-blob-type: BlockBlob`) through cURL. This avoids depending on an SDK:
  Microsoft retired its official PHP storage SDK, and the community
  replacement would need to be evaluated before being adopted. The REST call
  is small enough for a POC. For large files, the follow-up is chunked upload
  (`Put Block` / `Put Block List`).

### Endpoint

`POST /upload` — `multipart/form-data`, field `file`.

Steps:

1. Reject requests without a valid API key header (the endpoint must not be an
   open upload relay).
2. Validate the upload: PHP upload error code, maximum size, and MIME type
   detected from the content (`finfo`), checked against an allowlist.
3. Generate the blob name on the server: `<yyyy>/<mm>/<uuid>.<ext>`. The
   client's file name is never used in the path.
4. `PUT` the content with the detected `Content-Type` (`x-ms-blob-content-type`).
5. Return the URL.

Response `201 Created`:

```json
{
  "url": "https://<account>.blob.core.windows.net/public/2026/10/7f3c…e1.pdf",
  "blob": "2026/10/7f3c…e1.pdf",
  "contentType": "application/pdf",
  "size": 48213
}
```

Errors: `400` invalid or missing file, `401` missing/invalid API key,
`413` too large, `415` type not allowed, `502` Storage rejected the upload.

### Configuration (environment variables)

| Variable | Example |
|---|---|
| `STORAGE_ACCOUNT` | `stuploadpoc001` |
| `STORAGE_CONTAINER` | `public` |
| `STORAGE_SAS_TOKEN` | `sv=…&si=upload-policy&sr=c&sig=…` |
| `UPLOAD_MAX_BYTES` | `10485760` |
| `UPLOAD_ALLOWED_MIME` | `application/pdf,image/png,image/jpeg` |
| `UPLOAD_API_KEY` | random string |

### Repository layout

```
infra/
  main.bicep          storage account, container, access policy
  deploy.sh           az deployment + SAS generation
public/
  index.php           front controller, routes POST /upload
src/
  UploadHandler.php   validation and response
  BlobUploader.php    interface
  SasBlobUploader.php Put Blob over cURL
tests/
.env.example
README.md
```

## 6. Security considerations

- **Public means public.** Anyone holding the URL can read the file, forever,
  and the URL can be shared or indexed. Random blob names prevent guessing, not
  forwarding. Files with personal or financial data should not use this design
  (see decision 1).
- No anonymous listing: container access level is `blob`, not `container`.
- MIME allowlist excludes `text/html` and `image/svg+xml` to avoid serving
  active content from the storage domain.
- The SAS never reaches the browser and is never logged.
- Size limit enforced in PHP (`upload_max_filesize`, `post_max_size`) and in
  the handler.
- Optional hardening after the POC: blob soft delete, a lifecycle rule that
  expires old blobs, diagnostic logs, a CDN or custom domain in front.

## 7. Phases

| # | Phase | Deliverable | Done when |
|---|---|---|---|
| 1 | Infrastructure | `infra/` | A manual `curl -X PUT` with the SAS creates a blob and the blob opens anonymously in a browser |
| 2 | Uploader | `BlobUploader` + `SasBlobUploader` | Test uploads a file and gets back a reachable URL |
| 3 | Endpoint | `POST /upload` | `curl -F file=@sample.pdf` returns `201` with a URL that downloads the same bytes |
| 4 | Validation and errors | size, type, API key | Each error case returns its documented status |
| 5 | Documentation | `README.md` | A new person can deploy and run it from the README alone |

## 8. Acceptance criteria

- Uploading an allowed file returns `201` and a URL.
- The URL returns the identical file, with the right `Content-Type`, from a
  client with no credentials.
- Listing the container anonymously fails.
- Uploading with the same credential cannot overwrite or delete an existing blob.
- Oversized, disallowed and unauthenticated requests are rejected.
- The whole environment is removed with one command.

## 9. Open decisions

1. **Permanent public URL or time-limited link?** If the files can contain
   personal or financial data, the alternative is a private container and a
   read-only SAS URL with an expiry returned by the endpoint. Same code shape,
   different security posture. This plan assumes permanent public URLs.
2. **Region** for the storage account.
3. **Allowed file types and maximum size.**
4. **Retention:** keep files forever or expire them after N days.
5. **Where the POC runs:** only locally, or deployed to a host (which would
   make managed identity available).

## 10. Cost

Negligible at POC scale: `Standard_LRS` hot storage is billed per GB stored
plus per-operation and outbound-data charges; a few hundred small files stays
in the cents-per-month range.
