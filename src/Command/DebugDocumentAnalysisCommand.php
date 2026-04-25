<?php

namespace App\Command;

use App\Entity\Document;
use App\Enum\DocumentType;
use App\Service\AI\Llm\ChatRequest;
use App\Service\AI\Llm\ContentPart;
use App\Service\AI\Llm\LlmClient;
use App\Service\AI\Llm\ModelSize;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:debug:document-analysis',
    description: 'Analyze a document with the active LLM and show chain of thought reasoning',
)]
class DebugDocumentAnalysisCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FilesystemOperator $documentsStorage,
        private readonly LlmClient $llmClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('document-id', InputArgument::REQUIRED, 'The document UUID to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $documentId = $input->getArgument('document-id');

        $document = $this->entityManager->getRepository(Document::class)->find($documentId);

        if (!$document) {
            $io->error(sprintf('Document with ID "%s" not found', $documentId));
            return Command::FAILURE;
        }

        $io->title('Document Analysis Debug');
        $io->section('Document Info');
        $io->table(
            ['Property', 'Value'],
            [
                ['ID', (string) $document->getId()],
                ['Original Filename', $document->getOriginalFilename()],
                ['MIME Type', $document->getMimeType()],
                ['Current Type', $document->getType()->value . ' (' . $document->getTypeLabel() . ')'],
                ['Processed', $document->isProcessed() ? 'Yes' : 'No'],
                ['Active backend', $this->llmClient->isCustomEnabled() ? 'custom' : 'gemini'],
            ]
        );

        $io->section('Running LLM Analysis with Chain of Thought...');

        try {
            $content = $this->documentsStorage->read($document->getStoredFilename());

            $parts = [
                ContentPart::inlineData($document->getMimeType(), base64_encode($content)),
                ContentPart::text(sprintf('[Documento: %s]', $document->getOriginalFilename())),
                ContentPart::text($this->buildDebugPrompt()),
            ];

            $rawText = $this->llmClient->chat(new ChatRequest(
                systemPrompt: '',
                userParts: $parts,
                size: ModelSize::Mid,
                temperature: 0.1,
                jsonMode: true,
                maxOutputTokens: 4096,
            ));

            $io->section('Raw LLM Response');
            $io->writeln($rawText);

            $io->section('Parsed Analysis');
            $analysis = $this->parseResponse($rawText);

            $rows = [];
            foreach ($analysis as $key => $value) {
                if ($key === 'reasoning') {
                    continue;
                }
                if ($value instanceof DocumentType) {
                    $rows[] = [$key, $value->value . ' (' . $value->label() . ')'];
                } elseif (is_bool($value)) {
                    $rows[] = [$key, $value ? 'true' : 'false'];
                } elseif (is_null($value)) {
                    $rows[] = [$key, '<null>'];
                } else {
                    $rows[] = [$key, (string) $value];
                }
            }
            $io->table(['Field', 'Value'], $rows);

            if (isset($analysis['reasoning'])) {
                $io->section('Chain of Thought / Reasoning');
                $io->writeln($analysis['reasoning']);
            }

            $io->success('Analysis completed');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Analysis failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function buildDebugPrompt(): string
    {
        return <<<PROMPT
Analiza este documento relacionado con una solicitud de acceso a información pública en España (Ley 19/2013 o leyes autonómicas de transparencia).

IMPORTANTE: Necesito que me expliques tu razonamiento paso a paso ANTES de dar el resultado final.

Primero, explica en "reasoning":
1. Qué tipo de documento crees que es y POR QUÉ
2. Qué elementos visuales o textuales te han llevado a esa conclusión
3. Si hay ambigüedad, explica qué opciones consideraste y por qué elegiste una

Tipos de documento posibles:
- solicitud: La solicitud inicial de acceso a información presentada por el ciudadano
- acuse_recibo: Justificante de registro o acuse de recibo de la solicitud
- inicio_tramitacion: Notificación de inicio de tramitación (art. 20.1 Ley 19/2013)
- resolucion: Resolución que concede o deniega el acceso
- prorroga: Notificación de prórroga del plazo
- traslado: Comunicación de que la solicitud se traslada a otro órgano
- afectacion_terceros: Notificación de afectación a derechos de terceros
- reclamacion: Reclamación presentada ante el Consejo de Transparencia (por silencio, denegación, etc.)
- acuse_recibo_reclamacion: Acuse de recibo de la reclamación por el Consejo de Transparencia
- inicio_tramitacion_reclamacion: Notificación de inicio de tramitación de la reclamación
- resolucion_reclamacion: Resolución de un organismo de transparencia (CTBG, GAIP, Comisionado, etc.)
- otro: Cualquier otro documento que no encaje en las categorías anteriores

Extrae la siguiente información en formato JSON:

{
    "reasoning": "tu razonamiento detallado paso a paso sobre por qué clasificas el documento de esta manera",
    "documentType": "tipo de documento (uno de los indicados arriba)",
    "referenceNumber": "número de expediente o registro",
    "publicBodyName": "nombre de la administración",
    "documentDate": "fecha en formato YYYY-MM-DD",
    "applicableLaw": "ley de transparencia aplicable",
    "summary": "resumen breve del contenido",
    "status": "estado (enviada, en_tramite, concedida, denegada, silencio, pendiente, null)",
    "isExtension": "true/false si es prórroga",
    "isRedirection": "true/false si es traslado",
    "isThirdPartyRights": "true/false si afecta a terceros",
    "isProcessingStart": "true/false si es inicio de tramitación"
}

IMPORTANTE:
- El campo "reasoning" es OBLIGATORIO y debe explicar tu proceso de clasificación
- Responde SOLO con el JSON
- Si no puedes determinar un campo, usa null
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(string $text): array
    {
        $text = preg_replace('/^```json\s*/', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $text = trim($text);

        $data = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to parse response as JSON: ' . $text);
        }

        $data['documentType'] = DocumentType::fromAiValue($data['documentType'] ?? 'otro');

        return $data;
    }
}
