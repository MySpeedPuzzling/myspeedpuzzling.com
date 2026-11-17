<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Basics" (W-creation-wizard-research.md §3.3) - the event form (CompetitionFormType, the
 * same fields, labels and validation) without what belongs elsewhere: the registration and results links (Extras, W6)
 * and "Recurring" (the entry question). New here, for the one round the wizard creates with the event:
 *
 * - `startTime` ("HH:MM", the round's start on the first day, in the zone of the event's country);
 *   (option `start_time` false: left out - several rounds with their rounds, W5);
 * - `multiDay` ("It lasts more days") - unticked, the event ends the day it starts (dateTo = dateFrom).
 *
 * The date is required for an online event too: its round needs a start.
 *
 * @extends AbstractType<CompetitionFormData>
 */
final class EventWizardBasicsFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getParent(): string
    {
        return CompetitionFormType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->remove('registrationLink')
            ->remove('resultsLink')
            ->remove('isRecurring');

        if ($options['start_time'] === true) {
            $builder->add('startTime', TextType::class, [
                'label' => 'event_wizard.basics.start_time',
                'mapped' => false,
                'required' => true,
                'constraints' => [new NotBlank()],
                // Rendered as <input type="time"> (templates/event_wizard/basics.html.twig)
                'attr' => ['placeholder' => '10:00', 'autocomplete' => 'off'],
            ]);
        }

        $builder->add('multiDay', CheckboxType::class, [
            'label' => 'event_wizard.basics.multi_day',
            'mapped' => false,
            'required' => false,
        ]);

        // A new event's id, made when the page is shown: a second submit of the same page (a double click, a resend)
        // finds the draft the first one created instead of creating another. Anything but a uuid is replaced by a fresh
        // id (EventWizardBasicsController)
        $builder->add('draftId', HiddenType::class, [
            'mapped' => false,
        ]);

        // "Finish later": saved like "Save & continue", then the draft's page
        $builder->add('later', SubmitType::class, [
            'label' => 'event_wizard.finish_later',
        ]);

        // One day unless "It lasts more days" is ticked - an end date left in the hidden field is dropped
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();

            if (is_array($submitted) && ($submitted['multiDay'] ?? '') === '') {
                $submitted['dateTo'] = $submitted['dateFrom'] ?? null;
                $event->setData($submitted);
            }
        });

        // The round needs its day - for an online event too
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();

            if ($form->get('dateFrom')->getData() === null && $form->get('dateFrom')->getErrors()->count() === 0) {
                $form->get('dateFrom')->addError(new FormError($this->translator->trans('event_wizard.basics.date_required')));
            }

            $startTime = $form->has('startTime') ? $form->get('startTime')->getData() : null;

            if (is_string($startTime) && $startTime !== '' && self::startTime($startTime) === null) {
                $form->get('startTime')->addError(new FormError($this->translator->trans('event_wizard.basics.start_time_invalid')));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // false: no start time - a several-rounds draft whose rounds exist has their times on the Structure step (W5)
        $resolver->setDefaults(['start_time' => true]);
        $resolver->setAllowedTypes('start_time', 'bool');
    }

    /**
     * "HH:MM" as typed (a browser's time input sends it so; "9:30" is accepted too) - null when it is no time of day
     */
    public static function startTime(string $value): null|string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $matches) !== 1) {
            return null;
        }

        return sprintf('%02d:%s', (int) $matches[1], $matches[2]);
    }
}
