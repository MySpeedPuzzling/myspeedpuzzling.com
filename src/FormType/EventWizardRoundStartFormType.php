<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\EventWizardRoundStartFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One generated round's name, day and time (EventWizardRoundStartsFormType) - the day and time rendered as
 * <input type="date"> and <input type="time"> (templates/event_wizard/structure.html.twig), all required.
 *
 * @extends AbstractType<EventWizardRoundStartFormData>
 */
final class EventWizardRoundStartFormType extends AbstractType
{
    /**
     * @param mixed[] $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'event_wizard.structure.round_name',
        ]);
        $builder->add('date', TextType::class, [
            'label' => 'event_wizard.structure.start_date',
        ]);
        $builder->add('time', TextType::class, [
            'label' => 'event_wizard.structure.start_time',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardRoundStartFormData::class,
            // The row's own error ("choose the day and type the time") is shown on the row
            'error_bubbling' => false,
        ]);
    }
}
