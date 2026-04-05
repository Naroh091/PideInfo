<?php

namespace App\Service\Resolution;

use App\Entity\PublicBody;
use App\Repository\PublicBodyRepository;
use Cocur\Slugify\Slugify;
use Doctrine\ORM\EntityManagerInterface;

class PublicBodyResolver
{
    private Slugify $slugify;

    /** @var array<string, PublicBody> */
    private array $cache = [];

    public function __construct(
        private readonly PublicBodyRepository $publicBodyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        $this->slugify = new Slugify();
    }

    public function resolve(?string $name): ?PublicBody
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $name = trim($name);
        $key = mb_strtolower($name);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $publicBody = $this->publicBodyRepository->findOneByNameInsensitive($name);

        if (!$publicBody) {
            $publicBody = new PublicBody();
            $publicBody->setName($name);
            $publicBody->setLevel($this->guessLevel($name));
            $publicBody->setSlug($this->generateUniqueSlug($name));
            $this->entityManager->persist($publicBody);
        }

        $this->cache[$key] = $publicBody;

        return $publicBody;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }

    private function guessLevel(string $name): string
    {
        $lower = mb_strtolower($name);

        $localPrefixes = ['ayuntamiento ', 'diputación ', 'diputacion ', 'cabildo ', 'consell insular', 'mancomunidad '];
        foreach ($localPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'local';
            }
        }

        $autonomousPrefixes = ['consejería ', 'consejeria ', 'conselleria ', 'junta de ', 'gobierno de ', 'parlamento de '];
        foreach ($autonomousPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'autonomous';
            }
        }

        $statePrefixes = ['ministerio ', 'congreso ', 'senado'];
        foreach ($statePrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return 'state';
            }
        }

        return 'other';
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = $this->slugify->slugify($name);
        if ($base === '') {
            $base = 'entidad';
        }

        $slug = $base;
        $i = 2;
        while ($this->publicBodyRepository->findOneBy(['slug' => $slug])) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
