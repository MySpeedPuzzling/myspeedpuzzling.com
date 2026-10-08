<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\ReturnQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The old pairs/teams page of a round - retired for the round's tab of the participants spreadsheet
 * (docs/features/competitions-management/participants-spreadsheet.md D12): an unknown round is still 404, a round of
 * an event the visitor does not organise still 403, everything else lands on the sheet's tab of that round.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageRoundTeamsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-tymu-kola/{roundId}',
            'en' => '/en/manage-round-teams/{roundId}',
            'es' => '/es/manage-round-teams/{roundId}',
            'ja' => '/ja/manage-round-teams/{roundId}',
            'fr' => '/fr/manage-round-teams/{roundId}',
            'de' => '/de/manage-round-teams/{roundId}',
        ],
        name: 'manage_round_teams',
    )]
    public function __invoke(Request $request, string $roundId): RedirectResponse
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        // A validated `?return=` goes along - the sheet's back link goes where the old page's went
        return $this->redirectToRoute('participants_sheet', [
            'competitionId' => $competitionId,
            'tab' => $round->id->toString(),
        ] + ReturnQuery::from($request));
    }
}
