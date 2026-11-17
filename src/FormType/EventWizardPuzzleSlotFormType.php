<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardPuzzleSlotFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One puzzle picker of the Puzzles grid - the round puzzle form's own brand + puzzle autocomplete and a new puzzle's
 * pieces (RoundPuzzleFormType, the picker W1's Puzzle step uses too), the puzzle optional ("Decide later"). The brands
 * are not repeated in every picker: the page hands them over once (RoundPuzzleFormType `brand_options` false).
 *
 * @extends AbstractType<EventWizardPuzzleSlotFormData>
 */
final class EventWizardPuzzleSlotFormType extends AbstractType
{
    public function __construct(
        private readonly RoundPuzzleFormType $roundPuzzleFormType,
    ) {
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $competitionId */
        $competitionId = $options['competition_id'];

        /** @phpstan-ignore argument.type */
        $this->roundPuzzleFormType->buildForm($builder, [
            'competition_id' => $competitionId,
            'puzzle_required' => false,
            'brand_options' => false,
        ]);

        // A new puzzle's photo and codes: the round's own puzzle form; secret: the grid's switch and the row's override
        $builder
            ->remove('puzzlePhoto')
            ->remove('puzzleEans')
            ->remove('puzzleBrandCodes')
            ->remove('hideUntilRoundStarts')
            ->remove('hideMode');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardPuzzleSlotFormData::class,
            'label' => false,
        ]);
        $resolver->setRequired('competition_id');
        $resolver->setAllowedTypes('competition_id', 'string');
    }
}
