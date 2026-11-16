<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The two fields of "I didn't finish within the time limit" in the add and the edit-time form
 * (docs/features/competitions-management/unfinished-results-plan.md, step 4) - the same in both, and the one place that
 * turns the unfinished result's refusals (UnfinishedResult, the handlers) into errors on the right field.
 *
 * Neither field can ever invalidate a form by itself: a checkbox and a text input (no number type, no constraint),
 * read only when the form is in Speed Puzzling mode with the switch on (the FormData's isUnfinished()) - a finished,
 * relax or collection save never looks at them.
 */
final class UnfinishedResultFields
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    public static function add(FormBuilderInterface $builder): void
    {
        $builder->add('unfinished', CheckboxType::class, [
            'label' => 'unfinished_result.form.switch',
            'required' => false,
        ]);

        $builder->add('piecesPlaced', TextType::class, [
            'label' => 'unfinished_result.form.pieces_placed',
            'required' => false,
            'attr' => [
                'inputmode' => 'numeric',
                'pattern' => '[0-9]*',
                'maxlength' => 6,
                'autocomplete' => 'off',
            ],
        ]);
    }

    /**
     * The checks the form makes itself (the domain checks the rest - mapped by refused()).
     *
     * @param FormInterface<mixed> $form
     */
    public static function validate(FormInterface $form, null|int $piecesPlaced, TranslatorInterface $translator): void
    {
        if ($piecesPlaced === null || $piecesPlaced < 1) {
            $form->get('piecesPlaced')->addError(new FormError($translator->trans('unfinished_result.form.pieces_required')));
        }
    }

    /**
     * A refusal of the unfinished result as an error on its field - false for anything else.
     *
     * @param FormInterface<mixed> $form
     */
    public static function refused(\Throwable $refusal, FormInterface $form, TranslatorInterface $translator): bool
    {
        if ($refusal instanceof InvalidPiecesPlaced) {
            $form->get('piecesPlaced')->addError(new FormError($refusal->allPlaced()
                ? $translator->trans('unfinished_result.form.pieces_all', ['%count%' => $refusal->puzzlePiecesCount])
                : $translator->trans('unfinished_result.form.pieces_invalid', ['%max%' => max(1, $refusal->puzzlePiecesCount - 1)])));

            return true;
        }

        if ($refusal instanceof UnfinishedTotalTimeWithinLimit) {
            $form->get('timeMinutes')->addError(new FormError($translator->trans('unfinished_result.form.total_within_limit', [
                '%minutes%' => $refusal->minutesLimit,
                '%time%' => (new SolvingTime($refusal->totalSeconds))->toTimeString() ?? '',
            ])));

            return true;
        }

        if ($refusal instanceof UnfinishedResultNeedsTimeLimit) {
            $form->get('unfinished')->addError(new FormError($translator->trans('unfinished_result.form.needs_round')));

            return true;
        }

        return false;
    }
}
