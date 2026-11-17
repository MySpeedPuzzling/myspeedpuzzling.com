<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardRoundStartsFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The Structure step's start times (Jan 2026-11-17: "the rounds should always be scheduled by the organizer"): one row
 * per round the template generates - `rows` option = how many - each with its day and time. Sent, the rounds are
 * written with their starts in one transaction (SetUpEventWizardStructure); nothing before that.
 *
 * @extends AbstractType<EventWizardRoundStartsFormData>
 */
final class EventWizardRoundStartsFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $rows = $builder->create('rows', FormType::class, ['label' => false]);
        $count = $options['rows'];
        assert(is_int($count));

        for ($index = 0; $index < $count; $index++) {
            $rows->add((string) $index, EventWizardRoundStartFormType::class, ['label' => false]);
        }

        $builder->add($rows);

        $builder->add('create', SubmitType::class, [
            'label' => $options['replace'] === true ? 'event_wizard.structure.replace' : 'event_wizard.structure.create',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardRoundStartsFormData::class,
            'rows' => 0,
            // The event has rounds: these replace them
            'replace' => false,
        ]);
        $resolver->setAllowedTypes('rows', 'int');
        $resolver->setAllowedTypes('replace', 'bool');
    }
}
