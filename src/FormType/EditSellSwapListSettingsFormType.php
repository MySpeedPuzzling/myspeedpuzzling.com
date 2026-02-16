<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditSellSwapListSettingsFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<EditSellSwapListSettingsFormData>
 */
final class EditSellSwapListSettingsFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

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

        $builder->add('shippingCountries', ChoiceType::class, [
            'label' => 'sell_swap_list.settings.shipping_countries',
            'required' => false,
            'multiple' => true,
            'autocomplete' => true,
            'choices' => $this->getCountryChoicesGroupedByRegion(),
            'choice_translation_domain' => false,
            'choice_attr' => static function (string $countryCode): array {
                return ['data-icon' => 'fi fi-' . $countryCode];
            },
        ]);

        $builder->add('shippingCost', TextType::class, [
            'label' => 'sell_swap_list.settings.shipping_cost',
            'required' => false,
            'attr' => [
                'placeholder' => 'sell_swap_list.settings.shipping_cost_placeholder',
            ],
        ]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getCountryChoicesGroupedByRegion(): array
    {
        $centralEurope = [
            CountryCode::cz, CountryCode::sk, CountryCode::pl, CountryCode::hu,
            CountryCode::at, CountryCode::si, CountryCode::ch, CountryCode::li,
        ];

        $westernEurope = [
            CountryCode::de, CountryCode::fr, CountryCode::nl, CountryCode::be,
            CountryCode::lu, CountryCode::ie, CountryCode::gb, CountryCode::mc,
        ];

        $southernEurope = [
            CountryCode::es, CountryCode::pt, CountryCode::it, CountryCode::gr,
            CountryCode::hr, CountryCode::ba, CountryCode::rs, CountryCode::me,
            CountryCode::mk, CountryCode::al, CountryCode::mt, CountryCode::cy,
        ];

        $northernEurope = [
            CountryCode::se, CountryCode::no, CountryCode::dk, CountryCode::fi,
            CountryCode::is, CountryCode::ee, CountryCode::lv, CountryCode::lt,
        ];

        $easternEurope = [
            CountryCode::ro, CountryCode::bg, CountryCode::ua, CountryCode::md,
            CountryCode::by,
        ];

        $northAmerica = [
            CountryCode::us, CountryCode::ca, CountryCode::mx,
        ];

        $groups = [
            $this->translator->trans('sell_swap_list.settings.region.central_europe') => $centralEurope,
            $this->translator->trans('sell_swap_list.settings.region.western_europe') => $westernEurope,
            $this->translator->trans('sell_swap_list.settings.region.southern_europe') => $southernEurope,
            $this->translator->trans('sell_swap_list.settings.region.northern_europe') => $northernEurope,
            $this->translator->trans('sell_swap_list.settings.region.eastern_europe') => $easternEurope,
            $this->translator->trans('sell_swap_list.settings.region.north_america') => $northAmerica,
        ];

        // Collect all codes used in groups
        $usedCodes = [];
        foreach ($groups as $countries) {
            foreach ($countries as $country) {
                $usedCodes[] = $country->name;
            }
        }

        // Remaining countries
        $restOfWorld = [];
        foreach (CountryCode::cases() as $country) {
            if (!in_array($country->name, $usedCodes, true)) {
                $restOfWorld[] = $country;
            }
        }

        $groups[$this->translator->trans('sell_swap_list.settings.region.rest_of_world')] = $restOfWorld;

        $choices = [];
        foreach ($groups as $groupName => $countries) {
            foreach ($countries as $country) {
                $choices[$groupName][$country->value] = $country->name;
            }
        }

        return $choices;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditSellSwapListSettingsFormData::class,
        ]);
    }
}
