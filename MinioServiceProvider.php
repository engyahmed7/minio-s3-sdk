<?php

namespace Modules\Core\Features\Minio;

use Modules\Core\Features\Minio\Console\CheckMinioConnection;
use Modules\Core\Features\Minio\Constants\MinioConstants;
use Modules\Core\Features\Minio\Support\MinioObjectComposer;
use Modules\Core\Features\Minio\Support\MinioStorage;
use Modules\Core\Kernel\Support\FeatureServiceProvider;

/**
 * MinIO: S3-compatible object storage, used through Laravel's s3 disk (config/filesystems.php,
 * set from the AWS_* variables in .env).
 * Modules use it through MinioStorage, and MinioObjectComposer to join objects; Livewire
 * file uploads go straight to it.
 */
class MinioServiceProvider extends FeatureServiceProvider
{
    protected function key(): string
    {
        return 'minio';
    }

    public function register(): void
    {
        parent::register();

        $this->app->bind(MinioObjectComposer::class, fn () => new MinioObjectComposer(MinioStorage::disk()));
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckMinioConnection::class,
            ]);
        }

        if (filled(config('filesystems.disks.'.MinioStorage::diskName().'.endpoint'))) {
            $this->sendLivewireUploadsToMinio();
        }
    }

    /**
     * File fields upload straight from the browser to MinIO through a presigned URL, so
     * large files never pass through PHP. Skipped while no MinIO is configured, which keeps
     * Livewire's local temporary uploads.
     */
    private function sendLivewireUploadsToMinio(): void
    {
        config([
            'livewire.temporary_file_upload.disk' => MinioStorage::diskName(),
            'livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.MinioConstants::UPLOAD_MAX_KILOBYTES],
            'livewire.temporary_file_upload.max_upload_time' => MinioConstants::UPLOAD_MAX_MINUTES,
        ]);
    }
}
