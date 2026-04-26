<?php

declare(strict_types=1);

namespace App\Security\OAuth2;

use App\Entity\User as AppUser;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\Bundle\OAuth2ServerBundle\Entity\User as LeagueUser;
use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Replaces the bundle's default UserConverter so league/oauth2-server stores
 * the user's UUID as the JWT subject instead of the email returned by
 * Symfony's `getUserIdentifier()`. The MCP token handler validates the
 * subject as a UUID before loading the user.
 */
#[AsDecorator(decorates: 'league.oauth2_server.converter.user')]
final class UserConverter implements UserConverterInterface
{
    public function toLeague(UserInterface $user): UserEntityInterface
    {
        $entity = new LeagueUser();
        $entity->setIdentifier($user instanceof AppUser ? $user->getId()->toRfc4122() : $user->getUserIdentifier());

        return $entity;
    }
}
