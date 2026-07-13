<?php

declare(strict_types=1);

namespace App\Service\Legal;

use App\Entity\LegalArticle;
use App\Entity\LegalNorm;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The wildcard that makes the two-tier design work: reads the articulado of ANY norm in the
 * BOE straight from /var/data/legalize, indexed or not.
 *
 * Because of this, the whitelist only decides what is *discoverable* through
 * `search_legislation`; it never decides what is *readable*. `read_law_articles` reaches the
 * whole corpus.
 *
 * The LegalArticle objects returned are transient — built in memory, never persisted. They
 * exist so everything downstream (LegalCitationFormatter, the tools) handles tracked and
 * untracked norms through exactly one type.
 */
final class LegalNormReader
{
    /** A norm bigger than this is not a norm we can usefully paste into a prompt anyway. */
    private const MAX_FILE_BYTES = 5_242_880;

    public function __construct(
        private readonly LegalizeMarkdownParser $parser,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(LEGALIZE_PATH)%')]
        private readonly string $legalizePath,
    ) {
    }

    /**
     * @return list<LegalArticle> transient, in document order
     */
    public function readArticles(LegalNorm $norm): array
    {
        $parsed = $this->parsedArticles($norm);

        return array_map(
            fn (ParsedArticle $p): LegalArticle => $this->toTransient($norm, $p),
            $parsed,
        );
    }

    /**
     * Whole body of a norm that has no numbered articulado (orders, one-page resolutions).
     * Without this the model would get silence for a norm that does exist.
     */
    public function readRaw(LegalNorm $norm, int $maxChars): string
    {
        $markdown = $this->readFile($norm);
        if ($markdown === null) {
            return '';
        }

        $body = preg_replace('/^---\r?\n.*?\r?\n---\r?\n/su', '', $markdown) ?? $markdown;
        $body = preg_replace('#<small>.*?</small>#su', '', $body) ?? $body;
        $body = trim($body);

        return mb_strlen($body) > $maxChars
            ? mb_substr($body, 0, $maxChars) . "\n\n… [texto truncado]"
            : $body;
    }

    public function fileExists(LegalNorm $norm): bool
    {
        return $this->resolvePath($norm) !== null;
    }

    /** @return list<ParsedArticle> */
    private function parsedArticles(LegalNorm $norm): array
    {
        $key = sprintf('legal.norm.%s.%s', $norm->getBoeId(), $norm->getContentHash() ?? 'nohash');

        /** @var list<ParsedArticle> */
        return $this->cache->get($key, function (ItemInterface $item) use ($norm): array {
            $item->expiresAfter(86400);

            $markdown = $this->readFile($norm);

            return $markdown === null ? [] : $this->parser->parse($markdown);
        });
    }

    private function readFile(LegalNorm $norm): ?string
    {
        $path = $this->resolvePath($norm);
        if ($path === null) {
            return null;
        }

        $size = @filesize($path);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            $this->logger->warning('legalize: norm file too large to read on demand', [
                'boeId' => $norm->getBoeId(),
                'bytes' => $size,
            ]);

            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * `relative_path` comes from an external repository, so it is untrusted input: resolve it
     * and refuse anything that escapes the checkout.
     */
    private function resolvePath(LegalNorm $norm): ?string
    {
        $base = realpath($this->legalizePath);
        if ($base === false) {
            $this->logger->error('legalize: checkout not found', ['path' => $this->legalizePath]);

            return null;
        }

        $candidate = realpath($base . '/' . $norm->getRelativePath());
        if ($candidate === false) {
            return null;
        }

        if (!str_starts_with($candidate, $base . \DIRECTORY_SEPARATOR)) {
            $this->logger->error('legalize: path traversal blocked', [
                'boeId' => $norm->getBoeId(),
                'relativePath' => $norm->getRelativePath(),
            ]);

            return null;
        }

        return $candidate;
    }

    private function toTransient(LegalNorm $norm, ParsedArticle $parsed): LegalArticle
    {
        return (new LegalArticle())
            ->setNorm($norm)
            ->setAnchor($parsed->anchor)
            ->setKind($parsed->kind)
            ->setNumber($parsed->number)
            ->setNumberInt($parsed->numberInt)
            ->setNumberSuffix($parsed->numberSuffix)
            ->setPosition($parsed->position)
            ->setHeading($parsed->heading)
            ->setBreadcrumb($parsed->breadcrumb)
            ->setBreadcrumbJson($parsed->breadcrumbJson)
            ->setContent($parsed->content)
            ->setContentNotes($parsed->contentNotes)
            ->setRepealed($parsed->repealed);
    }
}
