<?php

namespace App\Twig\Components;

use App\Entity\AccessRequest;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent('RequestStatusBanner')]
class RequestStatusBanner
{
    use DefaultActionTrait;

    #[LiveProp]
    public AccessRequest $request;
}
