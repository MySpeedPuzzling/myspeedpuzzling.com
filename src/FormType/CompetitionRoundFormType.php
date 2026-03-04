<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CompetitionRoundFormData>
 */
final class CompetitionRoundFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'competition.round.form.name',
        ]);

        $builder->add('minutesLimit', NumberType::class, [
            'label' => 'competition.round.form.minutes_limit',
        ]);

        $builder->add('startsAt', DateTimeType::class, [
            'label' => 'competition.round.form.starts_at',
            'widget' => 'single_text',
        ]);

        $builder->add('badgeBackgroundColor', ColorType::class, [
            'label' => 'competition.round.form.badge_background_color',
            'required' => false,
        ]);

        $builder->add('badgeTextColor', ColorType::class, [
            'label' => 'competition.round.form.badge_text_color',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitionRoundFormData::class,
        ]);
    }
}
