<?php

namespace App\Service\Document;

use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

final class PdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function generateComplaintPdf(AccessRequest $accessRequest, ComplaintDraft $draft): string
    {
        $html = $this->twig->render('complaint/_pdf.html.twig', [
            'accessRequest' => $accessRequest,
            'draft' => $draft,
        ]);

        return $this->renderPdf($html);
    }

    public function generateFromHtml(string $html): string
    {
        return $this->renderPdf($html);
    }

    private function renderPdf(string $html): string
    {
        $fontDir = $this->ensureWritableFontDir();
        $tempDir = $this->ensureWritableTempDir();

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', [$this->projectDir]);
        // dompdf creates per-font `.ufm` metric caches the first time it sees
        // a family. By default it writes them into `vendor/dompdf/dompdf/lib/
        // fonts/` which is root-owned in the deployed container; pointing
        // both `fontDir` and `fontCache` to a writable cache directory under
        // `var/cache/` is the supported workaround.
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);
        $options->set('tempDir', $tempDir);
        $options->set('defaultFont', 'DM Sans');
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultPaperOrientation', 'portrait');

        $dompdf = new Dompdf($options);
        $this->ensureBrandFontsRegistered($dompdf);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function ensureWritableFontDir(): string
    {
        return $this->ensureWritableDir($this->projectDir . '/var/cache/dompdf/fonts');
    }

    private function ensureWritableTempDir(): string
    {
        return $this->ensureWritableDir($this->projectDir . '/var/cache/dompdf/tmp');
    }

    /**
     * Create `$path` (recursively) with permissions that allow whichever
     * Symfony process runs first — CLI as one user, then web server as
     * another — to share the same cache. Falls back to the system temp dir
     * when even creation fails. The fallback path means PDFs still render,
     * just without a persistent font-metric cache.
     */
    private function ensureWritableDir(string $path): string
    {
        if (!is_dir($path)) {
            @mkdir($path, 0o777, true);
        }
        if (is_dir($path) && !is_writable($path)) {
            // Existing dir owned by another user; widen perms so the rest of
            // the stack can write. `@chmod` returns false silently if we
            // don't own it — the is_writable check below catches that.
            @chmod($path, 0o777);
        }
        if (is_dir($path) && is_writable($path)) {
            return $path;
        }
        return sys_get_temp_dir();
    }

    /**
     * Register DM Sans and DM Serif Display from `resources/fonts/` so dompdf
     * doesn't try to discover them from a read-only vendor directory. Idempotent
     * — `registerFont()` re-writes the `.ufm` metric cache each call, but it
     * targets the writable `fontDir` we configured above so that's harmless.
     */
    private function ensureBrandFontsRegistered(Dompdf $dompdf): void
    {
        $metrics = $dompdf->getFontMetrics();
        $resources = $this->projectDir . '/resources/fonts';

        $variable = $resources . '/DMSans-Variable.ttf';
        if (is_file($variable) && empty($metrics->getFamily('DM Sans'))) {
            // dompdf can't instantiate variable-font axes: whatever file backs
            // the "bold" face renders at that file's default instance. The
            // static `DMSans-Bold.ttf` (wght=700, generated with fonttools
            // varLib.instancer) is what makes <strong> and font-weight:700
            // actually render bold.
            $bold = $resources . '/DMSans-Bold.ttf';
            $boldFile = is_file($bold) ? $bold : $variable;
            $metrics->registerFont(['family' => 'DM Sans', 'style' => 'normal', 'weight' => 'normal'], $variable);
            $metrics->registerFont(['family' => 'DM Sans', 'style' => 'normal', 'weight' => 'bold'], $boldFile);
            $metrics->registerFont(['family' => 'DM Sans', 'style' => 'italic', 'weight' => 'normal'], $variable);
            $metrics->registerFont(['family' => 'DM Sans', 'style' => 'italic', 'weight' => 'bold'], $boldFile);
        }

        $serif = $resources . '/DMSerifDisplay-Regular.ttf';
        if (is_file($serif) && empty($metrics->getFamily('DM Serif Display'))) {
            $metrics->registerFont(['family' => 'DM Serif Display', 'style' => 'normal', 'weight' => 'normal'], $serif);
        }

        // Tipografía de los documentos generados: Playfair Display para los
        // títulos y Gelasio (la métrica-compatible libre de Georgia) para el
        // cuerpo. Instancias estáticas por face — ver el gotcha de fuentes
        // variables arriba.
        $this->registerStaticFamily($metrics, 'Playfair Display', $resources . '/PlayfairDisplay');
        $this->registerStaticFamily($metrics, 'Gelasio', $resources . '/Gelasio');
    }

    /**
     * Register the four faces of a family from `{prefix}-Regular.ttf`,
     * `-Bold.ttf`, `-Italic.ttf` and `-BoldItalic.ttf`, falling back to the
     * regular face for any missing file.
     */
    private function registerStaticFamily(\Dompdf\FontMetrics $metrics, string $family, string $prefix): void
    {
        $regular = $prefix . '-Regular.ttf';
        if (!is_file($regular) || !empty($metrics->getFamily($family))) {
            return;
        }

        $faces = [
            ['normal', 'normal', '-Regular.ttf'],
            ['normal', 'bold', '-Bold.ttf'],
            ['italic', 'normal', '-Italic.ttf'],
            ['italic', 'bold', '-BoldItalic.ttf'],
        ];
        foreach ($faces as [$style, $weight, $suffix]) {
            $file = $prefix . $suffix;
            $metrics->registerFont(
                ['family' => $family, 'style' => $style, 'weight' => $weight],
                is_file($file) ? $file : $regular,
            );
        }
    }
}
