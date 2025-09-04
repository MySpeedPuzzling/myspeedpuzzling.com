<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\MarkForSaleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MarkForSaleFormData>
 */
final class MarkForSaleFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('saleType', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Sell' => 'sell',
                    'Lend' => 'lend',
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('price', NumberType::class, [
                'label' => 'Price',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'placeholder' => 'Enter price (optional)',
                    'step' => '0.01',
                ],
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'Currency',
                'required' => false,
                'choices' => [
                    'US Dollar (USD)' => 'USD',
                    'Euro (EUR)' => 'EUR',
                    'British Pound (GBP)' => 'GBP',
                    'Czech Koruna (CZK)' => 'CZK',
                    'Polish Złoty (PLN)' => 'PLN',
                    'Canadian Dollar (CAD)' => 'CAD',
                    'Australian Dollar (AUD)' => 'AUD',
                    'Japanese Yen (JPY)' => 'JPY',
                    'Swiss Franc (CHF)' => 'CHF',
                    'Swedish Krona (SEK)' => 'SEK',
                    'Norwegian Krone (NOK)' => 'NOK',
                    'Hungarian Forint (HUF)' => 'HUF',
                    'New Zealand Dollar (NZD)' => 'NZD',
                ],
                'placeholder' => 'Select currency',
                'attr' => [
                    'class' => 'form-select',
                ],
            ])
            ->add('condition', TextType::class, [
                'label' => 'Condition',
                'required' => false,
                'attr' => [
                    'placeholder' => 'e.g., New, Used, Missing 1 piece',
                    'maxlength' => 255,
                ],
            ])
            ->add('comment', TextareaType::class, [
                'label' => 'Additional Information',
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'Any additional details about the sale/lending',
                    'maxlength' => 500,
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MarkForSaleFormData::class,
        ]);
    }
}
