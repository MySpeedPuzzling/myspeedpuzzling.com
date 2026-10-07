<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin\SuspiciousTimes;

use SpeedPuzzling\Web\Query\GetSuspiciousTimeCaseDetail;
use SpeedPuzzling\Web\Query\GetSuspiciousTimeQueue;
use SpeedPuzzling\Web\Query\GetSuspiciousTimesOverview;
use SpeedPuzzling\Web\Security\SuspiciousResultsVoter;
use SpeedPuzzling\Web\Value\SuspiciousTimeQueueTab;
use SpeedPuzzling\Web\Value\SuspiciousTimeReasonCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The time verification queue (docs/features/suspicious-time-review.md, "Moderator queue") - admins and community
 * moderators. English only, never "suspicious" in what a person reads.
 */
#[IsGranted(SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES)]
final class TimeVerificationController extends AbstractController
{
    public const string ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    public const int NOTE_MAX_LENGTH = 1000;

    public function __construct(
        private readonly GetSuspiciousTimeQueue $getSuspiciousTimeQueue,
        private readonly GetSuspiciousTimeCaseDetail $getSuspiciousTimeCaseDetail,
        private readonly GetSuspiciousTimesOverview $getSuspiciousTimesOverview,
    ) {
    }

    #[Route(path: '/admin/time-verification', name: 'admin_time_verification', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $tab = SuspiciousTimeQueueTab::tryFrom($request->query->getString('tab')) ?? SuspiciousTimeQueueTab::Fast;
        $page = max(1, $request->query->getInt('page', 1));
        $counts = $this->getSuspiciousTimeQueue->counts();
        $direction = $tab->direction();
        $puzzleCards = [];
        $caseIds = [];
        $total = 0;
        $perPage = GetSuspiciousTimeQueue::PER_PAGE;

        if ($direction !== null) {
            $pending = $this->getSuspiciousTimeQueue->pending($direction, $page);
            $caseIds = $pending->caseIds;
            $total = $pending->total;

            // One decision about the puzzle settles many cases - on top of the first page
            if ($page === 1) {
                $puzzleCards = $this->getSuspiciousTimeQueue->puzzleCards($direction);
            }
        } elseif ($tab->listsCases()) {
            $caseIds = $this->getSuspiciousTimeQueue->decidedCaseIds($tab, $page);
            $total = $counts->of($tab) ?? 0;
        } elseif ($tab === SuspiciousTimeQueueTab::Log) {
            $total = $counts->decisions;
            $perPage = GetSuspiciousTimesOverview::LOG_PER_PAGE;
        }

        return $this->render('admin/suspicious_times/queue.html.twig', [
            'tab' => $tab,
            'tabs' => SuspiciousTimeQueueTab::cases(),
            'counts' => $counts,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
            'puzzle_cards' => $puzzleCards,
            'cases' => $this->getSuspiciousTimeCaseDetail->cards($caseIds),
            'log' => $tab === SuspiciousTimeQueueTab::Log ? $this->getSuspiciousTimesOverview->log($page) : [],
            'numbers' => $tab === SuspiciousTimeQueueTab::Numbers ? $this->getSuspiciousTimesOverview->numbers() : null,
            'reason_codes' => SuspiciousTimeReasonCode::cases(),
            'note_max_length' => self::NOTE_MAX_LENGTH,
        ]);
    }

    /**
     * Where an action sends the moderator back to: the tab and page the form was on.
     *
     * @return array{tab: string, page: null|int}
     */
    public static function returnParameters(Request $request): array
    {
        $tab = SuspiciousTimeQueueTab::tryFrom($request->request->getString('tab')) ?? SuspiciousTimeQueueTab::Fast;
        $page = max(1, $request->request->getInt('page', 1));

        return ['tab' => $tab->value, 'page' => $page > 1 ? $page : null];
    }

    /**
     * The note as the moderator typed it, or null when empty. False when it is too long.
     */
    public static function note(Request $request): null|false|string
    {
        $note = trim($request->request->getString('note'));

        if ($note === '') {
            return null;
        }

        return mb_strlen($note) > self::NOTE_MAX_LENGTH ? false : $note;
    }
}
