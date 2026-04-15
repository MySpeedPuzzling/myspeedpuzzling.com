<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\FeaturesOptionsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FeaturesOptionsFormData>
 */
final class FeaturesOptionsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('streakOptedOut', CheckboxType::class, [
            'label' => 'edit_profile.hide_streak',
            'help' => 'edit_profile.hide_streak_help',
            'required' => false,
        ]);

        $builder->add('rankingOptedOut', CheckboxType::class, [
            'label' => 'edit_profile.hide_ranking',
            'help' => 'edit_profile.hide_ranking_help',
            'required' => false,
        ]);

        $builder->add('timePredictionsOptedOut', CheckboxType::class, [
            'label' => 'edit_profile.hide_time_predictions',
            'help' => 'edit_profile.hide_time_predictions_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FeaturesOptionsFormData::class,
        ]);
    }
}
