<?php

namespace App\Form;

use App\Entity\CustomDeadline;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

class CustomDeadlineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('deadlineAt', DateType::class, [
                'label' => 'Fecha del recordatorio',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce una fecha']),
                    new GreaterThanOrEqual([
                        'value' => 'today',
                        'message' => 'La fecha debe ser hoy o posterior',
                    ]),
                ],
                'attr' => [
                    'min' => (new \DateTimeImmutable())->format('Y-m-d'),
                ],
            ])
            ->add('notifyTime', TimeType::class, [
                'label' => 'Hora de notificación',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => [
                    'placeholder' => '08:00',
                ],
                'help' => 'Deja vacío para recibir la notificación a las 8:00',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'attr' => [
                    'placeholder' => 'Ej: Contactar con el organismo para seguimiento...',
                    'rows' => 3,
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce una descripción']),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomDeadline::class,
        ]);
    }
}
