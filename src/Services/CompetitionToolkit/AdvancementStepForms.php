<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\FormData\AdvancementStepFormData;
use SpeedPuzzling\Web\FormType\AdvancementStepFormType;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The advancement step form of the add and edit pages (competition toolkit A8): the event's rounds labelled
 * "Name · Category".
 */
readonly final class AdvancementStepForms
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<AdvancementStepFormData>
     */
    public function create(string $competitionId, AdvancementStepFormData $data, string $action): FormInterface
    {
        $labels = [];

        foreach ($this->getCompetitionRoundsForManagement->ofCompetition($competitionId) as $round) {
            $labels[$round->id] = $round->name . ' · ' . $this->translator->trans('competition.round.category.' . $round->category->value);
        }

        return $this->formFactory->create(AdvancementStepFormType::class, $data, [
            'action' => $action,
            'rounds' => $labels,
            'map_placeholder' => $this->translator->trans('advancement_step.form.map_none'),
        ]);
    }
}
