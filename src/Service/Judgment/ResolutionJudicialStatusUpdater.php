<?php

declare(strict_types=1);

namespace App\Service\Judgment;

use App\Entity\Judgment;
use App\Entity\Resolution;
use App\Repository\JudgmentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ONLY writer of `resolution.judicial_status`.
 *
 * The column is derived data, and derived data rots the moment two things write it. Everything
 * that can change the verdict funnels through here: linking a judgment to a resolution, and
 * analysing a judgment (the effect and the stance — and therefore the DIRECTION of an annulment —
 * are only known after the PDF is read).
 *
 * It writes through the ORM on purpose: ResolutionIndexListener watches the UnitOfWork, so a
 * bulk DBAL UPDATE would leave Elasticsearch stale and the public filter silently wrong. The
 * volumes here are tiny (~400 judgments over ~350 resolutions), so the ORM costs nothing.
 */
final class ResolutionJudicialStatusUpdater
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JudgmentRepository $judgments,
    ) {
    }

    /**
     * Recomputes the status of every resolution this judgment touches.
     *
     * Note it reclassifies from the resolution's WHOLE chain, not from this judgment alone: an
     * annulment in cassation overrides the confirmation handed down in first instance, and only
     * the full chain knows that.
     */
    public function refreshFor(Judgment $judgment, bool $flush = true): void
    {
        $this->refreshResolutions($judgment->getResolutions()->toArray(), $flush);
    }

    /**
     * @param iterable<Resolution> $resolutions
     *
     * @return int resolutions whose status actually changed
     */
    public function refreshResolutions(iterable $resolutions, bool $flush = true): int
    {
        $resolutions = is_array($resolutions) ? $resolutions : iterator_to_array($resolutions);
        if ($resolutions === []) {
            return 0;
        }

        $ids = array_map(static fn (Resolution $r): string => (string) $r->getId(), $resolutions);
        $chains = $this->judgments->findByResolutionIds($ids);

        $changed = 0;
        foreach ($resolutions as $resolution) {
            $code = JudicialStatus::of($chains[(string) $resolution->getId()] ?? [])->code;

            if ($resolution->getJudicialStatus() === $code) {
                continue;
            }

            $resolution->setJudicialStatus($code);
            ++$changed;
        }

        if ($flush && $changed > 0) {
            $this->entityManager->flush();
        }

        return $changed;
    }
}
