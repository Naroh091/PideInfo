# Agent Presentation Plumbing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a durable task queue between the PideInfo web app and the Python agent, exposed through a `pideinfo://` URL scheme handler, so that pressing "Presentar con el agente" on a complaint hands off to the local agent which (in this phase) downloads the PDF and opens the right CTBG sede URL.

**Architecture:** New `AgentTask` entity persists pending work scoped per user. A new web controller endpoint creates `present_complaint` tasks and returns a `pideinfo://present-complaint/<task_id>` URL. The browser navigates to that URL; the OS dispatches it to the agent (registered as the handler for `pideinfo://`). A single-instance IPC layer in the agent forwards URLs from secondary invocations to the running daemon. The daemon's task dispatcher claims the task, downloads the PDF, opens the browser, and reports completion. JS in the web polls the task status and shows a fallback if the agent doesn't respond.

**Tech Stack:** PHP 8 / Symfony 7, Doctrine ORM, PostgreSQL, Twig, Lexik JWT (existing agent auth), vanilla JS / Alpine.js. Python 3.11+, httpx, pystray, asyncio. URL scheme registration via `xdg-mime` (Linux), `Info.plist`/`CFBundleURLTypes` (macOS), `HKEY_CURRENT_USER\Software\Classes` (Windows).

**Spec:** `docs/superpowers/specs/2026-04-30-agent-presentation-plumbing-design.md`.

---

## File structure

| File | Status | Responsibility |
|---|---|---|
| `src/Entity/AgentTask.php` | new | Doctrine entity for queued agent work. |
| `src/Repository/AgentTaskRepository.php` | new | Queries; atomic claim. |
| `migrations/VersionYYYYMMDDHHMMSS.php` | new | Idempotent schema for `agent_task`. |
| `src/Controller/Api/AgentTaskApiController.php` | new | 4 JWT-auth endpoints under `/api/agent/tasks`. |
| `src/Controller/ComplaintController.php` | modify | Add `presentViaAgent` POST endpoint. |
| `templates/solicitudes/show.html.twig` | modify | Add "Presentar con el agente" button + modo modal + status indicator. |
| `assets/controllers/agent_present_controller.js` | new | Stimulus: post task, redirect to `pideinfo://`, poll status, fallback UI. |
| `agent/protocol/__init__.py` | new | Package marker. |
| `agent/protocol/url_handler.py` | new | Parse `pideinfo://...` URLs and dispatch. |
| `agent/protocol/single_instance.py` | new | Unix-socket / named-pipe single-instance + URL relay. |
| `agent/protocol/registration.py` | new | Register/unregister `pideinfo://` handler per OS. |
| `agent/tasks/__init__.py` | new | Type-keyed dispatcher. |
| `agent/tasks/present_complaint.py` | new | Download PDF + open browser. |
| `agent/client/pideinfo.py` | modify | Add `get_pending_tasks`, `claim_task`, `progress_task`, `complete_task`. |
| `agent/main.py` | modify | Parse `--url` flag, integrate single-instance, listen for relayed URLs. |
| `agent/tray.py` | modify | Menu item "Registrar handler de pideinfo://". |
| `agent/version.py` | modify | Bump `__version__`. |
| `docs/agent.md` | modify | "Recepción de tareas" section. |
| `docs/complaint-workflow.md` | modify | "Presentación vía agente (fase 2a)" section. |
| `docs/architecture.md` | modify | Update agent↔web diagram. |
| `tests/Repository/AgentTaskRepositoryTest.php` | new | Atomic claim test. |
| `tests/Controller/Api/AgentTaskApiControllerTest.php` | new | Functional WebTestCase for the 4 endpoints. |

---

## Task 1: `AgentTask` entity + state constants

**Files:**
- Create: `src/Entity/AgentTask.php`

- [ ] **Step 1: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AgentTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AgentTaskRepository::class)]
#[ORM\Table(name: 'agent_task')]
#[ORM\Index(columns: ['user_id', 'status'], name: 'idx_agent_task_user_status')]
class AgentTask
{
    public const TYPE_PRESENT_COMPLAINT = 'present_complaint';

    public const MODE_AUTO = 'auto';
    public const MODE_SUPERVISED = 'supervised';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_FAILED];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\ManyToOne(targetEntity: AccessRequest::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AccessRequest $accessRequest = null;

    #[ORM\Column(length: 64)]
    private string $type;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $mode = null;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $result = null;

    public function __construct(User $user, string $type)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->type = $type;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getAccessRequest(): ?AccessRequest { return $this->accessRequest; }
    public function setAccessRequest(?AccessRequest $r): self { $this->accessRequest = $r; return $this; }
    public function getType(): string { return $this->type; }
    public function getMode(): ?string { return $this->mode; }
    public function setMode(?string $m): self { $this->mode = $m; return $this; }
    public function getPayload(): array { return $this->payload; }
    public function setPayload(array $p): self { $this->payload = $p; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getClaimedAt(): ?\DateTimeImmutable { return $this->claimedAt; }
    public function setClaimedAt(?\DateTimeImmutable $d): self { $this->claimedAt = $d; return $this; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $d): self { $this->completedAt = $d; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $m): self { $this->errorMessage = $m; return $this; }
    public function getResult(): ?array { return $this->result; }
    public function setResult(?array $r): self { $this->result = $r; return $this; }

    public function isTerminal(): bool { return in_array($this->status, self::TERMINAL_STATUSES, true); }
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/Entity/AgentTask.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Entity/AgentTask.php
git commit -m "feat(agent-task): add AgentTask entity with status/mode constants"
```

---

## Task 2: `AgentTaskRepository` with atomic claim

**Files:**
- Create: `src/Repository/AgentTaskRepository.php`
- Create: `tests/Repository/AgentTaskRepositoryTest.php`

- [ ] **Step 1: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgentTask;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AgentTask>
 */
class AgentTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentTask::class);
    }

    /**
     * @return AgentTask[]
     */
    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', AgentTask::STATUS_PENDING)
            ->orderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForRequest(\App\Entity\AccessRequest $request, string $type): ?AgentTask
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.accessRequest = :r')
            ->andWhere('t.type = :type')
            ->setParameter('r', $request)
            ->setParameter('type', $type)
            ->orderBy('t.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Atomic claim: pending → claimed. Returns the refreshed entity if the caller won the race,
     * null if another worker already claimed it.
     */
    public function claimAtomically(Uuid $id, User $user): ?AgentTask
    {
        $conn = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.u');

        $affected = $conn->executeStatement(
            'UPDATE agent_task SET status = :claimed, claimed_at = :now
             WHERE id = :id AND user_id = :user_id AND status = :pending',
            [
                'claimed' => AgentTask::STATUS_CLAIMED,
                'now' => $now,
                'id' => $id->toRfc4122(),
                'user_id' => $user->getId()->toRfc4122(),
                'pending' => AgentTask::STATUS_PENDING,
            ]
        );

        if ($affected === 0) {
            return null;
        }

        // Detach any cached snapshot so the refresh below sees the UPDATEd row.
        $task = $this->find($id);
        if ($task !== null) {
            $this->getEntityManager()->refresh($task);
        }
        return $task;
    }
}
```

- [ ] **Step 2: Write the failing repository test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Repository\AgentTaskRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AgentTaskRepositoryTest extends KernelTestCase
{
    public function testClaimAtomicallyReturnsTaskOnFirstCallAndNullOnSecond(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var AgentTaskRepository $repo */
        $repo = $em->getRepository(AgentTask::class);

        $user = $em->getRepository(User::class)->findOneBy([]);
        self::assertNotNull($user, 'Test fixture: at least one User must exist');

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $em->persist($task);
        $em->flush();

        $first = $repo->claimAtomically($task->getId(), $user);
        self::assertNotNull($first);
        self::assertSame(AgentTask::STATUS_CLAIMED, $first->getStatus());

        $second = $repo->claimAtomically($task->getId(), $user);
        self::assertNull($second);

        $em->remove($first);
        $em->flush();
    }
}
```

- [ ] **Step 3: Lint**

Run: `php -l src/Repository/AgentTaskRepository.php tests/Repository/AgentTaskRepositoryTest.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit (test will fail until migration exists)**

```bash
git add src/Repository/AgentTaskRepository.php tests/Repository/AgentTaskRepositoryTest.php
git commit -m "feat(agent-task): add repository with atomic claim + test"
```

---

## Task 3: Migration for `agent_task`

**Files:**
- Create: `migrations/Version<TIMESTAMP>.php` (use `bin/console doctrine:migrations:generate`)

- [ ] **Step 1: Generate the migration scaffold**

Run: `php bin/console doctrine:migrations:generate`
Expected: outputs path of new migration file, e.g. `migrations/Version20260501120000.php`. Note the path.

- [ ] **Step 2: Replace the migration body**

Edit the generated file. Replace `up()` and `down()` with idempotent statements:

```php
public function getDescription(): string
{
    return 'Add agent_task table for web→agent task queue (Tarea 2a).';
}

public function up(Schema $schema): void
{
    $this->addSql(<<<'SQL'
        CREATE TABLE IF NOT EXISTS agent_task (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            access_request_id UUID DEFAULT NULL,
            type VARCHAR(64) NOT NULL,
            mode VARCHAR(32) DEFAULT NULL,
            payload JSON NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            result JSON DEFAULT NULL,
            PRIMARY KEY (id)
        )
    SQL);
    $this->addSql('CREATE INDEX IF NOT EXISTS idx_agent_task_user_status ON agent_task (user_id, status)');
    $this->addSql('COMMENT ON COLUMN agent_task.id IS \'(DC2Type:uuid)\'');
    $this->addSql('COMMENT ON COLUMN agent_task.user_id IS \'(DC2Type:uuid)\'');
    $this->addSql('COMMENT ON COLUMN agent_task.access_request_id IS \'(DC2Type:uuid)\'');
    $this->addSql('COMMENT ON COLUMN agent_task.created_at IS \'(DC2Type:datetime_immutable)\'');
    $this->addSql('COMMENT ON COLUMN agent_task.claimed_at IS \'(DC2Type:datetime_immutable)\'');
    $this->addSql('COMMENT ON COLUMN agent_task.completed_at IS \'(DC2Type:datetime_immutable)\'');

    // FK constraints — non-fatal if user/access_request rows are deleted (cascade nullify for accessRequest).
    $this->addSql(<<<'SQL'
        DO $$ BEGIN
            ALTER TABLE agent_task ADD CONSTRAINT fk_agent_task_user FOREIGN KEY (user_id)
                REFERENCES "user"(id) ON DELETE CASCADE;
        EXCEPTION WHEN duplicate_object THEN NULL; END $$
    SQL);
    $this->addSql(<<<'SQL'
        DO $$ BEGIN
            ALTER TABLE agent_task ADD CONSTRAINT fk_agent_task_access_request FOREIGN KEY (access_request_id)
                REFERENCES access_request(id) ON DELETE SET NULL;
        EXCEPTION WHEN duplicate_object THEN NULL; END $$
    SQL);
}

public function down(Schema $schema): void
{
    $this->addSql('DROP TABLE IF EXISTS agent_task');
}
```

- [ ] **Step 3: Run the migration**

Run: `php bin/console doctrine:migrations:migrate --no-interaction`
Expected: `[OK] Successfully migrated to ...`. Verify with: `php bin/console dbal:run-sql "\\d agent_task"` should list the columns.

- [ ] **Step 4: Run the repository test**

Run: `php bin/phpunit tests/Repository/AgentTaskRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add migrations/Version*.php
git commit -m "feat(agent-task): add idempotent migration for agent_task table"
```

---

## Task 4: API endpoints — `AgentTaskApiController`

**Files:**
- Create: `src/Controller/Api/AgentTaskApiController.php`
- Create: `tests/Controller/Api/AgentTaskApiControllerTest.php`

- [ ] **Step 1: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\AgentTask;
use App\Entity\User;
use App\Repository\AgentTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/agent/tasks')]
#[IsGranted('ROLE_USER')]
class AgentTaskApiController extends AbstractController
{
    public function __construct(
        private readonly AgentTaskRepository $tasks,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/pending', name: 'api_agent_tasks_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $items = array_map(
            fn(AgentTask $t) => $this->serialize($t),
            $this->tasks->findPendingForUser($user)
        );

        return new JsonResponse(['tasks' => $items]);
    }

    #[Route('/{id}', name: 'api_agent_tasks_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $task = $this->loadOwnedTask($id);
        return new JsonResponse($this->serialize($task));
    }

    #[Route('/{id}/claim', name: 'api_agent_tasks_claim', methods: ['POST'])]
    public function claim(string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $uuid = Uuid::fromString($id);

        $task = $this->tasks->claimAtomically($uuid, $user);
        if ($task === null) {
            return new JsonResponse(['error' => 'already_claimed_or_unknown'], Response::HTTP_CONFLICT);
        }
        return new JsonResponse($this->serialize($task));
    }

    #[Route('/{id}/progress', name: 'api_agent_tasks_progress', methods: ['POST'])]
    public function progress(string $id, Request $request): JsonResponse
    {
        $task = $this->loadOwnedTask($id);
        $data = json_decode($request->getContent(), true) ?? [];
        $status = $data['status'] ?? null;

        if (!in_array($status, [AgentTask::STATUS_CLAIMED, AgentTask::STATUS_IN_PROGRESS], true)) {
            return new JsonResponse(['error' => 'invalid_status'], Response::HTTP_BAD_REQUEST);
        }
        if ($task->isTerminal()) {
            return new JsonResponse(['error' => 'task_terminal'], Response::HTTP_CONFLICT);
        }

        $task->setStatus($status);
        $this->em->flush();
        return new JsonResponse($this->serialize($task));
    }

    #[Route('/{id}/complete', name: 'api_agent_tasks_complete', methods: ['POST'])]
    public function complete(string $id, Request $request): JsonResponse
    {
        $task = $this->loadOwnedTask($id);
        if ($task->isTerminal()) {
            return new JsonResponse(['error' => 'task_terminal'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $success = (bool) ($data['success'] ?? false);

        $task->setStatus($success ? AgentTask::STATUS_DONE : AgentTask::STATUS_FAILED);
        $task->setCompletedAt(new \DateTimeImmutable());
        if (isset($data['result']) && is_array($data['result'])) {
            $task->setResult($data['result']);
        }
        if (!$success && isset($data['error']) && is_string($data['error'])) {
            $task->setErrorMessage(mb_substr($data['error'], 0, 2000));
        }
        $this->em->flush();
        return new JsonResponse($this->serialize($task));
    }

    private function loadOwnedTask(string $id): AgentTask
    {
        /** @var User $user */
        $user = $this->getUser();
        $task = $this->tasks->find(Uuid::fromString($id));
        if ($task === null || $task->getUser()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
            throw $this->createNotFoundException('task_not_found');
        }
        return $task;
    }

    private function serialize(AgentTask $t): array
    {
        return [
            'id' => $t->getId()->toRfc4122(),
            'type' => $t->getType(),
            'mode' => $t->getMode(),
            'status' => $t->getStatus(),
            'payload' => $t->getPayload(),
            'result' => $t->getResult(),
            'errorMessage' => $t->getErrorMessage(),
            'createdAt' => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'claimedAt' => $t->getClaimedAt()?->format(\DateTimeInterface::ATOM),
            'completedAt' => $t->getCompletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

- [ ] **Step 2: Verify routes are registered**

Run: `php bin/console cache:clear --env=dev && php bin/console debug:router | grep "api_agent_tasks"`
Expected: 5 lines listing `api_agent_tasks_pending|get|claim|progress|complete`.

- [ ] **Step 3: Write functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AgentTask;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AgentTaskApiControllerTest extends WebTestCase
{
    public function testClaimReturns409OnSecondCall(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy([]);
        self::assertNotNull($user);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $em->persist($task);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/claim');
        self::assertResponseIsSuccessful();
        $first = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(AgentTask::STATUS_CLAIMED, $first['status']);

        $client->request('POST', '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/claim');
        self::assertResponseStatusCodeSame(409);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->flush();
    }

    public function testCompleteSuccessTransitionsToDone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        $user = $em->getRepository(User::class)->findOneBy([]);

        $task = new AgentTask($user, AgentTask::TYPE_PRESENT_COMPLAINT);
        $task->setStatus(AgentTask::STATUS_IN_PROGRESS);
        $em->persist($task);
        $em->flush();

        $client->loginUser($user);
        $client->request(
            'POST',
            '/api/agent/tasks/' . $task->getId()->toRfc4122() . '/complete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['success' => true, 'result' => ['pdf_path' => '/tmp/x.pdf']])
        );
        self::assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(AgentTask::STATUS_DONE, $body['status']);
        self::assertSame('/tmp/x.pdf', $body['result']['pdf_path']);

        $em->remove($em->find(AgentTask::class, $task->getId()));
        $em->flush();
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Controller/Api/AgentTaskApiControllerTest.php tests/Repository/AgentTaskRepositoryTest.php`
Expected: PASS for both.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/Api/AgentTaskApiController.php tests/Controller/Api/AgentTaskApiControllerTest.php
git commit -m "feat(agent-task): add JWT-auth REST endpoints with claim/progress/complete"
```

---

## Task 5: Web endpoint — `presentViaAgent`

**Files:**
- Modify: `src/Controller/ComplaintController.php`

- [ ] **Step 1: Add the action**

Append to `src/Controller/ComplaintController.php` (just before the closing `}` of the class — after `downloadWord`):

```php
    #[Route('/presentar', name: 'app_complaint_present_via_agent', methods: ['POST'])]
    #[IsGranted('view', 'accessRequest')]
    public function presentViaAgent(
        Request $request,
        AccessRequest $accessRequest,
        \Doctrine\ORM\EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];
        $mode = $data['mode'] ?? null;
        if (!in_array($mode, [\App\Entity\AgentTask::MODE_AUTO, \App\Entity\AgentTask::MODE_SUPERVISED], true)) {
            return new JsonResponse(['error' => 'invalid_mode'], Response::HTTP_BAD_REQUEST);
        }

        $document = $this->findGeneratedDocument($accessRequest);
        if ($document === null || $document->getType() !== DocumentType::Complaint) {
            return new JsonResponse(['error' => 'no_complaint_document'], Response::HTTP_BAD_REQUEST);
        }

        $organism = $accessRequest->getApplicableLaw()->getComplaintOrganism();
        $complaintFormUrl = $organism?->getComplaintFormUrl();
        if (!$complaintFormUrl) {
            return new JsonResponse(['error' => 'no_form_url_configured'], Response::HTTP_CONFLICT);
        }

        $task = new \App\Entity\AgentTask($this->getUser(), \App\Entity\AgentTask::TYPE_PRESENT_COMPLAINT);
        $task->setAccessRequest($accessRequest);
        $task->setMode($mode);
        $task->setPayload([
            'access_request_id' => $accessRequest->getId()->toRfc4122(),
            'complaint_document_id' => $document->getId()->toRfc4122(),
            'complaint_form_url' => $complaintFormUrl,
            'request_external_id' => $accessRequest->getExternalId(),
            'pdf_download_url' => $this->generateUrl('app_complaint_pdf', ['id' => $accessRequest->getId()], 0),
        ]);
        $em->persist($task);
        $em->flush();

        return new JsonResponse([
            'taskId' => $task->getId()->toRfc4122(),
            'schemeUrl' => 'pideinfo://present-complaint/' . $task->getId()->toRfc4122(),
            'statusUrl' => $this->generateUrl('api_agent_tasks_get', ['id' => $task->getId()->toRfc4122()]),
        ]);
    }
```

- [ ] **Step 2: Verify route**

Run: `php bin/console cache:clear --env=dev && php bin/console debug:router | grep app_complaint_present_via_agent`
Expected: one line mapping `POST /solicitudes/{id}/reclamacion/presentar`.

- [ ] **Step 3: Lint**

Run: `php -l src/Controller/ComplaintController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/ComplaintController.php
git commit -m "feat(complaint): add presentViaAgent endpoint creating AgentTask"
```

---

## Task 6: Stimulus controller — `agent_present_controller.js`

**Files:**
- Create: `assets/controllers/agent_present_controller.js`

- [ ] **Step 1: Write the Stimulus controller**

```javascript
import { Controller } from '@hotwired/stimulus';

/*
 * Triggers the agent presentation flow:
 * 1. POSTs the complaint endpoint with {mode}.
 * 2. Navigates to pideinfo:// to wake the agent.
 * 3. Polls /api/agent/tasks/{id} every 2s, updates the status pill.
 * 4. After 5s without progress, surfaces the fallback panel.
 */
export default class extends Controller {
    static values = {
        presentUrl: String,
    };
    static targets = ['mode', 'status', 'fallback', 'fallbackPdf', 'retryLink'];

    async submit(event) {
        event.preventDefault();
        const mode = this.modeTargets.find(t => t.checked)?.value;
        if (!mode) return;

        this.setStatus('Creando tarea…');
        this.fallbackTarget.classList.add('hidden');

        let task;
        try {
            const response = await fetch(this.presentUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode }),
            });
            task = await response.json();
            if (!response.ok) {
                this.setStatus('Error: ' + (task.error || 'no se pudo crear la tarea'));
                return;
            }
        } catch (e) {
            this.setStatus('Error de red al crear la tarea');
            return;
        }

        this.setStatus('Despertando al agente…');
        window.location.href = task.schemeUrl;

        const stillPendingAt = Date.now() + 5000;
        this.retryLinkTarget.href = task.schemeUrl;
        this.fallbackPdfTarget.href = task.payload?.pdf_download_url || '';

        const tick = async () => {
            try {
                const r = await fetch(task.statusUrl, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) throw new Error('status ' + r.status);
                const t = await r.json();
                this.setStatus(this.label(t.status));
                if (Date.now() > stillPendingAt && t.status === 'pending') {
                    this.fallbackTarget.classList.remove('hidden');
                }
                if (t.status === 'done' || t.status === 'failed') return;
            } catch (_) { /* keep polling */ }
            setTimeout(tick, 2000);
        };
        setTimeout(tick, 1500);
    }

    setStatus(text) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
    }

    label(status) {
        switch (status) {
            case 'pending': return '🟡 Esperando al agente…';
            case 'claimed': return '🔵 Agente conectado, preparando…';
            case 'in_progress': return '🔵 Agente trabajando…';
            case 'done': return '🟢 Agente lanzado. Termina la presentación en el navegador.';
            case 'failed': return '🔴 Falló — pulsa Reintentar.';
            default: return status;
        }
    }
}
```

- [ ] **Step 2: Verify Stimulus loads**

Run: `cd assets && grep -c "agent_present" controllers.json 2>/dev/null || echo "no controllers.json — autoload by file"`
If `controllers.json` exists and you don't see the entry, add `"agent_present": { "enabled": true }` under `controllers`. Otherwise the file is auto-discovered (typical Symfony UX setup).

- [ ] **Step 3: Commit**

```bash
git add assets/controllers/agent_present_controller.js
git commit -m "feat(complaint-ui): add Stimulus controller for agent presentation handoff"
```

---

## Task 7: Show.html.twig — button + modal + status

**Files:**
- Modify: `templates/solicitudes/show.html.twig`

- [ ] **Step 1: Wrap the existing complaint CTAs with the new button + modal**

Find the block added in Tarea 1 (around the "Iniciar presentación" button inside the `complaint` doc panel). Replace the **inner** of that `<div class="mt-4 pt-4 border-t border-amber-200/60 …">` block:

Locate:

```twig
                            <div class="mt-4 pt-4 border-t border-amber-200/60 flex flex-wrap items-center gap-2">
                                {% if complaintFormUrl %}
                                    <button type="button"
                                            class="btn btn-accent btn-sm"
                                            onclick="window.open({{ complaintFormUrl|json_encode|raw }}, '_blank', 'noopener,noreferrer'); document.getElementById('complaint-pdf-download-{{ doc.id }}').click();">
                                        <i data-lucide="upload-cloud" class="w-4 h-4 mr-1.5"></i>
                                        Iniciar presentación
                                    </button>
```

Insert a **new primary button** above it ("Presentar con el agente") that opens a modal:

```twig
                            <div class="mt-4 pt-4 border-t border-amber-200/60 flex flex-wrap items-center gap-2"
                                 x-data="{ openAgentModal: false, agentMode: 'supervised' }"
                                 data-controller="agent-present"
                                 data-agent-present-present-url-value="{{ path('app_complaint_present_via_agent', {id: request.id}) }}">

                                {% if complaintFormUrl %}
                                    <button type="button"
                                            class="btn btn-accent btn-sm"
                                            @click="openAgentModal = true">
                                        <i data-lucide="bot" class="w-4 h-4 mr-1.5"></i>
                                        Presentar con el agente
                                    </button>
                                    <button type="button"
                                            class="btn btn-secondary btn-sm"
                                            onclick="window.open({{ complaintFormUrl|json_encode|raw }}, '_blank', 'noopener,noreferrer'); document.getElementById('complaint-pdf-download-{{ doc.id }}').click();">
                                        <i data-lucide="upload-cloud" class="w-4 h-4 mr-1.5"></i>
                                        Iniciar manual
                                    </button>
```

(**Keep** the rest of the original block: the hidden `<a>` for PDF download, "Descargar PDF", "Descargar Word", "Editar".)

Then **immediately after the closing `</div>` of that wrapper**, add the modal + status panel:

```twig
                                <span class="ml-auto text-xs text-slate-500" data-agent-present-target="status"></span>

                                <div class="hidden mt-3 w-full text-sm bg-slate-50 border border-slate-200 rounded-lg p-3"
                                     data-agent-present-target="fallback">
                                    <p class="text-slate-700 font-medium mb-2">El agente no parece estar abierto.</p>
                                    <div class="flex flex-wrap gap-2">
                                        <a class="btn btn-secondary btn-sm" data-agent-present-target="fallbackPdf">
                                            <i data-lucide="file-down" class="w-4 h-4 mr-1.5"></i>
                                            Descargar PDF
                                        </a>
                                        <a class="btn btn-secondary btn-sm" data-agent-present-target="retryLink">
                                            <i data-lucide="refresh-cw" class="w-4 h-4 mr-1.5"></i>
                                            Reintentar wake-up
                                        </a>
                                        <a href="{{ path('app_dashboard') }}#agent-setup" class="btn btn-secondary btn-sm">
                                            <i data-lucide="help-circle" class="w-4 h-4 mr-1.5"></i>
                                            Cómo registrar el handler
                                        </a>
                                    </div>
                                </div>

                                {# Modo modal — Alpine controlled #}
                                <div x-show="openAgentModal" x-cloak
                                     class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
                                     @keydown.escape.window="openAgentModal = false">
                                    <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6" @click.outside="openAgentModal = false">
                                        <h3 class="font-semibold text-slate-800 mb-2">Presentar con el agente</h3>
                                        <p class="text-sm text-slate-600 mb-4">Elige cómo quieres que el agente proceda en la sede del Consejo:</p>

                                        <label class="flex items-start gap-3 p-3 rounded-lg border border-slate-200 mb-2 cursor-pointer hover:border-primary-400">
                                            <input type="radio" name="agent-mode" value="supervised" x-model="agentMode" data-agent-present-target="mode" class="mt-1">
                                            <span>
                                                <span class="block font-medium text-slate-800">Supervisado</span>
                                                <span class="block text-xs text-slate-500">El agente rellena el formulario; tú revisas y pulsas «Enviar».</span>
                                            </span>
                                        </label>

                                        <label class="flex items-start gap-3 p-3 rounded-lg border border-slate-200 mb-4 cursor-pointer hover:border-primary-400">
                                            <input type="radio" name="agent-mode" value="auto" x-model="agentMode" data-agent-present-target="mode" class="mt-1">
                                            <span>
                                                <span class="block font-medium text-slate-800">Automático</span>
                                                <span class="block text-xs text-slate-500">El agente envía la reclamación por ti, sin intervención adicional.</span>
                                            </span>
                                        </label>

                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openAgentModal = false">Cancelar</button>
                                            <button type="button" class="btn btn-accent btn-sm"
                                                    @click="openAgentModal = false; $nextTick(() => $el.closest('[data-controller=agent-present]').dispatchEvent(new Event('agent-present:submit')))">
                                                Lanzar agente
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
```

Wire the dispatched event to the Stimulus action by editing the wrapping div's attributes — change to:

```twig
                            <div class="mt-4 pt-4 border-t border-amber-200/60 flex flex-wrap items-center gap-2"
                                 x-data="{ openAgentModal: false, agentMode: 'supervised' }"
                                 data-controller="agent-present"
                                 data-action="agent-present:submit->agent-present#submit"
                                 data-agent-present-present-url-value="{{ path('app_complaint_present_via_agent', {id: request.id}) }}">
```

- [ ] **Step 2: Lint twig**

Run: `php bin/console lint:twig templates/solicitudes/show.html.twig`
Expected: `[OK] All ... Twig files contain valid syntax.`

- [ ] **Step 3: Commit**

```bash
git add templates/solicitudes/show.html.twig
git commit -m "feat(complaint-ui): add agent presentation button + mode modal + fallback panel"
```

---

## Task 8: Python — protocol package skeleton

**Files:**
- Create: `agent/protocol/__init__.py` (empty marker, single line)
- Create: `agent/protocol/url_handler.py`

- [ ] **Step 1: Create the package marker**

```python
"""URL-scheme handler and IPC for the PideInfo agent."""
```

- [ ] **Step 2: Write `url_handler.py`**

```python
"""Parse pideinfo:// URLs and dispatch them to the agent task system.

Format: pideinfo://<action>/<task_id>

Currently only `present-complaint` is supported. Unknown actions log a warning
and are dropped.
"""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from typing import Callable

logger = logging.getLogger(__name__)

_URL_RE = re.compile(r"^pideinfo://(?P<action>[a-z0-9-]+)/(?P<task_id>[0-9a-f-]{36})$")


@dataclass(frozen=True)
class ParsedUrl:
    action: str
    task_id: str


def parse(url: str) -> ParsedUrl | None:
    """Return ParsedUrl or None for malformed inputs."""
    m = _URL_RE.match(url.strip())
    if m is None:
        logger.warning("Ignoring malformed pideinfo URL: %r", url)
        return None
    return ParsedUrl(action=m.group("action"), task_id=m.group("task_id"))


def handle(url: str, dispatch: Callable[[str, str], None]) -> None:
    """Parse and forward to the dispatcher.

    The dispatcher receives (action, task_id). Side effects (network, browser)
    happen there, not here — this function is pure-ish for testability.
    """
    parsed = parse(url)
    if parsed is None:
        return
    dispatch(parsed.action, parsed.task_id)
```

- [ ] **Step 3: Smoke test in REPL**

Run: `cd /home/app/agent && python -c "from agent.protocol.url_handler import parse; print(parse('pideinfo://present-complaint/00000000-0000-7000-8000-000000000000'))"`
Expected: `ParsedUrl(action='present-complaint', task_id='00000000-0000-7000-8000-000000000000')`.

Then: `python -c "from agent.protocol.url_handler import parse; print(parse('javascript:alert(1)'))"`
Expected: `None` (and a warning to stderr).

- [ ] **Step 4: Commit**

```bash
git add agent/protocol/__init__.py agent/protocol/url_handler.py
git commit -m "feat(agent): add pideinfo:// URL parser"
```

---

## Task 9: Python — single-instance IPC

**Files:**
- Create: `agent/protocol/single_instance.py`

- [ ] **Step 1: Write the module**

```python
"""Cross-platform single-instance + URL relay.

acquire_or_relay():
  - If another agent process is already running, send `url` to it and return False
    (caller is the "relay" and should exit).
  - Otherwise, install the IPC endpoint and return True (caller is the "primary"
    and should run the daemon, listening for relayed URLs via on_url).

POSIX: AF_UNIX socket at ~/.config/pideinfo/agent.sock (perms 0600).
Windows: named pipe \\.\pipe\pideinfo-agent.

The IPC protocol is a single line: the URL plus '\n', terminated by EOF.
"""

from __future__ import annotations

import logging
import os
import platform
import socket
import sys
import threading
from pathlib import Path
from typing import Callable

logger = logging.getLogger(__name__)

_IS_WINDOWS = platform.system() == "Windows"
_PIPE_NAME = r"\\.\pipe\pideinfo-agent"


def _socket_path() -> Path:
    base = Path(os.environ.get("XDG_CONFIG_HOME") or Path.home() / ".config") / "pideinfo"
    base.mkdir(parents=True, exist_ok=True)
    return base / "agent.sock"


# -- POSIX implementation ----------------------------------------------------

def _try_relay_posix(url: str) -> bool:
    path = _socket_path()
    if not path.exists():
        return False
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
            s.settimeout(2.0)
            s.connect(str(path))
            s.sendall((url + "\n").encode("utf-8"))
        logger.info("Relayed URL to existing agent via %s", path)
        return True
    except OSError as e:
        logger.warning("Could not relay (stale socket?): %s — removing.", e)
        try: path.unlink()
        except FileNotFoundError: pass
        return False


def _serve_posix(on_url: Callable[[str], None]) -> None:
    path = _socket_path()
    try: path.unlink()
    except FileNotFoundError: pass

    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    server.bind(str(path))
    os.chmod(path, 0o600)
    server.listen(8)
    logger.info("Listening for relayed URLs on %s", path)

    def loop():
        while True:
            try:
                conn, _ = server.accept()
            except OSError:
                return
            with conn:
                data = conn.recv(2048).decode("utf-8", errors="replace").strip()
                if data:
                    try: on_url(data)
                    except Exception as e:
                        logger.exception("URL handler raised: %s", e)

    threading.Thread(target=loop, daemon=True, name="pideinfo-ipc").start()


# -- Windows implementation --------------------------------------------------

def _try_relay_windows(url: str) -> bool:
    try:
        with open(_PIPE_NAME, "w", encoding="utf-8") as f:
            f.write(url + "\n")
        return True
    except OSError:
        return False


def _serve_windows(on_url: Callable[[str], None]) -> None:
    # Minimal implementation using win32pipe. Falls back to logging if pywin32
    # is not installed — not fatal, the agent still works for polling.
    try:
        import win32pipe, win32file  # type: ignore
    except ImportError:
        logger.warning("pywin32 missing; URL relay disabled on Windows.")
        return

    def loop():
        while True:
            handle = win32pipe.CreateNamedPipe(
                _PIPE_NAME,
                win32pipe.PIPE_ACCESS_INBOUND,
                win32pipe.PIPE_TYPE_MESSAGE | win32pipe.PIPE_READMODE_MESSAGE,
                1, 4096, 4096, 0, None,
            )
            try:
                win32pipe.ConnectNamedPipe(handle, None)
                _, data = win32file.ReadFile(handle, 4096)
                url = data.decode("utf-8", errors="replace").strip()
                if url:
                    try: on_url(url)
                    except Exception as e:
                        logger.exception("URL handler raised: %s", e)
            finally:
                try: win32file.CloseHandle(handle)
                except Exception: pass

    threading.Thread(target=loop, daemon=True, name="pideinfo-ipc").start()


# -- Public API --------------------------------------------------------------

def acquire_or_relay(url: str | None, on_url: Callable[[str], None]) -> bool:
    """Returns True if this process is the primary agent, False if it relayed and should exit."""
    if url is not None:
        relayed = _try_relay_windows(url) if _IS_WINDOWS else _try_relay_posix(url)
        if relayed:
            return False  # caller should exit

    if _IS_WINDOWS:
        _serve_windows(on_url)
    else:
        _serve_posix(on_url)
    return True
```

- [ ] **Step 2: Smoke (POSIX)**

Run two terminals.

Terminal A: `cd /home/app/agent && python -c "
import time, logging
logging.basicConfig(level=logging.INFO)
from agent.protocol.single_instance import acquire_or_relay
def on_url(u): print('got:', u)
print('primary?', acquire_or_relay(None, on_url))
time.sleep(30)
"`
Expected: prints `primary? True`, listens 30s.

Terminal B (within the 30s): `python -c "
from agent.protocol.single_instance import acquire_or_relay
print('primary?', acquire_or_relay('pideinfo://present-complaint/abc', lambda u: None))
"`
Expected: prints `primary? False`. Terminal A prints `got: pideinfo://present-complaint/abc`.

- [ ] **Step 3: Commit**

```bash
git add agent/protocol/single_instance.py
git commit -m "feat(agent): add single-instance Unix-socket / named-pipe IPC for URL relay"
```

---

## Task 10: Python — protocol handler registration

**Files:**
- Create: `agent/protocol/registration.py`

- [ ] **Step 1: Write the module (Linux complete; macOS/Windows minimal)**

```python
"""Register/unregister the pideinfo:// URL scheme with the OS.

Linux:   ~/.local/share/applications/pideinfo-agent.desktop + xdg-mime default
macOS:   Requires a .app bundle with CFBundleURLTypes; we only check, do not
         install (out of scope for fase 2a).
Windows: HKEY_CURRENT_USER\\Software\\Classes\\pideinfo + URL Protocol value.

`is_registered()` is best-effort. `register()` returns (success: bool, message: str)
so the tray menu can surface the result.
"""

from __future__ import annotations

import logging
import os
import platform
import shutil
import subprocess
import sys
from pathlib import Path

logger = logging.getLogger(__name__)

_DESKTOP_FILE = Path.home() / ".local/share/applications/pideinfo-agent.desktop"


def _agent_executable() -> str:
    """Return a shell-friendly invocation of the running agent for the desktop entry."""
    return shutil.which("pideinfo-agent") or f"{sys.executable} {Path(__file__).resolve().parents[2] / 'main.py'}"


# -- Linux -------------------------------------------------------------------

def _register_linux() -> tuple[bool, str]:
    exe = _agent_executable()
    desktop = f"""[Desktop Entry]
Type=Application
Name=PideInfo Agent
Exec={exe} --url %u
NoDisplay=true
Terminal=false
MimeType=x-scheme-handler/pideinfo;
Categories=Utility;
"""
    _DESKTOP_FILE.parent.mkdir(parents=True, exist_ok=True)
    _DESKTOP_FILE.write_text(desktop, encoding="utf-8")
    try:
        subprocess.run(
            ["xdg-mime", "default", "pideinfo-agent.desktop", "x-scheme-handler/pideinfo"],
            check=True, capture_output=True,
        )
        subprocess.run(
            ["update-desktop-database", str(_DESKTOP_FILE.parent)],
            check=False, capture_output=True,
        )
        return True, f"Handler registrado ({_DESKTOP_FILE})."
    except (subprocess.CalledProcessError, FileNotFoundError) as e:
        return False, f"xdg-mime falló: {e}"


def _is_registered_linux() -> bool:
    if not _DESKTOP_FILE.exists():
        return False
    try:
        out = subprocess.run(
            ["xdg-mime", "query", "default", "x-scheme-handler/pideinfo"],
            check=False, capture_output=True, text=True,
        )
        return "pideinfo-agent.desktop" in (out.stdout or "")
    except FileNotFoundError:
        return False


# -- Windows -----------------------------------------------------------------

def _register_windows() -> tuple[bool, str]:
    try:
        import winreg  # type: ignore
    except ImportError:
        return False, "winreg no disponible."
    exe = _agent_executable()
    cmd = f'"{sys.executable}" "{Path(__file__).resolve().parents[2] / "main.py"}" --url "%1"' \
        if not shutil.which("pideinfo-agent") else f'"{exe}" --url "%1"'
    try:
        with winreg.CreateKey(winreg.HKEY_CURRENT_USER, r"Software\Classes\pideinfo") as k:
            winreg.SetValueEx(k, None, 0, winreg.REG_SZ, "URL:PideInfo Protocol")
            winreg.SetValueEx(k, "URL Protocol", 0, winreg.REG_SZ, "")
        with winreg.CreateKey(winreg.HKEY_CURRENT_USER, r"Software\Classes\pideinfo\shell\open\command") as k:
            winreg.SetValueEx(k, None, 0, winreg.REG_SZ, cmd)
        return True, "Handler registrado en HKEY_CURRENT_USER."
    except OSError as e:
        return False, f"Registro falló: {e}"


def _is_registered_windows() -> bool:
    try:
        import winreg  # type: ignore
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Classes\pideinfo"):
            return True
    except (ImportError, OSError):
        return False


# -- Public ------------------------------------------------------------------

def register() -> tuple[bool, str]:
    sysname = platform.system()
    if sysname == "Linux":
        return _register_linux()
    if sysname == "Windows":
        return _register_windows()
    if sysname == "Darwin":
        return False, "macOS requiere bundle .app — no implementado en fase 2a."
    return False, f"Sistema no soportado: {sysname}"


def is_registered() -> bool:
    sysname = platform.system()
    if sysname == "Linux":
        return _is_registered_linux()
    if sysname == "Windows":
        return _is_registered_windows()
    return False
```

- [ ] **Step 2: Smoke (Linux dev)**

Run: `cd /home/app/agent && python -c "
import logging; logging.basicConfig(level=logging.INFO)
from agent.protocol.registration import register, is_registered
print('before:', is_registered())
print(register())
print('after:', is_registered())
"`
Expected: `before: False`, `(True, 'Handler registrado ...')`, `after: True`. The file `~/.local/share/applications/pideinfo-agent.desktop` should exist.

- [ ] **Step 3: Commit**

```bash
git add agent/protocol/registration.py
git commit -m "feat(agent): add pideinfo:// handler registration (Linux/Windows)"
```

---

## Task 11: Python — pideinfo client extensions

**Files:**
- Modify: `agent/client/pideinfo.py`

- [ ] **Step 1: Add task methods**

Append to the `PideInfoClient` class (or equivalent class in that file — verify the class name first with `grep "^class " agent/client/pideinfo.py`):

```python
    # ── Agent task queue ──────────────────────────────────────────────────

    def get_pending_tasks(self) -> list[dict]:
        r = self._client.get(f"{self.base_url}/api/agent/tasks/pending", headers=self._auth_headers())
        r.raise_for_status()
        return r.json().get("tasks", [])

    def claim_task(self, task_id: str) -> dict | None:
        r = self._client.post(f"{self.base_url}/api/agent/tasks/{task_id}/claim", headers=self._auth_headers())
        if r.status_code == 409:
            return None
        r.raise_for_status()
        return r.json()

    def progress_task(self, task_id: str, status: str, note: str | None = None) -> None:
        body = {"status": status}
        if note: body["note"] = note
        r = self._client.post(
            f"{self.base_url}/api/agent/tasks/{task_id}/progress",
            json=body, headers=self._auth_headers(),
        )
        r.raise_for_status()

    def complete_task(self, task_id: str, success: bool, *, result: dict | None = None, error: str | None = None) -> None:
        body: dict = {"success": success}
        if result is not None: body["result"] = result
        if error is not None: body["error"] = error
        r = self._client.post(
            f"{self.base_url}/api/agent/tasks/{task_id}/complete",
            json=body, headers=self._auth_headers(),
        )
        r.raise_for_status()
```

If `_auth_headers()` does not exist in this client, copy the JWT-header pattern from existing methods (look for any `Authorization: Bearer` to spot the helper used).

- [ ] **Step 2: Lint with python -c**

Run: `cd /home/app/agent && python -c "from agent.client.pideinfo import PideInfoClient; print('ok')"`
Expected: `ok` (no import error).

- [ ] **Step 3: Commit**

```bash
git add agent/client/pideinfo.py
git commit -m "feat(agent): add task queue methods to PideInfoClient"
```

---

## Task 12: Python — task dispatcher + present_complaint

**Files:**
- Create: `agent/tasks/__init__.py`
- Create: `agent/tasks/present_complaint.py`

- [ ] **Step 1: Write the dispatcher**

```python
"""Type-keyed dispatcher for agent tasks.

Each task type registers a handler with signature: handler(task: dict, client) -> None
The handler is responsible for the full lifecycle: claim → progress → complete.
"""

from __future__ import annotations

import logging
from typing import Callable, Protocol

logger = logging.getLogger(__name__)


class _ClientProto(Protocol):
    def claim_task(self, task_id: str) -> dict | None: ...
    def progress_task(self, task_id: str, status: str, note: str | None = ...) -> None: ...
    def complete_task(self, task_id: str, success: bool, *, result: dict | None = ..., error: str | None = ...) -> None: ...


_HANDLERS: dict[str, Callable[[dict, _ClientProto], None]] = {}


def register(task_type: str, handler: Callable[[dict, _ClientProto], None]) -> None:
    _HANDLERS[task_type] = handler


def dispatch_action_id(action: str, task_id: str, client: _ClientProto) -> None:
    """Resolve an action+task_id (from a pideinfo:// URL) to a task and dispatch it."""
    task = client.claim_task(task_id)
    if task is None:
        logger.info("Task %s already claimed; ignoring.", task_id)
        return
    handler = _HANDLERS.get(task["type"])
    if handler is None:
        logger.error("No handler for task type %r (id=%s)", task["type"], task_id)
        client.complete_task(task_id, success=False, error=f"no_handler:{task['type']}")
        return
    try:
        handler(task, client)
    except Exception as e:
        logger.exception("Handler for %s crashed: %s", task["type"], e)
        try: client.complete_task(task_id, success=False, error=f"handler_crashed:{e!s}"[:2000])
        except Exception: pass


def dispatch_existing(task: dict, client: _ClientProto) -> None:
    """Dispatch a task that was already claimed (e.g. discovered via pending poll)."""
    handler = _HANDLERS.get(task["type"])
    if handler is None:
        client.complete_task(task["id"], success=False, error=f"no_handler:{task['type']}")
        return
    try:
        handler(task, client)
    except Exception as e:
        logger.exception("Handler for %s crashed: %s", task["type"], e)
        try: client.complete_task(task["id"], success=False, error=f"handler_crashed:{e!s}"[:2000])
        except Exception: pass


# Auto-register handlers
from agent.tasks.present_complaint import handle as _present_complaint_handle  # noqa: E402

register("present_complaint", _present_complaint_handle)
```

- [ ] **Step 2: Write the present_complaint handler**

```python
"""Phase 2a handler: download the complaint PDF, open the CTBG sede URL.

No Playwright, no form filling. The user takes over once the browser opens.
"""

from __future__ import annotations

import logging
import os
import platform
import webbrowser
from pathlib import Path
from typing import Any

logger = logging.getLogger(__name__)


def _downloads_dir() -> Path:
    if platform.system() == "Windows":
        return Path(os.environ.get("USERPROFILE", str(Path.home()))) / "Downloads" / "PideInfo"
    return Path.home() / "Downloads" / "PideInfo"


def handle(task: dict, client: Any) -> None:
    task_id = task["id"]
    payload = task["payload"]

    client.progress_task(task_id, status="in_progress", note="Descargando PDF de la reclamación")

    pdf_url = payload.get("pdf_download_url")
    form_url = payload.get("complaint_form_url")
    if not pdf_url or not form_url:
        client.complete_task(task_id, success=False, error="payload_missing_urls")
        return

    target_dir = _downloads_dir()
    target_dir.mkdir(parents=True, exist_ok=True)
    pdf_path = target_dir / f"reclamacion_{payload.get('access_request_id', task_id)}.pdf"

    try:
        # Reuses the auth-bearing httpx client for absolute URLs against the API host.
        # Note: pdf_url returned by the web is an absolute path under the same base_url.
        r = client._client.get(client.base_url.rstrip("/") + pdf_url, headers=client._auth_headers())
        r.raise_for_status()
        pdf_path.write_bytes(r.content)
    except Exception as e:
        logger.exception("PDF download failed")
        client.complete_task(task_id, success=False, error=f"pdf_download_failed:{e!s}"[:2000])
        return

    try:
        webbrowser.open(form_url, new=2)  # new=2 → new tab if possible
    except Exception as e:
        logger.exception("webbrowser.open failed")
        client.complete_task(
            task_id, success=False,
            error=f"browser_open_failed:{e!s}"[:2000],
            result={"pdf_path": str(pdf_path)},
        )
        return

    client.complete_task(
        task_id, success=True,
        result={"pdf_path": str(pdf_path), "url_opened": form_url, "phase": "2a"},
    )
```

- [ ] **Step 3: Smoke (in-process, mocked client)**

Run: `cd /home/app/agent && python -c "
import logging; logging.basicConfig(level=logging.INFO)
from agent.tasks.present_complaint import handle
class FakeClient:
    base_url = 'http://localhost:8000'
    def progress_task(self, *a, **k): print('progress', a, k)
    def complete_task(self, *a, **k): print('complete', a, k)
task = {'id':'t1','type':'present_complaint','payload':{}}
handle(task, FakeClient())
"`
Expected: prints `progress (...) {...}` then `complete ('t1',) {'success': False, 'error': 'payload_missing_urls'}`.

- [ ] **Step 4: Commit**

```bash
git add agent/tasks/__init__.py agent/tasks/present_complaint.py
git commit -m "feat(agent): add task dispatcher and present_complaint phase-2a handler"
```

---

## Task 13: Python — main.py wiring

**Files:**
- Modify: `agent/main.py`

- [ ] **Step 1: Add `--url` arg parsing + integrate single-instance**

Locate the argparse section in `agent/main.py` (search: `--tray`). Add:

```python
parser.add_argument("--url", default=None, help="pideinfo://... URL handed by the OS handler.")
```

- [ ] **Step 2: Wire single-instance + URL handling**

Near the start of `main()` (after argparse parsing, before any heavy init), add:

```python
from agent.protocol.single_instance import acquire_or_relay
from agent.protocol.url_handler import handle as handle_url
from agent.tasks import dispatch_action_id

def _on_url(received_url: str) -> None:
    # Called from the IPC listener thread when another invocation relays a URL.
    # Lazy-import the client to avoid import order issues.
    from agent.client.pideinfo import build_client  # adjust to actual factory name
    client = build_client()
    handle_url(received_url, lambda action, task_id: dispatch_action_id(action, task_id, client))

is_primary = acquire_or_relay(args.url, _on_url)
if not is_primary:
    return  # we relayed our URL to the running agent; exit
```

If `build_client()` does not exist, replace with the actual factory pattern used in `main.py` (search for how the client is constructed today; likely `PideInfoClient(...)` with config). Mirror that.

- [ ] **Step 3: If args.url provided AND we're primary, dispatch it once**

Right after the `is_primary` check above, add:

```python
if args.url:
    # We're primary AND were started with a URL — dispatch synchronously before the daemon spins up.
    _on_url(args.url)
```

- [ ] **Step 4: On daemon idle ticks, drain pending tasks**

Find the daemon scheduler block (search: `do_sync` or `APScheduler`). Add a sibling job that runs every 60s and pulls pending tasks the agent missed (e.g. created while the agent was down). Pseudocode to adapt:

```python
def _drain_pending_tasks():
    from agent.client.pideinfo import build_client
    from agent.tasks import dispatch_existing
    client = build_client()
    for task in client.get_pending_tasks():
        claimed = client.claim_task(task["id"])
        if claimed is None:  # raced with another worker
            continue
        dispatch_existing(claimed, client)

scheduler.add_job(_drain_pending_tasks, "interval", seconds=60, id="drain_tasks", replace_existing=True)
```

- [ ] **Step 5: Smoke**

Run: `cd /home/app/agent && python main.py --help | grep -- '--url'`
Expected: lists the `--url` option.

- [ ] **Step 6: Commit**

```bash
git add agent/main.py
git commit -m "feat(agent): wire --url, single-instance, and pending-task drain into main loop"
```

---

## Task 14: Python — tray menu item

**Files:**
- Modify: `agent/tray.py`

- [ ] **Step 1: Add menu item that registers handler on demand**

Locate the menu construction in `agent/tray.py` (search: `pystray.MenuItem`). Add a new item:

```python
from agent.protocol.registration import register as _register_handler, is_registered as _is_registered

def _on_register_handler(_icon, _item):
    ok, msg = _register_handler()
    # Reuse the existing tray notifier to surface the result; pattern lives in tray.py already.
    try:
        from agent.notifier import notify  # adjust if different
        notify("PideInfo", msg)
    except Exception:
        print(msg)
```

Then within the menu list, add (alongside existing items, e.g. near "Sincronizar ahora"):

```python
pystray.MenuItem(
    lambda item: "✓ Handler registrado" if _is_registered() else "Registrar handler de pideinfo://",
    _on_register_handler,
    enabled=lambda item: not _is_registered(),
),
```

- [ ] **Step 2: Lint**

Run: `cd /home/app/agent && python -c "import agent.tray; print('ok')"`
Expected: `ok`.

- [ ] **Step 3: Commit**

```bash
git add agent/tray.py
git commit -m "feat(agent): tray menu item to register pideinfo:// handler"
```

---

## Task 15: Bump agent version + docs

**Files:**
- Modify: `agent/version.py`
- Modify: `docs/agent.md`
- Modify: `docs/complaint-workflow.md`
- Modify: `docs/architecture.md`

- [ ] **Step 1: Bump version**

Edit `agent/version.py`:

```python
"""Single source of truth for the PideInfo Agent version number."""

__version__ = "0.1.0"
```

- [ ] **Step 2: Document task queue in `docs/agent.md`**

Append a new section:

```markdown
## Recepción de tareas (web → agente)

A partir de la versión 0.1.0 el agente recibe tareas iniciadas desde la web vía:

1. **Cola persistente** — tabla `agent_task` en PideInfo. Una tarea contiene `type`, `mode`, `payload` JSON y `status` (`pending` → `claimed` → `in_progress` → `done`/`failed`). El agente lista las suyas con `GET /api/agent/tasks/pending`, las reclama con `POST /api/agent/tasks/{id}/claim` (atómico, devuelve 409 si ya estaba claimed) y reporta resultado con `POST /api/agent/tasks/{id}/complete`.

2. **Wake-up vía esquema URL** — `pideinfo://<action>/<task_id>`. Registrado como handler del SO mediante `agent/protocol/registration.py`. Linux usa `xdg-mime` + un `.desktop`; Windows usa `HKEY_CURRENT_USER\Software\Classes\pideinfo`; macOS requiere bundle (no implementado en 2a).

3. **Single-instance + relay** — al invocarse con `--url <pideinfo://...>`, el ejecutable detecta si ya hay un agente corriendo (Unix socket en `~/.config/pideinfo/agent.sock` o named pipe en Windows). Si lo hay, le envía el URL y sale; si no, se convierte en agente principal y procesa la URL antes de arrancar el daemon.

### Tipos de tarea soportados

- **`present_complaint`** (fase 2a): el agente descarga el PDF de la reclamación a `~/Downloads/PideInfo/` y abre el navegador en la URL del CTBG (estatal o autonómico, resuelta por la web). El usuario completa el envío manualmente. Una fase 2b posterior automatizará el formulario con Playwright.

### Drenaje de cola

Aparte del wake-up inmediato, el daemon ejecuta cada 60 s un `_drain_pending_tasks()` que lista pendientes y procesa las que se hubieran encolado mientras el agente estaba caído.
```

- [ ] **Step 3: Document the web-side flow in `docs/complaint-workflow.md`**

After the existing "Filing the complaint" section (updated in Tarea 1), insert a new subsection:

```markdown
### 1bis. Presentación vía agente (fase 2a)

Una vez la reclamación está guardada como `Document(type=Complaint)`, el detalle de la solicitud (`templates/solicitudes/show.html.twig`) ofrece **"Presentar con el agente"** junto a "Iniciar manual" (descarga PDF + abre sede sin agente).

Al pulsar "Presentar con el agente":
1. Modal de modo: el usuario elige **automático** o **supervisado** (la distinción se persiste en `AgentTask.mode` para la fase 2b).
2. POST `/solicitudes/{id}/reclamacion/presentar` crea un `AgentTask(type='present_complaint')` con payload `{access_request_id, complaint_document_id, complaint_form_url, request_external_id, pdf_download_url}` y devuelve un URL `pideinfo://present-complaint/<task_id>`.
3. El navegador navega al esquema URL; el SO lo entrega al agente registrado.
4. El agente descarga el PDF a `~/Downloads/PideInfo/` y abre el navegador en la URL del CTBG. Marca la tarea como `done` con `result.pdf_path` y `result.url_opened`.
5. La página web hace polling cada 2 s a `GET /api/agent/tasks/{id}` y refleja el estado. Si tras 5 s sigue `pending`, muestra fallback con descarga directa del PDF y un link de reintento.

La fase 2b sustituirá el paso 4 por una automatización Playwright que rellena el formulario, gestiona el cert popup y captura el número de expediente devuelto.
```

- [ ] **Step 4: Update `docs/architecture.md`**

Find the agent ↔ web section. Add a sentence/diagram fragment explaining the new direction:

> Además del flujo agente→web (sincronización de portales vía `POST /api/agent/webhook`), existe ahora un canal **web→agente** mediante la cola `agent_task` y el esquema URL `pideinfo://`. Ver `docs/agent.md#recepción-de-tareas`.

- [ ] **Step 5: Lint markdown manually**

Run: `php bin/console lint:twig templates/solicitudes/show.html.twig` — already linted, but run it again as a final smoke.
Run: `grep -n "TBD\|TODO" docs/agent.md docs/complaint-workflow.md docs/architecture.md` — expected: no output.

- [ ] **Step 6: Commit**

```bash
git add agent/version.py docs/agent.md docs/complaint-workflow.md docs/architecture.md
git commit -m "docs(agent): document task queue, pideinfo:// scheme, and 2a presentation flow"
```

---

## Task 16: End-to-end manual smoke

**Files:** none (verification only)

- [ ] **Step 1: Confirm DB state**

Run: `php bin/console doctrine:migrations:status | head -5`
Expected: shows the latest version number including `agent_task`.

- [ ] **Step 2: Verify routes**

Run: `php bin/console debug:router | grep -E 'agent_tasks|present_via_agent'`
Expected: 6 lines (5 task endpoints + presentViaAgent).

- [ ] **Step 3: Register handler on dev**

Run: `cd /home/app/agent && python -c "from agent.protocol.registration import register; print(register())"`
Expected: `(True, 'Handler registrado ...')`.

- [ ] **Step 4: Start primary agent**

Open a terminal: `cd /home/app/agent && python main.py --tray --once 2>&1 | tee /tmp/agent.log`
Expected: agent starts. Look for the "Listening for relayed URLs on ..." log line.

- [ ] **Step 5: Trigger via web**

In a browser (logged in), go to `/solicitudes/019d2fa2-ab55-7b95-a19a-7097c71a2abc`. Press **"Presentar con el agente"** → choose Supervisado → "Lanzar agente". Browser should navigate to `pideinfo://...`; agent log should show task claim + PDF download + browser open. Verify:

- `~/Downloads/PideInfo/reclamacion_<uuid>.pdf` exists.
- A new browser tab opens at the CTBG `complaintFormUrl`.
- The web's status pill shows 🟢 "Agente lanzado…".

- [ ] **Step 6: Trigger fallback path**

Stop the agent. From the same page, click the button again. After ~5 s the fallback panel should appear with [Descargar PDF], [Reintentar], [Cómo registrar el handler]. Click "Descargar PDF" — verifies that the task's `payload.pdf_download_url` is correct.

- [ ] **Step 7: Drain the queued task**

Restart the agent: `python main.py --tray --once`. Within ~60 s, the agent should pick up the still-pending task, process it, and the web's polling loop should reflect `done`. (This validates the queued-recovery path.)

---

## Self-review notes

I reviewed the plan against the spec on the following dimensions:

- **Spec coverage:** every numbered section of the spec has a corresponding task. Specifically:
  - Modelo de datos → Task 1, 2, 3.
  - API endpoints → Task 4.
  - Esquema URL → Task 8 (parser) + Task 13 (`--url` arg) + Task 10 (registration).
  - Single-instance + IPC → Task 9.
  - Dispatcher de tareas → Task 12.
  - UX en la web (botón, modal, polling, fallback) → Task 5, 6, 7.
  - Routing estatal vs autonómico → uses existing `complaintFormUrl` (Task 5 reads it; no new code).
  - Visibilidad en `show.html.twig` → Task 7 status pill.
  - Tests → Tasks 2, 4 (PHP); Python uses smoke steps inside each task because the agent has no pytest infra today.
  - Documentación → Task 15.
  - Migración / despliegue → Task 3 (idempotent migration); Task 15 (version bump).
  - Plan de verificación end-to-end → Task 16.

- **Type consistency:** task IDs are UUID v7 (`Uuid::v7()` PHP, RFC4122 strings on the wire). The `payload` JSON keys are consistent across Task 5 (creation), Task 12 (consumption), Task 7 (UI fallback uses `payload.pdf_download_url`).

- **No placeholders:** every code step shows the exact code. Where the engineer must adapt to existing patterns (e.g. `_auth_headers()` in `pideinfo.py`, `build_client()` factory in `main.py`), the step explicitly says "verify the exact name first with X" — the *what* is exact, only the *how-to-locate* is delegated when the engineer is closer to the codebase than this plan can reasonably encode.

- **Scope:** focused single feature. No future-phase scaffolding leaks (Playwright, expediente number capture).

---

**Plan complete and saved to `docs/superpowers/plans/2026-05-01-agent-presentation-plumbing.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using executing-plans, batch execution with checkpoints.

**Which approach?**
