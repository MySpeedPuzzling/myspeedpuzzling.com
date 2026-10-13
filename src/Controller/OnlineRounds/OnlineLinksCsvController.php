<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\OnlineLinkRow;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\Export\SpreadsheetSafeValue;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkList;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineLinkUrl;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The personal links as CSV (docs/features/online-competitions/README.md §5.4, contract §5 `online_links_csv`) - name and
 * link of everybody going, for the organiser's own mailing. The names are typed by people: every cell goes through
 * SpreadsheetSafeValue (text never becomes a formula). The links are secrets: never stored by a browser or a proxy.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OnlineLinksCsvController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineLinkList $onlineLinkList,
        private readonly OnlineLinkUrl $onlineLinkUrl,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-souteze/{competitionId}/odkazy.csv',
            'en' => '/en/online-competitions/{competitionId}/links.csv',
            'es' => '/es/online-competitions/{competitionId}/links.csv',
            'ja' => '/ja/online-competitions/{competitionId}/links.csv',
            'fr' => '/fr/online-competitions/{competitionId}/links.csv',
            'de' => '/de/online-competitions/{competitionId}/links.csv',
        ],
        name: 'online_links_csv',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();
        $usesOnlineTiming = $this->onlineRoundRepository->ofCompetition($competitionId) !== [];

        $lines = [[
            $this->translator->trans('online_rounds.links.csv.name'),
            $this->translator->trans('online_rounds.links.csv.link'),
        ]];

        foreach ($this->onlineLinkList->forCompetition($competitionId, $this->retrieveLoggedUserProfile->getProfile()?->playerId, $usesOnlineTiming) as $row) {
            if ($this->hasLink($row)) {
                assert($row->token !== null);
                $lines[] = [$row->name, $this->onlineLinkUrl->of($row->token)];
            }
        }

        $slug = new AsciiSlugger()->slug($competition->name)->lower()->truncate(60)->toString();

        return new Response(self::csv($lines), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => new ResponseHeaderBag()->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                ($slug !== '' ? $slug : 'event') . '-personal-links.csv',
            ),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function hasLink(OnlineLinkRow $row): bool
    {
        return $row->waitlisted === false && $row->token !== null;
    }

    /**
     * @param list<list<string>> $lines
     */
    private static function csv(array $lines): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        // A BOM, so a spreadsheet opened by double click reads names with accents as UTF-8
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($lines as $line) {
            fputcsv($handle, array_map(SpreadsheetSafeValue::forCsv(...), $line), ',', '"', '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }
}
