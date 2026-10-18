<?php

declare(strict_types=1);

namespace App\Tests\Service\AccessRequest\Batch;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\RegDestination;
use App\Entity\User;

/** In-memory drafts of a multi-recipient batch (no database). */
trait BatchFixtures
{
    private function draft(string $bodyName, string $lawName, ?string $regUnit = null, ?User $user = null): AccessRequest
    {
        $body = (new PublicBody())->setName($bodyName);
        $law = (new ApplicableLaw())->setName($lawName)->setShortCode('LT');

        $ar = new AccessRequest();
        $ar->setUser($user ?? new User());
        $ar->setPublicBody($body);
        $ar->setApplicableLaw($law);
        $ar->setStatus(AccessRequest::STATUS_PENDING);
        $ar->setTitle('');
        $ar->setDescription('');
        if ($regUnit !== null) {
            $ar->setRegDestination(new RegDestination($body, 'A12000001', $regUnit));
        }

        return $ar;
    }
}
