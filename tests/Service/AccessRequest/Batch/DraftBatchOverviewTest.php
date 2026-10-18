<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest\Batch;

use App\Billing\ActiveSubscriptionProvider;
use App\Billing\EffectivePlanResolver;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Billing\SubscriptionStatus;
use App\Entity\AccessRequest;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Service\AccessRequest\Batch\BatchVersionGenerator;
use App\Service\AccessRequest\Batch\DraftBatchOverview;
use App\Service\AccessRequest\Batch\DraftBatchTemplate;
use App\Service\AccessRequest\Batch\DraftBatchTemplateStore;
use App\Service\AI\Chat\AssistantTurnStore;
use PHPUnit\Framework\TestCase;

final class DraftBatchOverviewTest extends TestCase
{
    use BatchFixtures;

    public function testClassifiesEachRecipientRelativeToTheTemplate(): void
    {
        $empty = $this->draft('Vacío', 'L');
        self::assertSame(DraftBatchOverview::STATE_EMPTY, DraftBatchOverview::classify($empty));

        $independent = $this->draft('Aparte', 'L');
        $independent->setTitle('Contratos')->setDescription('Texto propio');
        self::assertSame(DraftBatchOverview::STATE_INDEPENDENT, DraftBatchOverview::classify($independent));

        $fromTemplate = $this->generated($this->draft('Plantilla', 'L'), 'tpl-v1');
        self::assertSame(DraftBatchOverview::STATE_TEMPLATE, DraftBatchOverview::classify($fromTemplate));

        $adjusted = $this->generated($this->draft('Ajustada', 'L'), 'tpl-v1');
        $adjusted->setDescription($adjusted->getDescription() . ' y un matiz propio.');
        self::assertSame(DraftBatchOverview::STATE_ADJUSTED, DraftBatchOverview::classify($adjusted));

        $sent = $this->generated($this->draft('Enviada', 'L'), 'tpl-v1');
        $sent->setStatus(AccessRequest::STATUS_SENT);
        self::assertSame(DraftBatchOverview::STATE_SENT, DraftBatchOverview::classify($sent));
    }

    public function testOnlyAdjustedOrIndependentVersionsNeedConsentToRegenerate(): void
    {
        self::assertTrue(DraftBatchOverview::regenerationOverwritesWork(DraftBatchOverview::STATE_ADJUSTED));
        self::assertTrue(DraftBatchOverview::regenerationOverwritesWork(DraftBatchOverview::STATE_INDEPENDENT));
        self::assertFalse(DraftBatchOverview::regenerationOverwritesWork(DraftBatchOverview::STATE_TEMPLATE));
        self::assertFalse(DraftBatchOverview::regenerationOverwritesWork(DraftBatchOverview::STATE_EMPTY));
    }

    public function testNavigationFlagsVersionsGeneratedFromAnOlderTemplate(): void
    {
        $user = new User();
        $current = new DraftBatchTemplate('b', 'Asunto', 'Expone [[ORGANISMO]]', 'Solicita');
        $older = new DraftBatchTemplate('b', 'Asunto', 'Expone antiguo', 'Solicita');

        $upToDate = $this->generated($this->draft('Al día', 'L', null, $user), $current->fingerprint());
        $stale = $this->generated($this->draft('Desfasada', 'L', null, $user), $older->fingerprint());
        $blank = $this->draft('Vacía', 'L', null, $user);

        $templates = $this->createStub(DraftBatchTemplateStore::class);
        $templates->method('find')->willReturn($current);
        $turns = $this->createStub(AssistantTurnStore::class);
        $turns->method('find')->willReturnCallback(
            static fn (AccessRequest $ar) => $ar === $stale ? ['status' => AssistantTurnStore::STATUS_RUNNING] : null,
        );

        $subscriptions = $this->createStub(ActiveSubscriptionProvider::class);
        $subscriptions->method('personalFor')->willReturn(new SubscriptionStatus(Plan::PROFESSIONAL, 'active', null, null));

        $overview = new DraftBatchOverview(
            $turns,
            $this->createStub(AccessRequestRepository::class),
            $templates,
            new EffectivePlanResolver($subscriptions),
            new PlanCatalog(),
        );
        $nav = $overview->navigation('b', [$upToDate, $stale, $blank], $user);

        self::assertNotNull($nav);
        self::assertSame(3, $nav['total']);
        self::assertSame(2, $nav['ready']);
        self::assertSame(2, $nav['generated']);
        self::assertSame(1, $nav['outdated']);
        self::assertSame((new PlanCatalog())->get(Plan::PROFESSIONAL)->name, $nav['planName'], 'el plan que permite el envío múltiple, junto a la corona');
        self::assertFalse($nav['rows'][0]['outdated']);
        self::assertTrue($nav['rows'][1]['outdated']);
        self::assertTrue($nav['rows'][1]['working'], 'un turno en curso se muestra en la pestaña');
        self::assertFalse($nav['rows'][2]['ready']);

        self::assertNull($overview->navigation('b', [$upToDate], $user), 'un solo destinatario no tiene flujo de plantilla');
    }

    private function generated(AccessRequest $ar, string $templateFingerprint): AccessRequest
    {
        $ar->setTitle('Contratos 2025');
        $ar->setDescription('Solicito la relación de contratos.');
        $ar->setMetadataValue(BatchVersionGenerator::METADATA_KEY, [
            'template_fingerprint' => $templateFingerprint,
            'content_fingerprint' => BatchVersionGenerator::contentFingerprint($ar),
        ]);

        return $ar;
    }
}
