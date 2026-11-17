<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormType;

use SpeedPuzzling\Web\FormData\CompetitionFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The event creation wizard's "Basics" of a series (W-creation-wizard-research.md §3.3, W3): the series form
 * (CompetitionFormType with `series` - no dates nor registration/results links, its editions have them) without the
 * date - "When" is the next step's rule. "When it happens" (schedule) is not asked: the dates say it.
 *
 * @extends AbstractType<CompetitionFormData>
 */
final class EventWizardSeriesBasicsFormType extends AbstractType
{
    public function getParent(): string
    {
        return CompetitionFormType::class;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A new series' id, made when the page is shown: a second submit of the same page finds the series the first one
        // created (EventWizardSeriesBasicsController, like W1's Basics)
        $builder->add('draftId', HiddenType::class, [
            'mapped' => false,
        ]);

        // "Finish later": saved like "Save & continue", then the series' page
        $builder->add('later', SubmitType::class, [
            'label' => 'event_wizard.finish_later',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'series' => true,
        ]);
    }
}
