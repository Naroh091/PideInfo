<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Entity\User;
use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use App\Service\AccessRequest\Batch\DraftBatchTemplate;
use App\Service\AI\Chat\Composer\BatchTemplatePromptComposer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class BatchTemplatePromptComposerTest extends TestCase
{
    public function testListsEveryRecipientWithItsLawAndChannelAndTheMarkers(): void
    {
        $prompt = $this->composer()->compose(new DraftBatchTemplate('b'), [
            $this->recipient('Ministerio de Hacienda', 'Ley 19/2013'),
            $this->recipient('Xunta de Galicia', 'Lei 1/2016', 'Consellería de Facenda'),
        ])->text;

        self::assertStringContainsString('- Ministerio de Hacienda — unidad: Ministerio de Hacienda — ley: Ley 19/2013 — canal: Portal de Transparencia', $prompt);
        self::assertStringContainsString('- Xunta de Galicia — unidad: Consellería de Facenda — ley: Lei 1/2016 — canal: registro electrónico (REG)', $prompt);
        self::assertStringContainsString('`[[LEY_APLICABLE]]`', $prompt);
        self::assertStringContainsString('La plantilla aún NO existe.', $prompt);
        self::assertStringNotContainsString('{{', $prompt, 'ningún placeholder del prompt gestionado sin resolver');
        // Machine contract stays in PHP and comes last.
        self::assertStringContainsString('"draft": {"title": str ≤80, "expone": str ≤4000, "solicita": str ≤4000', $prompt);
    }

    public function testCarriesTheCurrentTemplateAsState(): void
    {
        $prompt = $this->composer()->compose(
            new DraftBatchTemplate('b', 'Asunto', 'Expone al [[ORGANISMO]]', 'Solicita'),
            [$this->recipient('A', 'L'), $this->recipient('B', 'L')],
        )->text;

        self::assertStringContainsString("YA EXISTE una plantilla:\nASUNTO: Asunto\nEXPONE:\nExpone al [[ORGANISMO]]", $prompt);
    }

    private function composer(): BatchTemplatePromptComposer
    {
        return new BatchTemplatePromptComposer(new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 5)),
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''),
            new NullLogger(),
        ));
    }

    private function recipient(string $body, string $law, ?string $unit = null): AccessRequest
    {
        $publicBody = (new PublicBody())->setName($body);
        $ar = (new AccessRequest())
            ->setUser(new User())
            ->setPublicBody($publicBody)
            ->setApplicableLaw((new ApplicableLaw())->setName($law)->setShortCode('LT'));
        if ($unit !== null) {
            $ar->setRegDestination(new RegDestination($publicBody, 'A12000001', $unit));
        }

        return $ar;
    }
}
