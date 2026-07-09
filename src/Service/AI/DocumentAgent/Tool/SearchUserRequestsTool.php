<?php

declare(strict_types=1);

namespace App\Service\AI\DocumentAgent\Tool;

use App\Entity\AccessRequest;
use App\Repository\AccessRequestRepository;
use App\Service\AI\DocumentAgent\AnalysisToolContext;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Matching agéntico de documentos huérfanos: expone las solicitudes YA
 * REGISTRADAS del dueño del documento (nunca de otros usuarios) para que el
 * agente empareje razonando por organismo, fecha o materia solicitada — no
 * solo por número de expediente, que es donde fallaba el matching clásico.
 */
#[AsTool(
    name: 'search_user_requests',
    description: 'Lista las solicitudes de acceso ya registradas por el usuario (organismo, fecha de envío, estado, nº de expediente y qué se pidió) para localizar a cuál pertenece este documento. Sin argumentos devuelve las más recientes; con reference busca por nº de expediente/registro; con query filtra por texto (organismo o materia). Si identificas la solicitud, devuelve su id en matchedRequestId.',
)]
final class SearchUserRequestsTool
{
    private const MAX_RESULTS = 30;

    public function __construct(
        private readonly AnalysisToolContext $context,
        private readonly AccessRequestRepository $accessRequestRepository,
    ) {
    }

    /**
     * @param string $reference Número de expediente o de registro a buscar (opcional)
     * @param string $query     Texto libre para filtrar por organismo o materia solicitada (opcional)
     */
    public function __invoke(string $reference = '', string $query = ''): string
    {
        $owner = $this->context->getOwner();

        // La referencia exacta es el criterio más fuerte: si matchea, es esa.
        if ($reference !== '') {
            $match = $this->accessRequestRepository->findByExternalId($reference, $owner);
            if ($match) {
                return "Coincidencia exacta por número de expediente:\n" . $this->formatRequest($match);
            }
        }

        $requests = $this->accessRequestRepository->findBy(
            ['user' => $owner],
            ['sentAt' => 'DESC'],
            self::MAX_RESULTS * 2,
        );

        if ($query !== '') {
            $needle = mb_strtolower($query);
            $requests = array_values(array_filter($requests, function (AccessRequest $r) use ($needle) {
                $haystack = mb_strtolower(implode(' ', [
                    $r->getTitle(),
                    (string) $r->getDescription(),
                    $r->getPublicBody()->getName(),
                    (string) $r->getExternalId(),
                ]));

                return str_contains($haystack, $needle);
            }));
        }

        $requests = array_slice($requests, 0, self::MAX_RESULTS);

        if ($requests === []) {
            return $query !== '' || $reference !== ''
                ? 'Ninguna solicitud del usuario coincide con esos criterios. Prueba sin filtros para ver todas.'
                : 'El usuario no tiene solicitudes registradas todavía.';
        }

        $lines = [sprintf('Solicitudes registradas del usuario (%d):', count($requests))];
        foreach ($requests as $request) {
            $lines[] = $this->formatRequest($request);
        }
        $lines[] = 'Si una de ellas corresponde a este documento, devuelve su id en el campo matchedRequestId del análisis final.';

        return implode("\n", $lines);
    }

    private function formatRequest(AccessRequest $request): string
    {
        $bits = [
            'organismo: ' . $request->getPublicBody()->getName(),
            'estado: ' . $request->getStatus(),
        ];
        if ($request->getSentAt()) {
            $bits[] = 'enviada: ' . $request->getSentAt()->format('Y-m-d');
        }
        if ($request->getExternalId()) {
            $bits[] = 'expediente: ' . $request->getExternalId();
        }
        $description = trim((string) $request->getDescription());
        if ($description !== '') {
            $bits[] = 'pedido: ' . mb_substr($description, 0, 200);
        }

        return sprintf('- [%s] %s — %s', $request->getId(), $request->getTitle(), implode(' | ', $bits));
    }
}
