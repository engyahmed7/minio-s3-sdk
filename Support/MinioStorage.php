<?php

namespace Modules\Core\Features\Minio\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Features\Minio\Constants\MinioConstants;

/**
 * MinIO through Laravel's s3 disk (config/filesystems.php, set by the AWS_* variables).
 */
class MinioStorage
{
    public static function diskName(): string
    {
        return MinioConstants::DISK;
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    /**
     * The file's absolute URL (Storage::url() can return a host-less path).
     */
    public static function url(string $path): string
    {
        return url(self::disk()->url($path));
    }
}
