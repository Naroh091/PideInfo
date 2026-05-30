<?php

namespace App\Form;

use App\Entity\AccessRequestComplaint;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AccessRequestComplaintType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('externalId', TextType::class, [
                'label' => 'Número de referencia',
                'required' => false,
                'attr' => ['placeholder' => 'Ej: CTBG/RT/0123/2024'],
                'help' => 'Número de expediente del Consejo de Transparencia',
            ])
            ->add('filedAt', DateType::class, [
                'label' => 'Fecha de presentación',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => [
                    'Reclamada' => AccessRequestComplaint::STATUS_RECLAIMED,
                    'Reclamación estimada' => AccessRequestComplaint::STATUS_GRANTED,
                    'Reclamación desestimada' => AccessRequestComplaint::STATUS_DENIED,
                    'Reclamación archivada' => AccessRequestComplaint::STATUS_ARCHIVED,
                ],
            ])
            ->add('complaintResult', ChoiceType::class, [
                'label' => 'Resultado',
                'required' => false,
                'placeholder' => 'Sin resolver',
                'choices' => [
                    'Estimada' => AccessRequestComplaint::RESULT_UPHELD,
                    'Estimada parcialmente' => AccessRequestComplaint::RESULT_PARTIALLY_UPHELD,
                    'Desestimada' => AccessRequestComplaint::RESULT_DISMISSED,
                    'Inadmitida' => AccessRequestComplaint::RESULT_INADMITTED,
                    'Archivada' => AccessRequestComplaint::RESULT_ARCHIVED,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccessRequestComplaint::class,
        ]);
    }
}
