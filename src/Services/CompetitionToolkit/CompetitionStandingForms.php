<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\FormData\CompetitionStandingFormData;
use SpeedPuzzling\Web\FormType\CompetitionStandingFormType;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The standing form of the add and edit pages (competition toolkit A6): the event's rounds labelled "Name · Category".
 */
readonly final class CompetitionStandingForms
{
    public function __construct(
        private FormFactoryInterface $formFactory,
        private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return FormInterface<CompetitionStandingFormData>
     */
    public function create(string $competitionId, CompetitionStandingFormData $data, string $action): FormInterface
    {
        $labels = [];

        foreach ($this->getCompetitionRoundsForManagement->ofCompetition($competitionId) as $round) {
            $labels[$round->id] = $round->name . ' · ' . $this->translator->trans('competition.round.category.' . $round->category->value);
        }

        return $this->formFactory->create(CompetitionStandingFormType::class, $data, [
            'action' => $action,
            'rounds' => $labels,
        ]);
    }
}
