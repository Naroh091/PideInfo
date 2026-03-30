<?php

namespace App\Form\Type;

use App\Repository\PublicBodyRepository;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * Choice loader that presents existing public bodies but also accepts
 * new values prefixed with __new__: from Tom Select's create feature.
 */
class PublicBodyChoiceLoader implements ChoiceLoaderInterface
{
    public function __construct(
        private readonly PublicBodyRepository $repository,
    ) {
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        $publicBodies = $this->repository->findBy([], ['name' => 'ASC']);
        $choices = [];
        foreach ($publicBodies as $pb) {
            $choices[$pb->getName()] = (string) $pb->getId();
        }

        return new ArrayChoiceList($choices, $value);
    }

    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        $choices = [];
        foreach ($values as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            // Accept __new__: values as-is — they'll be handled by the transformer
            $choices[] = $v;
        }
        return $choices;
    }

    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        $values = [];
        foreach ($choices as $choice) {
            if ($choice === null || $choice === '') {
                continue;
            }
            $values[] = (string) $choice;
        }
        return $values;
    }
}
