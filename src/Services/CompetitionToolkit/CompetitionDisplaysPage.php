<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\FormData\AddCompetitionDisplayFormData;
use SpeedPuzzling\Web\FormType\AddCompetitionDisplayFormType;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the displays page and the toolkit hub's Displays card show (docs/features/competitions-management/toolkit/
 * D-displays-overlays.md §8.1, R §1.4 card 2): the event's displays, its rounds (to name the followed ones) and the
 * "New display" form, which posts to AddCompetitionDisplayController and comes back to $returnPath.
 */
readonly final class CompetitionDisplaysPage
{
    public function __construct(
        private GetCompetitionDisplays $getCompetitionDisplays,
        private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private GetCompetitionEvents $getCompetitionEvents,
        private FormFactoryInterface $formFactory,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return FormInterface<AddCompetitionDisplayFormData>
     */
    public function addForm(Request $request, string $competitionId, string $returnPath): FormInterface
    {
        return $this->formFactory->create(
            AddCompetitionDisplayFormType::class,
            AddCompetitionDisplayFormData::withLocale($request->getLocale()),
            ['action' => $this->urlGenerator->generate('add_competition_display', ['competitionId' => $competitionId, 'return' => $returnPath])],
        );
    }

    /**
     * @param null|FormInterface<AddCompetitionDisplayFormData> $addForm
     * @return array<string, mixed> the variables of competition_toolkit/displays.html.twig
     */
    public function variables(Request $request, string $competitionId, null|FormInterface $addForm = null): array
    {
        $returnPath = $this->urlGenerator->generate('competition_displays', ['competitionId' => $competitionId]);

        return [
            'competition' => $this->getCompetitionEvents->byId($competitionId),
            'rounds' => $this->getCompetitionRoundsForManagement->ofCompetition($competitionId),
            'displays' => $this->getCompetitionDisplays->ofCompetition($competitionId),
            'add_display_form' => ($addForm ?? $this->addForm($request, $competitionId, $returnPath))->createView(),
            'displays_return' => $returnPath,
        ];
    }
}
