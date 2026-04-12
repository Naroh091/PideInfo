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
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', [$this->projectDir]);
        $options->set('defaultFont', 'DM Sans');
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultPaperOrientation', 'portrait');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
