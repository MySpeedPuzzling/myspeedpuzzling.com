<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\PairsAndTeams;

use SpeedPuzzling\Web\Query\GetCoPuzzlers;
use SpeedPuzzling\Web\Query\GetGuestLinkRequests;
use SpeedPuzzling\Web\Results\PersonSuggestion;
use SpeedPuzzling\Web\Services\CoPuzzlerPicker;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Pairs & teams": everybody the player puzzled with, as the pairs and teams they formed
 * (docs/features/pairs-and-teams/README.md). Naming, preparing one ahead, deleting an unused one.
 */
final class PairsAndTeamsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'pairs_and_teams';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetCoPuzzlers $getCoPuzzlers,
        readonly private CoPuzzlerPicker $coPuzzlerPicker,
        readonly private GetGuestLinkRequests $getGuestLinkRequests,
    ) {
    }

    #[Route(
        path: '/{_locale}/pairs-and-teams',
        name: 'pairs_and_teams',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $suggestions = $this->getCoPuzzlers->forPlayer($player->playerId);

        $people = [];
        foreach ($suggestions->people as $person) {
            $people[$person->key] = $person;
        }

        $pairs = [];
        $teams = [];

        foreach ($suggestions->teams as $team) {
            $row = [
                'team' => $team,
                'members' => array_values(array_filter(array_map(
                    static fn(string $key): null|PersonSuggestion => $people[$key] ?? null,
                    $team->memberKeys,
                ))),
                // Puzzled together exactly once and never named: most of any long list, so kept out of the way
                'oneOff' => $team->timesCount === 1 && $team->name === null,
                'archived' => $team->archived,
            ];

            if ($team->size === 2) {
                $pairs[] = $row;
            } else {
                $teams[] = $row;
            }
        }

        // Co-puzzlers without an account: the names are free text, so this is where a typo gets fixed
        $guests = array_values(array_filter(
            $suggestions->people,
            static fn(PersonSuggestion $person): bool => $person->isGuest() && $person->timesCount > 0,
        ));

        $show = match ($request->query->getString('show')) {
            'teams' => 'teams',
            'guests' => 'guests',
            default => 'pairs',
        };

        return $this->render('pairs_and_teams/index.html.twig', [
            'show' => $show,
            'rows' => $show === 'teams' ? $teams : $pairs,
            'pairs_count' => count($pairs),
            'teams_count' => count($teams),
            'guests' => $guests,
            // Guests the player already asked somebody about: guest key => the asked player's code
            'pending_guest_links' => $show === 'guests' ? $this->getGuestLinkRequests->pendingOf($player->playerId) : [],
            'copuzzler_picker' => $this->coPuzzlerPicker->formState([]),
        ]);
    }
}
