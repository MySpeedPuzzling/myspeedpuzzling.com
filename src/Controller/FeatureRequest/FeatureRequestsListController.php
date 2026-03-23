<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\FeatureRequest;

use SpeedPuzzling\Web\Query\CountPlayerFeatureRequestsThisMonth;
use SpeedPuzzling\Web\Query\GetFeatureRequests;
use SpeedPuzzling\Web\Query\GetPlayerVoteCountThisMonth;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FeatureRequestsListController extends AbstractController
{
    public function __construct(
        readonly private GetFeatureRequests $getFeatureRequests,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private IsHintDismissed $isHintDismissed,
        readonly private CountPlayerFeatureRequestsThisMonth $countPlayerFeatureRequestsThisMonth,
        readonly private GetPlayerVoteCountThisMonth $getPlayerVoteCountThisMonth,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/navrhy-funkci',
            'en' => '/en/feature-requests',
            'es' => '/es/feature-requests',
            'ja' => '/ja/feature-requests',
            'fr' => '/fr/feature-requests',
            'de' => '/de/feature-requests',
        ],
        name: 'feature_requests',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $featureRequests = $this->getFeatureRequests->allSortedByVotes();

        $hintDismissed = false;
        $votesUsedThisMonth = 0;
        $requestsCreatedThisMonth = 0;

        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();
        if ($loggedPlayer !== null) {
            $hintDismissed = ($this->isHintDismissed)($loggedPlayer->playerId, HintType::FeatureRequestsIntro);
            $votesUsedThisMonth = ($this->getPlayerVoteCountThisMonth)($loggedPlayer->playerId);
            $requestsCreatedThisMonth = ($this->countPlayerFeatureRequestsThisMonth)($loggedPlayer->playerId);
        }

        return $this->render('feature_request/list.html.twig', [
            'feature_requests' => $featureRequests,
            'hint_dismissed' => $hintDismissed,
            'votes_used_this_month' => $votesUsedThisMonth,
            'requests_created_this_month' => $requestsCreatedThisMonth,
        ]);
    }
}
