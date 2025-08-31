<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ImportCompetitionPuzzlersController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
    ) {
    }

    #[Route(
        path: '/admin/import-competition-puzzlers',
        name: 'admin_import_competition_puzzlers',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        #[CurrentUser] User $user,
    ): Response {
        return $this->render('admin/import_competition_puzzlers.html.twig', [
            'live_events' => $this->getCompetitionEvents->allLive(),
            'upcoming_events' => $this->getCompetitionEvents->allUpcoming(),
            'past_events' => $this->getCompetitionEvents->allPast(),
        ]);
    }
}
