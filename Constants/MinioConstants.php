<?php

namespace Modules\Core\Features\Minio\Constants;

/**
 * Fixed rules of how the feature uses the disk. The connection itself is Laravel's s3 disk
 * (config/filesystems.php), set from the AWS_* variables in .env.
 */
final class MinioConstants
{
    public const string DISK = 's3';

    // Livewire's temporary uploads go straight from the browser to the disk (presigned PUT).
    // These replace Livewire's defaults (12 MB, 5 minutes) for every upload field; each
    // field still enforces its own maximum.
    public const int UPLOAD_MAX_KILOBYTES = 500 * 1024;
    public const int UPLOAD_MAX_MINUTES = 30;

    // S3 multipart rule: every part but the last must be at least this size.
    public const int MULTIPART_MIN_PART_BYTES = 5 * 1024 * 1024;
}
