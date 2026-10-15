# MinIO S3 SDK

Laravel feature for S3-compatible object storage on [MinIO](https://min.io). It uses Laravel’s `s3` filesystem disk, configured from the `AWS_*` environment variables, and gives the rest of the application a small, consistent API for storing files, building absolute URLs, joining objects, and sending Livewire uploads straight to the bucket.

Large files never have to pass through PHP. Livewire uploads go from the browser to MinIO over a presigned URL, and object composition runs inside MinIO whenever the sources meet the S3 multipart size rule.

## What it provides

| Piece | Role |
| --- | --- |
| `MinioStorage` | Resolves the configured disk and turns object paths into absolute URLs. |
| `MinioObjectComposer` | Joins existing objects into one object, in order. |
| `MinioServiceProvider` | Registers the composer, the connection command, and Livewire upload settings. |
| `minio:check` | Uploads, reads back, and deletes a test object to verify the connection. |

The feature lives in the `Modules\Core\Features\Minio` namespace and is booted by the host application’s `FeatureServiceProvider`.

## Requirements

- PHP 8.4 or later
- Laravel with the `s3` disk (`league/flysystem-aws-s3-v3`)
- AWS SDK for PHP (`aws/aws-sdk-php`)
- `guzzlehttp/psr7`, used when objects are joined by streaming
- Livewire, when browser file uploads should go directly to MinIO
- The host application’s `Modules\Core\Kernel\Support\FeatureServiceProvider`

## Configuration

The connection is Laravel’s `s3` disk in `config/filesystems.php`. Set it from the environment:

```dotenv
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_URL=
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

MinIO expects path-style requests, so `AWS_USE_PATH_STYLE_ENDPOINT` should be `true`. A typical disk entry:

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    'throw' => false,
],
```

Leave `AWS_ENDPOINT` empty to keep Livewire on its local temporary disk. The provider only redirects uploads after that endpoint is set.

## Registration

`MinioServiceProvider` registers itself under the feature key `minio`. On boot it:

- Binds `MinioObjectComposer` to the configured disk.
- Registers the `minio:check` Artisan command.
- Points Livewire temporary uploads at the MinIO disk when an endpoint is configured.

Livewire then uses these limits for every upload field:

| Setting | Value |
| --- | --- |
| Maximum size | 500 MB (`500 * 1024` kilobytes) |
| Maximum upload time | 30 minutes |

A field can still enforce a lower maximum of its own.

## Usage

### Store, read, and link a file

```php
use Modules\Core\Features\Minio\Support\MinioStorage;

MinioStorage::disk()->put('reports/2026/summary.csv', $contents);

$contents = MinioStorage::disk()->get('reports/2026/summary.csv');

$url = MinioStorage::url('reports/2026/summary.csv');
```

`MinioStorage::disk()` returns Laravel’s filesystem adapter for the `s3` disk, so the usual `put`, `get`, `delete`, `readStream`, and `writeStream` methods are available. `url()` wraps `Storage::url()` with `url()`, which keeps the result absolute when the disk would otherwise return a host-less path.

### Join objects into one

```php
use Modules\Core\Features\Minio\Support\MinioObjectComposer;

app(MinioObjectComposer::class)->compose(
    [
        'work/1/out/chunk_00001.csv',
        'work/1/out/chunk_00002.csv',
    ],
    'results/1/modified.csv',
);
```

Sources are joined in the order given. At least one source is required.

The composer picks the join strategy from the disk and the object sizes:

- **Inside MinIO.** When the disk is the S3 adapter and every source except the last is at least 5 MB, the target is a multipart upload whose parts are server-side copies. No byte is read into PHP. If the upload fails, the multipart upload is aborted so copied parts are not left in the bucket.
- **Through PHP.** Smaller sources, and any non-S3 disk (including `Storage::fake()` in tests), are streamed one after another into the target.

`MinioObjectComposer::canComposeOnServer()` exposes the size check if a caller needs to know which path will run before composing.

## Verify the connection

```bash
php artisan minio:check
```

The command prints the disk, endpoint, and bucket, uploads a small text object under `minio-check/`, reads it back, prints its URL, and deletes it.

```bash
php artisan minio:check --keep
```

`--keep` leaves the test object in the bucket. A missing disk, or any S3 error, is printed and the command exits with a failure status. For the duration of the check, the disk is set to throw so the real S3 error is shown instead of a bare `false`.

## Constants

`MinioConstants` holds the fixed rules. Change them in code; they are not environment settings.

| Constant | Value | Used for |
| --- | --- | --- |
| `DISK` | `s3` | Filesystem disk name. |
| `UPLOAD_MAX_KILOBYTES` | `512000` (500 MB) | Livewire temporary upload size. |
| `UPLOAD_MAX_MINUTES` | `30` | Livewire temporary upload time. |
| `MULTIPART_MIN_PART_BYTES` | `5242880` (5 MB) | S3 rule: every part but the last must be at least this size. |
