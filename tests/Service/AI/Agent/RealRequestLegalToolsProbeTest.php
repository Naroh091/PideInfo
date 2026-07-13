<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Agent;

use App\Entity\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Service\AI\Agent\AgentChatOrchestrator;
use App\Service\AI\Chat\AssistantChatRequest;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\Chat\LegalFrameworkComposer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Probe (not a CI test): runs the REAL agent against REAL access requests and reports which
 * legal tools it decides to call and which law it ends up reading. Makes live LLM calls.
 *
 * @group probe
 */
final class RealRequestLegalToolsProbeTest extends KernelTestCase
{
    public function testWhichLawsTheAgentReadsForRealRequests(): void
    {
        // Makes live LLM calls and needs real requests in the database. Opt-in only: it must
        // never run (and never bill) in CI.
        if (($_SERVER['PROBE_LLM'] ?? '') !== '1') {
            self::markTestSkipped('Sonda con LLM real. Ejecuta con PROBE_LLM=1.');
        }

        self::bootKernel();
        $c = self::getContainer();

        $qb = $c->get(AccessRequestRepository::class)->createQueryBuilder('ar')
            ->join('ar.applicableLaw', 'al')->addSelect('al')
            ->join('ar.publicBody', 'pb')->addSelect('pb')
            ->join('ar.user', 'u')->addSelect('u')
            ->where('ar.title IS NOT NULL')
            ->andWhere('LENGTH(ar.description) > 80')
            ->orderBy('ar.createdAt', 'DESC')
            ->setMaxResults((int) ($_SERVER['PROBE_LIMIT'] ?? 3));

        if (($filter = $_SERVER['PROBE_FILTER'] ?? '') !== '') {
            $qb->andWhere('LOWER(ar.title) LIKE :f OR LOWER(ar.description) LIKE :f')
                ->setParameter('f', '%' . mb_strtolower((string) $filter) . '%');
        }

        $requests = $qb->getQuery()->getResult();

        self::assertNotEmpty($requests, 'No hay solicitudes reales en la BD.');

        $composer = $c->get(RequestPromptComposer::class);
        $legal = $c->get(LegalFrameworkComposer::class);
        $orchestrator = $c->get(AgentChatOrchestrator::class);
        $tokenStorage = $c->get('security.token_storage');

        foreach ($requests as $ar) {
            /** @var AccessRequest $ar */
            $this->report($ar, $composer, $legal, $orchestrator, $tokenStorage);
        }

        $this->addToAssertionCount(1);
    }

    private function report(
        AccessRequest $ar,
        RequestPromptComposer $composer,
        LegalFrameworkComposer $legal,
        AgentChatOrchestrator $orchestrator,
        object $tokenStorage,
    ): void {
        $out = static fn (string $line) => fwrite(STDERR, $line . "\n");

        $out("\n\n" . str_repeat('═', 100));
        $out('SOLICITUD  ' . $ar->getTitle());
        $out('ORGANISMO  ' . $ar->getPublicBody()->getName());
        $out('LEY        ' . $ar->getApplicableLaw()->getShortCode() . ' → ' . ($ar->getApplicableLaw()->getBoeId() ?? '(sin boe_id)'));
        $out(str_repeat('─', 100));

        // 1) Deterministic half: what gets pasted into the system prompt without the model
        //    deciding anything.
        $block = $legal->compose($ar);
        preg_match_all('/^### (.+)$/m', $block, $m);
        $out('PRE-INYECTADO (' . mb_strlen($block) . ' chars): ' . ($block === '' ? '(nada)' : ''));
        foreach ($m[1] ?? [] as $heading) {
            $out('   · ' . $heading);
        }

        // 2) Agentic half: let the real model run and see which tools it reaches for.
        $tokenStorage->setToken(new UsernamePasswordToken($ar->getUser(), 'main', $ar->getUser()->getRoles()));

        $prompt = $composer->compose($ar, []);

        $req = new AssistantChatRequest(
            flow: 'request',
            entityId: (string) $ar->getId(),
            systemPrompt: $prompt->text,
            userMessage: 'Redacta la solicitud.',
            history: [],
            attachments: [],
            label: 'LegalToolsProbe',
            promptRef: $prompt,
            traceName: 'LegalToolsProbe',
        );

        $steps = [];
        $decision = null;

        try {
            foreach ($orchestrator->stream($req) as [$event, $payload]) {
                if ($event === 'step') {
                    $steps[] = $payload['message'] ?? '';
                } elseif ($event === 'decision') {
                    $decision = $payload;
                } elseif ($event === 'error') {
                    $out('ERROR: ' . ($payload['message'] ?? '?'));
                }
            }
        } catch (\Throwable $e) {
            $out('EXCEPCIÓN: ' . $e->getMessage());

            return;
        }

        $out('');
        $out('TOOLS QUE LLAMÓ EL MODELO:');
        foreach ($steps as $step) {
            $out('   → ' . strip_tags($step));
        }

        $draft = $decision['draft'] ?? [];
        $body = strip_tags(implode(' ', array_filter([
            $draft['body_html'] ?? null,
            $draft['body_text'] ?? null,
            $draft['expone'] ?? null,
            $draft['solicita'] ?? null,
        ])));

        $out('');
        $out('ACCIÓN: ' . ($decision['action'] ?? '(ninguna)') . '  ·  borrador: ' . mb_strlen($body) . ' chars');

        if (preg_match_all('/\b(art[íi]culos?\.?\s*\d+[^\s,;.)]*(?:\s+(?:de la|LTAIBG|LCSP|LBRL|ROF|Ley)\s*[\w\/]*)?)/iu', $body, $cites)) {
            $out('CITAS EN EL BORRADOR: ' . implode(' | ', array_slice(array_unique($cites[1]), 0, 12)));
        }

        if (preg_match_all('/\b(Ley(?:\s+Orgánica)?\s+\d+\/\d{4}|LCSP|LBRL|ROF|LTAIBG|Real Decreto\s+\d+\/\d{4})/iu', $body, $laws)) {
            $out('NORMAS INVOCADAS:    ' . implode(' | ', array_unique($laws[1])));
        }

        if (($_SERVER['PROBE_SHOW_DRAFT'] ?? '') === '1') {
            $out("\n--- BORRADOR ---\n" . $body);
        }
    }
}
