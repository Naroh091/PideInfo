<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Postal address + contact phone the REG step-1 form requires. Bound directly
 * to `User` (mapped fields) so saving the form updates the profile in place.
 *
 * Tipo de vía / Provincia / Población son **etiquetas exactas del REG**
 * (no códigos INE) cargadas desde `public/data/reg/options.json` por el
 * Stimulus controller `reg_address_form_controller.js`. Almacenamos la
 * etiqueta porque es lo que el agente luego inyecta literalmente en los
 * mat-select del REG, evitando un mapping intermedio.
 *
 * El form usa `HiddenType` para los tres campos: la UI real (combos +
 * cascada provincia → municipio) la pinta el controller; aquí sólo nos
 * preocupamos del valor final que viaja en el POST.
 */
class UserPersonalDataType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('addressStreetType', HiddenType::class, [
                'attr' => ['data-reg-address-form-target' => 'streetType'],
                'constraints' => [
                    new NotBlank(['message' => 'Selecciona el tipo de vía']),
                    new Length(max: 30),
                ],
            ])
            ->add('addressLine', TextType::class, [
                'label' => 'Dirección',
                'attr' => ['placeholder' => 'Nombre de la vía, número, escalera, piso, puerta', 'maxlength' => 160],
                'constraints' => [
                    new NotBlank(['message' => 'Introduce tu dirección']),
                    new Length(max: 160),
                ],
            ])
            ->add('addressCountry', HiddenType::class, [
                'data' => 'ES',
                'constraints' => [new NotBlank()],
            ])
            ->add('addressProvince', HiddenType::class, [
                'attr' => ['data-reg-address-form-target' => 'province'],
                'constraints' => [
                    new NotBlank(['message' => 'Selecciona tu provincia']),
                    new Length(max: 100),
                ],
            ])
            ->add('addressMunicipality', HiddenType::class, [
                'attr' => ['data-reg-address-form-target' => 'municipality'],
                'constraints' => [
                    new NotBlank(['message' => 'Selecciona tu población']),
                    new Length(max: 120),
                ],
            ])
            ->add('addressPostalCode', TextType::class, [
                'label' => 'Código Postal',
                'attr' => ['placeholder' => '28013', 'maxlength' => 10, 'inputmode' => 'numeric'],
                'constraints' => [
                    new NotBlank(['message' => 'Introduce el código postal']),
                    new Regex(['pattern' => '/^\d{5}$/', 'message' => 'CP español de 5 dígitos']),
                ],
            ])
            ->add('contactPhone', TelType::class, [
                'label' => 'Teléfono principal',
                'attr' => ['placeholder' => '600123456', 'maxlength' => 20, 'inputmode' => 'tel'],
                'constraints' => [
                    new NotBlank(['message' => 'Introduce un teléfono de contacto']),
                    new Regex(['pattern' => '/^\+?\d[\d\s\-]{6,18}$/', 'message' => 'Teléfono inválido']),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
