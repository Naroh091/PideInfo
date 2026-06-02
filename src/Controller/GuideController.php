<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/guias')]
#[IsGranted('ROLE_USER')]
class GuideController extends AbstractController
{
    private const GUIDES = [
        'como-funciona' => 'Cómo funciona',
        'solicitudes' => 'Añadir solicitudes',
        'buzon-virtual' => 'Buzón virtual',
        'documentos' => 'Documentos y comunicaciones',
        'agente' => 'Agente de sincronización',
        'reclamaciones' => 'Reclamaciones',
        'recordatorios' => 'Recordatorios',
        'listas' => 'Listas',
    ];

    #[Route('/privacidad', name: 'app_privacidad', priority: 10)]
    #[IsGranted('PUBLIC_ACCESS')]
    public function privacy(): Response
    {
        $filePath = $this->getParameter('kernel.project_dir') . '/guides/politica-de-privacidad.md';
        $markdown = file_get_contents($filePath);

        return $this->render('guias/privacy.html.twig', [
            'markdown' => $markdown,
        ]);
    }

    #[Route('', name: 'app_guias_index')]
    public function index(): Response
    {
        return $this->redirectToRoute('app_guias_show', ['slug' => 'como-funciona']);
    }

    #[Route('/{slug}', name: 'app_guias_show')]
    public function show(string $slug): Response
    {
        if (!isset(self::GUIDES[$slug])) {
            throw $this->createNotFoundException('Guía no encontrada');
        }

        $guidesDir = $this->getParameter('kernel.project_dir') . '/guides';
        $filePath = $guidesDir . '/' . $slug . '.md';

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('Guía no encontrada');
        }

        $markdown = file_get_contents($filePath);

        return $this->render('guias/show.html.twig', [
            'guides' => self::GUIDES,
            'currentSlug' => $slug,
            'currentTitle' => self::GUIDES[$slug],
            'markdown' => $markdown,
        ]);
    }
}
