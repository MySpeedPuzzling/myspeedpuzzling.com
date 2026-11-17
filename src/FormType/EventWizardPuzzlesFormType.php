<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardPuzzlesFormData;
use SpeedPuzzling\Web\FormData\EventWizardPuzzlesRowFormData;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The creation wizard's Puzzles grid (W-creation-wizard-research.md §3.6): "Keep every puzzle secret until its round
 * starts" + the reveal chosen once ("when I start the stopwatch" with `stopwatch_reveal` - COMPETITION_TOOLKIT_MANAGE, as
 * on W1's Puzzle step) + what is hidden; then one row per round (option `rows`: round id => whether "each competitor
 * solves K" is asked - the toolkit's round format, or a round using it already) with EventWizardPuzzlesRowFormData::SLOTS
 * pickers and the row's secret override.
 *
 * @extends AbstractType<EventWizardPuzzlesFormData>
 */
final class EventWizardPuzzlesFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $competitionId */
        $competitionId = $options['competition_id'];
        $stopwatch = $options['stopwatch_reveal'] === true;
        $reveals = $stopwatch
            ? [RoundPuzzleReveal::Automatic, RoundPuzzleReveal::Stopwatch, RoundPuzzleReveal::Manual]
            : [RoundPuzzleReveal::Automatic, RoundPuzzleReveal::Manual];

        $builder->add('secret', CheckboxType::class, [
            'label' => 'event_wizard.puzzles.secret',
            'required' => false,
        ]);
        $builder->add('revealMode', EnumType::class, [
            'class' => RoundPuzzleReveal::class,
            'choices' => $reveals,
            'label' => 'event_wizard.puzzle.reveal_label',
            'choice_label' => static fn (RoundPuzzleReveal $reveal): string => 'event_wizard.puzzles.reveal_' . $reveal->value,
            'expanded' => true,
        ]);
        $builder->add('hideMode', EnumType::class, [
            'class' => PuzzleHideMode::class,
            'label' => 'event_wizard.puzzle.hide_what',
            'expanded' => true,
            'choice_label' => static fn (PuzzleHideMode $mode): string => match ($mode) {
                PuzzleHideMode::ImageOnly => 'competition.round_puzzle.form.hide_mode_image_only',
                PuzzleHideMode::Entirely => 'competition.round_puzzle.form.hide_mode_entirely',
            },
        ]);

        $overrides = ['event_wizard.puzzles.override_default' => '', 'event_wizard.puzzles.override_public' => 'public'];
        foreach ($reveals as $reveal) {
            $overrides['event_wizard.puzzles.override_' . $reveal->value] = $reveal->value;
        }

        $rows = $builder->create('rows', FormType::class, ['label' => false]);

        /** @var array<string, bool> $rowOptions */
        $rowOptions = $options['rows'];

        foreach ($rowOptions as $roundId => $formatAsked) {
            $row = $builder->create($roundId, FormType::class, [
                'label' => false,
                'data_class' => EventWizardPuzzlesRowFormData::class,
            ]);

            $slots = $builder->create('puzzles', FormType::class, ['label' => false]);
            for ($index = 0; $index < EventWizardPuzzlesRowFormData::SLOTS; $index++) {
                $slots->add((string) $index, EventWizardPuzzleSlotFormType::class, ['competition_id' => $competitionId]);
            }
            $row->add($slots);

            $row->add('override', ChoiceType::class, [
                'label' => 'event_wizard.puzzles.override',
                'choices' => $overrides,
                'required' => false,
                'placeholder' => false,
            ]);

            if ($formatAsked) {
                $row->add('puzzlesToSolve', IntegerType::class, [
                    'label' => 'event_wizard.puzzles.to_solve',
                    'help' => 'event_wizard.puzzles.to_solve_help',
                    'required' => false,
                    'attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric'],
                ]);
            }

            $rows->add($row);
        }

        $builder->add($rows);

        // "+ another puzzle" of a row without JavaScript (its value = the round id): saved, then the grid again at that
        // row; "Finish later": saved, then the draft's page
        $builder->add('later', SubmitType::class, [
            'label' => 'event_wizard.finish_later',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardPuzzlesFormData::class,
            'stopwatch_reveal' => false,
            'rows' => [],
        ]);
        $resolver->setRequired('competition_id');
        $resolver->setAllowedTypes('competition_id', 'string');
        $resolver->setAllowedTypes('stopwatch_reveal', 'bool');
        $resolver->setAllowedTypes('rows', 'array');
    }
}
