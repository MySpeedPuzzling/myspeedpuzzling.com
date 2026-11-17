<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\FormData\EventWizardDatesFormData;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The event creation wizard's "Dates + each date's round" (W3) - "Add several dates" (AddEditionsFormType: the rule or
 * the picked days, starting, count, the name pattern) without what the series already says (event types, who can
 * enter - its editions follow the series), plus each date's round: who competes, the time limit, the start time - or
 * "No rounds - just dates on the calendar". A GET form without a name, like the classic page: the preview is a link.
 *
 * @extends AbstractType<EventWizardDatesFormData>
 */
final class EventWizardDatesFormType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getParent(): string
    {
        return AddEditionsFormType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->remove('eventTypes')
            ->remove('eligibility');

        $builder->add('withRounds', ChoiceType::class, [
            'label' => 'event_wizard_series.dates.rounds_label',
            'choices' => [
                'event_wizard_series.dates.with_rounds' => true,
                'event_wizard_series.dates.no_rounds' => false,
            ],
            'choice_value' => static fn (null|bool $choice): string => $choice === null ? '' : ($choice ? '1' : '0'),
            'expanded' => true,
            'placeholder' => false,
        ]);

        $builder->add('category', EnumType::class, [
            'class' => RoundCategory::class,
            'label' => 'event_wizard.puzzle.who_competes',
            'choice_label' => static fn (RoundCategory $category): string => match ($category) {
                RoundCategory::Solo => 'competition.round.category.solo',
                RoundCategory::Duo => 'event_wizard.puzzle.category_pairs',
                RoundCategory::Team => 'event_wizard.puzzle.category_teams',
            },
            'expanded' => true,
            'required' => false,
            'placeholder' => false,
        ]);

        $builder->add('teamSize', IntegerType::class, [
            'label' => 'event_wizard.puzzle.team_size',
            'required' => false,
            'attr' => [
                'min' => CompetitionRound::TEAM_SIZE_MIN,
                'max' => CompetitionRound::TEAM_SIZE_MAX,
                'step' => 1,
                'inputmode' => 'numeric',
                'class' => 'w-auto',
            ],
        ]);

        $builder->add('minutesLimit', IntegerType::class, [
            'label' => 'competition.round.form.minutes_limit',
            'required' => false,
            'attr' => ['min' => 1, 'step' => 1, 'inputmode' => 'numeric', 'class' => 'w-auto'],
        ]);

        $builder->add('startTime', TextType::class, [
            'label' => 'event_wizard.basics.start_time',
            'help' => 'event_wizard_series.dates.start_time_help',
            'required' => false,
            'attr' => ['placeholder' => '19:00', 'autocomplete' => 'off'],
        ]);

        // The round's fields are asked only with "Each date has a round"
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $data = $form->getData();

            if (!$data instanceof EventWizardDatesFormData || $data->hasRounds() === false) {
                return;
            }

            if ($data->startTime === null || trim($data->startTime) === '') {
                $form->get('startTime')->addError(new FormError($this->translator->trans('event_wizard_series.dates.start_time_required')));
            } elseif (EventWizardBasicsFormType::startTime($data->startTime) === null) {
                $form->get('startTime')->addError(new FormError($this->translator->trans('event_wizard.basics.start_time_invalid')));
            }

            if ($data->minutesLimit === null || $data->minutesLimit < 1) {
                $form->get('minutesLimit')->addError(new FormError($this->translator->trans('event_wizard_series.dates.minutes_limit_required')));
            }

            if (
                $data->roundCategory() === RoundCategory::Team
                && $data->teamSize !== null
                && ($data->teamSize < CompetitionRound::TEAM_SIZE_MIN || $data->teamSize > CompetitionRound::TEAM_SIZE_MAX)
            ) {
                $form->get('teamSize')->addError(new FormError($this->translator->trans('event_wizard_series.dates.team_size_range', [
                    '%min%' => CompetitionRound::TEAM_SIZE_MIN,
                    '%max%' => CompetitionRound::TEAM_SIZE_MAX,
                ])));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventWizardDatesFormData::class,
        ]);
    }
}
