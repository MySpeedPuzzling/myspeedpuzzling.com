<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\OnlineLinkRow;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkList;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkUrl;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The personal links of an online event (docs/features/online-competitions/README.md §5.4, contract §5 `online_links`):
 * its rounds with their online timing (each round's "Online timing" page sets it up), and one row per participant who
 * is going - name, linked profile, the link with a copy button, a small QR, "New link" (online_link_regenerate) - plus
 * the CSV and the printable QR sheet. Somebody added since online timing was turned on gets a link when this page opens
 * (OnlineLinkList). Organisers for whom the feature is on (ONLINE_ROUNDS_MANAGE). The links are secrets: never cached.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OnlineLinksController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineLinkList $onlineLinkList,
        private readonly OnlineLinkUrl $onlineLinkUrl,
        private readonly NameTagQrCode $qrCode,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-souteze/{competitionId}/odkazy',
            'en' => '/en/online-competitions/{competitionId}/links',
            'es' => '/es/online-competitions/{competitionId}/links',
            'ja' => '/ja/online-competitions/{competitionId}/links',
            'fr' => '/fr/online-competitions/{competitionId}/links',
            'de' => '/de/online-competitions/{competitionId}/links',
        ],
        name: 'online_links',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();

        $onlineRoundIds = array_map(
            static fn (OnlineRound $onlineRound): string => $onlineRound->round->id->toString(),
            $this->onlineRoundRepository->ofCompetition($competitionId),
        );
        $usesOnlineTiming = $onlineRoundIds !== [];

        $rounds = array_map(static fn (CompetitionRound $round): array => [
            'round' => $round,
            'online' => in_array($round->id->toString(), $onlineRoundIds, true),
            'availability' => OnlineTimingAvailability::of($competition->isOnline, $round->category),
        ], $this->competitionRoundRepository->ofCompetition($competition));

        $rows = $this->onlineLinkList->forCompetition($competitionId, $this->retrieveLoggedUserProfile->getProfile()?->playerId, $usesOnlineTiming);
        $going = array_values(array_filter($rows, static fn (OnlineLinkRow $row): bool => $row->waitlisted === false));

        $response = $this->render('online_rounds/links.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'uses_online_timing' => $usesOnlineTiming,
            'links' => array_map(function (OnlineLinkRow $row): array {
                $url = $row->token !== null ? $this->onlineLinkUrl->of($row->token) : null;

                return [
                    'row' => $row,
                    'url' => $url,
                    'qr' => $url !== null ? $this->qrCode->svg($url) : null,
                ];
            }, $usesOnlineTiming ? $going : []),
            'waitlisted_count' => count($rows) - count($going),
            'csrf_token_id' => RegenerateOnlineLinkController::CSRF_TOKEN_ID,
        ]);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
