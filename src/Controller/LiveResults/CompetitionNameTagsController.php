<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\LiveResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionNameTags;
use SpeedPuzzling\Web\Query\GetCompetitorPageSettings;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Results\CompetitionNameTag;
use SpeedPuzzling\Web\Results\RoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionTagSheet;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Printable name tags of an event (docs/features/competitions-management/live-results.md): an A4 sheet of tags -
 * name, flag, #CODE when linked, the table of the first round, and a QR that opens the participant in the live
 * entry. `?sort=name|table`, `?round=<roundId>` (only that round's people, with its table numbers), `?waitlist=1`
 * (the waitlist too - somebody may get a place at the door). A standalone print page like the table layout's.
 *
 * The competition toolkit's tags (B-qr-competitor-page.md §3.4, CompetitionTagSheet): `?kind=people|lineups|tables|
 * entries` and `?layout=tags|tents` - offered once the competition has a QR mode or competitor pages, or to whom
 * COMPETITION_TOOLKIT_MANAGE grants when they ask for a kind or layout.
 * Otherwise the page is the name tags page of before, byte for byte (CompetitionNameTagsGoldenTest).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionNameTagsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly GetCompetitionNameTags $getCompetitionNameTags,
        private readonly NameTagQrCode $nameTagQrCode,
        private readonly GetCompetitorPageSettings $getCompetitorPageSettings,
        private readonly CompetitionTagSheet $competitionTagSheet,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/jmenovky-ucastniku/{competitionId}',
            'en' => '/en/name-tags/{competitionId}',
            'es' => '/es/name-tags/{competitionId}',
            'ja' => '/ja/name-tags/{competitionId}',
            'fr' => '/fr/name-tags/{competitionId}',
            'de' => '/de/name-tags/{competitionId}',
        ],
        name: 'competition_name_tags',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);

        $roundId = $request->query->getString('round');
        $round = null;

        foreach ($rounds as $overview) {
            if ($overview->roundId === strtolower($roundId)) {
                $round = $overview;
            }
        }

        $settings = $this->getCompetitorPageSettings->of($competitionId);
        $toolkitConfigured = $settings->qrTagMode !== null || $settings->competitorPages;
        $asksForToolkit = $request->query->has('kind') || $request->query->has('layout');

        if ($toolkitConfigured || ($asksForToolkit && $this->isGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId))) {
            return self::privatePage($this->render('live_results/competition_tags.html.twig', [
                'competition' => $competition,
                'rounds' => $rounds,
                'uses_table_numbers' => array_any($rounds, static fn (RoundResultsOverview $overview): bool => $overview->tableNumbersOff === false),
            ] + $this->competitionTagSheet->variables($request, $competitionId, $rounds, $round, $settings)));
        }

        $sort = $request->query->getString('sort') === GetCompetitionNameTags::SORT_TABLE
            ? GetCompetitionNameTags::SORT_TABLE
            : GetCompetitionNameTags::SORT_NAME;

        $withWaitlist = $request->query->getBoolean('waitlist');
        $tags = $this->getCompetitionNameTags->forCompetition($competitionId, $round?->roundId, $sort, withWaitlist: true);
        $waitlisted = count(array_filter($tags, static fn (CompetitionNameTag $tag): bool => $tag->waitlisted));

        if ($withWaitlist === false) {
            $tags = array_values(array_filter($tags, static fn (CompetitionNameTag $tag): bool => $tag->waitlisted === false));
        }

        $locale = $request->getLocale();

        $response = $this->render('live_results/name_tags.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'round' => $round,
            'sort' => $sort,
            'with_waitlist' => $withWaitlist,
            // The choice is offered only when somebody is on the waitlist
            'waitlisted_count' => $waitlisted,
            'tags' => array_map(fn (CompetitionNameTag $tag): array => [
                'tag' => $tag,
                'qr' => $this->nameTagQrCode->svg($this->nameTagQrCode->url($competitionId, $tag->participantId, $locale)),
            ], $tags),
            'uses_table_numbers' => array_any($rounds, static fn (RoundResultsOverview $overview): bool => $overview->tableNumbersOff === false),
        ]);

        return self::privatePage($response);
    }

    private static function privatePage(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
