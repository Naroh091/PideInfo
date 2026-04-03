<?php

namespace App\MessageHandler;

use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\ResolutionAnalyzer;
use App\Service\Resolution\ResolutionDateExtractor;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
final class ProcessResolutionHandler
{
    private const MAX_CHUNK_CHARS = 4000;

    public function __construct(
        private readonly ResolutionRepository $resolutionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'resolutions.storage')]
        private readonly FilesystemOperator $resolutionsStorage,
        private readonly ResolutionAnalyzer $analyzer,
        private readonly ResolutionDateExtractor $dateExtractor,
        private readonly EmbeddingGenerator $embeddingGenerator,
        #[Autowire(service: 'ai.store.postgres.ctbg_resolutions')]
        private readonly StoreInterface $vectorStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessResolutionMessage $message): void
    {
        $resolution = $this->resolutionRepository->find($message->resolutionId);
        if (!$resolution) {
            $this->logger->warning('Resolution not found for processing', ['id' => $message->resolutionId]);
            return;
        }

        $ref = $resolution->getReferenceNumber();
        $this->logger->info("Processing resolution $ref");

        // Step 1: Download PDF if needed
        if (!$message->skipPdf && $resolution->getSourceUrl() && empty(trim($resolution->getFullText()))) {
            $this->downloadAndProcessPdf($resolution);
        }

        // Step 2: AI Analysis if needed (use keypoints as indicator — summary may be pre-filled from API)
        if (!$message->skipAnalysis && !empty(trim($resolution->getFullText())) && empty($resolution->getKeypoints())) {
            $this->analyzeResolution($resolution);
        }

        // Step 3: Vectorize if needed
        if (!$message->skipVectors && !empty(trim($resolution->getFullText()))) {
            $this->vectorizeResolution($resolution);
        }

        $this->entityManager->flush();
        $this->logger->info("Finished processing resolution $ref");
    }

    private function downloadAndProcessPdf(Resolution $resolution): void
    {
        $documentUrl = $resolution->getSourceUrl();

        try {
            $extension = strtolower(pathinfo(parse_url($documentUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

            $content = $this->fetchDocumentContent($documentUrl);

            if ($content === null || strlen($content) < 100) {
                return;
            }

            // Store in Flysystem
            $year = $resolution->getEntryYear() ?? date('Y');
            $safeRef = str_replace(['/', ' '], ['_', '_'], $resolution->getReferenceNumber());
            $storagePath = sprintf('%s/%d/%s.%s', $resolution->getSource(), $year, $safeRef, $extension ?: 'pdf');

            $this->resolutionsStorage->write($storagePath, $content);
            $resolution->setPdfStoragePath($storagePath);

            // Extract text
            $tmpFile = tempnam(sys_get_temp_dir(), 'res_doc_');
            file_put_contents($tmpFile, $content);

            $text = match ($extension) {
                'docx' => $this->extractTextFromDocx($tmpFile),
                'doc' => $this->extractTextFromDoc($tmpFile),
                default => $this->extractText($tmpFile),
            };
            @unlink($tmpFile);

            if (strlen(trim($text)) < 100) {
                return;
            }

            $text = $this->cleanRawText($text);
            $text = $this->cleanTextForSource($text, $resolution->getSource());
            $resolution->setFullText($this->sanitizeUtf8($text));

            // Try regex-based date extraction from raw text (only if no date source already set)
            $existingDateSource = ($resolution->getSourceMetadata() ?? [])['FECHA_RESOLUCION'] ?? null;
            $dateResult = $this->dateExtractor->extractFromText($text);
            if ($dateResult['date'] !== null && $existingDateSource === null) {
                $resolution->setResolutionDate($dateResult['date']);
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['FECHA_RESOLUCION'] = 'regex';
                $resolution->setSourceMetadata($meta);
            }

            $this->logger->info('Document processed', [
                'reference' => $resolution->getReferenceNumber(),
                'type' => $extension,
                'chars' => mb_strlen($text),
                'storage' => $storagePath,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Document download/processing failed', [
                'reference' => $resolution->getReferenceNumber(),
                'url' => $documentUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function analyzeResolution(Resolution $resolution): void
    {
        $cleanedText = $this->analyzer->cleanText($resolution->getFullText());

        try {
            $result = $this->analyzer->analyze($cleanedText);

            $resolution->setFullText($result['formatted_text']);
            $resolution->setSummary($result['summary']);
            $resolution->setKeypoints($result['keypoints']);

            if (!empty($result['subject'])) {
                $resolution->setSubject(mb_substr($result['subject'], 0, 500));
            }

            $existingDateSource = ($resolution->getSourceMetadata() ?? [])['FECHA_RESOLUCION'] ?? null;
            if ($result['resolution_date'] && $existingDateSource === null) {
                try {
                    $resolution->setResolutionDate(new \DateTimeImmutable($result['resolution_date']));
                    $meta = $resolution->getSourceMetadata() ?? [];
                    $meta['FECHA_RESOLUCION'] = 'LLM';
                    $resolution->setSourceMetadata($meta);
                } catch (\Exception) {
                }
            }

            if ($result['claim_date']) {
                try {
                    $resolution->setClaimDate(new \DateTimeImmutable($result['claim_date']));
                } catch (\Exception) {
                }
            }

            if ($resolution->getClaimDate() && $resolution->getResolutionDate()) {
                $days = $resolution->getClaimDate()->diff($resolution->getResolutionDate())->days;
                $resolution->setDaysToResolve($days);
            }

            $this->logger->info('AI analysis complete', [
                'reference' => $resolution->getReferenceNumber(),
                'keypoints' => count($result['keypoints']),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('AI analysis failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
            throw $e; // Let Messenger retry
        }
    }

    private function vectorizeResolution(Resolution $resolution): void
    {
        $fullText = $resolution->getFullText();
        if (empty(trim($fullText))) {
            return;
        }

        $baseMeta = array_filter([
            Metadata::KEY_SOURCE => $resolution->getReferenceNumber(),
            'reference' => $resolution->getReferenceNumber(),
            'outcome' => $resolution->getOutcome(),
            'source' => $resolution->getSource(),
            'scope' => $resolution->getScope(),
            'subject' => $this->sanitizeUtf8($resolution->getSubject()),
            'publicBody' => $this->sanitizeUtf8($resolution->getPublicBodyName()),
            'entityType' => $resolution->getEntityType(),
        ], fn ($v) => $v !== null);

        if ($resolution->getAutonomousCommunity()) {
            $baseMeta['autonomousCommunity'] = $resolution->getAutonomousCommunity()->getName();
        }

        $documents = [];

        // Full text chunks
        $chunks = $this->chunkText($fullText);
        foreach ($chunks as $index => $chunkText) {
            try {
                $embedding = $this->embeddingGenerator->generate($chunkText);
                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata(array_merge($baseMeta, [
                        Metadata::KEY_TEXT => $chunkText,
                        'chunkIndex' => $index,
                        'type' => 'fulltext',
                    ])),
                );
                usleep(100_000);
            } catch (\Exception $e) {
                $this->logger->error('Embedding error', [
                    'reference' => $resolution->getReferenceNumber(),
                    'chunk' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Keypoints as single document
        $keypoints = $resolution->getKeypoints();
        if (!empty($keypoints)) {
            $keypointsText = implode("\n\n", $keypoints);
            try {
                $embedding = $this->embeddingGenerator->generate($keypointsText);
                $documents[] = new VectorDocument(
                    id: Uuid::v7(),
                    vector: new Vector($embedding),
                    metadata: new Metadata(array_merge($baseMeta, [
                        Metadata::KEY_TEXT => $keypointsText,
                        'chunkIndex' => -1,
                        'type' => 'keypoints',
                    ])),
                );
            } catch (\Exception $e) {
                $this->logger->warning('Keypoints embedding error', [
                    'reference' => $resolution->getReferenceNumber(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($documents)) {
            $this->vectorStore->add($documents);
        }
    }

    // --- Utilities ---

    private function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['path'])) {
            return $url;
        }

        $segments = explode('/', $parts['path']);
        $encoded = array_map(fn (string $s) => rawurlencode(rawurldecode($s)), $segments);
        $parts['path'] = implode('/', $encoded);

        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $result .= $parts['host'];
        }
        $result .= $parts['path'];
        if (isset($parts['query'])) {
            $result .= '?' . $parts['query'];
        }

        return $result;
    }

    private function fetchDocumentContent(string $url): ?string
    {
        $url = $this->encodeUrlPath($url);
        $timeout = str_contains($url, 'gobiernoabierto.navarra.es') ? 2 : 60;
        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => $timeout]);
            return $response->getContent();
        } catch (\Exception $e) {
            $this->logger->info('Direct download failed, trying Wayback Machine', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        $waybackUrl = 'https://web.archive.org/web/' . $url;
        try {
            $response = $this->httpClient->request('GET', $waybackUrl, ['timeout' => 60]);
            $content = $response->getContent();
            $this->logger->info('Fetched from Wayback Machine', ['url' => $url]);
            return $content;
        } catch (\Exception $e) {
            $this->logger->warning('Wayback Machine fallback also failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function extractTextFromDoc(string $filePath): string
    {
        $process = new Process(['antiword', $filePath]);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful()) {
            return $process->getOutput();
        }

        return '';
    }

    private function extractTextFromDocx(string $filePath): string
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $t = $element->getText();
                    if (is_string($t)) {
                        $text .= $t . "\n";
                    } elseif (is_object($t) && method_exists($t, 'getText')) {
                        $text .= $t->getText() . "\n";
                    }
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $child) {
                        if (method_exists($child, 'getText')) {
                            $text .= $child->getText();
                        }
                    }
                    $text .= "\n";
                }
            }
        }

        return $text;
    }

    private function extractText(string $filePath): string
    {
        $process = new Process(['pdftotext', '-layout', $filePath, '-']);
        $process->setTimeout(30);
        $process->run();

        if ($process->isSuccessful() && strlen(trim($process->getOutput())) > 100) {
            return $process->getOutput();
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($filePath);
            return $pdf->getText();
        } catch (\Exception) {
            return '';
        }
    }

    private function cleanRawText(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x{FFFD}]/u', '', $text);
        $text = str_replace("\x00", '', $text);
        $text = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
        $text = preg_replace('/\r\n/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/^FIRMANTE\(.*$/m', '', $text);
        $text = preg_replace('/^\s*\d{1,3}\s*$/m', '', $text);

        return trim($text);
    }

    private function cleanTextForSource(string $text, string $source): string
    {
        if ($source === Resolution::SOURCE_CTG) {
            $pos = mb_stripos($text, 'ASUNTO:');
            if ($pos !== false) {
                $text = mb_substr($text, $pos);
            }
        }

        if ($source === Resolution::SOURCE_CVAIP) {
            $text = self::cleanCvaipText($text);
        }

        if ($source === Resolution::SOURCE_CTAR) {
            $text = self::cleanCtarText($text);
        }

        if ($source === Resolution::SOURCE_CTCYL) {
            $text = self::cleanCtcylText($text);
        }

        return trim($text);
    }

    public static function cleanCvaipText(string $text): string
    {
        // Remove page number footers: "Resolución XX/YYYY    N/M"
        $text = preg_replace('/^Resolución\s+\d+\/\d+\s+\d+\/\d+\s*$/mu', '', $text);

        // Remove LOKALIZATZAILEA/LOCALIZADOR blocks (electronic signature footers)
        $text = preg_replace('/^LOKALIZATZAILEA\s*\/\s*LOCALIZADOR\s*:.*$/mu', '', $text);
        $text = preg_replace('/^EGOITZA ELEKTRONIKOA\s*\/\s*SEDE ELECTR[OÓ]NICA\s*:.*$/mu', '', $text);
        $text = preg_replace('/^SINATZAILEA\s*\/\s*FIRMANTE\s*:.*$/mu', '', $text);

        // Remove electronic document notice
        $text = preg_replace('/Este documento es una representación del documento original electrónico[^.]*\./u', '', $text);

        // Strip everything from closing boilerplate onwards (signatures, appeals info)
        $pos = mb_stripos($text, 'Esta Resolución pone fin a la vía administrativa');
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Ensure double newlines before section headings and numbered items.
        // PhpWord .docx extraction joins paragraphs with single newlines,
        // but downstream processing (applyOperations) needs double newlines to split paragraphs.
        $sectionPatterns = [
            'ANTECEDENTES(?:\s+DE\s+HECHO)?',
            'FUNDAMENTOS(?:\s+(?:JURÍDICOS|DE\s+DERECHO))?',
            'RESOLUCI[ÓO]N|RESUELVE',
            'VISTOS',
            '\d+\.\-\s',
            '(?:Primero|Segundo|Tercero|Cuarto|Quinto|Sexto|Séptimo|Octavo|Noveno|Décimo|Único)\.\-',
        ];
        $text = preg_replace(
            '/\n(?=' . implode('|', $sectionPatterns) . ')/u',
            "\n\n",
            $text
        );

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function cleanCtarText(string $text): string
    {
        // Remove "Reclamación XX/YYYY" header line at the top
        $text = preg_replace('/^Reclamación\s+\d+\/\d{4}\s*$/mu', '', $text);

        // Remove page footers: "Página X de Y"
        $text = preg_replace('/^\s*Página\s+\d+\s+de\s+\d+\s*$/mu', '', $text);

        // Strip everything from closing boilerplate onwards (appeal info + signatures)
        $pos = mb_stripos($text, 'Esta Resolución es definitiva en la vía administrativa');
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function cleanCtcylText(string $text): string
    {
        // Remove form feed characters (page boundaries)
        $text = str_replace("\f", '', $text);

        // Remove 3-line page footer: "Comisionado de Transparencia de Castilla y León" + address + url
        $text = preg_replace('/^\s*Comisionado de Transparencia de Castilla y Le[oó]n\s*$/mu', '', $text);
        $text = preg_replace('/^\s*C\/\s*Sierra Pambley.*$/mu', '', $text);
        $text = preg_replace('/^\s*www\.ctcyl\.es.*$/mu', '', $text);

        // Strip closing boilerplate (appeal info + signatures)
        $pos = mb_stripos($text, 'Esta Resolución es ejecutiva');
        if ($pos === false) {
            $pos = mb_stripos($text, 'Contra esta resolución, que pone fin a la vía administrativa');
        }
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return preg_replace('/[\x{FFFD}]/u', '', $value);
    }

    /**
     * @return array<int, string>
     */
    private function chunkText(string $text): array
    {
        if (strlen($text) <= self::MAX_CHUNK_CHARS) {
            return [$text];
        }

        $paragraphs = preg_split('/\n{2,}/', $text);
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && strlen($current) + strlen($paragraph) + 2 > self::MAX_CHUNK_CHARS) {
                $chunks[] = trim($current);
                $current = $paragraph;
            } else {
                $current .= ($current !== '' ? "\n\n" : '') . $paragraph;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
