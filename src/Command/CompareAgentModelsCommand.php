<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AccessRequest;
use App\Eval\Agent\AgentEvalCase;
use App\Eval\Agent\AgentRunResult;
use App\Eval\Agent\AgentTurnOutcome;
use App\Eval\Agent\ComparisonPackBuilder;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest;
use App\Service\AI\Chat\Composer\ComplaintPromptComposer;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\DoctrinePriorityResolver;
use App\Service\AI\ModelChoice;
use App\Service\AI\ModelRouter;
use App\Service\AI\SimilarResolutionsLoader;
use App\Service\Complaint\ComplaintDraftGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Yaml\Yaml;

/**
 * Corre los MISMOS casos con el modelo teacher y con el modelo pequeño, y
 * produce un pack ciego listo para que lo juzgue un LLM externo (p. ej. Claude).
 *
 * Por qué existe: la decisión de si merece la pena destilar —y hacia qué
 * tamaño— no se puede tomar a ojo. Este comando la convierte en un dato.
 *
 * Cada modelo ejecuta su PROPIO bucle agéntico sobre el mismo caso: elige sus
 * herramientas, ve sus resultados y redacta con ellos. Eso duplica el coste de
 * las tools reales (Elasticsearch, embeddings, scraping, lectura de documentos),
 * lo cual es aceptable aquí porque es una tirada offline y acotada, pero es la
 * razón por la que esto NO se hace en producción con cada turno de usuario.
 *
 * El pack no lleva la identidad de los modelos y las métricas objetivas se
 * escriben en un fichero aparte: el juez no debe saber quién es quién ni cuánto
 * tardó cada uno. La clave para desanonimizar queda en `key.json`.
 */
#[AsCommand(
    name: 'app:agent:compare',
    description: 'Ejecuta los casos de config/eval/agent con el teacher y con el modelo pequeño y genera un pack ciego para LLM-as-judge',
)]
final class CompareAgentModelsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentChatOrchestrator $orchestrator,
        private readonly RequestPromptComposer $requestPromptComposer,
        private readonly ComplaintPromptComposer $complaintPromptComposer,
        private readonly SimilarResolutionsLoader $similarResolutions,
        private readonly DoctrinePriorityResolver $doctrinePriority,
        private readonly ModelRouter $modelRouter,
        private readonly ComparisonPackBuilder $packBuilder,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    /**
     * Mensaje por defecto de un caso al vuelo, por tarea. Son los turnos que
     * arrancan cada flujo en producción, así que sirven para una comparación
     * rápida sin tener que redactar nada.
     */
    private const DEFAULT_MESSAGES = [
        'request'   => 'Redacta la solicitud de acceso a la información.',
        'complaint' => 'Redacta la reclamación.',
        'alegation' => 'Responde a las alegaciones de la Administración.',
    ];

    protected function configure(): void
    {
        $this
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'UUID de una AccessRequest para comparar AL VUELO, sin pasar por el YAML. Repetible.')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Mensaje del usuario para los casos de --request-id (por defecto, el que arranca el flujo de --task)')
            ->addOption('follow-up', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Siguiente mensaje del usuario, guionizado e IDÉNTICO para los dos modelos. Repetible: un turno más por cada uno. Sin ninguno, la comparación es de un solo turno (planes y preguntas, no escritos).')
            ->addOption('case-id', null, InputOption::VALUE_REQUIRED, 'Id del caso al vuelo (por defecto: adhoc-<uuid>-<task>)')
            ->addOption('cases', null, InputOption::VALUE_REQUIRED, 'Fichero YAML de casos', 'config/eval/agent/cases.yaml')
            ->addOption('task', null, InputOption::VALUE_REQUIRED, 'Con --request-id, la tarea del caso (por defecto request). Sin él, filtra los casos del YAML. (request|complaint|alegation)')
            ->addOption('case', null, InputOption::VALUE_REQUIRED, 'Solo el caso del YAML con este id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de casos del YAML a ejecutar')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Directorio de salida', 'var/agent-compare')
            ->addOption('run-name', null, InputOption::VALUE_REQUIRED, 'Nombre de la tirada (por defecto: compare-<fecha>)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->modelRouter->isTeacherConfigured()) {
            $io->error('TEACHER_MODEL / TEACHER_MODEL_ENDPOINT vacíos: no hay con qué comparar.');

            return Command::FAILURE;
        }

        $requestIds = array_values(array_filter((array) $input->getOption('request-id')));

        if ($requestIds !== []) {
            try {
                $cases = $this->adHocCases($requestIds, $input);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        } else {
            $casesPath = $this->absolute((string) $input->getOption('cases'));
            if (!is_file($casesPath)) {
                $io->error(sprintf('No existe el fichero de casos: %s', $casesPath));

                return Command::FAILURE;
            }

            $cases = $this->loadCases($casesPath, $input);
            if ($cases === []) {
                $io->error('Ningún caso coincide con los filtros. Para una comparación suelta: --request-id=<uuid>.');

                return Command::FAILURE;
            }
        }

        $runName = (string) ($input->getOption('run-name') ?: 'compare-' . date('Y-m-d_H-i-s'));
        $teacher = new ModelChoice($this->modelRouter->teacher(), ModelChoice::ROLE_TEACHER);
        $student = new ModelChoice($this->modelRouter->student(), ModelChoice::ROLE_STUDENT);

        $io->title(sprintf('Comparación «%s»', $runName));
        $io->listing([
            sprintf('teacher: %s', $teacher->client->getModel()),
            sprintf('student: %s', $student->client->getModel()),
            sprintf('casos:   %d', count($cases)),
        ]);
        $io->note('Cada caso se ejecuta dos veces con su bucle de herramientas completo: las búsquedas y lecturas de documentos se pagan por duplicado.');

        $runs = [];
        $io->progressStart(count($cases) * 2);

        foreach ($cases as $case) {
            try {
                $request = $this->entityManager->getRepository(AccessRequest::class)->find($case->requestId);
            } catch (\Throwable $e) {
                // UUID mal formado, típicamente. Un caso malo no debe tumbar la
                // tirada: las demás comparaciones ya están pagadas.
                $io->warning(sprintf('Caso «%s»: %s no es un identificador válido (%s); se omite.', $case->id, $case->requestId, $e->getMessage()));
                $io->progressAdvance(2);
                continue;
            }

            if (!$request instanceof AccessRequest) {
                $io->warning(sprintf('Caso «%s»: no existe la solicitud %s; se omite.', $case->id, $case->requestId));
                $io->progressAdvance(2);
                continue;
            }

            $this->authenticateAs($request);

            $runs[$case->id] = [
                'case'    => $case,
                'teacher' => $this->runConversation($case, $request, $teacher),
                'student' => $this->runConversation($case, $request, $student),
            ];
            $io->progressAdvance(2);
        }

        $io->progressFinish();

        if ($runs === []) {
            $io->error('No se pudo ejecutar ningún caso.');

            return Command::FAILURE;
        }

        $built   = $this->packBuilder->build($runs, $runName);
        $metrics = $this->packBuilder->objectiveMetrics($runs);
        $dir     = $this->absolute((string) $input->getOption('out')) . '/' . $runName;

        $this->write($dir . '/pack.json', $built['pack']);
        $this->write($dir . '/key.json', $built['key']);
        $this->write($dir . '/metrics.json', $metrics);

        $this->renderSummary($io, $metrics);

        $io->success(sprintf('Pack ciego en %s/pack.json (la identidad de cada candidata, en key.json).', $dir));
        $io->writeln('Pásale <info>pack.json</info> a un LLM juez y guarda su veredicto junto a <info>key.json</info> para desanonimizar.');

        return Command::SUCCESS;
    }

    /**
     * Ejecuta la conversación entera del caso con UN modelo.
     *
     * Cada modelo sigue su PROPIA rama: si en el turno 1 propuso un plan, el
     * turno 2 redacta desde ese plan suyo, no desde uno prefabricado. Lo que se
     * mantiene idéntico entre candidatos son los mensajes del usuario, que están
     * guionizados en el caso — no hay usuario simulado que pueda contestarle
     * distinto a uno que a otro.
     */
    private function runConversation(AgentEvalCase $case, AccessRequest $request, ModelChoice $model): AgentRunResult
    {
        $rawTurns = $case->history;
        $hasDraft = $this->initialHasDraft($case, $request);
        $outcomes = [];

        foreach ($case->userMessages() as $userMessage) {
            $turn    = $this->buildTurn($case, $request, $rawTurns, $userMessage, $hasDraft);
            $outcome = $this->runOnce($turn, $userMessage, $model);
            $outcomes[] = $outcome;

            if ($outcome->failed()) {
                break;
            }

            // Se reconstruye el historial igual que en producción, incluido el
            // marcador de "en este turno generé/reescribí el borrador" que el
            // orquestador añade para que el modelo no redacte por inercia.
            $rawTurns[] = ['role' => 'user', 'content' => $userMessage];
            $rawTurns[] = ['role' => 'assistant', 'content' => $outcome->reply(), 'action' => $outcome->action()];

            if ($outcome->producedDraft()) {
                $hasDraft = true;
            }
        }

        return new AgentRunResult($model->client->getModel(), $model->role, $outcomes);
    }

    /**
     * ¿Hay ya un borrador en el canvas al arrancar el caso? Condiciona si el
     * flujo complaint entra por la FASE 1 obligatoria.
     */
    private function initialHasDraft(AgentEvalCase $case, AccessRequest $request): bool
    {
        if ($case->task !== 'request') {
            return false;
        }

        return trim((string) $request->getDescription()) !== '';
    }

    private function runOnce(AssistantChatRequest $turn, string $userMessage, ModelChoice $model): AgentTurnOutcome
    {
        $started   = microtime(true);
        $toolCalls = [];
        $decision  = [];
        $reply     = '';
        $error     = '';

        try {
            foreach ($this->orchestrator->stream($turn, $model) as [$event, $payload]) {
                if ($event === 'step' && ($payload['tool'] ?? null) !== null) {
                    // El orquestador emite DOS pasos por herramienta: el de
                    // arranque y el del resultado, que va prefijado con «✓».
                    // Solo cuentan los de arranque.
                    if (!str_starts_with((string) ($payload['message'] ?? ''), '✓')) {
                        $toolCalls[] = (string) $payload['tool'];
                    }
                } elseif ($event === 'chat_token') {
                    // La respuesta conversacional no viaja en el evento
                    // `decision`: llega troceada como chat_token.
                    $reply .= (string) ($payload['text'] ?? '');
                } elseif ($event === 'decision') {
                    $decision = [
                        'action' => $payload['action'] ?? '',
                        'draft'  => $payload['draft'] ?? null,
                        'plan'   => $payload['plan'] ?? null,
                    ];
                } elseif ($event === 'error') {
                    $error = (string) ($payload['message'] ?? 'error desconocido');
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($decision !== []) {
            $decision['conversational_reply'] = $reply;
        }

        return new AgentTurnOutcome(
            userMessage: $userMessage,
            decision: $decision,
            toolCalls: $toolCalls,
            elapsedMs: (int) round((microtime(true) - $started) * 1000),
            error: $error,
        );
    }

    /**
     * @param list<array<string, mixed>> $rawTurns historial en el formato de toLlmHistory()
     */
    private function buildTurn(
        AgentEvalCase $case,
        AccessRequest $request,
        array $rawTurns,
        string $userMessage,
        bool $hasDraft,
    ): AssistantChatRequest {
        $priority = $this->doctrinePriority->priorityOrganismIdsFor($request);
        $history  = AgentChatOrchestrator::toLlmHistory($rawTurns);

        if ($case->task === 'request') {
            $prompt = $this->requestPromptComposer->compose($request, $this->similarResolutions->load($request));

            return new AssistantChatRequest(
                flow: 'request',
                entityId: $request->getId()->toRfc4122(),
                systemPrompt: $prompt->text,
                userMessage: $userMessage,
                history: $history,
                attachments: [],
                label: 'RequestGenerationStream',
                promptRef: $prompt,
                traceName: 'RequestGenerationStream',
                hasDraft: $hasDraft,
                priorityOrganismIds: $priority,
            );
        }

        $mode = $case->task === 'alegation'
            ? ComplaintDraftGenerator::MODE_ALEGATION_RESPONSE
            : ComplaintDraftGenerator::MODE_COMPLAINT;
        $traceName = $case->task === 'alegation' ? 'AlegationGenerationStream' : 'ComplaintGenerationStream';

        $prompt = $this->complaintPromptComposer->compose($request, $mode, '');

        return new AssistantChatRequest(
            flow: 'complaint',
            entityId: $request->getId()->toRfc4122(),
            systemPrompt: $prompt->text,
            userMessage: $userMessage,
            history: $history,
            attachments: [],
            label: $traceName,
            promptRef: $prompt,
            traceName: $traceName,
            hasDraft: $hasDraft,
            priorityOrganismIds: $priority,
        );
    }

    /**
     * El orquestador lee `Security::getUser()` para decidir el toolset: sin
     * usuario autenticado retira las herramientas de salida a internet y la
     * comparación no reflejaría lo que pasa en producción.
     */
    private function authenticateAs(AccessRequest $request): void
    {
        $user = $request->getUser();
        if ($user === null) {
            return;
        }

        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    /**
     * Casos construidos desde la línea de comandos, sin YAML. Es el modo para
     * probar un expediente concreto sobre la marcha; el YAML sigue siendo el
     * sitio del dataset estable que se repite entre tiradas.
     *
     * El id se deriva del UUID y la tarea, no de un contador, para que el
     * reparto ciego A/B del mismo expediente sea el mismo en cada tirada.
     *
     * @param list<string> $requestIds
     * @return list<AgentEvalCase>
     */
    private function adHocCases(array $requestIds, InputInterface $input): array
    {
        $task = (string) ($input->getOption('task') ?: 'request');
        if (!isset(self::DEFAULT_MESSAGES[$task])) {
            throw new \InvalidArgumentException(sprintf(
                'Tarea desconocida «%s». Válidas: %s.',
                $task,
                implode(', ', array_keys(self::DEFAULT_MESSAGES)),
            ));
        }

        $message = trim((string) ($input->getOption('message') ?: self::DEFAULT_MESSAGES[$task]));
        $explicitId = $input->getOption('case-id');
        $followUps = array_values(array_filter(array_map(
            static fn (string $f): string => trim($f),
            (array) $input->getOption('follow-up'),
        )));

        $cases = [];
        foreach ($requestIds as $requestId) {
            $cases[] = new AgentEvalCase(
                id: $explicitId !== null && count($requestIds) === 1
                    ? (string) $explicitId
                    : sprintf('adhoc-%s-%s', $requestId, $task),
                task: $task,
                requestId: $requestId,
                userMessage: $message,
                followUps: $followUps,
                notes: 'Caso al vuelo (--request-id), no forma parte del dataset.',
            );
        }

        return $cases;
    }

    /** @return list<AgentEvalCase> */
    private function loadCases(string $path, InputInterface $input): array
    {
        $parsed = Yaml::parseFile($path);
        $taskFilter = $input->getOption('task');
        $caseFilter = $input->getOption('case');
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;

        $cases = [];
        foreach ((array) ($parsed['cases'] ?? []) as $raw) {
            $case = new AgentEvalCase(
                id: (string) ($raw['id'] ?? ''),
                task: (string) ($raw['task'] ?? 'request'),
                requestId: (string) ($raw['request_id'] ?? ''),
                userMessage: (string) ($raw['user_message'] ?? ''),
                followUps: array_values(array_map(
                    static fn (mixed $f): string => trim((string) $f),
                    (array) ($raw['follow_ups'] ?? []),
                )),
                history: array_values(array_map(
                    static fn (array $m): array => [
                        'role'    => (string) ($m['role'] ?? 'user'),
                        'content' => (string) ($m['content'] ?? ''),
                        'action'  => $m['action'] ?? null,
                    ],
                    (array) ($raw['history'] ?? []),
                )),
                notes: (string) ($raw['notes'] ?? ''),
            );

            if ($case->id === '' || $case->requestId === '') {
                continue;
            }
            if ($taskFilter !== null && $case->task !== $taskFilter) {
                continue;
            }
            if ($caseFilter !== null && $case->id !== $caseFilter) {
                continue;
            }

            $cases[] = $case;
            if ($limit !== null && count($cases) >= $limit) {
                break;
            }
        }

        return $cases;
    }

    /** @param array<string, mixed> $metrics */
    private function renderSummary(SymfonyStyle $io, array $metrics): void
    {
        $rows = [];
        foreach (['teacher', 'student'] as $role) {
            $m = $metrics[$role];
            $rows[] = [
                $role,
                $m['model'],
                sprintf('%d/%d', $m['casos'] - $m['fallos'], $m['casos']),
                $m['sin_borrador'],
                $m['tool_calls_media'],
                $m['tools_distintas'],
                $m['ms_mediana'],
                $m['long_borrador'],
                $m['citas_media'],
            ];
        }

        $io->table(
            ['rol', 'modelo', 'ok', 'sin redactar', 'tools/caso', 'tools distintas', 'ms mediana', 'long. borrador', 'citas/caso'],
            $rows,
        );

        foreach (['teacher', 'student'] as $role) {
            if ($metrics[$role]['secuencias'] !== []) {
                $io->writeln(sprintf('<info>%s</info> — secuencias de acción: %s', $role, json_encode($metrics[$role]['secuencias'], JSON_UNESCAPED_UNICODE)));
            }
        }

        if ($metrics['secuencias_divergentes'] !== []) {
            $io->section('Casos donde los modelos se comportaron distinto turno a turno');
            $io->listing($metrics['secuencias_divergentes']);
        }
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->projectDir . '/' . $path;
    }
}
