<?php

namespace App\Twig\Components;

use App\Billing\EffectivePlanResolver;
use App\Billing\Plan;
use App\Billing\PlanCatalog;
use App\Entity\User;
use App\Repository\UserNotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Topbar user menu — fuses the previous standalone notification bell into the
 * avatar button: a red dot appears when there are unread notifications, and a
 * "Notificaciones (N)" entry inside the dropdown leads to the full inbox.
 */
#[AsLiveComponent('UserMenu')]
final class UserMenu extends AbstractController
{
    use DefaultActionTrait;

    public function __construct(
        private readonly UserNotificationRepository $notificationRepository,
        private readonly Security $security,
        private readonly EffectivePlanResolver $planResolver,
        private readonly PlanCatalog $planCatalog,
    ) {
    }

    public function getUnreadCount(): int
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user) {
            return 0;
        }

        return $this->notificationRepository->countUnreadByUser($user);
    }

    public function getUser(): ?User
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        return $user;
    }

    /**
     * Display name of the user's paid plan (Profesional, Equipo,
     * Organización), or null on the free Individual plan / anonymous.
     * Used for the crown badge and the first dropdown row.
     */
    public function getPlanName(): ?string
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $plan = $this->planResolver->forUser($user);
        if ($plan === Plan::INDIVIDUAL) {
            return null;
        }

        return $this->planCatalog->get($plan)->name;
    }
}
