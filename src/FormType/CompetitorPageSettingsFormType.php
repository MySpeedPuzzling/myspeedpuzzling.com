<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitorPageSettingsFormData;
use SpeedPuzzling\Web\Value\QrTagMode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CompetitorPageSettingsFormData>
 */
final class CompetitorPageSettingsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Radios; the not-required placeholder is the "not chosen" radio (empty value = null = name tags as before)
        $builder->add('qrTagMode', EnumType::class, [
            'class' => QrTagMode::class,
            'expanded' => true,
            'required' => false,
            'placeholder' => 'competitor_page.settings.qr_mode.none',
            'label' => 'competitor_page.settings.qr_mode.label',
            'choice_label' => static fn (QrTagMode $mode): string => 'competitor_page.settings.qr_mode.' . $mode->value,
        ]);

        $builder->add('competitorPages', CheckboxType::class, [
            'label' => 'competitor_page.settings.pages',
            'help' => 'competitor_page.settings.pages_help',
            'required' => false,
        ]);

        $builder->add('liveResultsBeforePublishing', CheckboxType::class, [
            'label' => 'competitor_page.settings.live_results',
            'help' => 'competitor_page.settings.live_results_help',
            'required' => false,
        ]);

        $builder->add('timeProposals', CheckboxType::class, [
            'label' => 'competitor_page.settings.time_proposals',
            'help' => 'competitor_page.settings.time_proposals_help',
            'required' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompetitorPageSettingsFormData::class,
        ]);
    }
}
