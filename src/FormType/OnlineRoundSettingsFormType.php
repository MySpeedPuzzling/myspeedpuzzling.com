<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\OnlineRoundSettingsFormData;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A round's online timing settings (online_round_settings, docs/features/online-competitions/): finish detection, what
 * participants report when time is up, the missing-piece rule. Every choice a radio list - the organiser reads all of
 * them before choosing.
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnlineRoundSettingsFormData::class,
        ]);
    }
}
