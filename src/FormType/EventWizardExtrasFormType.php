<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardExtrasFormData;
use SpeedPuzzling\Web\Results\EventWizardExtrasState;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use SpeedPuzzling\Web\Value\QrTagMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The event creation wizard's "Extras" (W-creation-wizard-research.md §3.8): a switch per feature with its must-ask
 * fields. The toolkit rows exist only for whom COMPETITION_TOOLKIT_MANAGE grants (the state's $toolkit); name tags with
 * QR codes and competitor pages only in person; a display kind and overall standings only while the event has none
 * (EventWizardExtrasState::*Offered()).
 *
 * @extends AbstractType<EventWizardExtrasFormData>
 */
final class EventWizardExtrasFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var EventWizardExtrasState $state */
        $state = $options['state'];

        $builder->add('registration', CheckboxType::class, [
            'label' => 'event_wizard.extras.registration',
            'help' => 'event_wizard.extras.registration_help',
            'required' => false,
        ]);
        $builder->add('capacity', IntegerType::class, [
            'label' => 'competition_registration.settings.capacity',
            'help' => 'competition_registration.settings.capacity_help',
            'required' => false,
            'attr' => ['min' => 1],
        ]);

        foreach (['opensAt' => 'opens_at', 'closesAt' => 'closes_at'] as $field => $key) {
            // Typed like on the registration page: day and time in the event's zone
            $builder->add($field, DateTimeType::class, [
                'label' => 'competition_registration.settings.' . $key,
                'required' => false,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy HH:mm',
                'input' => 'datetime_immutable',
                'input_format' => 'd.m.Y H:i',
            ]);
        }

        $builder->add('entryFeeText', TextType::class, [
            'label' => 'competition_registration.settings.entry_fee',
            'help' => 'competition_registration.settings.entry_fee_help',
            'required' => false,
        ]);

        $builder->add('elsewhere', CheckboxType::class, [
            'label' => 'event_wizard.extras.elsewhere',
            'help' => 'event_wizard.extras.elsewhere_help',
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

        $builder->add('judges', CheckboxType::class, [
            'label' => 'event_wizard.extras.judges',
            'help' => 'event_wizard.extras.judges_help',
            'required' => false,
        ]);
        // The referees page's player search (AddCompetitionRefereesFormType)
        $builder->add('judgePlayers', TextType::class, [
            'label' => $state->referees === [] ? 'event_wizard.extras.judges_add' : 'event_wizard.extras.judges_add_more',
            'required' => false,
            'autocomplete' => true,
            'tom_select_options' => [
                'create' => false,
                'persist' => false,
                'maxItems' => 10,
                'closeAfterSelect' => true,
            ],
        ]);
        $builder->get('judgePlayers')->addModelTransformer(new CallbackTransformer(
            static function (null|array $value): string {
                /** @var null|array<string> $value */
                return implode(',', $value ?? []);
            },
            static function (null|string $value): array {
                if ($value === null || $value === '') {
                    return [];
                }

                return array_values(array_unique(array_filter(explode(',', $value))));
            },
        ));

        if ($state->competitorPagesOffered()) {
            $builder->add('qrTags', CheckboxType::class, [
                'label' => 'event_wizard.extras.qr_tags',
                'help' => 'event_wizard.extras.qr_tags_help',
                'required' => false,
            ]);
            $builder->add('qrTagMode', EnumType::class, [
                'class' => QrTagMode::class,
                'expanded' => true,
                'label' => 'competitor_page.settings.qr_mode.label',
                'choice_label' => static fn (QrTagMode $mode): string => 'competitor_page.settings.qr_mode.' . $mode->value,
            ]);
            $builder->add('competitorPages', CheckboxType::class, [
                'label' => 'event_wizard.extras.competitor_pages',
                'help' => 'event_wizard.extras.competitor_pages_help',
                'required' => false,
            ]);
            $builder->add('liveResultsBeforePublishing', CheckboxType::class, [
                'label' => 'competitor_page.settings.live_results',
                'help' => 'competitor_page.settings.live_results_help',
                'required' => false,
            ]);
            $builder->add('timeProposals', CheckboxType::class, [
                'label' => 'event_wizard.extras.time_proposals',
                'help' => 'event_wizard.extras.time_proposals_help',
                'required' => false,
            ]);
        }

        if ($state->timerScreenOffered()) {
            $builder->add('timerScreen', CheckboxType::class, [
                'label' => 'event_wizard.extras.timer_screen',
                'help' => 'event_wizard.extras.timer_screen_help',
                'required' => false,
            ]);
            $builder->add('screenPreset', EnumType::class, self::presetOptions(DisplayKind::Screen));
        }

        if ($state->overlayOffered()) {
            $builder->add('overlay', CheckboxType::class, [
                'label' => 'event_wizard.extras.overlay',
                'help' => 'event_wizard.extras.overlay_help',
                'required' => false,
            ]);
            $builder->add('overlayPreset', EnumType::class, self::presetOptions(DisplayKind::Overlay));
        }

        if ($state->standingsOffered()) {
            $builder->add('standings', CheckboxType::class, [
                'label' => 'event_wizard.extras.standings',
                'help' => 'event_wizard.extras.standings_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardExtrasFormData::class,
        ]);

        $resolver->setRequired('state');
        $resolver->setAllowedTypes('state', EventWizardExtrasState::class);
    }

    /**
     * The ready-made designs of one kind (Blank is for the displays page - the wizard starts from a design)
     *
     * @return array<string, mixed>
     */
    private static function presetOptions(DisplayKind $kind): array
    {
        return [
            'class' => DisplayPreset::class,
            'expanded' => true,
            'label' => 'event_wizard.extras.preset',
            'choices' => array_values(array_filter(
                DisplayPreset::cases(),
                static fn (DisplayPreset $preset): bool => $preset !== DisplayPreset::Blank && $preset->fits($kind),
            )),
            'choice_label' => static fn (DisplayPreset $preset): string => 'competition_display.preset.' . $preset->value,
        ];
    }
}
