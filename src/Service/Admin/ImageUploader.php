<?php

namespace App\Service\Admin;

use Aws\S3\S3Client;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageUploader
{
    public function __construct(
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
        private readonly S3Client $s3Client,
        #[Autowire(env: 'AWS_S3_BUCKET_NAME')]
        private readonly string $bucketName,
    ) {
    }

    public function upload(UploadedFile $file, string $prefix): string
    {
        $extension = $file->guessExtension() ?? 'png';
        $path = sprintf('images/%s/%s.%s', $prefix, bin2hex(random_bytes(8)), $extension);

        $stream = fopen($file->getPathname(), 'r');
        $this->storage->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    public function delete(string $path): void
    {
        if ($this->storage->fileExists($path)) {
            $this->storage->delete($path);
        }
    }

    /**
     * Get a pre-signed URL for an image stored in S3 (valid for 1 hour).
     */
    public function getUrl(string $path): string
    {
        // The default.storage has prefix 'default', so the full S3 key is default/{path}
        $key = 'default/' . ltrim($path, '/');

        $command = $this->s3Client->getCommand('GetObject', [
            'Bucket' => $this->bucketName,
            'Key' => $key,
        ]);

        $presignedRequest = $this->s3Client->createPresignedRequest($command, '+1 hour');

        return (string) $presignedRequest->getUri();
    }
}
