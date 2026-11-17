<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\FormData\AddCompetitionDisplayFormData;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AddCompetitionDisplayFormData>
 */
final class AddCompetitionDisplayFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('kind', EnumType::class, [
            'class' => DisplayKind::class,
            'expanded' => true,
            'label' => 'competition_display.form.kind',
            'choice_label' => static fn (DisplayKind $kind): string => 'competition_display.kind.' . $kind->value,
        ]);

        $builder->add('preset', EnumType::class, [
            'class' => DisplayPreset::class,
            'label' => 'competition_display.form.preset',
            'help' => 'competition_display.form.preset_help',
            'choice_label' => static fn (DisplayPreset $preset): string => 'competition_display.preset.' . $preset->value,
            'group_by' => static fn (DisplayPreset $preset): string => match (true) {
                $preset->fits(DisplayKind::Screen) && $preset->fits(DisplayKind::Overlay) => 'competition_display.preset.group_any',
                $preset->fits(DisplayKind::Screen) => 'competition_display.kind.screen',
                default => 'competition_display.kind.overlay',
            },
        ]);

        $builder->add('name', TextType::class, [
            'label' => 'competition_display.form.name',
            'help' => 'competition_display.form.name_help',
            'attr' => ['maxlength' => CompetitionDisplay::NAME_MAX_LENGTH],
        ]);

        $builder->add('locale', DisplayLocaleType::class, [
            'label' => 'competition_display.form.locale',
            'help' => 'competition_display.form.locale_help',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AddCompetitionDisplayFormData::class,
        ]);
    }
}
