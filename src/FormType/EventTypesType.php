<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Value\EventType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "What kind of event" (docs/features/events-page/event-types.md): a checkbox per EventType, optional - none ticked =
 * not set. The form data holds list<EventType>; EventTypes::of() turns it into the message's value.
 *
 * @extends AbstractType<list<EventType>>
 */
final class EventTypesType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => EventType::class,
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'label' => 'organizer_tools.form.event_types',
            'help' => 'organizer_tools.form.event_types_help',
            'choice_label' => static fn (EventType $type): string => $type->translationKey(),
            'label_attr' => ['class' => 'checkbox-inline'],
        ]);
    }

    public function getParent(): string
    {
        return EnumType::class;
    }
}
