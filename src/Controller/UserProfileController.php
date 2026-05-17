<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserPersonalDataType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Personal data (postal address + contact phone) the REG channel requires.
 * Two entry points share the same controller so the form is identical
 * regardless of where the user landed: the dedicated profile page (full
 * template) and the inline gating block that the "Enviar" flow injects when
 * a REG-bound submission is blocked on a missing profile.
 *
 * The inline variant returns the form fragment with a `_fragment=1` query
 * marker so the receiving page can swap it into the dispatch panel via Turbo
 * / HTMX without re-rendering the rest of the screen.
 */
#[IsGranted('ROLE_USER')]
class UserProfileController extends AbstractController
{
    #[Route('/perfil/datos-personales', name: 'app_user_personal_data', methods: ['GET', 'POST'])]
    public function personalData(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(UserPersonalDataType::class, $user);
        $form->handleRequest($request);

        $fragment = $request->query->getBoolean('_fragment');
        $retryBatch = $request->query->get('retry');
        $wantsJson = $this->wantsJson($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $em->flush();

                if ($wantsJson) {
                    return new JsonResponse(['ok' => true]);
                }
                if ($retryBatch !== null && $retryBatch !== '') {
                    // Dispatch is POST-only; bounce the user back to the submit page
                    // where the "Enviar" button still has the batchId in scope.
                    $this->addFlash('success', 'Datos guardados. Pulsa "Enviar" para reintentar el envío.');
                    return $this->redirectToRoute('app_solicitudes_submit_form', ['batch' => $retryBatch]);
                }
                $this->addFlash('success', 'Datos personales guardados.');
                return $this->redirectToRoute('app_user_personal_data');
            }

            if ($wantsJson) {
                return new JsonResponse([
                    'ok' => false,
                    'errors' => $this->collectFormErrors($form),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        return $this->render(
            $fragment
                ? 'profile/_personal_data_form.html.twig'
                : 'profile/personal_data.html.twig',
            [
                'form' => $form->createView(),
                'retry_batch' => $retryBatch,
            ]
        );
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }
        $accept = (string) $request->headers->get('Accept', '');
        return str_contains($accept, 'application/json');
    }

    /**
     * @return array<string, list<string>> child name → list of messages, plus
     *                                     a `_form` bucket for form-level errors
     */
    private function collectFormErrors(FormInterface $form): array
    {
        $out = [];
        foreach ($form->getErrors() as $error) {
            $out['_form'][] = $error->getMessage();
        }
        foreach ($form->all() as $name => $child) {
            foreach ($child->getErrors(true) as $error) {
                $out[$name][] = $error->getMessage();
            }
        }
        return $out;
    }
}
