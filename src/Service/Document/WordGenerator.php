<?php

namespace App\Service\Document;

use App\DTO\ComplaintDraft;
use App\Entity\AccessRequest;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Style\Font;

final class WordGenerator
{
    public function __construct()
    {
        Settings::setOutputEscapingEnabled(true);
    }

    public function generateComplaintWord(AccessRequest $accessRequest, ComplaintDraft $draft): string
    {
        $phpWord = new PhpWord();

        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(12);

        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 14], ['spaceAfter' => 240]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 12], ['spaceAfter' => 120]);

        $section = $phpWord->addSection([
            'marginTop' => 1440,
            'marginBottom' => 1440,
            'marginLeft' => 1440,
            'marginRight' => 1440,
        ]);

        $section->addTitle('RECLAMACIÓN ANTE EL ' . strtoupper($draft->transparencyCouncil), 1);

        $section->addTextBreak();

        $this->addFormattedContent($section, $draft->content);

        if ($draft->hasCitedCriteria()) {
            $section->addTextBreak();
            $section->addTitle('CRITERIOS INTERPRETATIVOS CITADOS', 2);
            foreach ($draft->citedCriteria as $criterion) {
                $section->addListItem($criterion, 0);
            }
        }

        if ($draft->hasCitedResolutions()) {
            $section->addTextBreak();
            $section->addTitle('RESOLUCIONES CITADAS', 2);
            foreach ($draft->citedResolutions as $resolution) {
                $text = $resolution->reference;
                if ($resolution->date) {
                    $text .= ' (' . $resolution->date . ')';
                }
                $section->addListItem($text, 0);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'complaint_') . '.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempFile);

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
    }

    private function addFormattedContent($section, string $content): void
    {
        $lines = explode("\n", $content);
        $currentParagraph = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($currentParagraph !== '') {
                    $section->addText($currentParagraph, ['size' => 12], ['alignment' => 'both']);
                    $currentParagraph = '';
                }
                $section->addTextBreak();
                continue;
            }

            if (preg_match('/^#{1,2}\s+(.+)$/', $line, $matches)) {
                if ($currentParagraph !== '') {
                    $section->addText($currentParagraph, ['size' => 12], ['alignment' => 'both']);
                    $currentParagraph = '';
                }
                $section->addText(
                    strtoupper($matches[1]),
                    ['bold' => true, 'size' => 12],
                    ['spaceAfter' => 120]
                );
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
                if ($currentParagraph !== '') {
                    $section->addText($currentParagraph, ['size' => 12], ['alignment' => 'both']);
                    $currentParagraph = '';
                }
                $section->addListItem($matches[1], 0, null, null, ['alignment' => 'both']);
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches)) {
                if ($currentParagraph !== '') {
                    $section->addText($currentParagraph, ['size' => 12], ['alignment' => 'both']);
                    $currentParagraph = '';
                }
                $section->addListItem($matches[1], 0, null, ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER], ['alignment' => 'both']);
                continue;
            }

            if ($currentParagraph !== '') {
                $currentParagraph .= ' ' . $line;
            } else {
                $currentParagraph = $line;
            }
        }

        if ($currentParagraph !== '') {
            $section->addText($currentParagraph, ['size' => 12], ['alignment' => 'both']);
        }
    }
}
