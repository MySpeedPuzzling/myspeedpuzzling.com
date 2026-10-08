<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<CompetitionRoundFormData>
 */
final class CompetitionRoundFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A one-day event asks only for the time (CompetitionEvent::singleDay())
        $isSingleDay = $options['single_day'] === true;
        /** @var null|\DateTimeImmutable $offsetAt */
        $offsetAt = $options['timezone_offset_at'];

        $builder->add('name', TextType::class, [
            'label' => 'competition.round.form.name',
            // The badge preview shows the name (round_badge_preview_controller.js)
            'attr' => [
                'data-round-badge-preview-target' => 'name',
            ],
        ]);

        $builder->add('minutesLimit', NumberType::class, [
            'label' => 'competition.round.form.minutes_limit',
        ]);

        if ($isSingleDay) {
            $builder->add('startsAtTime', TextType::class, [
                'label' => 'competition.round.form.starts_at_time',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(pattern: '/^([01]?\d|2[0-3]):[0-5]\d$/'),
                ],
            ]);
        } else {
            $builder->add('startsAt', DateTimeType::class, [
                'label' => 'competition.round.form.starts_at',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy HH:mm',
                'input' => 'datetime_immutable',
                'input_format' => 'd.m.Y H:i',
                'constraints' => [new Assert\NotNull()],
            ]);
        }

        $builder->add('timezone', TimezoneType::class, [
            'label' => 'competition.round.form.timezone',
            // A round saved without a zone in an event without a country is shown in the fallback zone - say so
            'help' => $options['timezone_assumed'] === true
                ? 'competition.round.form.timezone_assumed_help'
                : 'competition.round.form.timezone_help',
            // Pre-selected from the form data: the round's own zone on edit (never a default - that would
            // override it), on add the zone of the event's other rounds or its country's
            'autocomplete' => true,
            'constraints' => [
                new Assert\NotBlank(),
                new Assert\Timezone(),
            ],
            // The offset on the round's own date - in October, Chicago is UTC-5, not the UTC-6 of a January form
            'choice_label' => static function (string $timezone) use ($offsetAt): string {
                $tz = new \DateTimeZone($timezone);
                $offset = $tz->getOffset($offsetAt ?? new \DateTimeImmutable('now', $tz));
                $hours = intdiv($offset, 3600);
                $minutes = abs(intdiv($offset % 3600, 60));
                $utcOffset = sprintf('UTC%+d', $hours) . ($minutes > 0 ? sprintf(':%02d', $minutes) : '');

                return $timezone . ' (' . $utcOffset . ')';
            },
        ]);

        // The automatic reveal of the round's secret puzzles - minutes after the start, so the start stays the real start.
        // The templates render it inside <div id="reveal-delay"> - the round's puzzles page links there
        $data = $options['data'] ?? null;
        $current = $data instanceof CompetitionRoundFormData && $data->revealDelayMinutes !== null
            ? $data->revealDelayMinutes
            : RoundPuzzleReveal::DEFAULT_DELAY_MINUTES;
        $rangeParameters = ['{{ min }}' => '0', '{{ max }}' => (string) RoundPuzzleReveal::MAX_DELAY_MINUTES];

        $builder->add('revealDelayMinutes', IntegerType::class, [
            'label' => 'competition.round.form.reveal_delay_minutes',
            'help' => 'competition.round.form.reveal_delay_minutes_help',
            'help_translation_parameters' => ['%max%' => RoundPuzzleReveal::MAX_DELAY_MINUTES],
            'required' => true,
            // A missing or blank value keeps the delay the form was shown with (also a page rendered by a release
            // without the field)
            'empty_data' => (string) $current,
            'invalid_message' => 'competition_round_reveal_delay_range',
            'invalid_message_parameters' => $rangeParameters,
            'attr' => [
                'min' => 0,
                'max' => RoundPuzzleReveal::MAX_DELAY_MINUTES,
                'step' => 1,
                'inputmode' => 'numeric',
                'class' => 'w-auto',
            ],
        ]);

        // Whole minutes exactly as typed, the same in every page language - IntegerType parses with the language's
        // decimal and grouping separators ("2,5" or "2.5" mean different things in en and cs). A number input sends
        // plain digits anyway; anything else is the range error, never a guess
        $builder->get('revealDelayMinutes')
            ->resetViewTransformers()
            ->addViewTransformer(new CallbackTransformer(
                static fn (mixed $minutes): string => is_int($minutes) ? (string) $minutes : '',
                static function (mixed $typed): null|int {
                    if ($typed === null || $typed === '') {
                        return null;
                    }

                    if (!is_string($typed) || preg_match('/^\s*\d{1,4}\s*$/', $typed) !== 1) {
                        throw new TransformationFailedException('Whole minutes are expected.');
                    }

                    return (int) trim($typed);
                },
            ));

        // Editing a round with secret puzzles: saving a start or a reveal delay that reveals them earlier than planned needs
        // an explicit yes (EditCompetitionRoundController)
        if ($options['reveal_confirmation'] === true) {
            $builder->add('confirmReveal', CheckboxType::class, [
                'label' => 'competition.reveal.form.confirm_reveal',
                'mapped' => false,
                'required' => false,
            ]);
        }

        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'label' => 'competition.round.form.category',
            'help' => 'competition.round.form.category_help',
            'choice_label' => static fn(RoundCategory $category): string => match ($category) {
                RoundCategory::Solo => 'competition.round.category.solo',
                RoundCategory::Duo => 'competition.round.category.duo',
                RoundCategory::Team => 'competition.round.category.team',
            },
            'expanded' => true,
        ]);

        // The expected size of a team round's teams (participants-spreadsheet.md D5) - the templates show it for team
        // rounds only (round_team_size_controller.js follows the category). The most common size of the round's teams is
        // a placeholder, never a value: an untouched empty field stores nothing
        /** @var null|int $teamSizeGuess */
        $teamSizeGuess = $options['team_size_guess'];
        $teamSizeAttributes = [
            'min' => CompetitionRound::TEAM_SIZE_MIN,
            'max' => CompetitionRound::TEAM_SIZE_MAX,
            'step' => 1,
            'inputmode' => 'numeric',
            'class' => 'w-auto',
        ];

        if ($teamSizeGuess !== null) {
            $teamSizeAttributes['placeholder'] = 'participants_sheet_server.round_form.team_size_placeholder';
        }

        $builder->add('teamSize', IntegerType::class, [
            'label' => 'participants_sheet_server.round_form.team_size',
            'help' => 'participants_sheet_server.round_form.team_size_help',
            'required' => false,
            'attr' => $teamSizeAttributes,
            'attr_translation_parameters' => ['%size%' => $teamSizeGuess],
        ]);

        $builder->add('resultsLink', UrlType::class, [
            'label' => 'competition.round.form.results_link',
            'help' => 'competition.round.form.results_link_help',
            'required' => false,
        ]);

        // The round's name on a badge in this colour, wherever the round is shown. Empty = a distinct colour picked by
        // the round's place in the schedule; the text colour is always picked for contrast (RoundBadgeColor) - the
        // form asks for no text colour (the column stays, see the controllers)
        $builder->add('badgeBackgroundColor', TextType::class, [
            'label' => 'competition.round.form.badge_background_color',
            'help' => 'competition.round.form.badge_background_color_help',
            'required' => false,
            'attr' => [
                'data-controller' => 'colorpicker',
                'data-round-badge-preview-target' => 'color',
                'autocomplete' => 'off',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionRoundFormData::class,
            'single_day' => false,
            'timezone_offset_at' => null,
            'reveal_confirmation' => false,
            'timezone_assumed' => false,
            // The most common size of a team round's teams - the "Members per team" placeholder, null = none
            'team_size_guess' => null,
        ]);

        $resolver->setAllowedTypes('single_day', 'bool');
        $resolver->setAllowedTypes('timezone_offset_at', ['null', \DateTimeImmutable::class]);
        $resolver->setAllowedTypes('reveal_confirmation', 'bool');
        $resolver->setAllowedTypes('timezone_assumed', 'bool');
        $resolver->setAllowedTypes('team_size_guess', ['null', 'int']);
    }
}
