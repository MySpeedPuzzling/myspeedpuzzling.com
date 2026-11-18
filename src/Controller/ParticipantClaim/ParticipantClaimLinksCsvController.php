<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantClaim;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\Export\SpreadsheetSafeValue;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantClaimLinkList;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The personal claim links as CSV (docs/features/competitions-management/participant-linking.md §7.4) - name, country,
 * external id and claim link of everybody on the list not linked to a profile, for organisers who mail-merge in their
 * own tool. Missing links are made first (ParticipantClaimLinkList). The links carry no language (`participant_claim_link`)
 * - each person's page opens in their own language. Every cell through SpreadsheetSafeValue (a typed name never becomes
 * a formula); the links are secrets - never stored by a browser or a proxy. An event with online timing hands out its
 * personal links instead (§5.6): their CSV.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ParticipantClaimLinksCsvController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly ParticipantClaimLinkList $participantClaimLinkList,
        private readonly NameTagQrCode $nameTagQrCode,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odkazy-k-propojeni/{competitionId}.csv',
            'en' => '/en/claim-links/{competitionId}.csv',
            'es' => '/es/claim-links/{competitionId}.csv',
            'ja' => '/ja/claim-links/{competitionId}.csv',
            'fr' => '/fr/claim-links/{competitionId}.csv',
            'de' => '/de/claim-links/{competitionId}.csv',
        ],
        name: 'participants_claim_links_csv',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();

        if ($this->onlineRoundRepository->ofCompetition($competitionId) !== []) {
            return $this->redirectToRoute('online_links_csv', ['competitionId' => $competitionId]);
        }

        $lines = [[
            $this->translator->trans('participant_claim_link.csv.name'),
            $this->translator->trans('participant_claim_link.csv.country'),
            $this->translator->trans('participant_claim_link.csv.external_id'),
            $this->translator->trans('participant_claim_link.csv.link'),
        ]];

        foreach ($this->participantClaimLinkList->forCompetition($competitionId) as $row) {
            assert($row->token !== null);
            $lines[] = [$row->name, $row->country !== null ? strtoupper($row->country) : '', $row->externalId ?? '', $this->nameTagQrCode->claimUrl($row->token)];
        }

        $slug = new AsciiSlugger()->slug($competition->name)->lower()->truncate(60)->toString();

        return new Response(self::csv($lines), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => new ResponseHeaderBag()->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                ($slug !== '' ? $slug : 'event') . '-claim-links.csv',
            ),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
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
