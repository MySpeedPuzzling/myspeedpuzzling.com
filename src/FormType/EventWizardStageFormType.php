<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardStageFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Form\AbstractType;
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
        $builder->add('name', TextType::class, [
            'label' => 'event_wizard.structure.stage_name',
            'required' => false,
        ]);
        $builder->add('roundsCount', IntegerType::class, [
            'label' => 'event_wizard.structure.stage_rounds',
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
            'required' => false,
            'attr' => ['min' => 1, 'inputmode' => 'numeric'],
        ]);

        if ($options['advancement'] === true) {
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
        ]);
        $resolver->setAllowedTypes('advancement', 'bool');
    }
}
