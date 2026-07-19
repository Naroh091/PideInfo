<?php

namespace App\Experiment;

use Growthbook\Growthbook;
use Growthbook\InlineExperiment;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpFoundation\Request;

/**
 * A/B(/n) test for the public home hero: control ("El derecho enunciado") vs.
 * seven concrete citizen questions. Each arm is a full copy block (eyebrow +
 * marked title + subtitle); this class is the single source of truth for that
 * copy AND for the inline experiment definition.
 *
 * Assignment is deterministic per anonymous visitor (a first-party `pi_vid`
 * cookie hashed by GrowthBook). Rendering happens server-side so the visitor
 * never sees a control→variant flash.
 *
 * Two evaluation modes:
 *  - Managed: if GROWTHBOOK_CLIENT_KEY is set, the experiment lives in the
 *    GrowthBook dashboard as feature `home-hero` (a string returning one of the
 *    variant keys below). Weights, coverage and on/off are controlled there.
 *  - Inline fallback: with no key configured, an equal-weight 8-arm experiment
 *    baked in here runs, so the test works out of the box. The managed feature,
 *    once created, takes over transparently.
 *
 * Either way, exposure is captured from getViewedExperiments() and forwarded to
 * GA4 (see templates/home/index.html.twig). GrowthBook's GA4 datasource joins on
 * GA's own user_pseudo_id, so `pi_vid` never leaves the server.
 */
final class HomeHeroExperiment
{
    public const EXPERIMENT_KEY = 'home-hero';
    public const VISITOR_COOKIE = 'pi_vid';
    private const CONTROL = 'control';

    /**
     * @var array<string, array<string, string>> variant key => copy block.
     *   The title is split so Twig can wrap `titleMark` in `.rotulador`
     *   (the amber marker). Guillemets are added by the template.
     */
    public const VARIANTS = [
        self::CONTROL => [
            'eyebrow' => 'Ley 19/2013, de 9 de diciembre, de transparencia, acceso a la información pública y buen gobierno',
            'titlePre' => 'Todas las personas tienen ',
            'titleMark' => 'derecho a acceder',
            'titlePost' => ' a la información pública',
            'subtitle' => 'El acceso a la información pública en España a veces no es un camino sencillo. PideInfo te acompaña: te ayuda a redactar la solicitud y a gestionar la reclamación si te rechazan lo que pides.',
        ],
        'orquesta' => [
            'eyebrow' => 'Gasto municipal · Contratos de festejos',
            'titlePre' => '¿Cuánto se ha gastado el Ayuntamiento en ',
            'titleMark' => 'la orquesta de las fiestas',
            'titlePost' => ' de verano?',
            'subtitle' => 'Es dinero público y, por tanto, información que puedes pedir. PideInfo te ayuda a redactar la solicitud y a reclamar si el Ayuntamiento no responde.',
        ],
        'limpieza' => [
            'eyebrow' => 'Servicios municipales · Planes de limpieza',
            'titlePre' => '¿Por qué ',
            'titleMark' => 'mi calle se limpia menos',
            'titlePost' => ' que las del centro?',
            'subtitle' => 'Los planes y las frecuencias de limpieza son públicos. PideInfo te ayuda a pedirlos y a reclamar si tu Ayuntamiento no te los facilita.',
        ],
        'salud' => [
            'eyebrow' => 'Sanidad pública · Personal y sustituciones',
            'titlePre' => '¿Cómo se están cubriendo ',
            'titleMark' => 'las bajas en mi centro de salud',
            'titlePost' => '?',
            'subtitle' => 'La gestión de personal de tu centro es información pública. PideInfo te ayuda a redactar la solicitud y a reclamar si la Administración sanitaria guarda silencio.',
        ],
        'inspeccion' => [
            'eyebrow' => 'Salud pública · Inspecciones de establecimientos',
            'titlePre' => '¿Qué bares de mi ciudad ',
            'titleMark' => 'han suspendido la inspección sanitaria',
            'titlePost' => '?',
            'subtitle' => 'Los resultados de las inspecciones son información pública. PideInfo te ayuda a pedirlos y a reclamar si te los niegan.',
        ],
        'metro' => [
            'eyebrow' => 'Transporte público · Reclamaciones de usuarios',
            'titlePre' => '¿Cuántas quejas ha recibido el metro por ',
            'titleMark' => 'el aire acondicionado averiado',
            'titlePost' => '?',
            'subtitle' => 'El registro de quejas de un servicio público puede pedirse. PideInfo te ayuda a redactar la solicitud y a reclamar si la empresa pública no contesta.',
        ],
        'asesores' => [
            'eyebrow' => 'Altos cargos · Personal de confianza',
            'titlePre' => '¿Cuántos ',
            'titleMark' => 'asesores tiene el presidente',
            'titlePost' => ' y cuánto cobran?',
            'subtitle' => 'El personal eventual y sus retribuciones son información pública. PideInfo te ayuda a pedirla y a reclamar si no te responden.',
        ],
        'oposicion' => [
            'eyebrow' => 'Empleo público · Procesos selectivos',
            'titlePre' => '¿Con qué ',
            'titleMark' => 'criterios se corrigió el examen',
            'titlePost' => ' de mi oposición?',
            'subtitle' => 'Los criterios de corrección de un proceso selectivo son información pública. PideInfo te ayuda a solicitarlos y a reclamar si la Administración no los entrega.',
        ],
    ];

    public function __construct(
        #[Autowire(env: 'GROWTHBOOK_CLIENT_KEY')]
        private readonly string $clientKey,
        #[Autowire(env: 'GROWTHBOOK_API_HOST')]
        private readonly string $apiHost,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function assign(Request $request): HeroAssignment
    {
        $visitorId = (string) $request->cookies->get(self::VISITOR_COOKIE, '');
        $newVisitor = $visitorId === '';
        if ($newVisitor) {
            $visitorId = bin2hex(random_bytes(16));
        }

        [$key, $tracking] = $this->resolve($visitorId);

        return new HeroAssignment(
            variant: ['key' => $key] + self::VARIANTS[$key],
            tracking: $tracking,
            visitorId: $visitorId,
            newVisitor: $newVisitor,
        );
    }

    /**
     * @return array{0: string, 1: array{experimentId: string, variationId: int, variationKey: string}|null}
     */
    private function resolve(string $visitorId): array
    {
        $key = self::CONTROL;

        try {
            $gb = Growthbook::create()
                ->withLogger($this->logger)
                ->withAttributes(['id' => $visitorId]);

            if ($this->clientKey !== '') {
                $psr = new Psr18Client();
                $gb->withHttpClient($psr, $psr)
                    ->withCache(new Psr16Cache($this->cache), 60)
                    ->initialize($this->clientKey, $this->apiHost);
                $key = (string) $gb->getValue(self::EXPERIMENT_KEY, self::CONTROL);
            } else {
                $result = $gb->runInlineExperiment(
                    InlineExperiment::create(self::EXPERIMENT_KEY, array_keys(self::VARIANTS))
                );
                $key = $result->inExperiment ? (string) $result->value : self::CONTROL;
            }

            if (!isset(self::VARIANTS[$key])) {
                $key = self::CONTROL;
            }

            foreach ($gb->getViewedExperiments() as $viewed) {
                if ($viewed->experiment->key === self::EXPERIMENT_KEY) {
                    return [$key, [
                        'experimentId' => self::EXPERIMENT_KEY,
                        'variationId' => (int) $viewed->result->variationId,
                        'variationKey' => (string) $viewed->result->value,
                    ]];
                }
            }
        } catch (\Throwable $e) {
            // Never let an experiment failure take down the home page: fall back
            // to control and log for follow-up.
            $this->logger->error('home-hero experiment assignment failed', ['exception' => $e]);

            return [self::CONTROL, null];
        }

        return [$key, null];
    }
}
