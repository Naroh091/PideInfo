<?php

namespace App\Service\Email;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class VirtualEmailManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(env: 'VIRTUAL_EMAIL_DOMAIN')]
        private readonly string $domain,
    ) {
    }

    public function generateForUser(User $user): string
    {
        if ($user->hasVirtualEmail()) {
            return $user->getVirtualEmail();
        }

        $localPart = 'usuario-' . bin2hex(random_bytes(5));
        $email = $localPart . '@' . $this->domain;

        $user->setVirtualEmail($email);
        $this->entityManager->flush();

        return $email;
    }
}
