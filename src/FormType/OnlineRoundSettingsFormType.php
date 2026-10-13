<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\OnlineRoundSettingsFormData;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A round's online timing settings (online_round_settings, docs/features/online-competitions/): finish detection, what
 * participants report when time is up, the missing-piece rule - every choice a radio list, the organiser reads all of
 * them before choosing - and, with `video_available` (a media server is configured, LiveKitSettings), the live video
 * switches: the puzzle cameras live, and participants seeing each other.
 *
 * @extends AbstractType<OnlineRoundSettingsFormData>
 */
final class OnlineRoundSettingsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('detectionMode', EnumType::class, [
            'class' => OnlineDetectionMode::class,
            'expanded' => true,
            'label' => 'online_rounds.settings.detection.label',
            'help' => 'online_rounds.settings.detection.help',
            'choice_label' => static fn (OnlineDetectionMode $mode): string => 'online_rounds.settings.detection.' . $mode->value,
        ]);

        $builder->add('unfinishedMeasure', EnumType::class, [
            'class' => OnlineUnfinishedMeasure::class,
            'expanded' => true,
            'label' => 'online_rounds.settings.unfinished.label',
            'help' => 'online_rounds.settings.unfinished.help',
            'choice_label' => static fn (OnlineUnfinishedMeasure $measure): string => 'online_rounds.settings.unfinished.' . $measure->value,
        ]);

        $builder->add('missingPieceRule', EnumType::class, [
            'class' => OnlineMissingPieceRule::class,
            'expanded' => true,
            'label' => 'online_rounds.settings.missing_pieces.label',
            'help' => 'online_rounds.settings.missing_pieces.help',
            'choice_label' => static fn (OnlineMissingPieceRule $rule): string => 'online_rounds.settings.missing_pieces.' . $rule->value,
        ]);

        if ($options['video_available'] === true) {
            $builder->add('videoEnabled', CheckboxType::class, [
                'required' => false,
                'label' => 'online_rounds.settings.video.enabled',
                'help' => 'online_rounds.settings.video.enabled_help',
                'label_attr' => ['class' => 'checkbox-switch'],
            ]);

            $builder->add('participantsSeeEachOther', CheckboxType::class, [
                'required' => false,
                'label' => 'online_rounds.settings.video.see_each_other',
                'help' => 'online_rounds.settings.video.see_each_other_help',
                'label_attr' => ['class' => 'checkbox-switch'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnlineRoundSettingsFormData::class,
            'video_available' => false,
        ]);

        $resolver->setAllowedTypes('video_available', 'bool');
    }
}
