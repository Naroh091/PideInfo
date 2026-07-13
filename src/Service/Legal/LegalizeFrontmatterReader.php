<?php

declare(strict_types=1);

namespace App\Service\Legal;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the YAML frontmatter of a legalize-es file — and nothing else.
 *
 * Streams the header with fgets() instead of file_get_contents(): the catalogue sync walks
 * tens of thousands of files and some of them are 1.7 MB (Código Civil, LEC). Loading their
 * bodies to read the first 30 lines would make the daily 8am job a memory event.
 */
final class LegalizeFrontmatterReader
{
    /** No real norm has a longer header; a runaway file must not be read to the end. */
    private const MAX_FRONTMATTER_LINES = 300;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the file has no frontmatter or the YAML is
     *                                   broken — one bad file must not take the sync down
     */
    public function read(string $absolutePath): ?array
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            $this->logger->warning('legalize: cannot open file', ['path' => $absolutePath]);

            return null;
        }

        try {
            $first = fgets($handle);
            if ($first === false || rtrim($first, "\r\n") !== '---') {
                return null;
            }

            $lines = [];
            $count = 0;

            while (($line = fgets($handle)) !== false) {
                if (++$count > self::MAX_FRONTMATTER_LINES) {
                    $this->logger->warning('legalize: frontmatter fence not found', ['path' => $absolutePath]);

                    return null;
                }

                if (rtrim($line, "\r\n") === '---') {
                    return $this->parse(implode('', $lines), $absolutePath);
                }

                $lines[] = $line;
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parse(string $yaml, string $path): ?array
    {
        try {
            // Deliberately NOT Yaml::PARSE_DATETIME: it hands back mutable \DateTime objects.
            // Dates are normalised to DateTimeImmutable by the caller.
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $e) {
            $this->logger->warning('legalize: broken frontmatter', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (!is_array($parsed) || !isset($parsed['identifier'], $parsed['title'])) {
            $this->logger->warning('legalize: frontmatter without identifier/title', ['path' => $path]);

            return null;
        }

        return $parsed;
    }

    /** Frontmatter dates are plain `YYYY-MM-DD` strings. Anything else is dropped, not guessed. */
    public static function toDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%s-%s-%s 00:00:00', $m[1], $m[2], $m[3]));

        return $date !== false ? $date : null;
    }

    /** @return list<string> */
    public static function toStringList(mixed $value): array
    {
        if (is_string($value)) {
            return $value !== '' ? [$value] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
