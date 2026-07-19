<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Repository\CriterionRepository;
use App\Repository\JudgmentRepository;
use App\Repository\ResolutionRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Turns the `sources` the assistant declares ({type, reference, label}) into
 * displayable, LINKED citation pills. Links are built SERVER-SIDE from the
 * reference via the existing repositories — never from a URL the model invents:
 *   - resolution → internal PideInfo detail page (app_resoluciones_show)
 *   - criterion / judgment → the original document (entity sourceUrl), if any
 *
 * A source with no resolvable link is still returned (url = null) so the pill
 * renders as plain text rather than disappearing.
 */
final class CitationLinkResolver
{
    public function __construct(
        private readonly ResolutionRepository $resolutions,
        private readonly CriterionRepository $criteria,
        private readonly JudgmentRepository $judgments,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param array<int, mixed> $sources raw `sources` array from the model
     *
     * @return list<array{type: string, reference: string, label: string, url: string|null}>
     */
    public function resolve(array $sources): array
    {
        $resolved = [];
        $seen = [];

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $type = trim((string) ($source['type'] ?? ''));
            $reference = trim((string) ($source['reference'] ?? ''));
            if ($reference === '') {
                continue;
            }
            $label = trim((string) ($source['label'] ?? '')) ?: $reference;

            $key = $type . '|' . $reference;
            if (isset($seen[$key])) {
                continue; // dedupe repeated citations
            }
            $seen[$key] = true;

            $resolved[] = [
                'type'      => $type,
                'reference' => $reference,
                'label'     => $label,
                'url'       => $this->urlFor($type, $reference),
            ];
        }

        return $resolved;
    }

    private function urlFor(string $type, string $reference): ?string
    {
        return match ($type) {
            'resolution' => $this->resolutionUrl($reference),
            'criterion'  => $this->criteria->findOneByReference($reference)?->getSourceUrl(),
            'judgment'   => $this->judgments->findOneByReference($reference)?->getSourceUrl(),
            default      => null,
        };
    }

    private function resolutionUrl(string $reference): ?string
    {
        $resolution = $this->resolutions->findByReferenceNumber($reference);
        if ($resolution === null) {
            return null;
        }

        return $this->urlGenerator->generate(
            'app_resoluciones_show',
            ['id' => (string) $resolution->getId()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
