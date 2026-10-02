<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DismissHint;
use SpeedPuzzling\Web\Query\GetGettingStartedProgress;
use SpeedPuzzling\Web\Query\GetPlayerReviewCounts;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class HubController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetGettingStartedProgress $getGettingStartedProgress,
        readonly private GetPlayerReviewCounts $getPlayerReviewCounts,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/hub',
            'en' => '/en/hub',
            'es' => '/es/centro',
            'ja' => '/ja/ハブ',
            'fr' => '/fr/hub',
            'de' => '/de/zentrale',
        ],
        name: 'hub',
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $gettingStarted = null;
        $reviewCounts = null;

        if ($profile !== null) {
            // "Review your results" banner (docs/features/duplicate-results.md) - one query, signed-in players only;
            // without the first-try conflicts, which cost a pass over all the player's results (the profile has them)
            $reviewCounts = $this->getPlayerReviewCounts->forPlayer($profile->playerId, withFirstTryConflicts: false);

            $progress = $this->getGettingStartedProgress->forPlayer($profile);

            if ($progress->shouldBeShown()) {
                $gettingStarted = $progress;
            }
        }

        $response = $this->render('hub.html.twig', [
            'getting_started' => $gettingStarted,
            'review_counts' => $reviewCounts,
            // Open while the core action is still ahead of them; once the first puzzle is logged the
            // card steps back to one line, so it does not keep pushing the Hub off a phone screen
            'getting_started_folded' => $gettingStarted !== null && $gettingStarted->hasLoggedPuzzle,
        ]);

        if ($gettingStarted !== null && $profile !== null && $gettingStarted->isComplete()) {
            // Everything ticked: this render shows the "all set" state, and it is the last one
            $this->messageBus->dispatch(new DismissHint($profile->playerId, HintType::GettingStartedChecklist));
        }

        return $response;
    }
}
