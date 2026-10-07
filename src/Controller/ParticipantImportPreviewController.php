<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPreviewBuilder;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * An uploaded participant list before anything is written: sheet, encoding/separator, column mapping, mode and the
 * plan of what confirming does (docs/features/competitions-management/participant-import-preview.md D2-D4, D7, D19).
 * The form is a GET inside a Turbo Frame - its whole state is in the URL.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ParticipantImportPreviewController extends AbstractController
{
    /** Session-backed: only signed-in organisers see the page */
    public const string CSRF_TOKEN_ID = 'participant_import';

    public const string TOKEN_REQUIREMENT = '[0-9a-f]{32}';

    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly ParticipantImportStash $stash,
        private readonly ParticipantImportPreviewBuilder $previewBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/import-ucastniku-udalosti/{competitionId}/{token}',
            'en' => '/en/import-event-participants/{competitionId}/{token}',
            'es' => '/es/import-event-participants/{competitionId}/{token}',
            'ja' => '/ja/import-event-participants/{competitionId}/{token}',
            'fr' => '/fr/import-event-participants/{competitionId}/{token}',
            'de' => '/de/import-event-participants/{competitionId}/{token}',
        ],
        name: 'participant_import_preview',
        requirements: ['token' => self::TOKEN_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId, string $token): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $stashed = $this->stash->describe($token, $competitionId)
            ?? throw new NotFoundHttpException('The uploaded participant list is gone or belongs to another event.');

        $preview = $stashed->appliedAt === null
            ? $this->previewBuilder->build($stashed, $competitionId, $request->query->all())
            : null;

        $response = $this->render('competition/participant_import_preview.html.twig', [
            'competition' => $competition,
            'stashed' => $stashed,
            'preview' => $preview,
            'plan' => $preview?->plan,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'fields' => array_values(array_filter(
                ParticipantImportField::cases(),
                static fn (ParticipantImportField $field): bool => $field !== ParticipantImportField::Ignore && $field !== ParticipantImportField::TeamInRound,
            )),
            'actions' => ParticipantImportRowAction::cases(),
            'encodings' => ParticipantFileOptions::ENCODINGS,
            'separators' => array_keys(ParticipantFileOptions::SEPARATORS),
        ]);

        // The file holds personal data (D9): never cached, its URL (with the token) never leaves the site
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
