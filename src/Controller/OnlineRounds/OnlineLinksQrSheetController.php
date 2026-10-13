<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkList;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkUrl;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The personal links as a printable sheet (docs/features/online-competitions/README.md §5.4, contract §5
 * `online_links_qr_sheet`): one card per participant who is going - name, a QR to scan with the phone they compete on,
 * the short link to type. A standalone print page like the name tags. The links are secrets: never cached.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OnlineLinksQrSheetController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineLinkList $onlineLinkList,
        private readonly OnlineLinkUrl $onlineLinkUrl,
        private readonly NameTagQrCode $qrCode,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-souteze/{competitionId}/odkazy/qr-kody',
            'en' => '/en/online-competitions/{competitionId}/links/qr-sheet',
            'es' => '/es/online-competitions/{competitionId}/links/qr-sheet',
            'ja' => '/ja/online-competitions/{competitionId}/links/qr-sheet',
            'fr' => '/fr/online-competitions/{competitionId}/links/qr-sheet',
            'de' => '/de/online-competitions/{competitionId}/links/qr-sheet',
        ],
        name: 'online_links_qr_sheet',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();
        $usesOnlineTiming = $this->onlineRoundRepository->ofCompetition($competitionId) !== [];
        $cards = [];

        foreach ($this->onlineLinkList->forCompetition($competitionId, $this->retrieveLoggedUserProfile->getProfile()?->playerId, $usesOnlineTiming) as $row) {
            if ($row->waitlisted || $row->token === null) {
                continue;
            }

            $url = $this->onlineLinkUrl->of($row->token);
            $cards[] = [
                'row' => $row,
                'short_url' => OnlineLinkUrl::short($url),
                'qr' => $this->qrCode->svg($url),
            ];
        }

        $response = $this->render('online_rounds/links_qr_sheet.html.twig', [
            'competition' => $competition,
            'cards' => $cards,
        ]);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
