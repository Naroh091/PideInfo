<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * The CTBG sede stamps a fixed boilerplate on every "Acuse de recibo" it
 * emits, both for the request itself and for complaints. The two phrases
 * checked here only co-occur in CTBG complaint receipts, so when both
 * appear we can deterministically classify the document as such — useful
 * because the AI sometimes lands on the generic "acuse_recibo".
 */
final class ComplaintReceiptSniffer
{
    public function __construct(
        private readonly PdfTextExtractor $pdfTextExtractor,
    ) {
    }

    public function looksLikeComplaintReceipt(string $pdfBytes): bool
    {
        try {
            $text = $this->pdfTextExtractor->extractFullTextFromContent($pdfBytes);
        } catch (\Throwable) {
            return false;
        }
        $hasIssuer = (bool) preg_match(
            '/Consejo\s+de\s+Transparencia\s+y\s+Buen\s+Gobierno/iu',
            $text,
        );
        $hasDisclaimer = (bool) preg_match(
            '/Este\s+acuse\s+de\s+recibo\s+no\s+prejuzga\s+la\s+admisi[oó]n\s+definitiva\s+del\s+escrito/iu',
            $text,
        );
        return $hasIssuer && $hasDisclaimer;
    }
}
