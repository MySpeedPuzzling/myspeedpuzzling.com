<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The old participants page - retired for the participants spreadsheet's People tab
 * (docs/features/competitions-management/participants-spreadsheet.md D12). Bookmarks and old links land on the sheet;
 * nobody else learns anything they could not before (the event's organisers only).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageCompetitionParticipantsController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/sprava-ucastniku-udalosti/{competitionId}',
            'en' => '/en/manage-event-participants/{competitionId}',
            'es' => '/es/manage-event-participants/{competitionId}',
            'ja' => '/ja/manage-event-participants/{competitionId}',
            'fr' => '/fr/manage-event-participants/{competitionId}',
            'de' => '/de/manage-event-participants/{competitionId}',
        ],
        name: 'manage_competition_participants',
    )]
    public function __invoke(string $competitionId): RedirectResponse
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        return $this->redirectToRoute('participants_sheet', ['competitionId' => $competitionId]);
    }
}
