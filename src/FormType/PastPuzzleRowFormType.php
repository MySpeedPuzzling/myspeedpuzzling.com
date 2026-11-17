<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\PastPuzzleRowFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PastPuzzleRowFormData>
 */
final class PastPuzzleRowFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'label' => 'event_wizard_backfill.puzzles.category',
            'choice_label' => static fn (RoundCategory $category): string => match ($category) {
                RoundCategory::Solo => 'competition.round.category.solo',
                RoundCategory::Duo => 'event_wizard.puzzle.category_pairs',
                RoundCategory::Team => 'event_wizard.puzzle.category_teams',
            },
        ]);

        $builder->add('slot', EventWizardPuzzleSlotFormType::class, [
            'competition_id' => $options['competition_id'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PastPuzzleRowFormData::class,
            'label' => false,
        ]);
        $resolver->setRequired('competition_id');
        $resolver->setAllowedTypes('competition_id', 'string');
    }
}
