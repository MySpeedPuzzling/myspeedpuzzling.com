<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardScheduleRowFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of the schedule table (EventWizardScheduleFormType). The round id is never a field - the row's key in the
 * collection is (EventWizardStructureController builds the rows from the event's rounds).
 *
 * @extends AbstractType<EventWizardScheduleRowFormData>
 */
final class EventWizardScheduleRowFormType extends AbstractType
{
    public static function categoryLabel(RoundCategory $category): string
    {
        return match ($category) {
            RoundCategory::Solo => 'competition.round.category.solo',
            RoundCategory::Duo => 'event_wizard.puzzle.category_pairs',
            RoundCategory::Team => 'event_wizard.puzzle.category_teams',
        };
    }

    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'event_wizard.structure.round_name',
            'required' => false,
        ]);
        // Rendered as <input type="datetime-local"> (templates/event_wizard/structure.html.twig)
        $builder->add('start', TextType::class, [
            'label' => 'event_wizard.structure.start',
            'required' => false,
        ]);
        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'label' => 'event_wizard.puzzle.who_competes',
            'choice_label' => static fn (RoundCategory $category): string => self::categoryLabel($category),
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

        if ($options['existing'] === true) {
            $builder->add('remove', CheckboxType::class, [
                'label' => 'event_wizard.structure.remove',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardScheduleRowFormData::class,
            'existing' => true,
        ]);
        $resolver->setAllowedTypes('existing', 'bool');
    }
}
