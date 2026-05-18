<?php

namespace App\Controller;

use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/stats', name: 'app_stats')]
#[IsGranted('ROLE_USER')]
class StatsController extends AbstractController
{
    private const PRESETS = ['30d', '90d', '12m', 'all', 'custom'];

    public function __invoke(Request $request, AccessRequestRepository $repo): Response
    {
        $preset = $request->query->get('preset', '12m');
        if (!in_array($preset, self::PRESETS, true)) {
            $preset = '12m';
        }

        [$from, $to] = $this->resolveRange($preset, $request);

        $stats = $repo->getStatsFor($this->getUser(), $from, $to);

        return $this->render('stats/index.html.twig', [
            'stats' => $stats,
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function resolveRange(string $preset, Request $request): array
    {
        $today = new \DateTimeImmutable('today');

        return match ($preset) {
            'all' => [null, null],
            '30d' => [$today->modify('-30 days'), $today->modify('tomorrow')],
            '90d' => [$today->modify('-90 days'), $today->modify('tomorrow')],
            '12m' => [$today->modify('-12 months'), $today->modify('tomorrow')],
            'custom' => [
                $this->parseDate($request->query->get('from')),
                $this->parseDate($request->query->get('to'), endOfDay: true),
            ],
            default => [$today->modify('-12 months'), $today->modify('tomorrow')],
        };
    }

    private function parseDate(?string $value, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $d = new \DateTimeImmutable($value);
            return $endOfDay ? $d->setTime(23, 59, 59) : $d->setTime(0, 0, 0);
        } catch (\Exception) {
            return null;
        }
    }
}
