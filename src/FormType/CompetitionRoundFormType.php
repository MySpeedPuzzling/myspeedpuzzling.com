<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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

        // Editing a round with secret puzzles: saving a start that reveals them right away needs an explicit yes
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
        ]);

        $resolver->setAllowedTypes('single_day', 'bool');
        $resolver->setAllowedTypes('timezone_offset_at', ['null', \DateTimeImmutable::class]);
        $resolver->setAllowedTypes('reveal_confirmation', 'bool');
        $resolver->setAllowedTypes('timezone_assumed', 'bool');
    }
}
