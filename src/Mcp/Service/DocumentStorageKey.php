<?php

declare(strict_types=1);

namespace App\Mcp\Service;

/** Raw S3 key corresponding to the path used with documents.storage. */
final class DocumentStorageKey
{
    public static function forStoredFilename(string $storedFilename): string
    {
        // Keep this prefix in sync with documents.storage in config/packages/flysystem.yaml.
        return 'documents/'.ltrim($storedFilename, '/');
    }
}
