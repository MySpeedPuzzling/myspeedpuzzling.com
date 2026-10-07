<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ReviewResults;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordResultReviewVisit;
use SpeedPuzzling\Web\Query\GetFirstTryTimes;
use SpeedPuzzling\Web\Query\GetPlayerDuplicateCases;
use SpeedPuzzling\Web\Query\GetPlayerSuspiciousTimes;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Review your results" (docs/features/duplicate-results.md, "Review page"): results awaiting verification first
 * (docs/features/suspicious-time-review.md, "Where they see it"), results saved twice, copies removed
 * automatically, then the first-try conflicts and late first tries (docs/features/first-try-integrity.md) -
 * always the signed-in player's own.
 *
 * `?from=rc-<contactId>` = opened from a "Your results" e-mail; the visit is recorded for the admin funnel.
 */
final class ReviewResultsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'review_results';
    private const string CONTACT_PREFIX = 'rc-';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerDuplicateCases $getPlayerDuplicateCases,
        readonly private GetFirstTryTimes $getFirstTryTimes,
        readonly private MessageBusInterface $messageBus,
        readonly private GetPlayerSuspiciousTimes $getPlayerSuspiciousTimes,
    ) {
    }

    #[Route(
        path: '/{_locale}/review-results',
        name: 'review_results',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $from = $request->query->getString('from');
        $contactId = substr($from, strlen(self::CONTACT_PREFIX));

        if (str_starts_with($from, self::CONTACT_PREFIX) && Uuid::isValid($contactId)) {
            $this->messageBus->dispatch(new RecordResultReviewVisit($contactId, $player->playerId));
        }

        return $this->render('review_results/index.html.twig', [
            'player_id' => $player->playerId,
            'suspicious_times' => $this->getPlayerSuspiciousTimes->openOf($player->playerId),
            'answered_suspicious_times' => $this->getPlayerSuspiciousTimes->recentlyAnsweredOf($player->playerId),
            'duplicates' => $this->getPlayerDuplicateCases->openOf($player->playerId),
            'auto_removals' => $this->getPlayerDuplicateCases->autoRemovalsOf($player->playerId),
            'conflicts' => $this->getFirstTryTimes->conflictsOf($player->playerId),
            'late_first_tries' => $this->getFirstTryTimes->lateFirstTriesOf($player->playerId),
        ]);
    }

    /**
     * The copies of a set as the page showed them (`copies[]` of the keep / both-real forms).
     *
     * @return list<string>
     */
    public static function shownCopies(Request $request): array
    {
        $copies = array_filter(
            $request->request->all('copies'),
            static fn (mixed $copy): bool => is_string($copy) && Uuid::isValid($copy),
        );

        /** @var list<string> */
        return array_values($copies);
    }
}
