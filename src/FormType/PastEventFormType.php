<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PastEventFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The backfill path's event form (PastEventFormData). `series_choices` / `organization_choices` (id => name) are the
 * ones the player may use - their own series, the organizations they are on the team of; a new one is always offered.
 *
 * @extends AbstractType<PastEventFormData>
 */
final class PastEventFormType extends AbstractType
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
        /** @var array<string, string> $seriesChoices */
        $seriesChoices = $options['series_choices'];
        /** @var array<string, string> $organizationChoices */
        $organizationChoices = $options['organization_choices'];

        $builder->add('name', TextType::class, [
            'label' => 'event_wizard_backfill.event.name',
        ]);

        $builder->add('date', DateType::class, [
            'label' => 'event_wizard_backfill.event.date',
            'widget' => 'single_text',
            'format' => 'dd.MM.yyyy',
            'html5' => false,
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y',
        ]);

        $builder->add('isOnline', ChoiceType::class, [
            'label' => 'event_wizard.basics.where',
            'choices' => [
                'competition.form.event_type_offline' => false,
                'competition.form.event_type_online' => true,
            ],
            'expanded' => true,
            'placeholder' => false,
        ]);

        $builder->add('location', TextType::class, [
            'label' => 'competition.form.location',
            'required' => false,
        ]);

        $countries = [];
        foreach (CountryCode::cases() as $country) {
            $countries[$country->value] = $country->name;
        }

        $builder->add('locationCountryCode', ChoiceType::class, [
            'label' => 'competition.form.country',
            'choices' => $countries,
            'required' => false,
            'placeholder' => $this->translator->trans('competition.form.country_placeholder'),
            'autocomplete' => true,
            'choice_translation_domain' => false,
            'choice_attr' => static fn (string $countryCode): array => ['data-icon' => 'fi fi-' . $countryCode],
        ]);

        $builder->add('partOf', CheckboxType::class, [
            'label' => 'event_wizard_backfill.event.part_of',
            'required' => false,
        ]);

        $builder->add('series', ChoiceType::class, [
            'label' => 'event_wizard_backfill.event.series',
            'required' => false,
            'placeholder' => $this->translator->trans('event_wizard_backfill.event.series_none'),
            'choices' => [...array_keys($seriesChoices), PastEventFormData::NEW],
            'choice_translation_domain' => false,
            'choice_label' => fn (string $value): string => $value === PastEventFormData::NEW
                ? $this->translator->trans('event_wizard_backfill.event.series_new')
                : ($seriesChoices[$value] ?? $value),
        ]);

        $builder->add('newSeriesName', TextType::class, [
            'label' => 'event_wizard_backfill.event.new_series_name',
            'required' => false,
        ]);

        $builder->add('organization', ChoiceType::class, [
            'label' => 'organizer_tools.form.organization',
            'required' => false,
            'placeholder' => $this->translator->trans('organizer_tools.form.organization_none'),
            'choices' => [...array_keys($organizationChoices), PastEventFormData::NEW],
            'choice_translation_domain' => false,
            'choice_label' => fn (string $value): string => $value === PastEventFormData::NEW
                ? $this->translator->trans('event_wizard_backfill.event.organization_new')
                : ($organizationChoices[$value] ?? $value),
        ]);

        $builder->add('newOrganizationName', TextType::class, [
            'label' => 'event_wizard_backfill.event.new_organization_name',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PastEventFormData::class,
            'series_choices' => [],
            'organization_choices' => [],
        ]);
        $resolver->setAllowedTypes('series_choices', 'array');
        $resolver->setAllowedTypes('organization_choices', 'array');
    }
}
