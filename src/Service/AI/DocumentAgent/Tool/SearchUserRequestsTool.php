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
     * @param string $query     Palabras clave para puntuar por organismo o materia solicitada, p. ej. "alumbrado El Algar Cartagena" (opcional)
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

        // Pool completo del usuario (acotado): la solicitud buscada puede ser
        // antigua, no vale quedarse con las N más recientes antes de puntuar.
        $requests = $this->accessRequestRepository->findBy(
            ['user' => $owner],
            ['sentAt' => 'DESC'],
            500,
        );

        // La query se puntúa por tokens (no como substring literal): una
        // descripción larga en lenguaje natural debe encontrar la solicitud
        // que comparte organismo/materia aunque no coincida palabra por palabra.
        if ($query !== '') {
            $tokens = $this->tokenize($query);
            if ($tokens !== []) {
                $scored = [];
                foreach ($requests as $i => $request) {
                    $haystack = mb_strtolower(implode(' ', [
                        $request->getTitle(),
                        $request->getDescription(),
                        $request->getPublicBody()->getName(),
                        (string) $request->getExternalId(),
                    ]));
                    $score = 0;
                    foreach ($tokens as $token) {
                        if (str_contains($haystack, $token)) {
                            $score++;
                        }
                    }
                    if ($score > 0) {
                        $scored[] = [$score, $i, $request];
                    }
                }
                // Mejor puntuación primero; a igualdad, la más reciente.
                usort($scored, fn(array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
                $requests = array_column($scored, 2);
            }
        }

        $total = count($requests);
        $requests = array_slice($requests, 0, self::MAX_RESULTS);

        if ($requests === []) {
            return $query !== ''
                ? 'Ninguna solicitud del usuario coincide con esas palabras clave. Vuelve a llamar sin query para ver las más recientes.'
                : 'El usuario no tiene solicitudes registradas todavía.';
        }

        $lines = [sprintf(
            'Solicitudes del usuario%s (%d%s):',
            $query !== '' ? ' ordenadas por afinidad con la búsqueda' : ' más recientes',
            count($requests),
            $total > count($requests) ? sprintf(' de %d — usa query para afinar', $total) : '',
        )];
        foreach ($requests as $request) {
            $lines[] = $this->formatRequest($request);
        }
        $lines[] = 'Si una de ellas corresponde a este documento, devuelve su id en el campo matchedRequestId del análisis final.';

        return implode("\n", $lines);
    }

    /**
     * @return list<string> lowercase keywords (≥4 chars, sin duplicados, máx. 12)
     */
    private function tokenize(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}\/-]+/u', mb_strtolower($query)) ?: [];
        $tokens = array_values(array_unique(array_filter($words, fn(string $w) => mb_strlen($w) >= 4)));

        return array_slice($tokens, 0, 12);
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
