<?php

namespace Modules\Core\Features\Minio\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Features\Minio\Support\MinioStorage;
use Throwable;

class CheckMinioConnection extends Command
{
    protected $signature = 'minio:check {--keep : Leave the test file in the bucket}';

    protected $description = 'Upload, read back and delete a test file on the MinIO disk to verify the configured connection';

    public function handle(): int
    {
        $disk = MinioStorage::diskName();
        $config = config("filesystems.disks.{$disk}");

        if ($config === null) {
            $this->components->error("The [{$disk}] disk is not registered; check the AWS_* variables in .env.");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Disk', $disk);
        $this->components->twoColumnDetail('Endpoint', $config['endpoint']);
        $this->components->twoColumnDetail('Bucket', $config['bucket']);

        // Surface the real S3 error instead of a bare false.
        config(["filesystems.disks.{$disk}.throw" => true]);
        Storage::forgetDisk($disk);

        $path = 'minio-check/'.now()->format('Ymd-His').'.txt';
        $content = 'consent-BE MinIO check at '.now()->toIso8601String();

        try {
            $this->components->task('Upload '.$path, fn () => MinioStorage::disk()->put($path, $content));
            $this->components->task('Read it back', fn () => MinioStorage::disk()->get($path) === $content);

            $this->components->twoColumnDetail('URL', MinioStorage::url($path));

            if (! $this->option('keep')) {
                $this->components->task('Delete it', fn () => MinioStorage::disk()->delete($path));
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('MinIO is working.');

        return self::SUCCESS;
    }
}
