<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AutonomousCommunity;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Repository\AutonomousCommunityRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\RegDestinationRepository;
use App\Service\Submission\Dir3Parser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reg:import-destinations',
    description: 'Import REG (RED SARA) DIR3 destination units from the official Excel/CSV export.',
)]
class ImportRegDestinationsCommand extends Command
{
    /**
     * Expected header — matches the Excel column titles the DIR3 export uses
     * (case-insensitive, accents/spaces tolerated). The first row of the CSV
     * must contain these labels; order doesn't matter.
     */
    private const HEADER_ALIASES = [
        'oficina' => ['oficina'],
        'unidad' => ['unidad'],
        'organismo' => ['organismo'],
        'raiz' => ['raiz', 'raíz'],
        'nivel' => ['nivel administracion', 'nivel administración', 'nivel'],
        'comunidad' => ['comunidad'],
        'provincia' => ['provincia'],
        'activacion' => ['fecha activacion', 'fecha activación', 'fecha de activacion', 'fecha de activación'],
    ];

    /**
     * "Nivel Administracion" cell → PublicBody::LEVEL_* constant. Anything
     * else (rare administrative levels, blanks, mojibake survivors) defaults
     * to state so we don't silently misclassify.
     */
    private const NIVEL_TO_LEVEL = [
        'administracion del estado' => PublicBody::LEVEL_STATE,
        'estado' => PublicBody::LEVEL_STATE,
        'administracion de justicia' => PublicBody::LEVEL_STATE, // Ministerio Fiscal, TSJ, etc. — bodies are national, units are territorial.
        'universidades' => PublicBody::LEVEL_STATE,
        'administracion autonomica' => PublicBody::LEVEL_AUTONOMOUS,
        'autonomica' => PublicBody::LEVEL_AUTONOMOUS,
        'administracion local' => PublicBody::LEVEL_LOCAL,
        'local' => PublicBody::LEVEL_LOCAL,
        'entidades locales' => PublicBody::LEVEL_LOCAL,
    ];

    /**
     * Alias table for CCAA names that the DIR3 export uses with slightly
     * different wording than `autonomous_community.name`. Keys are
     * normalised (lowercase, accents/punct stripped) so we match against
     * `normalize()`.
     */
    private const COMUNIDAD_ALIASES = [
        'castilla la mancha' => 'Castilla-La Mancha',
        'castilla y leon' => 'Castilla y León',
        'comunidad valenciana' => 'Comunitat Valenciana',
        'valenciana' => 'Comunitat Valenciana',
        'madrid' => 'Comunidad de Madrid',
        'comunidad de madrid' => 'Comunidad de Madrid',
        'navarra' => 'Comunidad Foral de Navarra',
        'comunidad foral de navarra' => 'Comunidad Foral de Navarra',
        'islas baleares' => 'Illes Balears',
        'baleares' => 'Illes Balears',
        'pais vasco' => 'País Vasco',
        'asturias' => 'Principado de Asturias',
        'principado de asturias' => 'Principado de Asturias',
        'murcia' => 'Región de Murcia',
        'region de murcia' => 'Región de Murcia',
        'ciudad autonoma de ceuta' => 'Ceuta',
        'ciudad autonoma de melilla' => 'Melilla',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly RegDestinationRepository $regDestinationRepository,
        private readonly AutonomousCommunityRepository $autonomousCommunityRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('csv', InputArgument::REQUIRED, 'Path to the CSV (Excel "Save As" → CSV UTF-8) with the DIR3 export.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report but do not persist anything.')
            ->addOption('no-disable', null, InputOption::VALUE_NONE, 'Skip soft-disabling RegDestinations missing from this run.')
            ->addOption('strict-public-body', null, InputOption::VALUE_NONE, 'Fail if a row references a Raíz that does not match any existing PublicBody by code or name (instead of creating a stub).')
            ->addOption('delimiter', null, InputOption::VALUE_REQUIRED, 'CSV delimiter override.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = (string) $input->getArgument('csv');
        $dryRun = (bool) $input->getOption('dry-run');
        $noDisable = (bool) $input->getOption('no-disable');
        $strict = (bool) $input->getOption('strict-public-body');
        $delimiter = $input->getOption('delimiter');

        if (!is_file($path) || !is_readable($path)) {
            $io->error(sprintf('CSV not found or unreadable: %s', $path));
            return Command::FAILURE;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $io->error('Could not open the CSV file.');
            return Command::FAILURE;
        }

        // BOM strip + delimiter sniff on the first line.
        $first = fgets($handle);
        if ($first === false) {
            $io->error('CSV is empty.');
            fclose($handle);
            return Command::FAILURE;
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = $delimiter !== null ? (string) $delimiter : self::sniffDelimiter($first);

        $headerRow = str_getcsv($first, $delimiter, '"', '\\');
        $headerMap = $this->mapHeader($headerRow);
        $missing = array_diff(['unidad', 'raiz'], array_keys($headerMap));
        if ($missing !== []) {
            $io->error(sprintf('CSV missing required columns: %s', implode(', ', $missing)));
            fclose($handle);
            return Command::FAILURE;
        }

        $io->title('REG destinations import');
        $io->writeln(sprintf('File: <info>%s</info>  delimiter: <info>%s</info>  dry-run: <info>%s</info>',
            $path, $delimiter, $dryRun ? 'yes' : 'no'));

        // Quick row count for the progress bar — much faster than the parse loop.
        $totalRows = $this->countDataRows($path);
        $progress = $io->createProgressBar($totalRows);
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% · %elapsed:6s%/%estimated:-6s% · %message%');
        $progress->setMessage('parseando…');
        $progress->start();

        $stats = [
            'rows_read' => 0,
            'rows_skipped' => 0,
            'public_body_created' => 0,
            'public_body_matched_by_code' => 0,
            'public_body_matched_by_name' => 0,
            'destination_created' => 0,
            'destination_updated' => 0,
            'destination_unchanged' => 0,
            'destination_disabled' => 0,
        ];
        $warnings = [];
        $seenUnitCodes = [];
        $batchSize = 500;
        $batchCounter = 0;

        // Cache PublicBody by dir3 code within a run for speed.
        /** @var array<string, PublicBody> $bodyByDir3 */
        $bodyByDir3 = [];

        // Per-Raíz aggregates so we can decide level / CCAA only after seeing
        // ALL its Unidades. Otherwise national bodies whose units are spread
        // across CCAA (Ministerio Fiscal, Guardia Civil…) end up tagged with
        // whichever CCAA happened to appear first in the file.
        /** @var array<string, array<string, true>> $ccaaSetByDir3 */
        $ccaaSetByDir3 = [];
        /** @var array<string, array<string, true>> $levelSetByDir3 */
        $levelSetByDir3 = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $stats['rows_read']++;

            if (count(array_filter($row, fn($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                $stats['rows_skipped']++;
                continue;
            }

            $get = fn(string $key): ?string => $headerMap[$key] !== null && isset($row[$headerMap[$key]])
                ? trim((string) $row[$headerMap[$key]])
                : null;

            $unit = Dir3Parser::parse($get('unidad'));
            $raiz = Dir3Parser::parse($get('raiz'));
            $org = Dir3Parser::parse($get('organismo'));
            $oficina = Dir3Parser::parse($get('oficina'));

            if ($unit === null || $raiz === null) {
                $stats['rows_skipped']++;
                $warnings[] = sprintf('Row %d: skipped (could not parse unidad/raiz).', $stats['rows_read']);
                continue;
            }

            $nivelRaw = $get('nivel');
            $comunidadRaw = $get('comunidad');
            $rowLevel = $this->resolveLevel($nivelRaw);
            $rowCommunity = $this->resolveCommunity($comunidadRaw);

            // --- Resolve / upsert PublicBody (Raíz) ---
            $body = $bodyByDir3[$raiz['code']] ?? null;
            if ($body === null) {
                $body = $this->publicBodyRepository->findOneBy(['dir3Code' => $raiz['code']]);
                if ($body !== null) {
                    $stats['public_body_matched_by_code']++;
                } else {
                    $body = $this->publicBodyRepository->findOneByNameInsensitive($raiz['name']);
                    if ($body !== null && $body->getDir3Code() === null) {
                        $body->setDir3Code($raiz['code']);
                        $stats['public_body_matched_by_name']++;
                    } elseif ($body === null) {
                        if ($strict) {
                            $stats['rows_skipped']++;
                            $warnings[] = sprintf('Row %d: skipped (strict): no PublicBody for raíz %s "%s".',
                                $stats['rows_read'], $raiz['code'], $raiz['name']);
                            continue;
                        }
                        $body = (new PublicBody())
                            ->setName($raiz['name'])
                            ->setDir3Code($raiz['code'])
                            ->setImportedFromReg(true)
                            ->setLevel(PublicBody::LEVEL_STATE); // tentative — finalised below
                        $this->em->persist($body);
                        $stats['public_body_created']++;
                    }
                }
                $bodyByDir3[$raiz['code']] = $body;
            }

            // Aggregate observations for this Raíz. The actual level / CCAA
            // assignment happens after the whole file has been read so we
            // can detect "spans multiple CCAA" and refuse to anchor.
            if ($rowLevel !== null) {
                $levelSetByDir3[$raiz['code']][$rowLevel] = true;
            }
            if ($rowCommunity !== null) {
                $ccaaSetByDir3[$raiz['code']][$rowCommunity->getId()->toRfc4122()] = true;
            }

            // --- Upsert RegDestination (Unidad) ---
            $intermediateCode = ($org !== null && $org['code'] !== $raiz['code']) ? $org['code'] : null;
            $intermediateName = ($org !== null && $org['code'] !== $raiz['code']) ? $org['name'] : null;
            $activatedAt = $this->parseDate($get('activacion'));
            $comunidad = $get('comunidad') ?: null;
            $provincia = $get('provincia') ?: null;
            $nivel = $get('nivel') ?: null;

            $destination = $this->regDestinationRepository->findOneByDir3($unit['code']);
            if ($destination === null) {
                $destination = new RegDestination($body, $unit['code'], $unit['name']);
                $destination
                    ->setIntermediateOrganismDir3($intermediateCode)
                    ->setIntermediateOrganismName($intermediateName)
                    ->setOficinaDir3($oficina['code'] ?? null)
                    ->setOficinaName($oficina['name'] ?? null)
                    ->setComunidad($comunidad)
                    ->setProvincia($provincia)
                    ->setNivelAdministracion($nivel)
                    ->setActivatedAt($activatedAt);
                $this->em->persist($destination);
                $stats['destination_created']++;
            } else {
                $before = [
                    $destination->getName(),
                    $destination->getPublicBody()->getId()->toRfc4122(),
                    $destination->getIntermediateOrganismDir3(),
                    $destination->getIntermediateOrganismName(),
                    $destination->getOficinaDir3(),
                    $destination->getOficinaName(),
                    $destination->getComunidad(),
                    $destination->getProvincia(),
                    $destination->getNivelAdministracion(),
                    $destination->getActivatedAt()?->format('Y-m-d'),
                    $destination->getDisabledAt()?->format('Y-m-d'),
                ];
                $destination
                    ->setName($unit['name'])
                    ->setPublicBody($body)
                    ->setIntermediateOrganismDir3($intermediateCode)
                    ->setIntermediateOrganismName($intermediateName)
                    ->setOficinaDir3($oficina['code'] ?? null)
                    ->setOficinaName($oficina['name'] ?? null)
                    ->setComunidad($comunidad)
                    ->setProvincia($provincia)
                    ->setNivelAdministracion($nivel)
                    ->setActivatedAt($activatedAt)
                    ->setDisabledAt(null);
                $after = [
                    $destination->getName(),
                    $destination->getPublicBody()->getId()->toRfc4122(),
                    $destination->getIntermediateOrganismDir3(),
                    $destination->getIntermediateOrganismName(),
                    $destination->getOficinaDir3(),
                    $destination->getOficinaName(),
                    $destination->getComunidad(),
                    $destination->getProvincia(),
                    $destination->getNivelAdministracion(),
                    $destination->getActivatedAt()?->format('Y-m-d'),
                    null,
                ];
                if ($before === $after) {
                    $stats['destination_unchanged']++;
                } else {
                    $stats['destination_updated']++;
                }
            }
            $seenUnitCodes[$unit['code']] = true;

            if (!$dryRun && ++$batchCounter >= $batchSize) {
                $progress->setMessage('flushing…');
                $this->em->flush();
                $batchCounter = 0;
            }

            $progress->setMessage(sprintf(
                '+%d unidades · +%d organismos',
                $stats['destination_created'],
                $stats['public_body_created'],
            ));
            $progress->advance();
        }
        fclose($handle);
        $progress->finish();
        $io->newLine(2);

        // --- Finalise PublicBody level + CCAA from the per-Raíz aggregates ---
        // Only touches bodies imported from REG (we never overwrite curated state).
        $bodyLevelChanged = 0;
        $bodyCcaaChanged = 0;
        $bodyCcaaCleared = 0;
        foreach ($bodyByDir3 as $dir3 => $body) {
            if (!$body->isImportedFromReg()) {
                continue;
            }
            $levels = array_keys($levelSetByDir3[$dir3] ?? []);
            $ccaaIds = array_keys($ccaaSetByDir3[$dir3] ?? []);

            // Level: pick the dominant one. If multiple appear, fall back
            // to STATE — that's the safe default for cross-territorial bodies.
            $newLevel = match (count($levels)) {
                1 => $levels[0],
                0 => PublicBody::LEVEL_STATE,
                default => PublicBody::LEVEL_STATE,
            };
            if ($body->getLevel() !== $newLevel) {
                $body->setLevel($newLevel);
                $bodyLevelChanged++;
            }

            // CCAA: anchor only if every Unidad of this Raíz pointed to the
            // same CCAA. Spans-multiple → null. The Unidad is what carries
            // territorial scope in those cases.
            if (count($ccaaIds) === 1) {
                $current = $body->getAutonomousCommunity();
                if ($current === null || $current->getId()->toRfc4122() !== $ccaaIds[0]) {
                    $community = $this->autonomousCommunityRepository->find($ccaaIds[0]);
                    if ($community !== null) {
                        $body->setAutonomousCommunity($community);
                        $bodyCcaaChanged++;
                    }
                }
            } else {
                if ($body->getAutonomousCommunity() !== null) {
                    $body->setAutonomousCommunity(null);
                    $bodyCcaaCleared++;
                }
            }
        }
        $stats['public_body_level_updated'] = $bodyLevelChanged;
        $stats['public_body_ccaa_assigned'] = $bodyCcaaChanged;
        $stats['public_body_ccaa_cleared'] = $bodyCcaaCleared;

        // --- Soft-disable destinations not seen this run ---
        if (!$noDisable && $seenUnitCodes !== []) {
            $io->writeln('Marcando unidades ausentes como deshabilitadas…');
            $today = new \DateTimeImmutable('today');
            $all = $this->regDestinationRepository->findAll();
            foreach ($all as $existing) {
                if (isset($seenUnitCodes[$existing->getDir3Code()])) {
                    continue;
                }
                if ($existing->isDisabled()) {
                    continue;
                }
                $existing->setDisabledAt($today);
                $stats['destination_disabled']++;
            }
        }

        if ($dryRun) {
            $this->em->clear();
            $io->note('--dry-run: rolling back, nothing persisted.');
        } else {
            $io->writeln('Flush final…');
            $this->em->flush();
        }

        $io->section('Summary');
        foreach ($stats as $k => $v) {
            $io->writeln(sprintf('  %s: <info>%d</info>', $k, $v));
        }

        if ($warnings !== []) {
            $io->section('Warnings');
            foreach (array_slice($warnings, 0, 20) as $w) {
                $io->writeln('  - ' . $w);
            }
            if (count($warnings) > 20) {
                $io->writeln(sprintf('  …and %d more', count($warnings) - 20));
            }
        }

        $io->success('Done.');
        return Command::SUCCESS;
    }

    /**
     * @param list<string> $headerRow
     * @return array<string, int|null>
     */
    private function mapHeader(array $headerRow): array
    {
        $normalized = array_map(fn($h) => self::normalize($h), $headerRow);
        $map = [];
        foreach (self::HEADER_ALIASES as $key => $aliases) {
            $found = null;
            foreach ($aliases as $alias) {
                $idx = array_search(self::normalize($alias), $normalized, true);
                if ($idx !== false) {
                    $found = $idx;
                    break;
                }
            }
            $map[$key] = $found;
        }
        return $map;
    }

    private static function normalize(string $s): string
    {
        $s = strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        // Strip anything that isn't a-z, 0-9 or whitespace — kills mojibake
        // like "ActivaciÃ³n" (UTF-8 of Latin-1 of UTF-8) without losing the
        // anchor letters that still pin the match.
        $s = preg_replace('/[^a-z0-9\s]+/u', '', $s) ?? $s;
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    private static function sniffDelimiter(string $line): string
    {
        $counts = [
            "\t" => substr_count($line, "\t"),
            ';' => substr_count($line, ';'),
            ',' => substr_count($line, ','),
        ];
        arsort($counts);
        $top = array_key_first($counts);
        return $counts[$top] > 0 ? $top : ',';
    }

    private function resolveLevel(?string $nivelRaw): ?string
    {
        if ($nivelRaw === null || $nivelRaw === '') {
            return null;
        }
        $key = self::normalize($nivelRaw);
        return self::NIVEL_TO_LEVEL[$key] ?? null;
    }

    /** In-process cache so we don't hit the DB once per row. */
    private array $communityCache = [];

    private function resolveCommunity(?string $comunidadRaw): ?AutonomousCommunity
    {
        if ($comunidadRaw === null || $comunidadRaw === '') {
            return null;
        }
        $key = self::normalize($comunidadRaw);
        if (array_key_exists($key, $this->communityCache)) {
            return $this->communityCache[$key];
        }

        // Try alias table first (handles "Comunidad Valenciana" → "Comunitat
        // Valenciana", etc.); fall back to a name lookup with the original
        // text.
        $canonical = self::COMUNIDAD_ALIASES[$key] ?? trim($comunidadRaw);
        $community = $this->autonomousCommunityRepository->findByName($canonical);
        $this->communityCache[$key] = $community;
        return $community;
    }

    /**
     * Cheap pre-pass that counts data rows (lines minus header) so the
     * progress bar can show ETA. Streams the file in 64 KiB chunks and
     * counts newlines — handles both LF and CRLF without splitting the
     * content into PHP strings per row.
     */
    private function countDataRows(string $path): int
    {
        $count = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                break;
            }
            $count += substr_count($chunk, "\n");
        }
        fclose($handle);
        return max(0, $count - 1); // subtract the header line
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($dt !== false) {
                return $dt;
            }
        }
        return null;
    }
}
