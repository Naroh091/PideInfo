<?php

namespace App\Form;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Form\Type\PublicBodyAutocompleteType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class AccessRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Título de la solicitud',
                'attr' => ['placeholder' => 'Ej: Solicitud de información sobre contratos públicos'],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce un título']),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'attr' => [
                    'placeholder' => 'Describe qué información estás solicitando...',
                    'rows' => 5,
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce una descripción']),
                ],
            ])
            ->add('publicBody', PublicBodyAutocompleteType::class, [
                'label' => 'Organismo público',
            ])
            ->add('applicableLaw', EntityType::class, [
                'class' => ApplicableLaw::class,
                'label' => 'Ley aplicable',
                'placeholder' => 'Selecciona la ley aplicable',
                'choice_label' => 'name',
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, selecciona la ley aplicable']),
                ],
            ])
            ->add('sentAt', DateType::class, [
                'label' => 'Fecha de envío',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => !$options['include_status_fields'],
                'constraints' => $options['include_status_fields'] ? [] : [
                    new NotBlank(['message' => 'Por favor, introduce la fecha de envío']),
                ],
            ])
            ->add('externalId', TextType::class, [
                'label' => 'Número de expediente',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: TRANS/2024/001'],
                'help' => 'Si la administración te ha proporcionado un número de expediente',
            ])
        ;

        if ($options['include_status_fields']) {
            $builder
                ->add('status', ChoiceType::class, [
                    'label' => 'Estado',
                    // Las 6 posiciones vivas. La decisión (concesión/denegación/
                    // inadmisión) se fija en el campo Resolución, no aquí.
                    'choices' => [
                        'Borrador' => AccessRequest::STATUS_PENDING,
                        'Enviada' => AccessRequest::STATUS_SENT,
                        'En trámite' => AccessRequest::STATUS_PROCESSING,
                        'Pendiente de recepción' => AccessRequest::STATUS_GRANTED,
                        'Finalizada' => AccessRequest::STATUS_FINISHED,
                        'Silencio administrativo' => AccessRequest::STATUS_DELAYED,
                    ],
                ])
                ->add('deadlineAt', DateType::class, [
                    'label' => 'Plazo de respuesta',
                    'widget' => 'single_text',
                    'input' => 'datetime_immutable',
                ])
            ;
        }

        if ($options['include_complaint_fields']) {
            $builder->add('complaint', AccessRequestComplaintType::class, [
                'label' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccessRequest::class,
            'include_status_fields' => false,
            'include_complaint_fields' => false,
        ]);
    }
}
