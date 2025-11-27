<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditSellSwapListSettingsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EditSellSwapListSettingsFormData>
 */
final class EditSellSwapListSettingsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('description', TextareaType::class, [
            'label' => 'sell_swap_list.settings.description',
            'required' => false,
            'attr' => [
                'rows' => 3,
            ],
            'help' => 'sell_swap_list.settings.description_help',
        ]);

        $builder->add('currency', ChoiceType::class, [
            'label' => 'sell_swap_list.settings.currency',
            'required' => false,
            'placeholder' => 'sell_swap_list.settings.currency_placeholder',
            'choices' => [
                'USD ($)' => 'USD',
                'EUR (€)' => 'EUR',
                'GBP (£)' => 'GBP',
                'CZK (Kč)' => 'CZK',
                'PLN (zł)' => 'PLN',
                'sell_swap_list.settings.currency_custom' => 'custom',
            ],
        ]);

        $builder->add('customCurrency', TextType::class, [
            'label' => 'sell_swap_list.settings.custom_currency',
            'required' => false,
            'attr' => [
                'placeholder' => 'sell_swap_list.settings.custom_currency_placeholder',
            ],
        ]);

        $builder->add('shippingInfo', TextareaType::class, [
            'label' => 'sell_swap_list.settings.shipping_info',
            'required' => false,
            'attr' => [
                'rows' => 3,
            ],
            'help' => 'sell_swap_list.settings.shipping_info_help',
        ]);

        $builder->add('contactInfo', TextareaType::class, [
            'label' => 'sell_swap_list.settings.contact_info',
            'required' => false,
            'attr' => [
                'rows' => 3,
            ],
            'help' => 'sell_swap_list.settings.contact_info_help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditSellSwapListSettingsFormData::class,
        ]);
    }
}
