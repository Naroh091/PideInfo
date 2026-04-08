<?php

namespace App\Controller;

use App\Entity\Resolution;
use App\Repository\ComplaintOrganismRepository;
use App\Repository\PublicBodyRepository;
use App\Repository\ResolutionRepository;
use App\Service\OgImageGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/og-image')]
class OgImageController extends AbstractController
{
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly OgImageGenerator $generator,
        private readonly string $projectDir,
    ) {
    }

    #[Route('/home.png', name: 'og_image_home')]
    public function home(): Response
    {
        return $this->cached('home', fn () => $this->generator->generateHome());
    }

    #[Route('/resoluciones.png', name: 'og_image_resoluciones')]
    public function resoluciones(ResolutionRepository $repo): Response
    {
        return $this->cached('resoluciones', function () use ($repo) {
            $stats = $repo->getGlobalStats();
            $outcomeStats = $repo->getOutcomeStats();

            return $this->generator->generateResolucionesIndex(
                (int) $stats['totalCount'],
                (int) $stats['successRate'],
                $outcomeStats,
            );
        });
    }

    #[Route('/reclamados.png', name: 'og_image_reclamados')]
    public function reclamados(ResolutionRepository $repo): Response
    {
        return $this->cached('reclamados', function () use ($repo) {
            $total = $repo->countPublicBodyStats('');

            return $this->generator->generateReclamados($total);
        });
    }

    #[Route('/organismo/{slug}.png', name: 'og_image_organismo')]
    public function organismo(
        string $slug,
        ComplaintOrganismRepository $organismRepo,
        ResolutionRepository $resolutionRepo,
    ): Response {
        $organism = $organismRepo->findBySlug($slug);
        if (!$organism) {
            throw $this->createNotFoundException();
        }

        return $this->cached('organismo-' . $slug, function () use ($organism, $resolutionRepo) {
            $filters = ['organism' => $organism->getId()->toRfc4122()];
            $aggregates = $resolutionRepo->getFilteredAggregates($filters);

            return $this->generator->generateOrganismo(
                $organism->getName(),
                (int) $aggregates['totalCount'],
                (int) $aggregates['successRate'],
            );
        });
    }

    #[Route('/resolucion/{id}.png', name: 'og_image_resolucion')]
    public function resolucion(Resolution $resolution): Response
    {
        return $this->cached('resolucion-' . $resolution->getId()->toRfc4122(), function () use ($resolution) {
            return $this->generator->generateResolucion(
                $resolution->getSubject() ?? $resolution->getReferenceNumber(),
                $resolution->getOutcomeLabel(),
                $resolution->getOutcome(),
                $resolution->getPublicBodyName(),
                $resolution->getReferenceNumber(),
            );
        });
    }

    #[Route('/reclamado/{slug}.png', name: 'og_image_reclamado')]
    public function reclamado(
        string $slug,
        PublicBodyRepository $publicBodyRepo,
        ResolutionRepository $resolutionRepo,
    ): Response {
        $publicBody = $publicBodyRepo->findOneBy(['slug' => $slug]);
        if (!$publicBody) {
            throw $this->createNotFoundException();
        }

        return $this->cached('reclamado-' . $slug, function () use ($publicBody, $resolutionRepo) {
            $filters = ['publicBody' => $publicBody->getName(), 'publicBodyExact' => true];
            $aggregates = $resolutionRepo->getFilteredAggregates($filters);

            return $this->generator->generateReclamado(
                $publicBody->getName(),
                (int) $aggregates['totalCount'],
                (int) $aggregates['successRate'],
            );
        });
    }

    private function cached(string $key, callable $generate): Response
    {
        $cacheDir = $this->projectDir . '/var/cache/og';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0775, true);
        }

        $file = $cacheDir . '/' . md5($key) . '.png';

        if (file_exists($file) && (time() - filemtime($file)) < self::CACHE_TTL) {
            $png = file_get_contents($file);
        } else {
            $png = $generate();
            file_put_contents($file, $png);
        }

        return new Response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
        ]);
    }
}
