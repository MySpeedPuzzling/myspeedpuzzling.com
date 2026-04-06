<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\FormData\CompetitionFormData;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<CompetitionFormData>
 */
final class CompetitionFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly Connection $database,
        private readonly ImageThumbnailTwigExtension $imageThumbnail,
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
            'required' => false,
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
            'autocomplete' => true,
            'choice_translation_domain' => false,
            'choice_attr' => static function (string $countryCode): array {
                return ['data-icon' => 'fi fi-' . $countryCode];
            },
        ]);

        $builder->add('dateFrom', DateType::class, [
            'label' => 'competition.form.date_from',
            'required' => false,
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
            'help' => 'competition.form.dates_help',
        ]);

        $builder->add('dateTo', DateType::class, [
            'label' => 'competition.form.date_to',
            'required' => false,
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->add('isOnline', ChoiceType::class, [
            'label' => 'competition.form.event_type',
            'choices' => [
                'competition.form.event_type_offline' => false,
                'competition.form.event_type_online' => true,
            ],
            'expanded' => true,
            'placeholder' => false,
        ]);

        $builder->add('isRecurring', CheckboxType::class, [
            'label' => 'competition.form.is_recurring',
            'help' => 'competition.form.is_recurring_help',
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
        if ($data->maintainers !== []) {
            $rows = $this->database->executeQuery(
                'SELECT id, name, code, country, avatar FROM player WHERE id IN (?)',
                [$data->maintainers],
                [ArrayParameterType::STRING],
            )->fetchAllAssociative();

            foreach ($rows as $row) {
                /** @var array{id: string, name: null|string, code: string, country: null|string, avatar: null|string} $row */
                $maintainerChoices[] = [
                    'value' => $row['id'],
                    'text' => $this->buildPlayerOptionHtml(
                        $row['name'] ?? $row['code'],
                        $row['code'],
                        $row['country'],
                        $row['avatar'],
                    ),
                ];
            }
        }

        $builder->add('maintainers', TextType::class, [
            'label' => 'competition.form.maintainers',
            'help' => 'competition.form.maintainers_help',
            'required' => false,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 10,
                'options' => $maintainerChoices,
                'closeAfterSelect' => true,
            ],
        ]);

        $builder->get('maintainers')->addModelTransformer(new CallbackTransformer(
            function (null|array $value): string {
                if ($value === null || $value === []) {
                    return '';
                }

                return implode(',', $value);
            },
            function (null|string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                return array_filter(explode(',', $value));
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionFormData::class,
        ]);
    }

    private function buildPlayerOptionHtml(
        string $name,
        string $code,
        null|string $country,
        null|string $avatar,
    ): string {
        $name = htmlspecialchars($name);
        $code = htmlspecialchars($code);

        if ($avatar !== null) {
            $avatarUrl = htmlspecialchars($this->imageThumbnail->thumbnailUrl($avatar, 'puzzle_small'));
            $avatarHtml = <<<HTML
<img alt="" class="rounded-circle me-2" style="width: 24px; height: 24px; object-fit: cover;" src="{$avatarUrl}">
HTML;
        } else {
            $avatarHtml = '<i class="ci-user me-2"></i>';
        }

        $flagHtml = '';
        $countryCode = CountryCode::fromCode($country);
        if ($countryCode !== null) {
            $flagHtml = '<span class="shadow-custom fi fi-' . $countryCode->name . ' me-1"></span> ';
        }

        return <<<HTML
<div class="d-flex align-items-center">{$avatarHtml}{$flagHtml}<span>{$name}</span><small class="text-muted ms-1">#{$code}</small></div>
HTML;
    }
}
