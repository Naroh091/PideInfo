<?php

namespace App\Security\Voter;

use App\Entity\AccessRequest;
use App\Entity\User;
use App\Service\Anonymous\AnonymousDraftSessionStore;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AccessRequestVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';

    public function __construct(private readonly AnonymousDraftSessionStore $sessionStore)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof AccessRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Anonymous drafting (/redactar): an ownerless request is readable and
        // editable by the session that created it (NullToken callers included —
        // this must run before the instanceof User bail-out). Never DELETE:
        // anonymous drafts die via claim or purge. Anything else falls through
        // to the normal flow, so admins keep access and strangers get denied.
        if ($subject instanceof AccessRequest
            && $subject->getUser() === null
            && in_array($attribute, [self::VIEW, self::EDIT], true)
            && $this->sessionStore->contains($subject->getId())) {
            return true;
        }

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

        // User owns the request (anonymous drafts have no owner: no match)
        if ($accessRequest->getUser()?->getId()->equals($user->getId()) === true) {
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
