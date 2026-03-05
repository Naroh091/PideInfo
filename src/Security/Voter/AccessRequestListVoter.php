<?php

namespace App\Security\Voter;

use App\Entity\AccessRequestList;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AccessRequestListVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof AccessRequestList;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var AccessRequestList $list */
        $list = $subject;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // Owner can always do anything
        if ($list->getUser()->getId()->equals($user->getId())) {
            return true;
        }

        // For VIEW: org members can view shared lists
        if ($attribute === self::VIEW) {
            if ($list->getVisibility() === AccessRequestList::VISIBILITY_SHARED) {
                $userOrg = $user->getOrganization();
                $listOrg = $list->getOrganization();
                if ($userOrg !== null && $listOrg !== null && $userOrg->getId()->equals($listOrg->getId())) {
                    return true;
                }
            }
        }

        // EDIT/DELETE: owner only (handled above)
        return false;
    }
}
