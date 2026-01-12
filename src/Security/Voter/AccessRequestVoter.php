<?php

namespace App\Security\Voter;

use App\Entity\AccessRequest;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AccessRequestVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof AccessRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var AccessRequest $accessRequest */
        $accessRequest = $subject;

        // Admins can do anything
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        // User owns the request
        if ($accessRequest->getUser()->getId()->equals($user->getId())) {
            return true;
        }

        // User belongs to same organization
        $userOrg = $user->getOrganization();
        $requestOrg = $accessRequest->getOrganization();
        if ($userOrg !== null && $requestOrg !== null && $userOrg->getId()->equals($requestOrg->getId())) {
            return true;
        }

        return false;
    }
}
