<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardStageFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One stage row of the Qualification template (EventWizardStructureFormType). `advance` ("top N of each go on") only
 * with the option `advancement` - for whom COMPETITION_TOOLKIT_MANAGE grants (W7).
 *
 * @extends AbstractType<EventWizardStageFormData>
 */
final class EventWizardStageFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $challenges = $options['challenges'] === true;

        if ($challenges) {
            // A Challenges row (spws-wizard.md): the rounds are named after their challenges - no name of its own
            $builder->add('challenges', ChoiceType::class, [
                'label' => 'event_wizard.structure.challenges.back_to_back',
                'help' => 'event_wizard.structure.challenges.back_to_back_help',
                'required' => false,
                'placeholder' => 'event_wizard.structure.challenges.unused',
                'choices' => array_combine(
                    array_map(static fn (int $count): string => (string) $count, range(1, EventWizardStageFormData::CHALLENGES_MAX)),
                    range(1, EventWizardStageFormData::CHALLENGES_MAX),
                ),
                'choice_translation_domain' => false,
            ]);
        } else {
            $builder->add('name', TextType::class, [
                'label' => 'event_wizard.structure.stage_name',
                'required' => false,
            ]);
        }

        $builder->add('roundsCount', IntegerType::class, [
            'label' => $challenges ? 'event_wizard.structure.challenges.rounds' : 'event_wizard.structure.stage_rounds',
            'required' => false,
            'attr' => ['min' => 1, 'max' => EventWizardStageFormData::ROUNDS_MAX, 'inputmode' => 'numeric'],
        ]);
        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'label' => 'event_wizard.puzzle.who_competes',
            // Always one of them - no "required" marker, no empty choice
            'required' => false,
            'placeholder' => false,
            'empty_data' => RoundCategory::Solo->value,
            'choice_label' => static fn (RoundCategory $category): string => EventWizardScheduleRowFormType::categoryLabel($category),
        ]);
        $builder->add('teamSize', IntegerType::class, [
            'label' => 'event_wizard.puzzle.team_size',
            'required' => false,
            'attr' => ['min' => 2, 'max' => 20, 'inputmode' => 'numeric'],
        ]);
        $builder->add('minutesLimit', IntegerType::class, [
            'label' => 'competition.round.form.minutes_limit',
            // A block's limit covers all its challenges (one stopwatch)
            'help' => $challenges ? 'event_wizard.structure.challenges.minutes_help' : null,
            'required' => false,
            'attr' => ['min' => 1, 'inputmode' => 'numeric'],
        ]);

        if ($options['advancement'] === true && $challenges === false) {
            $builder->add('advance', IntegerType::class, [
                'label' => 'event_wizard.structure.advance',
                'required' => false,
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardStageFormData::class,
            'advancement' => false,
            'challenges' => false,
        ]);
        $resolver->setAllowedTypes('advancement', 'bool');
        $resolver->setAllowedTypes('challenges', 'bool');
    }
}
