<?php

declare(strict_types=1);

namespace App\Service\Team;

use App\Billing\EffectivePlanResolver;
use App\Billing\PlanCatalog;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use App\Repository\UserRepository;

/**
 * Organization member and role management (spec §3.4).
 *
 * Caps: the organization's effective plan caps, floored at the current
 * member count so pre-billing teams are neither expelled nor blocked
 * (conservative grandfathering): the cap only stops GROWING past the plan max.
 */
final class TeamService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AccessRequestRepository $requests,
        private readonly PlanCatalog $catalog,
        private readonly EffectivePlanResolver $plans,
    ) {
    }

    /**
     * @throws MemberCapExceededException
     */
    public function assertCanAddMember(Organization $organization): void
    {
        $current = $this->users->countByOrganization($organization);
        $max = $this->maxMembersFor($organization);

        if ($current >= $max) {
            throw new MemberCapExceededException($max, $this->orgPlan($organization));
        }
    }

    public function maxMembersFor(Organization $organization): int
    {
        $planMax = $this->catalog->get($this->orgPlan($organization))->maxMembers;

        return max($planMax, $this->users->countByOrganization($organization));
    }

    public function countMembers(Organization $organization): int
    {
        return $this->users->countByOrganization($organization);
    }

    /**
     * @throws \LogicException when the manager cannot administer.
     */
    public function assertCanManage(User $manager, Organization $organization): void
    {
        if ($manager->getOrganization()?->getId()->equals($organization->getId()) !== true
            || !$manager->canManageOrganization()
        ) {
            throw new \LogicException('Solo OWNER o ADMIN de la organización pueden gestionar miembros.');
        }
    }

    public function addMember(Organization $organization, User $user, string $role = User::ORG_ROLE_MEMBER): void
    {
        $this->assertCanAddMember($organization);
        if (!\in_array($role, [User::ORG_ROLE_OWNER, User::ORG_ROLE_ADMIN, User::ORG_ROLE_MEMBER], true)) {
            throw new \InvalidArgumentException(sprintf('Rol desconocido: "%s".', $role));
        }
        $user->setOrganization($organization);
        $user->setOrganizationRole($role);
    }

    public function removeMember(User $user): void
    {
        // On leaving, what the person had shared becomes personal again:
        // team visibility was borrowed, not permanent (spec §3.5).
        foreach ($this->requests->findSharedByUser($user) as $request) {
            $request->setOrganization(null);
            $request->setSharedWithOrganization(false);
        }
        $user->setOrganization(null);
        $user->setOrganizationRole(null);
    }

    private function orgPlan(Organization $organization): string
    {
        return $this->plans->forOrganization($organization);
    }
}
