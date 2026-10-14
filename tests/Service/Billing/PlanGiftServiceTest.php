<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Entity\Organization;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Billing\PlanGiftService;
use App\Tests\Support\PurgesAccessRequestFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regalo de planes (admin): suscripción activa sin Stripe por N meses.
 */
final class PlanGiftServiceTest extends KernelTestCase
{
    use PurgesAccessRequestFixtures;

    public function testGiftToUserActivatesForMonths(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'gifted-user@example.com');
        $em->flush();

        /** @var PlanGiftService $gifts */
        $gifts = static::getContainer()->get(PlanGiftService::class);
        $sub = $gifts->giftToUser($user, 'professional', 3);

        try {
            self::assertSame(Subscription::STATUS_ACTIVE, $sub->getStatus());
            self::assertNull($sub->getStripeSubscriptionId(), 'sin Stripe');
            $days = (int) (new \DateTimeImmutable('today'))->diff($sub->getCurrentPeriodEnd())->format('%a');
            self::assertGreaterThanOrEqual(85, $days, 'unos tres meses de vigencia');
            self::assertLessThanOrEqual(95, $days, 'unos tres meses de vigencia');
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testGiftToOrganization(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $org = (new Organization())->setName('Org regalada '.bin2hex(random_bytes(3)));
        $em->persist($org);
        $this->trackFixtureOrg($org->getId()->toRfc4122());
        $em->flush();

        /** @var PlanGiftService $gifts */
        $gifts = static::getContainer()->get(PlanGiftService::class);
        $sub = $gifts->giftToOrganization($org, 'team', 1);

        try {
            self::assertSame('team', $sub->getPlan());
            self::assertSame($org->getId()->toRfc4122(), $sub->getOrganization()->getId()->toRfc4122());
        } finally {
            $em->remove($em->getRepository(Subscription::class)->find($sub->getId()));
            $em->flush();
        }
    }

    public function testInvalidPlanOrMonthsThrow(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();

        $user = $this->makeUser($em, 'gift-invalid@example.com');
        $em->flush();

        /** @var PlanGiftService $gifts */
        $gifts = static::getContainer()->get(PlanGiftService::class);
        $this->expectException(\InvalidArgumentException::class);
        $gifts->giftToUser($user, 'individual', 3);
    }

    private function makeUser($em, string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('x')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setIsVerified(true)
            ->setIsActive(true);
        $em->persist($user);
        $this->trackFixtureUser($user->getId()->toRfc4122());

        return $user;
    }
}
