# HearingProcess Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar el plazo de un trámite de audiencia (documento tipo `audiencia` con `hearing_days`/`hearing_days_type`) como entidad `HearingProcess` asociada a la reclamación, y mostrarlo en timeline, dossier-notice, zona Plazos del detalle y box de Plazos de la home.

**Architecture:** Un servicio `HearingProcessManager` encapsula la creación idempotente del `HearingProcess` (cálculo de fechas vía `DeadlineCalculator`, entrada en `DeadlineHistory`); los dos handlers de documentos (single y batch) lo invocan desde su caso `Audiencia`. La UI lee de `AccessRequestComplaint::getActiveHearingProcess()` y de un query del repositorio para las alertas.

**Tech Stack:** Symfony 7, Doctrine ORM (Postgres), Twig + Live Components, PHPUnit 11.

**⚠️ REGLA DEL REPO (CLAUDE.md):** NO hacer `git commit` ni `git push` en ningún paso. David confirma los commits. Los pasos de "commit" habituales se sustituyen por verificación (`php -l`, `phpunit`, `lint:container`).

**Contexto previo importante:**
- El prompt de Langfuse `pideinfo-document-analyze-single` **v5** (labels production/staging/latest) YA devuelve `hearing_days` y `hearing_days_type` y YA incluye `audiencia` como documentType. El bundled local está desfasado respecto a esa versión → hay que fusionar (sin borrar nada del bundled actual).
- El prompt `pideinfo-document-analyze-multi` v2 de Langfuse NO tiene hearing_days ni audiencia como valor → añadirlos al bundled; David decidirá cuándo sincronizar a Langfuse.
- Los tests que requieren BD (`KernelTestCase`) fallan en este entorno (error pre-existente de `test.service_container`). Todos los tests nuevos deben ser **unit tests puros** (`PHPUnit\Framework\TestCase`).

---

### Task 1: `DeadlineCalculator::calculateHearingDeadline()`

**Files:**
- Modify: `src/Service/AccessRequest/DeadlineCalculator.php`
- Test: `tests/Service/AccessRequest/DeadlineCalculatorTest.php` (create)

- [ ] **Step 1.1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest;

use App\Service\AccessRequest\DeadlineCalculator;
use PHPUnit\Framework\TestCase;

final class DeadlineCalculatorTest extends TestCase
{
    private DeadlineCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DeadlineCalculator();
    }

    public function testHearingDeadlineCalendarDays(): void
    {
        // Notificado el lunes 2026-06-01, 10 días naturales: cuenta del 2 al 11.
        $end = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-01'), 10, 'calendar',
        );

        $this->assertSame('2026-06-11', $end->format('Y-m-d'));
    }

    public function testHearingDeadlineBusinessDaysSkipsWeekends(): void
    {
        // Notificado el viernes 2026-06-05, 10 días hábiles.
        // Cuenta desde el lunes 8: 8,9,10,11,12 (5) + 15,16,17,18,19 (10) → 2026-06-19.
        $end = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-05'), 10, 'business',
        );

        $this->assertSame('2026-06-19', $end->format('Y-m-d'));
    }

    public function testHearingDeadlineBusinessDaysSkipsNationalHolidays(): void
    {
        // Notificado el 2026-12-04 (viernes), 3 días hábiles.
        // Sábado 5 y domingo 6 fuera; lunes 7 cuenta (1); martes 8 festivo
        // (Inmaculada); miércoles 9 (2); jueves 10 (3) → 2026-12-10.
        $end = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-12-04'), 3, 'business',
        );

        $this->assertSame('2026-12-10', $end->format('Y-m-d'));
    }

    public function testHearingDeadlineUnknownTypeFallsBackToBusiness(): void
    {
        $business = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-05'), 10, 'business',
        );
        $unknown = $this->calculator->calculateHearingDeadline(
            new \DateTimeImmutable('2026-06-05'), 10, 'lo-que-sea',
        );

        $this->assertSame($business->format('Y-m-d'), $unknown->format('Y-m-d'));
    }
}
```

- [ ] **Step 1.2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit tests/Service/AccessRequest/DeadlineCalculatorTest.php`
Expected: ERROR "Call to undefined method ... calculateHearingDeadline()"

- [ ] **Step 1.3: Implement the method**

Add to `src/Service/AccessRequest/DeadlineCalculator.php` (after `calculateComplaintDeadline()`):

```php
    /**
     * Deadline of a hearing process (trámite de audiencia). The legal count
     * starts the day AFTER the notification (Ley 39/2015 art. 30.3):
     * business days skip weekends + national holidays; calendar days are
     * plain natural days, so the end lands on documentDate + N days.
     */
    public function calculateHearingDeadline(\DateTimeImmutable $documentDate, int $days, string $daysType): \DateTimeImmutable
    {
        return match ($daysType) {
            'calendar' => $documentDate->modify("+{$days} days"),
            // 'business' and any unknown value: plazos en días se entienden
            // hábiles salvo indicación expresa (Ley 39/2015 art. 30.2).
            default => $this->addBusinessDays($documentDate, $days),
        };
    }
```

Nota: `addBusinessDays()` ya empieza a contar en `+1 day`, por lo que el "día siguiente" queda cubierto. Para naturales, `documentDate + N` equivale a contar N días empezando el día siguiente.

- [ ] **Step 1.4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit tests/Service/AccessRequest/DeadlineCalculatorTest.php`
Expected: OK (4 tests)

---

### Task 2: Entidad `HearingProcess`, relación, repositorio, `TYPE_HEARING`, migración

**Files:**
- Create: `src/Entity/HearingProcess.php`
- Create: `src/Repository/HearingProcessRepository.php`
- Modify: `src/Entity/AccessRequestComplaint.php` (colección + helper)
- Modify: `src/Entity/DeadlineHistory.php` (constante + label)
- Create: `migrations/Version20260602120000.php`
- Test: `tests/Entity/HearingProcessTest.php` (create)

- [ ] **Step 2.1: Write the failing entity tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AccessRequestComplaint;
use App\Entity\HearingProcess;
use PHPUnit\Framework\TestCase;

final class HearingProcessTest extends TestCase
{
    public function testIsActiveWhenEndDateIsTodayOrFuture(): void
    {
        $active = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-2 days'))
            ->setEndDate(new \DateTimeImmutable('today'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $expired = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-20 days'))
            ->setEndDate(new \DateTimeImmutable('-1 day'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $this->assertTrue($active->isActive());
        $this->assertFalse($expired->isActive());
    }

    public function testDaysUntilEnd(): void
    {
        $hearing = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('+5 days'))
            ->setHearingDays(5)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_CALENDAR);

        $this->assertSame(5, $hearing->getDaysUntilEnd());
    }

    public function testComplaintActiveHearingProcessPicksLatestNonExpired(): void
    {
        $complaint = new AccessRequestComplaint();

        $expired = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-30 days'))
            ->setEndDate(new \DateTimeImmutable('-10 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);
        $activeShort = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('+3 days'))
            ->setHearingDays(3)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);
        $activeLong = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('today'))
            ->setEndDate(new \DateTimeImmutable('+10 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $complaint->addHearingProcess($expired);
        $complaint->addHearingProcess($activeShort);
        $complaint->addHearingProcess($activeLong);

        $this->assertSame($activeLong, $complaint->getActiveHearingProcess());
        // El más relevante para mostrar: el activo; si no hay activos, el último vencido.
        $this->assertSame($activeLong, $complaint->getLatestHearingProcess());
    }

    public function testComplaintLatestHearingProcessWhenAllExpired(): void
    {
        $complaint = new AccessRequestComplaint();

        $older = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-40 days'))
            ->setEndDate(new \DateTimeImmutable('-30 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);
        $newer = (new HearingProcess())
            ->setStartDate(new \DateTimeImmutable('-20 days'))
            ->setEndDate(new \DateTimeImmutable('-5 days'))
            ->setHearingDays(10)
            ->setHearingDaysType(HearingProcess::DAYS_TYPE_BUSINESS);

        $complaint->addHearingProcess($older);
        $complaint->addHearingProcess($newer);

        $this->assertNull($complaint->getActiveHearingProcess());
        $this->assertSame($newer, $complaint->getLatestHearingProcess());
    }
}
```

- [ ] **Step 2.2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit tests/Entity/HearingProcessTest.php`
Expected: ERROR "Class App\Entity\HearingProcess not found"

- [ ] **Step 2.3: Create the entity**

`src/Entity/HearingProcess.php`:

```php
<?php

namespace App\Entity;

use App\Repository\HearingProcessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Trámite de audiencia abierto por el organismo de transparencia dentro de una
 * reclamación: ventana de N días (hábiles o naturales) para que el reclamante
 * presente alegaciones. Se crea al procesar un documento tipo `audiencia` que
 * trae hearing_days en su análisis.
 */
#[ORM\Entity(repositoryClass: HearingProcessRepository::class)]
#[ORM\Table(name: 'hearing_process')]
#[ORM\Index(columns: ['complaint_id', 'end_date'], name: 'idx_hearing_complaint_end')]
class HearingProcess
{
    public const DAYS_TYPE_BUSINESS = 'business';
    public const DAYS_TYPE_CALENDAR = 'calendar';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: AccessRequestComplaint::class, inversedBy: 'hearingProcesses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AccessRequestComplaint $complaint;

    /** Documento que abrió el trámite — clave de idempotencia al reprocesar. */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $triggerDocument = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $hearingDays;

    #[ORM\Column(length: 16)]
    private string $hearingDaysType = self::DAYS_TYPE_BUSINESS;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getComplaint(): AccessRequestComplaint
    {
        return $this->complaint;
    }

    public function setComplaint(AccessRequestComplaint $complaint): static
    {
        $this->complaint = $complaint;
        return $this;
    }

    public function getTriggerDocument(): ?Document
    {
        return $this->triggerDocument;
    }

    public function setTriggerDocument(?Document $triggerDocument): static
    {
        $this->triggerDocument = $triggerDocument;
        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getHearingDays(): int
    {
        return $this->hearingDays;
    }

    public function setHearingDays(int $hearingDays): static
    {
        $this->hearingDays = $hearingDays;
        return $this;
    }

    public function getHearingDaysType(): string
    {
        return $this->hearingDaysType;
    }

    public function setHearingDaysType(string $hearingDaysType): static
    {
        $this->hearingDaysType = $hearingDaysType;
        return $this;
    }

    public function getHearingDaysTypeLabel(): string
    {
        return $this->hearingDaysType === self::DAYS_TYPE_CALENDAR ? 'días naturales' : 'días hábiles';
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** El plazo sigue abierto (la fecha límite es hoy o futura). */
    public function isActive(): bool
    {
        return $this->endDate >= new \DateTimeImmutable('today');
    }

    public function getDaysUntilEnd(): int
    {
        $today = new \DateTimeImmutable('today');
        $interval = $today->diff($this->endDate);
        return $interval->invert ? -$interval->days : $interval->days;
    }
}
```

- [ ] **Step 2.4: Add the collection + helpers to `AccessRequestComplaint`**

In `src/Entity/AccessRequestComplaint.php`:

Add imports after the existing ones (`use Doctrine\ORM\Mapping as ORM;` block):

```php
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
```

Add the property after `$filedAt` (line ~87):

```php
    /** @var Collection<int, HearingProcess> */
    #[ORM\OneToMany(mappedBy: 'complaint', targetEntity: HearingProcess::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['endDate' => 'DESC'])]
    private Collection $hearingProcesses;
```

In `__construct()` add:

```php
        $this->hearingProcesses = new ArrayCollection();
```

Add methods at the end of the class (after `getDaysUntilComplianceDeadline()`):

```php
    /** @return Collection<int, HearingProcess> */
    public function getHearingProcesses(): Collection
    {
        return $this->hearingProcesses;
    }

    public function addHearingProcess(HearingProcess $hearingProcess): static
    {
        if (!$this->hearingProcesses->contains($hearingProcess)) {
            $this->hearingProcesses->add($hearingProcess);
            $hearingProcess->setComplaint($this);
        }
        return $this;
    }

    /**
     * Trámite de audiencia vivo: el de fecha límite más lejana entre los no
     * vencidos. Null cuando no hay ninguno abierto.
     */
    public function getActiveHearingProcess(): ?HearingProcess
    {
        $active = null;
        foreach ($this->hearingProcesses as $hearing) {
            if (!$hearing->isActive()) {
                continue;
            }
            if ($active === null || $hearing->getEndDate() > $active->getEndDate()) {
                $active = $hearing;
            }
        }
        return $active;
    }

    /**
     * El trámite más relevante para mostrar: el activo o, si todos vencieron,
     * el de fecha límite más reciente.
     */
    public function getLatestHearingProcess(): ?HearingProcess
    {
        $active = $this->getActiveHearingProcess();
        if ($active !== null) {
            return $active;
        }

        $latest = null;
        foreach ($this->hearingProcesses as $hearing) {
            if ($latest === null || $hearing->getEndDate() > $latest->getEndDate()) {
                $latest = $hearing;
            }
        }
        return $latest;
    }

    /** Idempotencia: trámite ya registrado para este documento, si existe. */
    public function findHearingProcessByTriggerDocument(Document $document): ?HearingProcess
    {
        foreach ($this->hearingProcesses as $hearing) {
            $trigger = $hearing->getTriggerDocument();
            if ($trigger !== null && $trigger->getId()->equals($document->getId())) {
                return $hearing;
            }
        }
        return null;
    }
```

- [ ] **Step 2.5: Add `TYPE_HEARING` to `DeadlineHistory`**

In `src/Entity/DeadlineHistory.php`, after `TYPE_THIRD_PARTY_ALLEGATIONS`:

```php
    public const TYPE_HEARING = 'hearing';
```

And in `getDeadlineTypeLabel()` add the match arm before `default`:

```php
            self::TYPE_HEARING => 'Plazo de alegaciones (audiencia)',
```

- [ ] **Step 2.6: Create the repository**

`src/Repository/HearingProcessRepository.php`:

```php
<?php

namespace App\Repository;

use App\Entity\HearingProcess;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HearingProcess>
 */
class HearingProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HearingProcess::class);
    }

    /**
     * Hearing processes del usuario cuyo plazo vence dentro de N días o ya ha
     * vencido — para la box de Plazos de la home. Incluye los de solicitudes
     * de la organización del usuario, como el resto de fuentes de alertas.
     *
     * @return HearingProcess[]
     */
    public function findApproachingByUser(User $user, int $daysAhead = 7): array
    {
        $deadline = (new \DateTimeImmutable('today'))->modify("+{$daysAhead} days");

        $qb = $this->createQueryBuilder('hp')
            ->join('hp.complaint', 'c')
            ->join('c.accessRequest', 'ar')
            ->join('ar.user', 'u')
            ->where('hp.endDate <= :deadline')
            ->andWhere('u.email = :email')
            ->setParameter('deadline', $deadline)
            ->setParameter('email', $user->getEmail())
            ->orderBy('hp.endDate', 'ASC');

        if ($user->getOrganization() !== null) {
            $qb->orWhere('ar.organization = :organization AND hp.endDate <= :deadline')
               ->setParameter('organization', $user->getOrganization());
        }

        return $qb->getQuery()->getResult();
    }
}
```

- [ ] **Step 2.7: Create the idempotent migration**

`migrations/Version20260602120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabla hearing_process: trámites de audiencia de una reclamación, con su
 * ventana de alegaciones (start_date/end_date) calculada a partir de
 * hearing_days + hearing_days_type extraídos del documento que lo abre.
 *
 * Idempotente: CREATE TABLE/INDEX IF NOT EXISTS, constraints envueltas en
 * bloques DO $$ ... EXCEPTION WHEN duplicate_object.
 */
final class Version20260602120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create hearing_process table (trámites de audiencia of a complaint)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS hearing_process (
                id UUID NOT NULL,
                complaint_id UUID NOT NULL,
                trigger_document_id UUID DEFAULT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                hearing_days SMALLINT NOT NULL,
                hearing_days_type VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);

        $this->addSql("COMMENT ON COLUMN hearing_process.id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.complaint_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.trigger_document_id IS '(DC2Type:uuid)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.start_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.end_date IS '(DC2Type:date_immutable)'");
        $this->addSql("COMMENT ON COLUMN hearing_process.created_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_hearing_complaint_end ON hearing_process (complaint_id, end_date)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_hearing_trigger_document ON hearing_process (trigger_document_id)');

        $this->addSql('DO $$ BEGIN ALTER TABLE hearing_process ADD CONSTRAINT FK_hearing_complaint FOREIGN KEY (complaint_id) REFERENCES access_request_complaint (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
        $this->addSql('DO $$ BEGIN ALTER TABLE hearing_process ADD CONSTRAINT FK_hearing_trigger_document FOREIGN KEY (trigger_document_id) REFERENCES document (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS hearing_process');
    }
}
```

- [ ] **Step 2.8: Run the tests and validate**

Run: `php vendor/bin/phpunit tests/Entity/HearingProcessTest.php && php -l src/Entity/HearingProcess.php && php -l src/Entity/AccessRequestComplaint.php && APP_ENV=dev php bin/console doctrine:schema:validate --skip-sync 2>&1 | tail -5`
Expected: tests OK (4 tests); "[OK] The mapping files are correct" (o al menos sin errores de mapping nuevos).

---

### Task 3: Normalización de `hearing_days` en `DocumentAnalyzer`

**Files:**
- Modify: `src/Service/AI/DocumentAnalyzer.php` (método `normalizeDocumentAnalysis`, líneas ~441-466)
- Test: `tests/Service/AI/DocumentAnalyzerNormalizeTest.php` (create)

- [ ] **Step 3.1: Write the failing test** (usa ReflectionMethod como `ResolutionAnalyzerSplitterTest`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\AI;

use App\Enum\DocumentType;
use App\Service\AI\DocumentAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DocumentAnalyzerNormalizeTest extends TestCase
{
    public function testNormalizesHearingFieldsForAudienciaDocument(): void
    {
        $result = $this->normalize([
            'documentType' => 'audiencia',
            'hearing_days' => '10',
            'hearing_days_type' => 'business',
        ]);

        $this->assertSame(DocumentType::Audiencia, $result['documentType']);
        $this->assertSame(10, $result['hearing_days']);
        $this->assertSame('business', $result['hearing_days_type']);
    }

    public function testHearingDaysTypeDefaultsToBusinessWhenMissingOrInvalid(): void
    {
        $missing = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 15]);
        $invalid = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 15, 'hearing_days_type' => 'lunar']);

        $this->assertSame('business', $missing['hearing_days_type']);
        $this->assertSame('business', $invalid['hearing_days_type']);
    }

    public function testHearingDaysNullWhenAbsentOrNotPositive(): void
    {
        $absent = $this->normalize(['documentType' => 'audiencia']);
        $zero = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 0]);
        $garbage = $this->normalize(['documentType' => 'audiencia', 'hearing_days' => 'muchos']);

        $this->assertNull($absent['hearing_days']);
        $this->assertNull($zero['hearing_days']);
        $this->assertNull($garbage['hearing_days']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $analyzer = (new ReflectionClass(DocumentAnalyzer::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DocumentAnalyzer::class, 'normalizeDocumentAnalysis');

        return $method->invoke($analyzer, $data);
    }
}
```

- [ ] **Step 3.2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit tests/Service/AI/DocumentAnalyzerNormalizeTest.php`
Expected: FAIL — `hearing_days` queda como string '10' / `hearing_days_type` ausente.

- [ ] **Step 3.3: Implement normalization**

In `src/Service/AI/DocumentAnalyzer.php`, inside `normalizeDocumentAnalysis()`, add before the final `return $data;`:

```php
        // Trámite de audiencia: normaliza el plazo de alegaciones que el LLM
        // extrae del documento. hearing_days debe ser un entero positivo;
        // hearing_days_type cae a 'business' (días hábiles, Ley 39/2015 art.
        // 30.2) cuando falta o trae un valor desconocido.
        $rawDays = $data['hearing_days'] ?? null;
        $data['hearing_days'] = is_numeric($rawDays) && (int) $rawDays > 0 ? (int) $rawDays : null;
        $data['hearing_days_type'] = in_array($data['hearing_days_type'] ?? null, ['business', 'calendar'], true)
            ? $data['hearing_days_type']
            : 'business';
```

- [ ] **Step 3.4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit tests/Service/AI/DocumentAnalyzerNormalizeTest.php`
Expected: OK (3 tests). Also run `php vendor/bin/phpunit` (full) → same pre-existing failures only.

---

### Task 4: Servicio `HearingProcessManager`

**Files:**
- Create: `src/Service/Complaint/HearingProcessManager.php`
- Test: `tests/Service/Complaint/HearingProcessManagerTest.php` (create)

- [ ] **Step 4.1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Complaint;

use App\Entity\AccessRequest;
use App\Entity\AccessRequestComplaint;
use App\Entity\DeadlineHistory;
use App\Entity\Document;
use App\Entity\HearingProcess;
use App\Service\AccessRequest\DeadlineCalculator;
use App\Service\Complaint\HearingProcessManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class HearingProcessManagerTest extends TestCase
{
    private HearingProcessManager $manager;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->manager = new HearingProcessManager(new DeadlineCalculator(), $this->entityManager);
    }

    public function testCreatesHearingProcessWithCalculatedEndDate(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $hearing = $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'calendar',
        ]);

        $this->assertInstanceOf(HearingProcess::class, $hearing);
        $this->assertSame('2026-06-01', $hearing->getStartDate()->format('Y-m-d'));
        $this->assertSame('2026-06-11', $hearing->getEndDate()->format('Y-m-d'));
        $this->assertSame(10, $hearing->getHearingDays());
        $this->assertSame('calendar', $hearing->getHearingDaysType());
        $this->assertSame($document, $hearing->getTriggerDocument());
        $this->assertCount(1, $complaint->getHearingProcesses());
    }

    public function testRecordsDeadlineHistoryEntry(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $persisted = [];
        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'business',
        ]);

        $histories = array_filter($persisted, fn (object $e) => $e instanceof DeadlineHistory);
        $this->assertCount(1, $histories);
        /** @var DeadlineHistory $history */
        $history = array_values($histories)[0];
        $this->assertSame(DeadlineHistory::TYPE_HEARING, $history->getDeadlineType());
        $this->assertSame($document, $history->getTriggerDocument());
    }

    public function testIsIdempotentForTheSameTriggerDocument(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));
        $analysis = ['hearing_days' => 10, 'hearing_days_type' => 'business'];

        $first = $this->manager->registerFromDocument($complaint, $document, $analysis);
        $second = $this->manager->registerFromDocument($complaint, $document, $analysis);

        $this->assertSame($first, $second);
        $this->assertCount(1, $complaint->getHearingProcesses());
    }

    public function testReturnsNullWhenAnalysisHasNoHearingDays(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(new \DateTimeImmutable('2026-06-01'));

        $this->assertNull($this->manager->registerFromDocument($complaint, $document, []));
        $this->assertNull($this->manager->registerFromDocument($complaint, $document, ['hearing_days' => null]));
        $this->assertCount(0, $complaint->getHearingProcesses());
    }

    public function testFallsBackToTodayWhenDocumentHasNoDate(): void
    {
        $complaint = $this->buildComplaint();
        $document = $this->buildDocument(null);

        $hearing = $this->manager->registerFromDocument($complaint, $document, [
            'hearing_days' => 10,
            'hearing_days_type' => 'calendar',
        ]);

        $this->assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $hearing->getStartDate()->format('Y-m-d'));
    }

    private function buildComplaint(): AccessRequestComplaint
    {
        $complaint = new AccessRequestComplaint();
        $accessRequest = new AccessRequest();
        $complaint->setAccessRequest($accessRequest);
        $accessRequest->setComplaint($complaint);

        return $complaint;
    }

    private function buildDocument(?\DateTimeImmutable $documentDate): Document
    {
        $document = new Document();
        if ($documentDate !== null) {
            $document->setDocumentDate($documentDate);
        }

        return $document;
    }
}
```

NOTA: si `AccessRequest`/`Document` requieren argumentos de constructor o setters obligatorios para instanciarse, ajustar `buildComplaint()`/`buildDocument()` en consecuencia (mirar sus constructores; `Document` y `AccessRequest` tienen constructores sin argumentos).

- [ ] **Step 4.2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit tests/Service/Complaint/HearingProcessManagerTest.php`
Expected: ERROR "Class App\Service\Complaint\HearingProcessManager not found"

- [ ] **Step 4.3: Implement the service**

`src/Service/Complaint/HearingProcessManager.php`:

```php
<?php

namespace App\Service\Complaint;

use App\Entity\AccessRequestComplaint;
use App\Entity\DeadlineHistory;
use App\Entity\Document;
use App\Entity\HearingProcess;
use App\Service\AccessRequest\DeadlineCalculator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registra el trámite de audiencia que abre un documento tipo `audiencia`:
 * crea (o actualiza, si el documento se reprocesa) el HearingProcess de la
 * reclamación y deja constancia en DeadlineHistory. Compartido por los dos
 * handlers de procesado de documentos (single y batch) para que ambos caminos
 * se comporten igual.
 */
class HearingProcessManager
{
    public function __construct(
        private readonly DeadlineCalculator $deadlineCalculator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $analysis Análisis normalizado del documento (claves hearing_days / hearing_days_type).
     */
    public function registerFromDocument(AccessRequestComplaint $complaint, Document $document, array $analysis): ?HearingProcess
    {
        $days = $analysis['hearing_days'] ?? null;
        if (!is_int($days) || $days <= 0) {
            return null;
        }

        $daysType = in_array($analysis['hearing_days_type'] ?? null, [HearingProcess::DAYS_TYPE_BUSINESS, HearingProcess::DAYS_TYPE_CALENDAR], true)
            ? $analysis['hearing_days_type']
            : HearingProcess::DAYS_TYPE_BUSINESS;

        $startDate = $document->getDocumentDate() ?? new \DateTimeImmutable('today');
        $endDate = $this->deadlineCalculator->calculateHearingDeadline($startDate, $days, $daysType);

        // Reprocesar el mismo documento actualiza el trámite ya registrado en
        // vez de duplicarlo.
        $hearing = $complaint->findHearingProcessByTriggerDocument($document);
        $isNew = $hearing === null;
        $previousEndDate = $hearing?->getEndDate();

        if ($hearing === null) {
            $hearing = new HearingProcess();
            $hearing->setTriggerDocument($document);
            $complaint->addHearingProcess($hearing);
            $this->entityManager->persist($hearing);
        }

        $hearing
            ->setStartDate($startDate)
            ->setEndDate($endDate)
            ->setHearingDays($days)
            ->setHearingDaysType($daysType);

        // Solo deja rastro en el historial de plazos cuando hay novedad real.
        if ($isNew || $previousEndDate?->format('Y-m-d') !== $endDate->format('Y-m-d')) {
            $history = new DeadlineHistory();
            $history->setAccessRequest($complaint->getAccessRequest());
            $history->setDeadlineType(DeadlineHistory::TYPE_HEARING);
            $history->setPreviousDeadline($previousEndDate);
            $history->setNewDeadline($endDate);
            $history->setReason(DeadlineHistory::REASON_INITIAL);
            $history->setNotes(sprintf(
                'Trámite de audiencia: %d %s para alegar (hasta %s)',
                $days,
                $hearing->getHearingDaysTypeLabel(),
                $endDate->format('d/m/Y'),
            ));
            $history->setTriggerDocument($document);
            $this->entityManager->persist($history);
        }

        return $hearing;
    }

    /**
     * Nota para el historial de estados / timeline. Null cuando el análisis no
     * trae plazo (el caller usa entonces su nota genérica).
     *
     * @param array<string, mixed> $analysis
     */
    public function buildTimelineNote(?HearingProcess $hearing): string
    {
        if ($hearing === null) {
            return 'Trámite de audiencia notificado por el organismo de transparencia';
        }

        return sprintf(
            'Trámite de audiencia abierto: %d %s para alegar (hasta %s)',
            $hearing->getHearingDays(),
            $hearing->getHearingDaysTypeLabel(),
            $hearing->getEndDate()->format('d/m/Y'),
        );
    }
}
```

- [ ] **Step 4.4: Run the test to verify it passes**

Run: `php vendor/bin/phpunit tests/Service/Complaint/HearingProcessManagerTest.php`
Expected: OK (5 tests)

---

### Task 5: Integración en los handlers de documentos

**Files:**
- Modify: `src/MessageHandler/ProcessDocumentHandler.php` (constructor + caso `Audiencia`, líneas ~616-622)
- Modify: `src/MessageHandler/ProcessDocumentBatchHandler.php` (constructor + switch, después del caso `ComplaintResolution` ~línea 534)

- [ ] **Step 5.1: Wire the manager into `ProcessDocumentHandler`**

Add constructor dependency (after `private readonly MessageBusInterface $messageBus,`):

```php
        private readonly \App\Service\Complaint\HearingProcessManager $hearingProcessManager,
```

(o añade `use App\Service\Complaint\HearingProcessManager;` arriba y usa el nombre corto, siguiendo el estilo de imports del fichero.)

Replace the `Audiencia` case (lines ~616-622):

```php
            case DocumentType::Audiencia:
                $complaint = $this->ensureComplaint($accessRequest);
                $hearing = $this->hearingProcessManager->registerFromDocument($complaint, $document, $analysis);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    $this->hearingProcessManager->buildTimelineNote($hearing), $eventDate
                );
                break;
```

- [ ] **Step 5.2: Wire the manager into `ProcessDocumentBatchHandler`**

Add the same constructor dependency. Then add a new case after `case DocumentType::ComplaintResolution: ... break;` (~line 541):

```php
            case DocumentType::Audiencia:
                $complaint = $this->ensureComplaint($accessRequest);
                $hearing = $this->hearingProcessManager->registerFromDocument($complaint, $document, $analysis);
                $this->recordStatusChange(
                    $accessRequest, 'complaint', AccessRequestComplaint::STATUS_RECLAIMED,
                    $this->hearingProcessManager->buildTimelineNote($hearing), $eventDate,
                );
                break;
```

IMPORTANTE: comprobar la firma de `updateAccessRequestFromAnalysis()` del batch handler — si no recibe el `Document`, hay que pasarlo (revisar el call site en `__invoke`). Si el `Document` no está disponible en ese método, añadirlo como parámetro.

- [ ] **Step 5.3: Verify**

Run: `php -l src/MessageHandler/ProcessDocumentHandler.php && php -l src/MessageHandler/ProcessDocumentBatchHandler.php && APP_ENV=dev php bin/console lint:container 2>&1 | grep -E "OK|ERROR" && php vendor/bin/phpunit 2>&1 | tail -3`
Expected: lint OK, container OK, tests con solo los fallos pre-existentes.

---

### Task 6: Prompts bundled (fusión con la versión de Langfuse)

**Files:**
- Modify: `config/prompts/document/analyze-single.md`
- Modify: `config/prompts/document/analyze-multi.md`

- [ ] **Step 6.1: `analyze-single.md`** — fusionar lo que tiene Langfuse v5 y falta en el bundled (NO borrar nada del bundled):

1. En el JSON (después de la línea de `"keyPoints"`, línea ~27), añadir:

```
    "hearing_days": "los días que dura el trámite de audiencia para el ciudadano, si el documento abre uno (null si no aplica)",
    "hearing_days_type": "el tipo de días del trámite de audiencia: 'business' si son hábiles, 'calendar' si son naturales (null si no aplica)"
```

(añadiendo la coma tras `"keyPoints": "..."`)

2. Tras la línea del cierre del JSON (`}`), añadir la aclaración de formato de fechas que tiene Langfuse:

```
Si hay dudas en el formato de fechas que aparecen en el documento (por ejemplo 02/05/2026) asume siempre el formato DD/MM/YYYY.
```

3. En la línea `REGLAS PARA documentType (valores posibles: ...)` (línea ~100), añadir `audiencia` a la lista (entre `respuesta_alegaciones` y `otro`).

4. Tras el párrafo de `respuesta_alegaciones` (línea ~106), añadir:

```
Usa "audiencia" si es una notificación del organismo de transparencia notificando la apertura de un trámite de audiencia en el marco de un proceso de reclamación para que el ciudadano alegue. Cuando uses este tipo, rellena "hearing_days" con el número de días que da el documento para alegar y "hearing_days_type" con 'business' si son días hábiles o 'calendar' si son naturales. Si el documento no especifica el tipo de días, usa 'business' (los plazos administrativos en días se entienden hábiles salvo indicación expresa).
```

- [ ] **Step 6.2: `analyze-multi.md`** — mismas adiciones adaptadas al formato por-documento:

1. En el objeto de `"documents"` (tras `"alegationPoints": null`, línea ~39), añadir:

```
            "hearing_days": null,
            "hearing_days_type": null
```

(con la coma tras `"alegationPoints": null`)

2. En `REGLAS PARA documentType` (línea ~112), añadir `audiencia` a la lista de valores y añadir el mismo párrafo de la regla de "audiencia" del paso anterior tras el párrafo de `respuesta_alegaciones`.

- [ ] **Step 6.3: Verify y aviso a David**

Run: `php vendor/bin/phpunit tests/ 2>&1 | tail -3` (sin regresiones).

NO ejecutar `app:langfuse:sync-prompts` (publicaría una nueva versión con label production). Avisar a David al final: el bundled y Langfuse v5 han divergido (Langfuse tiene audiencia+hearing_days pero le faltan las reglas de inadmitida/parcialmente_concedida/notificacion del bundled); tras este merge el bundled es el superconjunto y le toca a él decidir cuándo sincronizar.

---

### Task 7: UI — fila en la zona Plazos del detalle (`RequestStatusSidebar`)

**Files:**
- Modify: `templates/components/RequestStatusSidebar.html.twig` (después del bloque "Plazo resolución reclamación", antes del bloque "Plazo de cumplimiento")

- [ ] **Step 7.1: Add the row**

Insert between the "Plazo resolución reclamación" block and the "Plazo de cumplimiento" block:

```twig
        {% if request.complaint and request.complaint.latestHearingProcess %}
            {% set hearing = request.complaint.latestHearingProcess %}
            {% set hearingPassed = not hearing.isActive %}
            <div class="aside-row">
                <span class="aside-key">Plazo de alegaciones</span>
                <span class="aside-value">
                    <span class="{{ hearingPassed ? 'text-red-600 font-semibold' : '' }}">
                        {{ hearing.endDate|date('d/m/Y') }}
                    </span>
                    {% if hearingPassed %}
                        <span class="aside-tag is-passed">Vencido</span>
                    {% else %}
                        <span class="meta">· {{ hearing.daysUntilEnd }} día{{ hearing.daysUntilEnd != 1 ? 's' : '' }}</span>
                    {% endif %}
                </span>
                <p class="aside-note">
                    Trámite de audiencia: {{ hearing.hearingDays }} {{ hearing.hearingDaysTypeLabel }} desde el {{ hearing.startDate|date('d/m/Y') }}.
                </p>
            </div>
        {% endif %}
```

- [ ] **Step 7.2: Verify**

Run: `APP_ENV=dev php bin/console lint:twig templates/components/RequestStatusSidebar.html.twig`
Expected: OK

---

### Task 8: UI — dossier-notice en el detalle de la solicitud

**Files:**
- Modify: `templates/solicitudes/show.html.twig` — insertar después de `{{ component('RequestStatusBanner', {request: request}) }}` y su `</div>` de cierre (~línea 1219), antes del bloque de agent tasks.

- [ ] **Step 8.1: Add the notice**

```twig
{# Trámite de audiencia activo: el reclamante tiene un plazo abierto para
   presentar alegaciones. Se oculta al vencer; el histórico queda en el timeline. #}
{% if request.complaint and request.complaint.activeHearingProcess %}
    {% set hearing = request.complaint.activeHearingProcess %}
    <div class="rise rise-3">
        <aside class="dossier-notice is-amber mb-6">
            <div class="dossier-notice-body">
                <div class="dossier-notice-icon">
                    <i data-lucide="megaphone" class="w-4 h-4"></i>
                </div>
                <div class="dossier-notice-content">
                    <div class="dossier-notice-eyebrow">
                        <span class="dot"></span>
                        Trámite de audiencia
                        <span class="meta">· {{ hearing.daysUntilEnd }} día{{ hearing.daysUntilEnd != 1 ? 's' : '' }} restante{{ hearing.daysUntilEnd != 1 ? 's' : '' }}</span>
                    </div>
                    <h3 class="dossier-notice-headline">
                        Tienes hasta el {{ hearing.endDate|format_date(pattern='d MMMM y', locale='es') }} para presentar alegaciones.
                    </h3>
                    <div class="dossier-notice-prose">
                        <p>
                            El organismo de transparencia ha abierto un trámite de audiencia de
                            {{ hearing.hearingDays }} {{ hearing.hearingDaysTypeLabel }}
                            (desde el {{ hearing.startDate|date('d/m/Y') }}) para que puedas alegar
                            lo que estimes oportuno antes de que resuelva la reclamación.
                        </p>
                    </div>
                    {% if hearing.triggerDocument %}
                        <div class="dossier-notice-footer">
                            <a href="{{ path('app_document_download', {id: hearing.triggerDocument.id}) }}" class="btn btn-ghost btn-sm" data-turbo="false">
                                <i data-lucide="file-text" class="w-3.5 h-3.5 mr-1.5"></i>
                                Ver notificación del trámite
                            </a>
                        </div>
                    {% endif %}
                </div>
            </div>
        </aside>
    </div>
{% endif %}
```

NOTA: verificar el nombre real de la ruta de descarga de documentos (`grep -n "app_document_download\|document_download" config/ src/Controller/ templates/solicitudes/show.html.twig`). Si la ruta se llama distinto (p. ej. `app_documentos_download`), usar esa. Si `format_date` (twig/intl-extra) no está disponible en el proyecto (comprobar con `grep -rn "format_date" templates/`), usar `{{ hearing.endDate|date('d/m/Y') }}`.

- [ ] **Step 8.2: Verify**

Run: `APP_ENV=dev php bin/console lint:twig templates/solicitudes/show.html.twig`
Expected: OK

---

### Task 9: UI — box de Plazos de la home (`DeadlineAlerts`)

**Files:**
- Modify: `src/Twig/Components/DeadlineAlerts.php`
- Modify: `templates/components/DeadlineAlerts.html.twig` (macro `alertRow` + estilos del tag)

- [ ] **Step 9.1: Add the repository dependency and the alert source**

In `src/Twig/Components/DeadlineAlerts.php`:

Constructor — add:

```php
        private readonly \App\Repository\HearingProcessRepository $hearingProcessRepository,
```

In `getAlerts()`, after the "Get custom deadlines" block (before the sort):

```php
        // Get hearing process deadlines (trámites de audiencia)
        $hearings = $this->hearingProcessRepository->findApproachingByUser($user, $this->daysThreshold);

        foreach ($hearings as $hearing) {
            $daysUntil = $hearing->getDaysUntilEnd();
            $isPassed = !$hearing->isActive();
            $accessRequest = $hearing->getComplaint()->getAccessRequest();

            $alerts[] = [
                'id' => (string) $accessRequest->getId(),
                'title' => $accessRequest->getTitle(),
                'publicBody' => $accessRequest->getPublicBody()->getName(),
                'deadlineAt' => $hearing->getEndDate(),
                'daysUntil' => $daysUntil,
                'isPassed' => $isPassed,
                'type' => $this->getAlertType($daysUntil, $isPassed),
                'message' => $this->getHearingAlertMessage($daysUntil, $isPassed),
                'isHearing' => true,
            ];
        }
```

Add the message helper after `getComplaintAlertMessage()`:

```php
    private function getHearingAlertMessage(int $daysUntil, bool $isPassed): string
    {
        if ($isPassed) {
            return 'Plazo de alegaciones (audiencia) vencido';
        }
        if ($daysUntil === 0) {
            return 'El plazo de alegaciones vence hoy';
        }
        if ($daysUntil === 1) {
            return 'El plazo de alegaciones vence mañana';
        }
        return sprintf('Quedan %d días para presentar alegaciones', $daysUntil);
    }
```

- [ ] **Step 9.2: Add the tag in the template macro**

In `templates/components/DeadlineAlerts.html.twig`, macro `alertRow`, dentro de `agenda-row-meta`, add a branch before `{% elseif alert.isCustom is defined %}`:

```twig
                {% elseif alert.isHearing is defined %}
                    <span class="agenda-row-tag tag-hearing">audiencia</span>
```

And in the `<style>` block, after `.tag-custom`:

```css
    .tag-hearing    { background: rgb(254 226 226); color: rgb(185 28 28); }
```

- [ ] **Step 9.3: Verify**

Run: `php -l src/Twig/Components/DeadlineAlerts.php && APP_ENV=dev php bin/console lint:twig templates/components/DeadlineAlerts.html.twig && APP_ENV=dev php bin/console lint:container 2>&1 | grep -E "OK|ERROR"`
Expected: all OK

---

### Task 10: Documentación

**Files:**
- Modify: `docs/document-processing.md`
- Modify: `docs/complaint-workflow.md`

- [ ] **Step 10.1: `docs/document-processing.md`**

En la sección "Qué extrae la IA" (línea ~83), documentar las claves nuevas:

```markdown
**Trámite de audiencia.** Cuando `documentType = audiencia`, el análisis incluye `hearing_days`
(días para alegar, entero) y `hearing_days_type` (`business` = hábiles, `calendar` = naturales;
si el documento no lo especifica, el prompt instruye devolver `business`). Al procesarse, ambos
handlers (single y batch) delegan en `HearingProcessManager`, que crea de forma idempotente
(clave: documento que lo dispara) un `HearingProcess` asociado a la reclamación con
`startDate` = fecha del documento y `endDate` calculada con `DeadlineCalculator::calculateHearingDeadline()`
(el cómputo empieza el día siguiente, saltando festivos nacionales y fines de semana en el caso
de días hábiles), y registra la entrada correspondiente en `DeadlineHistory` (tipo `hearing`).
El plazo se muestra en el dossier-notice del detalle (mientras está vivo), en la zona Plazos
del detalle (`RequestStatusSidebar`) y en la box de Plazos de la home (`DeadlineAlerts`).
```

- [ ] **Step 10.2: `docs/complaint-workflow.md`**

Añadir una sección tras la descripción del flujo de reclamación (buscar la sección de documentos de reclamación / alegaciones):

```markdown
### Trámite de audiencia

Cuando el organismo de transparencia abre un trámite de audiencia (documento tipo `audiencia`),
PideInfo registra un `HearingProcess` vinculado a la reclamación con la ventana de alegaciones:
fecha de inicio (la del documento) y fecha límite (calculada con los `hearing_days` extraídos
por el LLM, en días hábiles o naturales según `hearing_days_type`). Una reclamación puede
acumular varios trámites de audiencia; el "activo" es el más reciente cuya fecha límite no ha
vencido. El plazo se destaca en el detalle de la solicitud (aviso superior + zona Plazos) y en
la agenda de plazos de la home, y deja rastro en el timeline y en `DeadlineHistory`.
```

- [ ] **Step 10.3: Final full verification**

Run:
```bash
php vendor/bin/phpunit 2>&1 | tail -5
APP_ENV=dev php bin/console lint:container 2>&1 | grep -E "OK|ERROR"
APP_ENV=dev php bin/console lint:twig templates/ 2>&1 | tail -3
APP_ENV=dev php bin/console doctrine:migrations:status 2>&1 | grep -i "new"
git status --short && git diff --stat | tail -25
```
Expected: tests sin regresiones (solo fallos pre-existentes de BD), container OK, twig OK, 1 migración nueva pendiente. Mostrar el diff a David y **esperar su confirmación antes de cualquier commit**.

---

## Self-review checklist (ya aplicado)

- ✅ Cobertura del spec: extracción LLM (T3, T6), entidad+migración (T2), cálculo (T1), handler single+batch (T4, T5), dossier-notice (T8), zona Plazos detalle (T7), box Plazos home (T9), timeline (vía nota del status change en T5), docs (T10).
- ✅ Consistencia de tipos: `registerFromDocument()` consume las claves normalizadas en T3; `getLatestHearingProcess()`/`getActiveHearingProcess()` definidos en T2 y usados en T7/T8; `isHearing` definido en T9 componente y usado en T9 template.
- ✅ Sin placeholders: todos los pasos llevan código completo; los dos puntos de verificación dependiente del repo (nombre de ruta de descarga, firma de `updateAccessRequestFromAnalysis`) están marcados con instrucciones concretas de comprobación.
