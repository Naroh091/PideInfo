<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Central indexing rule: `base.html.twig` asks this for the `robots` meta of
 * every page.
 *
 * The default is **noindex**, and only the paths listed here opt in. Deny by
 * default is the safe direction for a site whose bulk is private: panels,
 * expedientes, documents, notifications and anonymous drafts all carry personal
 * data, and before this the only thing keeping them out of search results was
 * the login wall — anything served publicly by mistake was indexable.
 *
 * Keep {@see INDEXABLE_PREFIXES} aligned with the PUBLIC_ACCESS entries in
 * config/packages/security.yaml that actually hold public content. Public but
 * personal or transactional routes (/asistente, auth screens, per-draft URLs)
 * stay out on purpose.
 */
final class SeoExtension extends AbstractExtension
{
    public const INDEX = 'index, follow';
    public const NOINDEX = 'noindex, nofollow';

    /**
     * Paths indexable on their own but not in their sub-paths. `/redactar` is
     * the public landing for drafting without an account; everything below it
     * identifies a concrete draft and must stay out of the index.
     *
     * @var list<string>
     */
    private const INDEXABLE_EXACT = ['/', '/redactar'];

    /**
     * Paths indexable including everything under them: the public resolution
     * repository (the site's search surface) and the privacy notice.
     *
     * @var list<string>
     */
    private const INDEXABLE_PREFIXES = ['/resoluciones', '/guias/privacidad'];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('seo_robots', [$this, 'robots']),
        ];
    }

    public function robots(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return null === $request ? self::NOINDEX : $this->robotsForPath($request->getPathInfo());
    }

    /**
     * Split out from {@see robots()} so the rule is testable without a request.
     */
    public function robotsForPath(string $path): string
    {
        $path = '/' === $path ? $path : rtrim($path, '/');

        if (in_array($path, self::INDEXABLE_EXACT, true)) {
            return self::INDEX;
        }

        foreach (self::INDEXABLE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return self::INDEX;
            }
        }

        return self::NOINDEX;
    }
}
