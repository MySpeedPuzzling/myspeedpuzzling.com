<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardScheduleFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Structure step's schedule table (W-creation-wizard-research.md §3.5): every round's name, start, who competes and
 * time limit editable, "remove" per round, spare rows for new rounds. One row per key of the data's `rows` (a round id,
 * or `new<N>`) - built from the data handed to the form, never from what is posted.
 *
 * @extends AbstractType<EventWizardScheduleFormData>
 */
final class EventWizardScheduleFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $rows = $builder->create('rows', FormType::class, ['label' => false]);

        /** @var array<string, bool> $rowKeys key => existing round */
        $rowKeys = $options['row_keys'];

        foreach ($rowKeys as $key => $existing) {
            $rows->add($key, EventWizardScheduleRowFormType::class, [
                'label' => false,
                'existing' => $existing,
            ]);
        }

        $builder->add($rows);

        // "Finish later": saved like "Save & continue", then the draft's page
        $builder->add('later', SubmitType::class, [
            'label' => 'event_wizard.finish_later',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardScheduleFormData::class,
            'row_keys' => [],
        ]);
        $resolver->setAllowedTypes('row_keys', 'array');
    }
}
