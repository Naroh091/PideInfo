<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException(
                'Tu cuenta aún no ha sido activada. Recibirás un aviso por correo cuando esté lista. Si tienes dudas, escríbenos por DM en Twitter a @naroh o a info@iniciativafaro.es.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
