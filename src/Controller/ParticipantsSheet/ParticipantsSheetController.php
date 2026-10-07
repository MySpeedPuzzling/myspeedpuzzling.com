<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use SpeedPuzzling\Web\FormData\ExcelImportFormData;
use SpeedPuzzling\Web\FormType\ExcelImportFormType;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The participants spreadsheet of an event (docs/features/competitions-management/participants-spreadsheet.md): the
 * People tab and one tab per round, full screen, for the event's organisers. Twig renders the frame - the top bar, the
 * Tools menu, the import dialog, the setup checklist - and embeds the state the state endpoint answers
 * (GetParticipantsSheetState), so the first paint needs no request; the `participants-sheet` Stimulus controller
 * renders the tabs and the grid from it and saves through the sheet's JSON endpoints.
 *
 * Replaced the participants page and the round teams page (D12) - both redirect here.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ParticipantsSheetController extends AbstractController
{
    public const string TAB_PEOPLE = 'people';

    // Generated into URLs whose round the page fills in (`__ROUND__`) - a route's requirement accepts only a UUID
    private const string ROUND_PLACEHOLDER_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly GetParticipantsSheetState $getParticipantsSheetState,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/tabulka-ucastniku/{competitionId}',
            'en' => '/en/participants-sheet/{competitionId}',
            'es' => '/es/participants-sheet/{competitionId}',
            'ja' => '/ja/participants-sheet/{competitionId}',
            'fr' => '/fr/participants-sheet/{competitionId}',
            'de' => '/de/participants-sheet/{competitionId}',
        ],
        name: 'participants_sheet',
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $competitionId = $this->competitionRepository->get($competitionId)->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $state = $this->getParticipantsSheetState->forCompetition(
            $competitionId,
            $this->retrieveLoggedUserProfile->getProfile()?->playerId,
        );

        // A round of this event, else the People tab - an id of another event's round is nobody's business here
        $tabRound = $state->round($request->query->getString('tab', self::TAB_PEOPLE));
        $tab = $tabRound !== null ? $tabRound->id : self::TAB_PEOPLE;

        $importForm = $this->createForm(ExcelImportFormType::class, new ExcelImportFormData(), [
            'action' => $this->generateUrl('import_competition_participants', ['competitionId' => $competitionId]),
        ]);

        $response = $this->render('participants_sheet/page.html.twig', [
            'state' => $state,
            // In a <script type="application/json">: `<`, `>`, `&`, quotes escaped - a name can never close the tag
            'state_json' => json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
            'tab' => $tab,
            'urls' => $this->urls($competitionId),
            'countries' => self::countries($request->getLocale()),
            'import_form' => $importForm,
            'csrf_token_id' => OfficialResultsApi::CSRF_TOKEN_ID,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // Stateless CSRF checks the Origin/Referer of the JSON writes - a no-referrer policy would break them
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function urls(string $competitionId): array
    {
        $state = $this->generateUrl('participants_sheet_state', ['competitionId' => $competitionId]);
        $roundUrl = fn (string $route): string => str_replace(
            self::ROUND_PLACEHOLDER_ID,
            '__ROUND__',
            $this->generateUrl($route, ['roundId' => self::ROUND_PLACEHOLDER_ID]),
        );

        return [
            'state' => $state,
            'version' => $this->generateUrl('participants_sheet_version', ['competitionId' => $competitionId]),
            'changes' => $this->generateUrl('participants_sheet_changes', ['competitionId' => $competitionId]),
            'registration' => $this->generateUrl('participants_sheet_registration', ['competitionId' => $competitionId]),
            'record' => $roundUrl('official_results_record'),
            'tables' => $roundUrl('official_results_assign_table_numbers'),
            'playerSearch' => $this->generateUrl('player_search_autocomplete', ['format' => 'co-puzzler']),
            'import' => $this->generateUrl('import_competition_participants', ['competitionId' => $competitionId]),
            'export' => $this->generateUrl('export_competition_participants', ['competitionId' => $competitionId]),
            'rounds' => $this->generateUrl('manage_competition_rounds', ['competitionId' => $competitionId]),
        ];
    }

    /**
     * Country code (CountryCode case name) => its name in the page's language, by name - the sheet's country cells
     * and their typeahead.
     *
     * @return array<string, string>
     */
    private static function countries(string $locale): array
    {
        $countries = [];
        foreach (CountryCode::cases() as $country) {
            $code = strtoupper($country->name);
            $countries[$country->name] = Countries::exists($code) ? Countries::getName($code, $locale) : $country->value;
        }

        $collator = new \Collator($locale);
        uasort($countries, static fn (string $a, string $b): int => (int) $collator->compare($a, $b));

        return $countries;
    }
}
