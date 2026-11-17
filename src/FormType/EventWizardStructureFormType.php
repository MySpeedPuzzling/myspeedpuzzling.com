<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardStructureFormData;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStructureTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The creation wizard's Structure templates (W-creation-wizard-research.md §3.5) - "Create the rounds" writes what
 * EventWizardStructurePlanner generates from it. Options (both for whom COMPETITION_TOOLKIT_MANAGE grants, W7):
 * - `advancement`: each stage's "top N of each go on" (advancement steps);
 * - `standings`: "add up the times per category" (a standing per category).
 *
 * @extends AbstractType<EventWizardStructureFormData>
 */
final class EventWizardStructureFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('template', EnumType::class, [
            'class' => EventWizardStructureTemplate::class,
            'label' => 'event_wizard.structure.template',
            'choice_label' => static fn (EventWizardStructureTemplate $template): string => 'event_wizard.entry.qualify_' . $template->value,
            'expanded' => true,
        ]);

        $builder->add('stages', CollectionType::class, [
            'entry_type' => EventWizardStageFormType::class,
            'entry_options' => ['label' => false, 'advancement' => $options['advancement']],
            'label' => false,
        ]);

        foreach (['solo', 'pairs', 'teams'] as $category) {
            $builder->add($category . 'Rounds', IntegerType::class, [
                'label' => 'event_wizard.structure.' . $category . '_rounds',
                'required' => false,
                'attr' => ['min' => 0, 'max' => EventWizardStructureFormData::CATEGORY_ROUNDS_MAX, 'inputmode' => 'numeric'],
            ]);
            $builder->add($category . 'Minutes', IntegerType::class, [
                'label' => 'competition.round.form.minutes_limit',
                'required' => false,
                'attr' => ['min' => 1, 'inputmode' => 'numeric'],
            ]);
        }

        $builder->add('teamSize', IntegerType::class, [
            'label' => 'event_wizard.puzzle.team_size',
            'required' => false,
            'attr' => ['min' => 2, 'max' => 20, 'inputmode' => 'numeric'],
        ]);

        if ($options['standings'] === true) {
            $builder->add('sumPerCategory', CheckboxType::class, [
                'label' => 'event_wizard.structure.sum_per_category',
                'help' => 'event_wizard.structure.sum_per_category_help',
                'required' => false,
            ]);
        }

        $builder->add('interleaved', ChoiceType::class, [
            'label' => 'event_wizard.structure.order',
            'choices' => [
                'event_wizard.structure.order_interleaved' => true,
                'event_wizard.structure.order_by_category' => false,
            ],
            'expanded' => true,
        ]);

        $builder->add('startTime', TextType::class, [
            'label' => 'event_wizard.structure.first_start',
            // Rendered as <input type="time">
            'attr' => ['placeholder' => '10:00', 'autocomplete' => 'off'],
        ]);

        $builder->add('everyMinutes', IntegerType::class, [
            'label' => 'event_wizard.structure.every_minutes',
            'required' => false,
            // Empty = automatic (EventWizardStructurePlanner)
            'attr' => ['min' => 0, 'step' => 5, 'inputmode' => 'numeric', 'placeholder' => 'event_wizard.structure.every_minutes_automatic'],
        ]);

        $builder->add('build', SubmitType::class, [
            'label' => $options['replace'] === true ? 'event_wizard.structure.replace' : 'event_wizard.structure.build',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardStructureFormData::class,
            'advancement' => false,
            'standings' => false,
            // The event has rounds: the template replaces them
            'replace' => false,
        ]);
        $resolver->setAllowedTypes('advancement', 'bool');
        $resolver->setAllowedTypes('standings', 'bool');
        $resolver->setAllowedTypes('replace', 'bool');
    }
}
