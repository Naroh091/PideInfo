<?php

namespace App\Service\Document;

use Psr\Log\LoggerInterface;

/**
 * Rasterizes PDF pages to PNG bytes via the `pdftoppm` system binary
 * (poppler-utils). Used when sending PDFs to chat models that only accept
 * images (OpenAI-compat path) — Gemini handles PDFs natively and doesn't
 * need this.
 */
class PdfRasterizer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<int, string> Binary PNG content, one entry per rendered page (in order).
     */
    public function rasterizeFromContent(string $pdfBytes, int $maxPages = 30, int $dpi = 150): array
    {
        $tmpDir = sys_get_temp_dir() . '/pdfraster_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Failed to create tmp dir for rasterization: ' . $tmpDir);
        }

        $pdfPath = $tmpDir . '/in.pdf';
        $prefix = $tmpDir . '/page';

        try {
            file_put_contents($pdfPath, $pdfBytes);

            $cmd = sprintf(
                'pdftoppm -png -r %d -l %d %s %s 2>&1',
                $dpi,
                $maxPages,
                escapeshellarg($pdfPath),
                escapeshellarg($prefix),
            );

            exec($cmd, $output, $code);
            if ($code !== 0) {
                $this->logger->error('pdftoppm failed', ['code' => $code, 'output' => $output]);
                throw new \RuntimeException(sprintf('pdftoppm exited with code %d: %s', $code, implode("\n", $output)));
            }

            $files = glob($tmpDir . '/page-*.png') ?: [];
            sort($files, SORT_NATURAL);

            $pngs = [];
            foreach ($files as $f) {
                $bytes = @file_get_contents($f);
                if ($bytes !== false) {
                    $pngs[] = $bytes;
                }
            }

            return $pngs;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }

    /**
     * Render a single page (1-indexed) to PNG bytes via `pdftoppm -f N -l N`.
     * Used by the vision-OCR fallback to rasterize only the specific pages of a
     * PDF that lack a text layer, at a higher default DPI than the bulk path for
     * better transcription quality.
     *
     * @return string|null Binary PNG content, or null if the page could not be rendered.
     */
    public function rasterizePageFromContent(string $pdfBytes, int $page, int $dpi = 200): ?string
    {
        if ($page < 1) {
            return null;
        }

        $tmpDir = sys_get_temp_dir() . '/pdfraster_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Failed to create tmp dir for rasterization: ' . $tmpDir);
        }

        $pdfPath = $tmpDir . '/in.pdf';
        $prefix = $tmpDir . '/page';

        try {
            file_put_contents($pdfPath, $pdfBytes);

            $cmd = sprintf(
                'pdftoppm -png -r %d -f %d -l %d %s %s 2>&1',
                $dpi,
                $page,
                $page,
                escapeshellarg($pdfPath),
                escapeshellarg($prefix),
            );

            exec($cmd, $output, $code);
            if ($code !== 0) {
                $this->logger->error('pdftoppm (single page) failed', ['page' => $page, 'code' => $code, 'output' => $output]);
                return null;
            }

            $files = glob($tmpDir . '/page-*.png') ?: [];
            if ($files === []) {
                return null;
            }
            sort($files, SORT_NATURAL);

            $bytes = @file_get_contents($files[0]);

            return $bytes !== false ? $bytes : null;
        } finally {
            foreach (glob($tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tmpDir);
        }
    }
}
