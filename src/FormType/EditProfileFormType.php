<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

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

        $allCountries = [];

        foreach (CountryCode::cases() as $country) {
            $allCountries[$country->value] = $country->name;
        }

        $countries = [
            $this->translator->trans('forms.country_most_common') => [
                CountryCode::cz->value => CountryCode::cz->name,
                CountryCode::sk->value => CountryCode::sk->name,
                CountryCode::pl->value => CountryCode::pl->name,
                CountryCode::de->value => CountryCode::de->name,
                CountryCode::at->value => CountryCode::at->name,
                CountryCode::no->value => CountryCode::no->name,
                CountryCode::fi->value => CountryCode::fi->name,
                CountryCode::us->value => CountryCode::us->name,
                CountryCode::ca->value => CountryCode::ca->name,
                CountryCode::fr->value => CountryCode::fr->name,
                CountryCode::nz->value => CountryCode::nz->name,
                CountryCode::es->value => CountryCode::es->name,
                CountryCode::nl->value => CountryCode::nl->name,
                CountryCode::pt->value => CountryCode::pt->name,
                CountryCode::gb->value => CountryCode::gb->name,
            ],
            $this->translator->trans('forms.country_all') => $allCountries,
        ];

        $builder->add('country', ChoiceType::class, [
            'label' => 'forms.nationality',
            'required' => false,
            'expanded' => false,
            'multiple' => false,
            'choices' => $countries,
            'choice_translation_domain' => false,
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditProfileFormData::class,
        ]);
    }
}
