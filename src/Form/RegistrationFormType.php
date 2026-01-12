<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Tu nombre'],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce tu nombre']),
                    new Length(['min' => 2, 'max' => 255]),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Apellidos',
                'attr' => ['placeholder' => 'Tus apellidos'],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce tus apellidos']),
                    new Length(['min' => 2, 'max' => 255]),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Correo electrónico',
                'attr' => ['placeholder' => 'tu@email.com'],
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce tu correo electrónico']),
                    new Email(['message' => 'El correo electrónico no es válido']),
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'Contraseña',
                    'attr' => ['placeholder' => 'Tu contraseña'],
                ],
                'second_options' => [
                    'label' => 'Confirmar contraseña',
                    'attr' => ['placeholder' => 'Repite la contraseña'],
                ],
                'invalid_message' => 'Las contraseñas no coinciden',
                'constraints' => [
                    new NotBlank(['message' => 'Por favor, introduce una contraseña']),
                    new Length([
                        'min' => 8,
                        'minMessage' => 'La contraseña debe tener al menos {{ limit }} caracteres',
                        'max' => 4096,
                    ]),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'Acepto los términos y condiciones',
                'mapped' => false,
                'constraints' => [
                    new IsTrue(['message' => 'Debes aceptar los términos y condiciones']),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
