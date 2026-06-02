<?php

namespace App\Controller\Admin;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\AutonomousCommunity;
use App\Entity\ComplaintOrganism;
use App\Entity\Document;
use App\Entity\Organization;
use App\Entity\PublicBody;
use App\Entity\Resolution;
use App\Entity\UsageHint;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);

        return $this->redirect($adminUrlGenerator->setController(AccessRequestCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('PideInfo - Administración')
            ->setFaviconPath('favicon.ico')
            ->setTranslationDomain('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Panel principal', 'fa fa-home');

        yield MenuItem::section('Solicitudes');
        yield MenuItem::linkToCrud('Solicitudes', 'fa fa-folder-open', AccessRequest::class);
        yield MenuItem::linkToCrud('Documentos', 'fa fa-file', Document::class);

        yield MenuItem::section('Usuarios');
        yield MenuItem::linkToCrud('Usuarios', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Organizaciones', 'fa fa-building', Organization::class);

        yield MenuItem::section('Configuración');
        yield MenuItem::linkToCrud('Novedades', 'fa fa-bullhorn', UsageHint::class);
        yield MenuItem::linkToCrud('Comunidades Autónomas', 'fa fa-map', AutonomousCommunity::class);
        yield MenuItem::linkToCrud('Leyes aplicables', 'fa fa-gavel', ApplicableLaw::class);
        yield MenuItem::linkToCrud('Órganos de reclamación', 'fa fa-balance-scale', ComplaintOrganism::class);
        yield MenuItem::linkToCrud('Organismos públicos', 'fa fa-landmark', PublicBody::class);

        yield MenuItem::section('RAG');
        yield MenuItem::linkToCrud('Resoluciones', 'fa fa-book', Resolution::class);

        yield MenuItem::section('Sistema');
        yield MenuItem::linkToRoute('Messenger Monitor', 'fa fa-cogs', 'zenstruck_messenger_monitor_dashboard');

        yield MenuItem::section('');
        yield MenuItem::linkToRoute('Volver a la aplicación', 'fa fa-arrow-left', 'app_dashboard');
    }
}
