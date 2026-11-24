<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardStandingRulesFormData;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The rules of a standing the creation wizard makes - the standing form's fields and words (CompetitionStandingFormType),
 * used by the Structure templates and the Extras' "Overall standings" (spws-wizard.md).
 *
 * @extends AbstractType<EventWizardStandingRulesFormData>
 */
final class EventWizardStandingRulesFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('penaltySecondsPerPiece', IntegerType::class, [
            'label' => 'competition_standing.form.penalty',
            'help' => 'competition_standing.form.penalty_help',
            'required' => false,
            'attr' => ['min' => 0, 'max' => StandingRules::PENALTY_MAX, 'inputmode' => 'numeric'],
        ]);

        $builder->add('penaliseFinishedMissing', CheckboxType::class, [
            'required' => false,
            'label' => 'finished_missing.standing_form.penalise',
            'help' => 'finished_missing.standing_form.penalise_help',
        ]);

        $builder->add('orderRule', EnumType::class, [
            'class' => StandingOrder::class,
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
            'label' => 'competition_standing.form.order',
            'choice_label' => static fn (StandingOrder $order): string => 'competition_standing.order.' . $order->value,
        ]);

        $builder->add('missingRule', EnumType::class, [
            'class' => StandingMissing::class,
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
            'label' => 'competition_toolkit.standings.missing_rule.label',
            'choice_label' => static fn (StandingMissing $missing): string => 'competition_toolkit.standings.missing_rule.' . $missing->value,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardStandingRulesFormData::class,
            'label' => false,
        ]);
    }
}
