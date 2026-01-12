<?php

namespace App\Form\DataTransformer;

use App\Entity\PublicBody;
use App\Repository\PublicBodyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

class PublicBodyTransformer implements DataTransformerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicBodyRepository $repository,
    ) {
    }

    /**
     * Transforms a PublicBody entity to its ID (or name for new ones)
     */
    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (!$value instanceof PublicBody) {
            throw new TransformationFailedException('Expected a PublicBody entity');
        }

        return (string) $value->getId();
    }

    /**
     * Transforms an ID (or __new__:name) to a PublicBody entity
     */
    public function reverseTransform(mixed $value): ?PublicBody
    {
        if (empty($value)) {
            return null;
        }

        // Check if it's a new entry
        if (str_starts_with($value, '__new__:')) {
            $name = trim(substr($value, 8));
            if (empty($name)) {
                return null;
            }

            // Check if one with this name already exists
            $existing = $this->repository->findOneBy(['name' => $name]);
            if ($existing) {
                return $existing;
            }

            // Create new public body
            $publicBody = new PublicBody();
            $publicBody->setName($name);
            $publicBody->setLevel('other');
            $this->entityManager->persist($publicBody);

            return $publicBody;
        }

        // It's an existing ID
        $publicBody = $this->repository->find($value);

        if (null === $publicBody) {
            throw new TransformationFailedException(sprintf(
                'Public body with ID "%s" not found',
                $value
            ));
        }

        return $publicBody;
    }
}
