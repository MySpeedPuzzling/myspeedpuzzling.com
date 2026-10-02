<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetDuplicatePuzzleSignals;
use SpeedPuzzling\Web\Query\GetDuplicateResultsOverview;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Value\DuplicateCaseListTab;
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicatePuzzleSignalStatus;
use SpeedPuzzling\Web\Value\DuplicateTier;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Duplicate results in numbers (docs/features/duplicate-results.md, "Admin overview") - admins only,
 * not moderators: these are players' own results.
 */
#[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
final class DuplicateResultsController extends AbstractController
{
    public function __construct(
        private readonly GetDuplicateResultsOverview $getDuplicateResultsOverview,
        private readonly ClockInterface $clock,
        private readonly GetDuplicatePuzzleSignals $getDuplicatePuzzleSignals,
    ) {
    }

    #[Route(path: '/admin/duplicate-results', name: 'admin_duplicate_results', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $now = $this->clock->now();
        $tab = DuplicateCaseListTab::tryFrom($request->query->getString('tab')) ?? DuplicateCaseListTab::Open;
        $tier = DuplicateTier::tryFrom($request->query->getString('tier'));
        $kind = DuplicateKind::tryFrom($request->query->getString('kind'));
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->getDuplicateResultsOverview->countCases($tab, $tier, $kind);
        // Catalogue signal (Layer 4): two puzzle records with the same time from the same person
        $signalCounts = $this->getDuplicatePuzzleSignals->countsByStatus();
        $signalsPage = max(1, $request->query->getInt('signals_page', 1));

        return $this->render('admin/duplicate_results.html.twig', [
            'totals' => $this->getDuplicateResultsOverview->totals($now),
            'by_tier' => $this->getDuplicateResultsOverview->casesByTierAndStatus(),
            'by_kind' => $this->getDuplicateResultsOverview->casesByKindAndStatus(),
            'trend' => $this->getDuplicateResultsOverview->monthlyTrend($now),
            'gap_classes' => $this->getDuplicateResultsOverview->gapClasses(),
            'confirmed_real_share' => $this->getDuplicateResultsOverview->confirmedRealShareByTier(),
            'cases' => $this->getDuplicateResultsOverview->cases($tab, $tier, $kind, $page),
            'cases_total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / GetDuplicateResultsOverview::PER_PAGE)),
            'tab' => $tab,
            'tier' => $tier,
            'kind' => $kind,
            'tabs' => DuplicateCaseListTab::cases(),
            'tiers' => DuplicateTier::cases(),
            'kinds' => DuplicateKind::cases(),
            'statuses' => DuplicateCaseStatus::cases(),
            'puzzle_signals' => $this->getDuplicatePuzzleSignals->open($signalsPage),
            'puzzle_signal_counts' => $signalCounts,
            'signals_page' => $signalsPage,
            'signals_pages' => max(1, (int) ceil($signalCounts[DuplicatePuzzleSignalStatus::Open->value] / GetDuplicatePuzzleSignals::PER_PAGE)),
        ]);
    }
}
