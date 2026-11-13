<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Collator;
use SpeedPuzzling\Web\FormData\EditProfileFormData;
use SpeedPuzzling\Web\Services\TimezoneChoices;
use SpeedPuzzling\Web\Value\TimezoneIdentifier;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<EditProfileFormData>
 */
final class EditProfileFormType extends AbstractType
{
    /**
     * The settings field's id - other pages link straight to it (`edit_profile#timezone`, docs/features/player-timezone.md)
     */
    public const string TIMEZONE_ANCHOR = 'timezone';

    public function __construct(
        readonly private TranslatorInterface $translator,
        readonly private TimezoneChoices $timezoneChoices,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'my_profile.name',
            'required' => false,
            'help' => 'forms.name_help',
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'forms.location',
            'required' => false,
            'help' => 'forms.location_help',
        ]);

        // Values are the codes (`cz`), so the browser's guess can pick one (profile_country_controller.js); each country
        // is listed twice (most common + all), which without choice_value would number the options instead.
        // Names in the page's language, the English one kept for the search ("Czechia" finds "Česko").
        $locale = $this->translator->getLocale();
        $collator = new Collator($locale);
        $allCountries = CountryCode::cases();
        usort(
            $allCountries,
            static fn (CountryCode $a, CountryCode $b): int => (int) $collator->compare($a->localizedName($locale), $b->localizedName($locale)),
        );

        $mostCommon = [
            CountryCode::cz,
            CountryCode::sk,
            CountryCode::pl,
            CountryCode::de,
            CountryCode::at,
            CountryCode::no,
            CountryCode::fi,
            CountryCode::us,
            CountryCode::ca,
            CountryCode::fr,
            CountryCode::nz,
            CountryCode::es,
            CountryCode::nl,
            CountryCode::pt,
            CountryCode::gb,
        ];

        $mostCommonCodes = [];
        foreach ($mostCommon as $country) {
            $mostCommonCodes[] = $country->name;
        }

        $allCodes = [];
        foreach ($allCountries as $country) {
            $allCodes[] = $country->name;
        }

        $builder->add('country', ChoiceType::class, [
            'label' => 'forms.nationality',
            'required' => false,
            'expanded' => false,
            'multiple' => false,
            'placeholder' => 'player_country.placeholder',
            'choices' => [
                $this->translator->trans('forms.country_most_common') => $mostCommonCodes,
                $this->translator->trans('forms.country_all') => $allCodes,
            ],
            'choice_value' => static fn (null|string $code): string => $code ?? '',
            'choice_label' => static fn (string $code): string => CountryCode::fromCode($code)?->localizedName($locale) ?? $code,
            'choice_attr' => static fn (string $code): array => [
                'data-flag' => $code,
                'data-alias' => CountryCode::fromCode($code)->value ?? '',
            ],
            'choice_translation_domain' => false,
            'attr' => [
                'data-profile-country-target' => 'select',
            ],
        ]);

        // Searchable through timezone_select_controller.js, which also preselects the browser's zone while none is
        // saved ("Detected from your browser" - stored only when the player saves). An unknown value is a form error.
        $builder->add('timezone', ChoiceType::class, [
            'label' => 'player_timezone.label',
            'help' => 'player_timezone.help',
            'required' => false,
            'placeholder' => 'player_timezone.placeholder',
            'choices' => $this->timezoneChoices->grouped($this->translator->getLocale()),
            'choice_translation_domain' => false,
            'attr' => [
                'data-timezone-select-target' => 'select',
            ],
        ]);

        $builder->add('facebook', TextType::class, [
            'label' => 'facebook',
            'required' => false,
        ]);

        $builder->add('instagram', TextType::class, [
            'label' => 'instagram',
            'required' => false,
        ]);

        $builder->add('twitch', TextType::class, [
            'label' => 'Twitch',
            'required' => false,
        ]);

        $builder->add('bio', TextareaType::class, [
            'label' => 'forms.about_me',
            'required' => false,
        ]);

        // Raster formats only, like every other image upload: an SVG would be rendered on
        // the server (ImageMagick hands SVG to an external delegate)
        $builder->add('avatar', FileType::class, [
            'label' => 'forms.avatar',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '2m',
                    mimeTypes: [
                        'image/jpeg',
                        'image/png',
                        'image/gif',
                        'image/webp',
                        'image/heic',
                        'image/heif',
                        'image/avif',
                    ],
                    mimeTypesMessage: 'image_invalid_mime_type',
                ),
            ],
        ]);
    }

    /**
     * @param mixed[] $options
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // A stable id instead of `edit_profile_form_timezone`: the anchor other pages link to, on both forms using it
        $view['timezone']->vars['id'] = self::TIMEZONE_ANCHOR;
        // For the browser's old zone names (templates/_timezone_field.html.twig → timezone_select_controller.js)
        $view['timezone']->vars['browser_aliases'] = TimezoneIdentifier::aliases();

        // For the "We think you're from …" guess (templates/_country_field.html.twig) - only while no country is saved,
        // the zone map is ~13 kB of HTML
        $data = $form->getData();

        if (CountryCode::fromCode($data->country) === null) {
            $view['country']->vars['guess_zone_countries'] = TimezoneIdentifier::countryCodes();
            $view['country']->vars['guess_saved_zone'] = $data->timezone;
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditProfileFormData::class,
        ]);
    }
}
