<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ComplaintOrganism;
use App\Repository\ComplaintOrganismRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Directorio de consejos de transparencia para el pie público.
 *
 * Fuente única de verdad: el catálogo `ComplaintOrganism` (mismo que usa el
 * flujo de reclamaciones), de modo que si la web de un consejo cambia en BD el
 * pie la refleja sin tocar plantillas. Devuelve solo los que tienen `url`, con
 * el CTBG (estatal) primero y el resto por sigla — así el chip estatal ancla la
 * fila. El resultado se cachea por petición (el pie aparece en todas las
 * páginas públicas y el catálogo es diminuto y estable).
 */
final class TransparencyDirectoryExtension extends AbstractExtension
{
    /** @var list<array{shortName: string, name: string, url: string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ComplaintOrganismRepository $organisms,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('transparency_councils', [$this, 'councils']),
        ];
    }

    /**
     * @return list<array{shortName: string, name: string, url: string}>
     */
    public function councils(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $councils = [];
        foreach ($this->organisms->findAllOrdered() as $organism) {
            $url = $organism->getUrl();
            $shortName = $organism->getShortName();
            if ($url === null || $url === '' || $shortName === null || $shortName === '') {
                continue;
            }
            $councils[] = [
                'shortName' => $shortName,
                'name' => $organism->getName(),
                'url' => $url,
            ];
        }

        usort($councils, static function (array $a, array $b): int {
            // El CTBG estatal abre la fila; el resto, alfabético por sigla.
            if ($a['shortName'] === ComplaintOrganism::SHORT_NAME_CTBG) {
                return -1;
            }
            if ($b['shortName'] === ComplaintOrganism::SHORT_NAME_CTBG) {
                return 1;
            }

            return strcmp($a['shortName'], $b['shortName']);
        });

        return $this->cache = $councils;
    }
}
