<?php

namespace App\Controller\Admin;

use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Zenstruck\Messenger\Monitor\Controller\MessengerMonitorController as BaseController;

#[Route('/admin/messenger')]
#[IsGranted('ROLE_ADMIN')]
final class MessengerMonitorController extends BaseController
{
}
