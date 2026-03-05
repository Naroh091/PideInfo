<?php

namespace App\Form;

use App\Entity\AccessRequestList;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class AccessRequestListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre de la lista',
                'attr' => ['placeholder' => 'Ej: Solicitudes de medio ambiente'],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce un nombre']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Descripción opcional de la lista...',
                    'rows' => 3,
                ],
            ])
            ->add('color', ChoiceType::class, [
                'label' => 'Color',
                'required' => false,
                'placeholder' => 'Sin color',
                'choices' => [
                    'Azul' => '#3b82f6',
                    'Verde' => '#22c55e',
                    'Amarillo' => '#eab308',
                    'Rojo' => '#ef4444',
                    'Morado' => '#a855f7',
                    'Rosa' => '#ec4899',
                    'Naranja' => '#f97316',
                    'Gris' => '#6b7280',
                ],
            ])
        ;

        if ($options['show_visibility']) {
            $builder->add('visibility', ChoiceType::class, [
                'label' => 'Visibilidad',
                'choices' => [
                    'Privada' => AccessRequestList::VISIBILITY_PRIVATE,
                    'Compartida' => AccessRequestList::VISIBILITY_SHARED,
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccessRequestList::class,
            'show_visibility' => false,
        ]);
    }
}
