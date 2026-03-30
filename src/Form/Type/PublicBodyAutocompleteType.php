<?php

namespace App\Form\Type;

use App\Form\DataTransformer\PublicBodyTransformer;
use App\Repository\PublicBodyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PublicBodyAutocompleteType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PublicBodyRepository $repository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(
            new PublicBodyTransformer($this->entityManager, $this->repository)
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'choice_loader' => new PublicBodyChoiceLoader($this->repository),
            'placeholder' => 'Busca o crea un organismo...',
            'required' => false,
            'attr' => [
                'data-controller' => 'tom-select',
                'data-tom-select-create-value' => 'true',
                'data-tom-select-placeholder-value' => 'Busca o crea un organismo...',
            ],
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }
}
