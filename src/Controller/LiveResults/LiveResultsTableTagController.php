<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Services\CompetitionTagLanding;
use SpeedPuzzling\Web\Services\PreferredLocale;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A printed table tag - whoever sits at the seating table number in the current round (competition toolkit
 * B-qr-competitor-page.md §3.1; table numbers 1-9999, HasOfficialResult). Frozen path without a language
 * (R-review-and-plan.md §1.3, short for a small QR): the viewer's language is picked (PreferredLocale); a language
 * prefix is accepted too. Where it leads: CompetitionTagLanding.
 */
final class LiveResultsTableTagController extends AbstractController
{
    public const string TABLE_REQUIREMENT = '[1-9]\d{0,3}';

    public function __construct(
        private readonly CompetitionTagLanding $competitionTagLanding,
        private readonly PreferredLocale $preferredLocale,
    ) {
    }

    #[Route(
        path: '/live/{competitionId}/t/{tableNumber}',
        name: 'live_results_table_tag',
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'tableNumber' => self::TABLE_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    #[Route(
        path: '/{_locale}/live/{competitionId}/t/{tableNumber}',
        name: 'live_results_table_tag_localized',
        requirements: [
            '_locale' => 'en|cs|es|ja|fr|de',
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'tableNumber' => self::TABLE_REQUIREMENT,
        ],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId, int $tableNumber): RedirectResponse
    {
        $locale = $request->attributes->has('_locale') ? $request->getLocale() : $this->preferredLocale->of($request);

        return $this->competitionTagLanding->redirect(strtolower($competitionId), CompetitionTag::table($tableNumber), $locale);
    }
}
