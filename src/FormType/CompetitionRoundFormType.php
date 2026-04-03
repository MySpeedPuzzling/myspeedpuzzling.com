<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use DateTimeImmutable;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CompetitionRoundFormData>
 */
final class CompetitionRoundFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var null|DateTimeImmutable $dateFrom */
        $dateFrom = $options['date_from'];
        /** @var null|DateTimeImmutable $dateTo */
        $dateTo = $options['date_to'];
        /** @var null|string $countryCode */
        $countryCode = $options['country_code'];

        $isSingleDay = $dateFrom !== null
            && $dateTo !== null
            && $dateFrom->format('Y-m-d') === $dateTo->format('Y-m-d');

        $builder->add('name', TextType::class, [
            'label' => 'competition.round.form.name',
        ]);

        $builder->add('minutesLimit', NumberType::class, [
            'label' => 'competition.round.form.minutes_limit',
        ]);

        if ($isSingleDay) {
            $builder->add('startsAtTime', TextType::class, [
                'label' => 'competition.round.form.starts_at_time',
                'mapped' => false,
            ]);
        } else {
            $builder->add('startsAt', DateTimeType::class, [
                'label' => 'competition.round.form.starts_at',
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd.MM.yyyy HH:mm',
                'input' => 'datetime_immutable',
                'input_format' => 'd.m.Y H:i',
            ]);
        }

        $builder->add('timezone', TimezoneType::class, [
            'label' => 'competition.round.form.timezone',
            'help' => 'competition.round.form.timezone_help',
            'mapped' => false,
            'data' => CountryCode::fromCode($countryCode)?->defaultTimezone() ?? 'Europe/Prague',
            'autocomplete' => true,
            'choice_label' => static function (string $timezone): string {
                $tz = new \DateTimeZone($timezone);
                $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));
                $hours = intdiv($offset, 3600);
                $minutes = abs(intdiv($offset % 3600, 60));
                $utcOffset = sprintf('UTC%+d', $hours) . ($minutes > 0 ? sprintf(':%02d', $minutes) : '');

                return $timezone . ' (' . $utcOffset . ')';
            },
        ]);

        $builder->add('badgeBackgroundColor', TextType::class, [
            'label' => 'competition.round.form.badge_background_color',
            'empty_data' => '#fe696a',
            'attr' => [
                'data-controller' => 'colorpicker',
            ],
        ]);

        $builder->add('badgeTextColor', TextType::class, [
            'label' => 'competition.round.form.badge_text_color',
            'empty_data' => '#ffffff',
            'attr' => [
                'data-controller' => 'colorpicker',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionRoundFormData::class,
            'date_from' => null,
            'date_to' => null,
            'country_code' => null,
        ]);

        $resolver->setAllowedTypes('date_from', ['null', DateTimeImmutable::class]);
        $resolver->setAllowedTypes('date_to', ['null', DateTimeImmutable::class]);
        $resolver->setAllowedTypes('country_code', ['null', 'string']);
    }
}
