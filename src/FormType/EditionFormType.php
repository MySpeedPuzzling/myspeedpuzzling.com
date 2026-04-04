<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EditionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<EditionFormData>
 */
final class EditionFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'edition.form.name',
        ]);

        $builder->add('startsAt', DateTimeType::class, [
            'label' => 'edition.form.starts_at',
            'widget' => 'single_text',
            'html5' => false,
            'format' => 'dd.MM.yyyy HH:mm',
            'input' => 'datetime_immutable',
            'input_format' => 'd.m.Y H:i',
        ]);

        $builder->add('timezone', TimezoneType::class, [
            'label' => 'competition.round.form.timezone',
            'help' => 'competition.round.form.timezone_help',
            'mapped' => false,
            'data' => 'Europe/Prague',
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

        $builder->add('minutesLimit', NumberType::class, [
            'label' => 'edition.form.minutes_limit',
        ]);

        $builder->add('registrationLink', UrlType::class, [
            'label' => 'edition.form.registration_link',
            'required' => false,
        ]);

        $builder->add('resultsLink', UrlType::class, [
            'label' => 'edition.form.results_link',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EditionFormData::class,
        ]);
    }
}
