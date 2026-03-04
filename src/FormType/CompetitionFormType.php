<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<CompetitionFormData>
 */
final class CompetitionFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'competition.form.name',
        ]);

        $builder->add('shortcut', TextType::class, [
            'label' => 'competition.form.shortcut',
            'help' => 'competition.form.shortcut_help',
            'required' => false,
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'competition.form.description',
            'required' => false,
            'attr' => ['rows' => 4],
        ]);

        $builder->add('link', TextType::class, [
            'label' => 'competition.form.link',
            'required' => false,
        ]);

        $builder->add('registrationLink', TextType::class, [
            'label' => 'competition.form.registration_link',
            'required' => false,
        ]);

        $builder->add('resultsLink', TextType::class, [
            'label' => 'competition.form.results_link',
            'required' => false,
        ]);

        $builder->add('location', TextType::class, [
            'label' => 'competition.form.location',
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

        $builder->add('locationCountryCode', ChoiceType::class, [
            'label' => 'competition.form.country',
            'choices' => $countries,
            'required' => false,
            'placeholder' => 'competition.form.country_placeholder',
        ]);

        $builder->add('dateFrom', DateType::class, [
            'label' => 'competition.form.date_from',
            'widget' => 'single_text',
            'required' => false,
            'help' => 'competition.form.dates_help',
        ]);

        $builder->add('dateTo', DateType::class, [
            'label' => 'competition.form.date_to',
            'widget' => 'single_text',
            'required' => false,
        ]);

        $builder->add('isOnline', CheckboxType::class, [
            'label' => 'competition.form.is_online',
            'required' => false,
        ]);

        $builder->add('logo', FileType::class, [
            'label' => 'competition.form.logo',
            'required' => false,
            'constraints' => [
                new Image(
                    maxSize: '5M',
                    mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                ),
            ],
        ]);

        /** @var CompetitionFormData $data */
        $data = $builder->getData();

        $maintainerChoices = [];
        foreach ($data->maintainers as $playerId) {
            $maintainerChoices[$playerId] = $playerId;
        }

        $builder->add('maintainers', TextType::class, [
            'label' => 'competition.form.maintainers',
            'help' => 'competition.form.maintainers_help',
            'required' => false,
            'autocomplete' => true,
            'options_as_html' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 10,
                'options' => $maintainerChoices,
                'closeAfterSelect' => true,
            ],
            'attr' => [
                'data-fetch-url' => $this->urlGenerator->generate('player_search_autocomplete', ['_locale' => 'en']),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionFormData::class,
        ]);
    }
}
