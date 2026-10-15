<?php

namespace Modules\Core\Features\Minio\Support;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\AppendStream;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use Modules\Core\Features\Minio\Constants\MinioConstants;
use Throwable;

/**
 * Joins objects of the MinIO disk into one object, in the given order:
 *
 *     $composer->compose(['work/1/out/chunk_00001.csv', 'work/1/out/chunk_00002.csv'], 'results/1/modified.csv');
 *
 * On MinIO, when every source but the last is at least 5 MB (the S3 multipart minimum), the
 * join happens inside MinIO and no byte passes through PHP. Otherwise — or on any other disk,
 * like a faked one in tests — the sources are streamed one after another into the target.
 */
readonly class MinioObjectComposer
{
    public function __construct(private FilesystemAdapter $disk) {}

    /**
     * @param  list<string>  $sourcePaths  joined in this order
     */
    public function compose(array $sourcePaths, string $targetPath): void
    {
        if ($sourcePaths === []) {
            throw new InvalidArgumentException('Composing needs at least one source object.');
        }

        if ($this->disk instanceof AwsS3V3Adapter && self::canComposeOnServer(array_map($this->disk->size(...), $sourcePaths))) {
            $this->composeOnServer($this->disk, $sourcePaths, $targetPath);
        } else {
            $this->composeByStreaming($sourcePaths, $targetPath);
        }
    }

    /**
     * @param  list<int>  $sizes  of the sources, in order
     */
    public static function canComposeOnServer(array $sizes): bool
    {
        return array_all(array_slice($sizes, 0, -1), fn (int $size): bool => $size >= MinioConstants::MULTIPART_MIN_PART_BYTES);
    }

    /**
     * A multipart upload whose parts are server-side copies of the sources.
     *
     * @param  list<string>  $sourcePaths
     */
    private function composeOnServer(AwsS3V3Adapter $disk, array $sourcePaths, string $targetPath): void
    {
        $client = $disk->getClient();
        $bucket = $disk->getConfig()['bucket'];
        $upload = ['Bucket' => $bucket, 'Key' => $disk->path($targetPath)];
        $upload['UploadId'] = $client->createMultipartUpload($upload)['UploadId'];

        try {
            $parts = [];

            foreach ($sourcePaths as $index => $sourcePath) {
                $copy = $client->uploadPartCopy($upload + [
                    'PartNumber' => $index + 1,
                    'CopySource' => $bucket.'/'.S3Client::encodeKey($disk->path($sourcePath)),
                ]);

                $parts[] = ['PartNumber' => $index + 1, 'ETag' => $copy['CopyPartResult']['ETag']];
            }

            $client->completeMultipartUpload($upload + ['MultipartUpload' => ['Parts' => $parts]]);
        } catch (Throwable $exception) {
            // Without this the copied parts would stay in the bucket, invisible but stored.
            $client->abortMultipartUpload($upload);

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $sourcePaths
     */
    private function composeByStreaming(array $sourcePaths, string $targetPath): void
    {
        $joinedSources = new AppendStream(array_map(
            fn (string $sourcePath) => Utils::streamFor($this->disk->readStream($sourcePath)),
            $sourcePaths,
        ));

        $this->disk->writeStream($targetPath, StreamWrapper::getResource($joinedSources));
    }
}
