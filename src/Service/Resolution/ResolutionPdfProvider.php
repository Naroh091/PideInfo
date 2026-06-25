<?php

namespace App\Service\Resolution;

use App\Entity\Resolution;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Provides the PDF bytes of a resolution so callers can attach the first/last
 * page to the analysis call for date extraction.
 *
 * Order of preference:
 *  1. The stored copy (`pdfStoragePath`).
 *  2. Fallback: download from `sourceUrl` and backfill storage. Many CTBG
 *     resolutions take their text from the published Excel and never downloaded
 *     the PDF, so `pdfStoragePath` is empty even though `sourceUrl` points at the
 *     PDF — without this fallback the date images would never be sent for them.
 *
 * Returns null for non-PDF sources (e.g. Word) or when no PDF can be obtained.
 */
final class ResolutionPdfProvider
{
    public function __construct(
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPdfBytes(Resolution $resolution): ?string
    {
        $stored = $this->readStored($resolution);
        if ($stored !== null) {
            return $stored;
        }

        return $this->fetchAndPersist($resolution);
    }

    private function readStored(Resolution $resolution): ?string
    {
        $path = $resolution->getPdfStoragePath();
        if ($path === null || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
            return null;
        }

        try {
            $content = $this->resolutionsStorage->read($path);
        } catch (\Throwable) {
            return null;
        }

        return ($content !== '' && str_starts_with($content, '%PDF-')) ? $content : null;
    }

    private function fetchAndPersist(Resolution $resolution): ?string
    {
        $url = $resolution->getSourceUrl();
        if ($url === null) {
            return null;
        }
        $urlPath = strtolower((string) parse_url($url, PHP_URL_PATH));
        if (!str_ends_with($urlPath, '.pdf')) {
            return null;
        }

        try {
            $content = $this->httpClient->request('GET', $url, ['timeout' => 60])->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Could not fetch PDF from sourceUrl for date images', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($content === '' || !str_starts_with($content, '%PDF-')) {
            return null;
        }

        // Backfill storage so subsequent runs use the stored copy.
        try {
            $year = $resolution->getEntryYear() ?? (int) date('Y');
            $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
            $storagePath = sprintf('%s/%d/%s.pdf', $resolution->getSource(), $year, $safeRef);
            $this->resolutionsStorage->write($storagePath, $content);
            $resolution->setPdfStoragePath($storagePath);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not persist fetched PDF for resolution', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
        }

        return $content;
    }
}
