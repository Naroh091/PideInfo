<?php

namespace App\Twig;

use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class EasyAdminUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('ea_admin_url', [$this, 'generateAdminUrl']),
        ];
    }

    public function generateAdminUrl(string $controllerFqcn, string $action, string $entityId = null): string
    {
        $builder = $this->adminUrlGenerator
            ->unsetAll()
            ->setController($controllerFqcn)
            ->setAction($action);

        if ($entityId !== null) {
            $builder->setEntityId($entityId);
        }

        return $builder->generateUrl();
    }
}
