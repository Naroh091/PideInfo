<?php

namespace App\MessageHandler;

use App\Entity\Resolution;
use App\Message\ProcessResolutionMessage;
use App\Repository\ResolutionRepository;
use App\Service\AI\EmbeddingGenerator;
use App\Service\Resolution\CrtWebReader;
use App\Service\Resolution\CtcanWebReader;
use App\Service\Resolution\CtpdaCsvReader;
use App\Service\Resolution\CvtWebReader;
use App\Service\Resolution\ResolutionAnalyzer;
use App\Service\Resolution\CtpdWebReader;
use App\Service\Resolution\PublicBodyResolver;
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
        private readonly CtcanWebReader $ctcanWebReader,
        private readonly PublicBodyResolver $publicBodyResolver,
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

        // Block A: Step 0.5 + 1 — CTCAN URL resolution + PDF download/re-extraction
        try {
            // Step 0.5: CTCAN — resolve PDF URL from detail page if not yet known
            if (!$message->skipPdf && $resolution->getSource() === Resolution::SOURCE_CTCAN
                && !$resolution->getSourceUrl() && empty(trim($resolution->getFullText()))) {
                $detailUrl = ($resolution->getSourceMetadata() ?? [])['detailUrl'] ?? null;
                if ($detailUrl) {
                    $pdfUrl = $this->ctcanWebReader->fetchDetailPage($detailUrl);
                    if ($pdfUrl) {
                        $resolution->setSourceUrl($pdfUrl);
                        $this->logger->info('Resolved CTCAN PDF URL', ['reference' => $ref, 'pdfUrl' => $pdfUrl]);
                    }
                }
            }

            // Step 1: Download/extract PDF text
            if ($message->forceReExtractText) {
                $this->reExtractTextFromStorage($resolution);
            } elseif (!$message->skipPdf && $resolution->getSourceUrl() && empty(trim($resolution->getFullText()))) {
                $this->downloadAndProcessPdf($resolution);
            }
        } catch (\Exception $e) {
            $this->logger->error('PDF step failed', ['reference' => $ref, 'error' => $e->getMessage()]);
        }

        // Block B: Step 1.5 — source-specific metadata extraction from PDF text
        try {
            if ($resolution->getSource() === Resolution::SOURCE_CRT) {
                $this->extractCrtMetadata($resolution);
            }

            if ($resolution->getSource() === Resolution::SOURCE_CVT) {
                $this->extractCvtMetadata($resolution);
            }

            if ($resolution->getSource() === Resolution::SOURCE_CTPDA) {
                $this->extractCtpdaMetadata($resolution);
            }

            if ($resolution->getSource() === Resolution::SOURCE_CTCAN) {
                $this->extractCtcanMetadata($resolution);
            }
        } catch (\Exception $e) {
            $this->logger->error('Metadata extraction step failed', ['reference' => $ref, 'error' => $e->getMessage()]);
        }

        // Block C: Step 2 — AI Analysis if needed (use keypoints as indicator — summary may be pre-filled from API)
        try {
            $needsAnalysis = $message->forceAnalysis || match ($message->analysisMode) {
                'non-complete' => $resolution->getInadmissionCauses() === null,
                default => empty($resolution->getKeypoints()),
            };
            if (!$message->skipAnalysis && !empty(trim($resolution->getFullText())) && $needsAnalysis) {
                $this->analyzeResolution($resolution, $message->flex, $message->analysisMode);
            }
        } catch (\Exception $e) {
            $this->logger->error('Analysis step failed', ['reference' => $ref, 'error' => $e->getMessage()]);
        }

        // Block D: Step 3 — Vectorize if needed
        try {
            if (!$message->skipVectors && !empty(trim($resolution->getFullText()))) {
                $this->vectorizeResolution($resolution);
            }
        } catch (\Exception $e) {
            $this->logger->error('Vectorization step failed', ['reference' => $ref, 'error' => $e->getMessage()]);
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

            // Try regex-based date extraction before source-specific cleaning (which may strip signatures)
            $existingDateSource = ($resolution->getSourceMetadata() ?? [])['FECHA_RESOLUCION'] ?? null;
            $dateResult = $this->dateExtractor->extractFromText($text);
            if ($dateResult['date'] !== null && $existingDateSource === null) {
                $resolution->setResolutionDate($dateResult['date']);
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['FECHA_RESOLUCION'] = 'regex';
                $resolution->setSourceMetadata($meta);
            }

            $text = $this->cleanTextForSource($text, $resolution->getSource());
            $resolution->setFullText($this->sanitizeUtf8($text));

            // CTPD: extract metadata (outcome, publicBody, subject, claimDate) from PDF text
            if ($resolution->getSource() === Resolution::SOURCE_CTPD) {
                $this->extractCtpdMetadata($resolution, $text);
            }

            // CVT: extract claim date from PDF text
            if ($resolution->getSource() === Resolution::SOURCE_CVT) {
                $this->extractCvtMetadata($resolution);
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

    private function reExtractTextFromStorage(Resolution $resolution): void
    {
        $storagePath = $resolution->getPdfStoragePath();
        $ref = $resolution->getReferenceNumber();

        try {
            $content = null;

            if ($storagePath) {
                try {
                    $content = $this->resolutionsStorage->read($storagePath);
                } catch (\Exception $e) {
                    $this->logger->warning('Could not read stored file, will try sourceUrl', [
                        'reference' => $ref,
                        'storagePath' => $storagePath,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($content === null || strlen($content) < 100) {
                $sourceUrl = $resolution->getSourceUrl();
                if (!$sourceUrl) {
                    $this->logger->warning('No stored file and no sourceUrl for re-extraction', ['reference' => $ref]);
                    return;
                }
                $content = $this->fetchDocumentContent($sourceUrl);
                if ($content === null || strlen($content) < 100) {
                    return;
                }
            }

            $extension = 'pdf';
            if ($storagePath) {
                $extension = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
            } elseif ($resolution->getSourceUrl()) {
                $extension = strtolower(pathinfo(parse_url($resolution->getSourceUrl(), PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            }

            $tmpFile = tempnam(sys_get_temp_dir(), 'res_doc_');
            file_put_contents($tmpFile, $content);

            $text = match ($extension) {
                'docx' => $this->extractTextFromDocx($tmpFile),
                'doc' => $this->extractTextFromDoc($tmpFile),
                default => $this->extractText($tmpFile),
            };
            @unlink($tmpFile);

            if (strlen(trim($text)) < 100) {
                $this->logger->warning('Re-extracted text too short', ['reference' => $ref, 'chars' => strlen(trim($text))]);
                return;
            }

            $text = $this->cleanRawText($text);

            // Try regex-based date extraction before source-specific cleaning (which may strip signatures)
            $dateResult = $this->dateExtractor->extractFromText($text);
            if ($dateResult['date'] !== null) {
                $resolution->setResolutionDate($dateResult['date']);
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['FECHA_RESOLUCION'] = 'regex';
                $resolution->setSourceMetadata($meta);
            }

            $text = $this->cleanTextForSource($text, $resolution->getSource());
            $resolution->setFullText($this->sanitizeUtf8($text));

            // Clear stale analysis data so downstream steps re-analyze
            $resolution->setSummary('');
            $resolution->setKeypoints(null);
            $resolution->setSubject(null);

            // CTPD: extract metadata (publicBody, claimDate) from PDF text
            if ($resolution->getSource() === Resolution::SOURCE_CTPD) {
                $this->extractCtpdMetadata($resolution, $text);
            }

            // CVT: extract claim date from PDF text
            if ($resolution->getSource() === Resolution::SOURCE_CVT) {
                $this->extractCvtMetadata($resolution);
            }

            $this->logger->info('Text re-extracted from stored file', [
                'reference' => $ref,
                'type' => $extension,
                'chars' => mb_strlen($text),
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Re-extraction failed', [
                'reference' => $ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function analyzeResolution(Resolution $resolution, bool $flex = false, string $mode = 'all'): void
    {
        $cleanedText = $this->analyzer->cleanText($resolution->getFullText());

        try {
            $result = match ($mode) {
                'format' => $this->analyzer->formatText($cleanedText, flex: $flex),
                'analyze' => $this->analyzer->extractAnalysis($cleanedText, flex: $flex),
                'non-complete' => $this->analyzer->extractNonCompleteAnalysis($cleanedText, flex: $flex),
                default => $this->analyzer->analyze($cleanedText, flex: $flex),
            };

            if (isset($result['formatted_text'])) {
                $resolution->setFullText($result['formatted_text']);
            }

            $this->analyzer->applyAnalysisResult($resolution, $result);

            $this->logger->info('AI analysis complete', [
                'reference' => $resolution->getReferenceNumber(),
                'mode' => $mode,
                'outcome' => $result['outcome'] ?? null,
                'limits' => $result['limits'] ?? [],
                'inadmission_causes' => $result['inadmission_causes'] ?? [],
                'keypoints_count' => isset($result['keypoints']) ? count((array) $result['keypoints']) : null,
                'topics' => $result['topics'] ?? [],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('AI analysis failed', [
                'reference' => $resolution->getReferenceNumber(),
                'error' => $e->getMessage(),
            ]);
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

    private function extractCrtMetadata(Resolution $resolution): void
    {
        $text = $resolution->getFullText();

        if (empty(trim($text))) {
            if ($resolution->getSourceUrl()) {
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['pdf_error'] = 'password_protected_or_unreadable';
                $resolution->setSourceMetadata($meta);
            }

            return;
        }

        $metadata = CrtWebReader::parseMetadataFromText($text, $resolution->getEntryYear());

        if ($metadata['subject']) {
            $resolution->setSubject(mb_substr($metadata['subject'], 0, 500));
        } elseif (empty($resolution->getSubject())) {
            $resolution->setSubject('Solicitud de acceso a información pública');
        }

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
        }

    }

    private function extractCvtMetadata(Resolution $resolution): void
    {
        $text = $resolution->getFullText();

        if (empty(trim($text))) {
            if ($resolution->getSourceUrl()) {
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['pdf_error'] = 'password_protected_or_unreadable';
                $resolution->setSourceMetadata($meta);
            }

            return;
        }

        $metadata = CvtWebReader::parseMetadataFromText($text);

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
        }

    }

    private function extractCtpdaMetadata(Resolution $resolution): void
    {
        $text = $resolution->getFullText();

        if (empty(trim($text))) {
            if ($resolution->getSourceUrl()) {
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['pdf_error'] = 'password_protected_or_unreadable';
                $resolution->setSourceMetadata($meta);
            }

            return;
        }

        $metadata = CtpdaCsvReader::parseMetadataFromText($text);

        // Only set publicBodyName from PDF if not already set from CSV
        if ($metadata['publicBodyName'] && empty($resolution->getPublicBodyName())) {
            $resolution->setPublicBodyName($metadata['publicBodyName']);
            $resolution->setPublicBody($this->publicBodyResolver->resolve($metadata['publicBodyName']));
        }

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
        }
    }

    private function extractCtcanMetadata(Resolution $resolution): void
    {
        $text = $resolution->getFullText();

        if (empty(trim($text))) {
            if ($resolution->getSourceUrl()) {
                $meta = $resolution->getSourceMetadata() ?? [];
                $meta['pdf_error'] = 'password_protected_or_unreadable';
                $resolution->setSourceMetadata($meta);
            }

            return;
        }

        $metadata = CtcanWebReader::parseMetadataFromText($text, $resolution->getEntryYear());

        if ($metadata['subject'] && empty($resolution->getSubject())) {
            $resolution->setSubject(mb_substr($metadata['subject'], 0, 500));
        } elseif (empty($resolution->getSubject())) {
            $resolution->setSubject('Solicitud de acceso a información pública');
        }

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
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
        $options = ['timeout' => $timeout];

        // comunidad.madrid blocks requests without a browser-like User-Agent
        if (str_contains($url, 'comunidad.madrid')) {
            $options['headers'] = [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ];
        }

        // ctpdandalucia.es has an untrusted SSL certificate
        if (str_contains($url, 'ctpdandalucia.es')) {
            $options['verify_peer'] = false;
        }

        try {
            $response = $this->httpClient->request('GET', $url, $options);
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

    private function extractCtpdMetadata(Resolution $resolution, string $text): void
    {
        $metadata = CtpdWebReader::parseMetadataFromText($text);

        if ($metadata['publicBodyName']) {
            $resolution->setPublicBodyName($metadata['publicBodyName']);
            $resolution->setPublicBody($this->publicBodyResolver->resolve($metadata['publicBodyName']));
        }

        // Only override subject if not already set (new mode pre-sets it from DTO)
        if ($metadata['subject'] && empty($resolution->getSubject())) {
            $resolution->setSubject(mb_substr($metadata['subject'], 0, 500));
        }

        // Only override outcome if still 'pending' (new mode pre-sets it from accordion)
        if ($metadata['outcomeText'] && $resolution->getOutcome() === 'pending') {
            $outcome = self::mapCtpdOutcome($metadata['outcomeText']);
            $resolution->setOutcome($outcome);
        }

        if ($metadata['claimDate']) {
            $resolution->setClaimDate($metadata['claimDate']);
        }
    }

    private static function mapCtpdOutcome(string $outcomeStr): string
    {
        $normalized = mb_strtolower(trim($outcomeStr));

        $map = [
            'estimación' => Resolution::OUTCOME_FAVORABLE,
            'estimatoria' => Resolution::OUTCOME_FAVORABLE,
            'estimar' => Resolution::OUTCOME_FAVORABLE,
            'estimada' => Resolution::OUTCOME_FAVORABLE,
            'desestimación' => Resolution::OUTCOME_UNFAVORABLE,
            'desestimatoria' => Resolution::OUTCOME_UNFAVORABLE,
            'desestimada' => Resolution::OUTCOME_UNFAVORABLE,
            'desestimar' => Resolution::OUTCOME_UNFAVORABLE,
            'estimación parcial' => Resolution::OUTCOME_PARTIAL,
            'estimada parcialmente' => Resolution::OUTCOME_PARTIAL,
            'parcialmente estimada' => Resolution::OUTCOME_PARTIAL,
            'inadmisión' => Resolution::OUTCOME_INADMISSIBLE,
            'inadmisión a trámite' => Resolution::OUTCOME_INADMISSIBLE,
            'inadmitida' => Resolution::OUTCOME_INADMISSIBLE,
            'terminación del procedimiento' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
            'pérdida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
            'pérdida sobrevenida del objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
            'pérdida sobrevenida de objeto' => Resolution::OUTCOME_LOSS_OF_PURPOSE,
            'desistimiento' => Resolution::OUTCOME_WITHDRAWAL,
            'archivo' => Resolution::OUTCOME_ARCHIVED,
            'archivada' => Resolution::OUTCOME_ARCHIVED,
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        // Try longer keys first for substring matching
        $keys = array_keys($map);
        usort($keys, fn (string $a, string $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($keys as $key) {
            if (str_contains($normalized, $key)) {
                return $map[$key];
            }
        }

        return $normalized;
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
            $text = self::cleanCtgText($text);
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

        if ($source === Resolution::SOURCE_CTPD) {
            $text = self::cleanCtpdText($text);
        }

        if ($source === Resolution::SOURCE_CRT) {
            $text = self::cleanCrtText($text);
        }

        if ($source === Resolution::SOURCE_CVT) {
            $text = self::cleanCvtText($text);
        }

        if ($source === Resolution::SOURCE_CTPDA) {
            $text = self::cleanCtpdaText($text);
        }

        if ($source === Resolution::SOURCE_CTCAN) {
            $text = self::cleanCtcanText($text);
        }

        return trim($text);
    }

    public static function cleanCtgText(string $text): string
    {
        // Strip everything before "ASUNTO:"
        $pos = mb_stripos($text, 'ASUNTO:');
        if ($pos !== false) {
            $text = mb_substr($text, $pos);
        }

        // Remove repeated header/footer blocks (contact info of Comisión da Transparencia)
        $text = preg_replace('/^\s*[•.\s:,]*Comisión da\s+o?\s*$/mu', '', $text);
        $text = preg_replace('/^\s*[•.\s:,]*transparencia\s*$/mu', '', $text);
        $text = preg_replace('/^\s*[•.\s:,]*de Galicia\s*$/mu', '', $text);
        $text = preg_replace('/^\s*\(?\+34\)?\s*981\s*56\s*97\s*40.*$/mu', '', $text);
        $text = preg_replace('/^\s*Rúa do Hórreo.*$/mu', '', $text);
        $text = preg_replace('/^\s*15700.*Santiago.*Compostela.*$/mu', '', $text);
        $text = preg_replace('/^\s*A Coruña\s*$/mu', '', $text);
        $text = preg_replace('/^\s*[•.\s]*[Il]nfo@comisiondatransparencia\.ga[l1]\s*$/mu', '', $text);
        $text = preg_replace('/^\s*[•.\s]*www\.comisiondatransparencia\.ga[l1]\s*$/mu', '', $text);

        // Remove short lines that are only punctuation/symbols (logo OCR artifacts)
        $text = preg_replace('/^\s*[•.\s:,"""*_|\\\\e]{1,20}\s*$/mu', '', $text);

        // Remove inline header variant (single-line mixed contact info)
        $text = preg_replace('/^\s*Comisión da\s+e\s+\(?[•+]?34\)?981.*$/mu', '', $text);
        $text = preg_replace('/^\s*o\s+Rúa do Hórreo.*$/mu', '', $text);
        $text = preg_replace('/^\s*transparencia\s+A Coruña\s*$/mu', '', $text);
        $text = preg_replace('/^\s*de Galicia\s+e\s+[Il]nfo@comisiondatransparencia.*$/mu', '', $text);

        // Remove digital signature block
        $text = preg_replace('/Firmado digitalmente por.*?(?=\n\n|\z)/su', '', $text);

        // Strip closing boilerplate (appeal info)
        $pos = mb_stripos($text, 'Contra esta resolución que pon fin');
        if ($pos === false) {
            $pos = mb_stripos($text, 'Contra esta resolución, que pon fin');
        }
        if ($pos === false) {
            $pos = mb_stripos($text, 'Contra esta resolución que pone fin');
        }
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

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

    public static function cleanCtpdText(string $text): string
    {
        // Remove form feed characters
        $text = str_replace("\f", '', $text);

        // Remove "Nº EXPEDIENTE:" header line (repeated on every page, new source)
        $text = preg_replace('/^\s*N[ºo°]\s*EXPEDIENTE:.*$/mu', '', $text);

        // Remove repeated header: organism name variants (old + new)
        $text = preg_replace('/^\s*Consejo de Transparencia y Participaci[oó]n de la Comunidad de Madrid\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Consejo de Transparencia y Protecci[oó]n de Datos de la Comunidad de Madrid\s*$/mu', '', $text);

        // Remove address footers with optional page numbers (old: Albufera N/M, new: San Jerónimo)
        $text = preg_replace('/^\s*Avenida de la Albufera.*28031.*Madrid\s*(?:\d{1,3}\/\d{1,3})?\s*$/mu', '', $text);
        $text = preg_replace('/^\s*\|?\s*consejo\.typ@asambleamadrid\.es\s*\|?\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Carrera de San Jer[oó]nimo.*$/mu', '', $text);
        $text = preg_replace('/^\s*28014\s*Madrid\s*$/mu', '', $text);

        // Remove right-margin watermark (CSV verification block, new source)
        $text = preg_replace('/^\s*La autenticidad de este documento se puede comprobar en\s*$/mu', '', $text);
        $text = preg_replace('/^\s*mediante el siguiente c[oó]digo seguro de verificaci[oó]n:\s*$/mu', '', $text);
        $text = preg_replace('/^\s*https?:\/\/gestiona\.comunidad\.madrid\/csv\s*$/mu', '', $text);

        // Remove logo alt-text artifacts (multiline block from PDF extraction)
        $text = preg_replace('/Consejo de\s*\nTransparencia y\s*\n(?:Participaci[oó]n|Protecci[oó]n de Datos)\s*(?:de la\s*\n)?(?:Comunidad de Madrid)?/iu', '', $text);

        // Remove page numbers in "N/M" format (e.g., "1/6", "2/6")
        $text = preg_replace('/^\s*\d{1,3}\/\d{1,3}\s*$/m', '', $text);

        // Remove digital signature block (new source)
        $text = preg_replace('/^\s*Firmado digitalmente por:.*$/mu', '', $text);
        $text = preg_replace('/^\s*Fecha:\s*\d{4}\.\d{2}\.\d{2}\s+\d{2}:\d{2}\s*$/mu', '', $text);

        // Strip closing boilerplate (appeal info + signatures)
        $pos = mb_stripos($text, 'Conforme a la Ley 29/1998');
        if ($pos === false) {
            $pos = mb_stripos($text, 'Contra la presente resolución');
        }
        if ($pos === false) {
            $pos = mb_stripos($text, 'De acuerdo con el artículo 48 del Reglamento');
        }
        if ($pos === false) {
            $pos = mb_stripos($text, 'De acuerdo con el artículo 50 de la Ley');
        }
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function cleanCrtText(string $text): string
    {
        // Remove form feed characters
        $text = str_replace("\f", '', $text);

        // 1. Remove "FIRMADO POR" everywhere — it's always an electronic signature marker, never content
        $text = preg_replace('/\bFIRMADO POR\b/u', '', $text);

        // 2. Remove signature blocks: date line + name + title lines ending with known patterns
        //    Matches: "DD/MM/YYYY\nName\n...title...\n...Castilla-la Mancha" or "...Regional de Transparencia"
        $text = preg_replace(
            '/^\s*\d{2}\/\d{2}\/\d{4}\s*\n\s*[\p{Lu}][\p{L}\s,\.]+\n(?:\s*.+\n)*?\s*.*(?:Castilla[- ][Ll]a Mancha|Regional de Transparencia)\s*$/mu',
            '',
            $text,
        );

        // 3. Remove orphan signer title lines (left over from partially cleaned blocks)
        $text = preg_replace('/^\s*El(?:\/la)?\s+(?:Presidente|Secretario).*(?:Consejo Regional|Regional de Transparencia).*$/mu', '', $text);
        $text = preg_replace('/^\s*Transparencia Y Buen Gobierno de Castilla-la Mancha\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Y Buen Gobierno de Castilla-la Mancha\s*$/mu', '', $text);

        // 4. Remove garbled logo/watermark header (from bad OCR on some PDFs)
        $text = preg_replace('/^\s*iCRT\s*$/mu', '', $text);
        $text = preg_replace('/^\s*w\s+CASTILLA-LA MANCHA\s*$/mu', '', $text);
        $text = preg_replace('/^\s*w\s*$/mu', '', $text);
        $text = preg_replace('/^\s*CASTILLA-LA MANCHA\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Gnnflj\(\).*$/mu', '', $text);
        $text = preg_replace('/^\s*,\s*\.\.,?\s*$/mu', '', $text);

        // 5. Remove repeated footer block
        $text = preg_replace('/^\s*CONSEJO REGIONAL DE TRANSPARENCIA Y BUEN\s*$/mu', '', $text);
        $text = preg_replace('/^\s*GOBIERNO DE CASTILLA-LA MANCHA\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Decreto\s+N\s*[ºo°]\s*\d+\s+de\s+\d{1,2}\/\d{2}\/\d{4}.*$/mu', '', $text);
        $text = preg_replace('/^\s*P[aá]g\.\s*\d+\s+de\s+\d+\s*$/mu', '', $text);
        $text = preg_replace('/^\s*La comprobaci[oó]n de la autenticidad.*$/mu', '', $text);
        $text = preg_replace('/^\s*(?:mediante el siguiente|https?:\/\/sede\.consejotransparenciaclm).*$/mu', '', $text);

        // 6. Remove clean header variant (non-garbled)
        $text = preg_replace('/^\s*Consejo Regional de Transparencia y Buen Gobierno\s*$/mu', '', $text);
        $text = preg_replace('/^\s*de Castilla[- ]La Mancha\s*$/mu', '', $text);
        $text = preg_replace('/^\s*y Buen Gobierno,?\s*$/mu', '', $text);

        // 7. Strip closing boilerplate (appeal info + signatures)
        $pos = mb_stripos($text, 'Notifíquese al interesado que, contra la presente Resolución');
        if ($pos === false) {
            $pos = mb_stripos($text, 'contra la presente Resolución, que pone fin a la vía administrativa');
        }
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // 8. Remove standalone footnote URLs
        $text = preg_replace('/^\s*https?:\/\/\S+\s*$/mu', '', $text);

        // 9. Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function cleanCvtText(string $text): string
    {
        // Remove form feed characters
        $text = str_replace("\f", '', $text);

        // Remove repeated header: "Consell de Transparència, Accés a la Informació Pública i Bon Govern"
        $text = preg_replace('/^\s*Consell de Transpar[eè]ncia,?\s*(?:Acc[eé]s a la Informaci[oó] P[uú]blica i Bon Govern)?\s*$/mu', '', $text);

        // Remove "GENERALITAT VALENCIANA" / "GENERALITAT" header lines
        $text = preg_replace('/^\s*GENERALITAT\s*(?:VALENCIANA)?\s*$/mu', '', $text);

        // Remove CSV verification lines (electronic signature)
        $text = preg_replace('/^\s*CSV\s*:.*$/mu', '', $text);
        $text = preg_replace('/^\s*(?:La comprovació|La comprobación)\s+d[e\']\s*(?:autenticitat|la autenticidad).*$/mu', '', $text);
        $text = preg_replace('/^\s*(?:mitjançant|mediante)\s+.*(?:sede\.gva|csv\.gva).*$/mu', '', $text);
        $text = preg_replace('/^\s*https?:\/\/\S*(?:gva\.es|sede\.gva)\S*\s*$/mu', '', $text);

        // Remove page numbers
        $text = preg_replace('/^\s*P[àa]g(?:ina)?\.?\s*\d+\s*(?:de\s*\d+)?\s*$/mu', '', $text);

        // Remove standalone URLs
        $text = preg_replace('/^\s*https?:\/\/\S+\s*$/mu', '', $text);

        // Strip closing boilerplate (appeal info + signatures)
        $pos = mb_stripos($text, 'Contra la present resolució');
        if ($pos === false) {
            $pos = mb_stripos($text, 'Aquesta resolució posa fi a la via administrativa');
        }
        if ($pos === false) {
            $pos = mb_stripos($text, 'La present resolució posa fi a la via administrativa');
        }
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function cleanCtpdaText(string $text): string
    {
        // Remove form feed characters
        $text = str_replace("\f", '', $text);

        // Remove page footer lines: "Página X de Y. Resolución [RES-]NNN/YYYY, de DD de month ..." (all variants)
        $text = preg_replace('/^\s*Página\s+\d+\s+de\s+\d+.*$/mu', '', $text);

        // Remove standalone resolution footer (2016-2021 where it's a separate line)
        $text = preg_replace('/^\s*Resolución\s+(?:RES-)?\d+\/\d{4}(?:,\s+de\s+.+?)?\s*$/mu', '', $text);

        // Remove footer URLs and emails
        $text = preg_replace('/^\s*www\.ctpdandalucia\.es\s*$/mu', '', $text);
        $text = preg_replace('/^\s*ctpdandalucia@juntadeandalucia\.es\s*$/mu', '', $text);

        // Remove "Documento apto para ser publicado en el Portal del Consejo" (2024+)
        $text = preg_replace('/^\s*Documento apto para ser publicado en el Portal del Consejo\s*$/mu', '', $text);

        // Remove standalone organism header line (2021 variant)
        $text = preg_replace('/^\s*Consejo de Transparencia y Protecci[oó]n de Datos de Andaluc[ií]a\s*$/mu', '', $text);

        // Remove signature block
        $text = preg_replace('/^\s*EL DIRECTOR DEL CONSEJO DE TRANSPARENCIA\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Y PROTECCI[OÓ]N DE DATOS DE ANDALUC[IÍ]A\s*$/mu', '', $text);

        // Remove signature text variants
        $text = preg_replace('/^\s*Consta la firma\s*$/mu', '', $text);
        $text = preg_replace('/^\s*.*consta firmad[oa]\s+electr[oó]nicamente\.?\s*$/mu', '', $text);

        // Remove known director names
        $text = preg_replace('/^\s*Manuel Medina Guerrero\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Jes[uú]s Jim[eé]nez L[oó]pez\s*$/mu', '', $text);

        // Strip closing boilerplate (appeal info + signatures)
        $pos = mb_stripos($text, 'Contra esta resolución, que pone fin a la vía administrativa');
        if ($pos !== false) {
            $text = mb_substr($text, 0, $pos);
        }

        // Collapse multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    public static function cleanCtcanText(string $text): string
    {
        // Remove form feed characters
        $text = str_replace("\f", '', $text);

        // Remove repeated organism header (2015 format, split across two lines)
        $text = preg_replace('/^\s*Comisionado de Transparencia\s*\n\s*y Acceso a la Informaci[oó]n P[uú]blica\s*$/mu', '', $text);
        // Single-line variant
        $text = preg_replace('/^\s*Comisionado de Transparencia y Acceso a la Informaci[oó]n P[uú]blica\s*$/mu', '', $text);

        // Remove footer: address + phone + URL (all variants)
        $text = preg_replace('/^\s*Edificio del Parlamento de Canarias\.\s*C\/ Teobaldo Power,\s*7\.\s*38002 Santa Cruz de Tenerife\s*$/mu', '', $text);
        $text = preg_replace('/^\s*(?:Tel[eé]fono:|Tel\.)\s*\+34\s*922\s*47\s*\d{4}\s*[–\-]?\s*(?:www\.)?transparenciacanarias\.org\s*$/mu', '', $text);

        // Remove standalone footer URL
        $text = preg_replace('/^\s*(?:www\.)?transparenciacanarias\.org\s*$/mu', '', $text);

        // Remove standalone page numbers (at page boundaries)
        $text = preg_replace('/^\s*\d{1,3}\s*$/mu', '', $text);

        // Remove page footers: "Página X de Y"
        $text = preg_replace('/^\s*P[aá]gina\s+\d+\s+de\s+\d+\s*$/mu', '', $text);

        // Remove electronic signature / CSV verification blocks
        $text = preg_replace('/^\s*(?:CSV|C[oó]digo Seguro de Verificaci[oó]n)\s*:.*$/mu', '', $text);
        $text = preg_replace('/^\s*Puede verificar la autenticidad.*$/mu', '', $text);
        $text = preg_replace('/^\s*Este documento es copia aut[eé]ntica.*$/mu', '', $text);
        $text = preg_replace('/^\s*Firmado electr[oó]nicamente.*$/mu', '', $text);
        $text = preg_replace('/^\s*FIRMADO POR\s*$/mu', '', $text);

        // Remove signature block: title + known commissioner names + date
        $text = preg_replace('/^\s*(?:LA COMISIONADA|EL COMISIONADO) DE TRANSPARENCIA Y ACCESO A LA INFORMACI[OÓ]N P[UÚ]BLICA\s*$/mu', '', $text);
        $text = preg_replace('/^\s*(?:D\.?\s*)?(?:María Noelia García Leal|Daniel Cerd[aá]n Elcid)\s*$/mu', '', $text);
        $text = preg_replace('/^\s*Resoluci[oó]n firmada el \d{2}-\d{2}-\d{4}\s*$/mu', '', $text);

        // Remove "nueva reclamación" boilerplate paragraph
        $text = preg_replace('/^\s*Queda a disposici[oó]n del reclamante la posibilidad de presentar nueva reclamaci[oó]n.*?petici[oó]n de informaci[oó]n formulada\.\s*$/msu', '', $text);

        // Strip closing boilerplate: appeals info (all variants)
        $pos = mb_stripos($text, 'De acuerdo con el artículo 51 de la LTAIP');
        if ($pos === false) {
            $pos = mb_stripos($text, 'Contra la presente resolución');
        }
        if ($pos === false) {
            $pos = mb_stripos($text, 'Esta resolución pone fin a la vía administrativa');
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
