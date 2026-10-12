<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\SeoExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(SeoExtension::class)]
class SeoExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paths(): iterable
    {
        // Public content: the repository is the site's search surface.
        yield 'home' => ['/', SeoExtension::INDEX];
        yield 'resolutions index' => ['/resoluciones', SeoExtension::INDEX];
        yield 'resolution detail' => ['/resoluciones/0199a1b2-c3d4-4e5f-8a9b-0c1d2e3f4a5b', SeoExtension::INDEX];
        yield 'organism listing' => ['/resoluciones/organismo/ctbg', SeoExtension::INDEX];
        yield 'trailing slash' => ['/resoluciones/', SeoExtension::INDEX];
        yield 'privacy notice' => ['/guias/privacidad', SeoExtension::INDEX];
        yield 'anonymous drafting landing' => ['/redactar', SeoExtension::INDEX];

        // Private or personal: everything else.
        yield 'dashboard' => ['/panel', SeoExtension::NOINDEX];
        yield 'requests' => ['/solicitudes', SeoExtension::NOINDEX];
        yield 'request detail' => ['/solicitudes/0199a1b2-c3d4-4e5f-8a9b-0c1d2e3f4a5b', SeoExtension::NOINDEX];
        yield 'documents' => ['/documentos', SeoExtension::NOINDEX];
        yield 'communications' => ['/comunicaciones', SeoExtension::NOINDEX];
        yield 'profile' => ['/perfil/aplicaciones-conectadas', SeoExtension::NOINDEX];
        yield 'admin' => ['/admin', SeoExtension::NOINDEX];
        yield 'other guides stay private' => ['/guias/como-reclamar', SeoExtension::NOINDEX];
        yield 'a concrete anonymous draft' => ['/redactar/solicitud/0199a1b2-c3d4-4e5f-8a9b-0c1d2e3f4a5b', SeoExtension::NOINDEX];
        yield 'a concrete anonymous complaint draft' => ['/redactar/reclamacion/0199a1b2-c3d4-4e5f-8a9b-0c1d2e3f4a5b', SeoExtension::NOINDEX];
        yield 'assistant chat' => ['/asistente/conversacion', SeoExtension::NOINDEX];
        yield 'login' => ['/login', SeoExtension::NOINDEX];
        yield 'mesa de resoluciones' => ['/mesa-resoluciones', SeoExtension::NOINDEX];
    }

    #[DataProvider('paths')]
    public function test_robots_for_path(string $path, string $expected): void
    {
        $this->assertSame($expected, $this->extension()->robotsForPath($path));
    }

    /**
     * `/mesa-resoluciones` must not be swept in by the `/resoluciones` rule.
     */
    public function test_prefix_match_respects_path_segments(): void
    {
        $extension = $this->extension();

        $this->assertSame(SeoExtension::NOINDEX, $extension->robotsForPath('/resolucioneso'));
        $this->assertSame(SeoExtension::NOINDEX, $extension->robotsForPath('/mesa-resoluciones/pendientes'));
    }

    public function test_uses_the_current_request(): void
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/resoluciones/0199a1b2-c3d4-4e5f-8a9b-0c1d2e3f4a5b'));

        $this->assertSame(SeoExtension::INDEX, (new SeoExtension($stack))->robots());
    }

    /**
     * No request (CLI rendering, e.g. the PDF templates) must not claim to be
     * an indexable page.
     */
    public function test_without_a_request_it_is_noindex(): void
    {
        $this->assertSame(SeoExtension::NOINDEX, (new SeoExtension(new RequestStack()))->robots());
    }

    private function extension(): SeoExtension
    {
        return new SeoExtension(new RequestStack());
    }
}
