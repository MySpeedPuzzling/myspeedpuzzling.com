<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\FormData\CompetitionRoundFormData;
use SpeedPuzzling\Web\FormData\EventWizardPuzzleFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The event creation wizard's "Puzzle & format" (W-creation-wizard-research.md §3.4). The puzzle fields are the round
 * puzzle form's own (RoundPuzzleFormType - brand + puzzle autocomplete, a new puzzle's pieces, photo and codes, "keep it
 * secret" + what to hide), the puzzle optional ("Decide later").
 *
 * Options:
 * - `stopwatch_reveal`: "when I start the stopwatch" among the reveal choices (COMPETITION_TOOLKIT_MANAGE);
 * - `puzzle_format`: "each competitor solves all / K of them" (COMPETITION_TOOLKIT_MANAGE, or a round using `pick`
 *   already - the edit round form's rule), shown by the template once the round has a puzzle and another is added.
 *
 * @extends AbstractType<EventWizardPuzzleFormData>
 */
final class EventWizardPuzzleFormType extends AbstractType
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
        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'label' => 'event_wizard.puzzle.who_competes',
            'choice_label' => static fn (RoundCategory $category): string => match ($category) {
                RoundCategory::Solo => 'competition.round.category.solo',
                RoundCategory::Duo => 'event_wizard.puzzle.category_pairs',
                RoundCategory::Team => 'event_wizard.puzzle.category_teams',
            },
            'expanded' => true,
        ]);

        $builder->add('teamSize', IntegerType::class, [
            'label' => 'event_wizard.puzzle.team_size',
            'help' => 'participants_sheet_server.round_form.team_size_help',
            'required' => false,
            'attr' => [
                'min' => CompetitionRound::TEAM_SIZE_MIN,
                'max' => CompetitionRound::TEAM_SIZE_MAX,
                'step' => 1,
                'inputmode' => 'numeric',
                'class' => 'w-auto',
            ],
        ]);

        $builder->add('minutesLimit', IntegerType::class, [
            'label' => 'competition.round.form.minutes_limit',
            'attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'class' => 'w-auto'],
        ]);

        /** @var string $competitionId */
        $competitionId = $options['competition_id'];
        // The round puzzle form's fields, built into this form - its data class has the same properties
        /** @phpstan-ignore argument.type */
        $this->roundPuzzleFormType->buildForm($builder, [
            'competition_id' => $competitionId,
            'puzzle_required' => false,
        ]);

        $revealChoices = [RoundPuzzleReveal::Automatic];
        if ($options['stopwatch_reveal'] === true) {
            $revealChoices[] = RoundPuzzleReveal::Stopwatch;
        }
        $revealChoices[] = RoundPuzzleReveal::Manual;

        // Labels in templates/event_wizard/puzzle.html.twig (the automatic one names its moment)
        $builder->add('revealMode', EnumType::class, [
            'class' => RoundPuzzleReveal::class,
            'choices' => $revealChoices,
            'label' => 'event_wizard.puzzle.reveal_label',
            'choice_label' => static fn (RoundPuzzleReveal $reveal): string => 'event_wizard.puzzle.reveal_' . $reveal->value,
            'expanded' => true,
        ]);

        // "Add another puzzle": saved, then this step again; "Finish later": saved, then the draft's page
        $builder->add('another', SubmitType::class, [
            'label' => 'event_wizard.puzzle.add_another',
        ]);
        $builder->add('later', SubmitType::class, [
            'label' => 'event_wizard.finish_later',
        ]);

        if ($options['puzzle_format'] === true) {
            /** @var int $puzzlesCount */
            $puzzlesCount = $options['puzzles_count'];

            // The round edit form's three choices and words (CompetitionRoundFormType, reliability hardening T1)
            $builder->add('puzzleFormat', ChoiceType::class, [
                'property_path' => 'puzzleFormatChoice',
                'label' => 'competition_toolkit.round_format.form.label',
                'choices' => [
                    'competition_toolkit.round_format.name.total' => CompetitionRoundFormData::FORMAT_CHOICE_TOTAL,
                    'competition_toolkit.round_format.name.choice_all' => CompetitionRoundFormData::FORMAT_CHOICE_ALL,
                    'competition_toolkit.round_format.name.choice_pick' => CompetitionRoundFormData::FORMAT_CHOICE_PICK,
                ],
                'choice_translation_parameters' => static fn (): array => ['%count%' => $puzzlesCount],
                'expanded' => true,
            ]);

            $builder->add('puzzlesToSolve', IntegerType::class, [
                'label' => 'competition_toolkit.round_format.name.to_solve',
                'help' => 'competition_toolkit.round_format.name.to_solve_help',
                'help_translation_parameters' => ['%count%' => $puzzlesCount],
                'required' => false,
                'attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'class' => 'w-auto'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardPuzzleFormData::class,
            'stopwatch_reveal' => false,
            'puzzle_format' => false,
            // The round's puzzles now - "Solve all N" names N
            'puzzles_count' => 0,
        ]);

        $resolver->setRequired('competition_id');
        $resolver->setAllowedTypes('competition_id', 'string');
        $resolver->setAllowedTypes('stopwatch_reveal', 'bool');
        $resolver->setAllowedTypes('puzzle_format', 'bool');
        $resolver->setAllowedTypes('puzzles_count', 'int');
    }
}
