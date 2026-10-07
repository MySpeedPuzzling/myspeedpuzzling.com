<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Message\ApplyParticipantImport;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPreviewBuilder;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportStash;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Confirm" on the import preview: the form carries the previewed state (sheet, encoding, separator, mapping, mode)
 * and the plan's fingerprint. The plan is made again from the stashed file; anything that changed since the preview
 * sends the organiser back to it (docs/features/competitions-management/participant-import-preview.md D7, D8, D19).
 * Every answer is a 303.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ConfirmParticipantImportController extends AbstractController
{
    public function __construct(
        private readonly ParticipantImportStash $stash,
        private readonly ParticipantImportPreviewBuilder $previewBuilder,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/import-ucastniku-udalosti/{competitionId}/{token}/potvrdit',
            'en' => '/en/import-event-participants/{competitionId}/{token}/confirm',
            'es' => '/es/import-event-participants/{competitionId}/{token}/confirm',
            'ja' => '/ja/import-event-participants/{competitionId}/{token}/confirm',
            'fr' => '/fr/import-event-participants/{competitionId}/{token}/confirm',
            'de' => '/de/import-event-participants/{competitionId}/{token}/confirm',
        ],
        name: 'participant_import_confirm',
        requirements: ['token' => ParticipantImportPreviewController::TOKEN_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $token): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $toParticipants = $this->redirectToRoute('manage_competition_participants', [
            'competitionId' => $competitionId,
        ], Response::HTTP_SEE_OTHER);

        $input = $request->request->all();

        if (!$this->isCsrfTokenValid(ParticipantImportPreviewController::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->flash('danger', 'competition.participants.import.confirm.expired');

            return $this->backToPreview($competitionId, $token, self::stateOf($input));
        }

        $stashed = $this->stash->describe($token, $competitionId);

        if ($stashed === null) {
            $this->flash('danger', 'competition.participants.import.confirm.gone');

            return $toParticipants;
        }

        if ($stashed->isApplied()) {
            $this->flash('info', 'competition.participants.import.confirm.already_imported');

            return $toParticipants;
        }

        $preview = $this->previewBuilder->build($stashed, $competitionId, $input);
        $plan = $preview->plan;
        $back = $this->backToPreview($competitionId, $token, $preview->query());

        // The mapping must be the one made for exactly these columns, and the plan the one the organiser saw
        if ($plan === null || $preview->rows === null || $preview->mappingFromInput === false || $plan->fingerprint !== $request->request->getString('fingerprint')) {
            $this->flash('warning', 'competition.participants.import.confirm.stale');

            return $back;
        }

        if ($plan->canBeApplied() === false) {
            $this->flash('danger', 'competition.participants.import.confirm.cannot_apply');

            return $back;
        }

        if ($plan->removesAnything()) {
            if ($request->request->getBoolean('confirm_removal') === false) {
                $this->flash('danger', 'competition.participants.import.confirm.removal_not_confirmed');

                return $back;
            }

            if ($plan->isLargeRemoval() && trim($request->request->getString('removed_count')) !== (string) $preview->removedPeople()) {
                $this->flash('danger', 'competition.participants.import.confirm.removed_count_wrong');

                return $back;
            }
        }

        try {
            $this->messageBus->dispatch(new ApplyParticipantImport(
                $competitionId,
                $preview->rows,
                $plan->mode->value,
                $plan->fingerprint,
            ));
        } catch (ParticipantImportPreviewStale) {
            $this->flash('warning', 'competition.participants.import.confirm.stale');

            return $back;
        }

        $this->stash->markApplied($token, $competitionId);
        $this->stash->discard($token, $competitionId);

        $this->addFlash('success', $this->translator->trans('competition.participants.import.confirm.summary', [
            '%added%' => $plan->count(ParticipantImportRowAction::New),
            '%updated%' => $plan->count(ParticipantImportRowAction::Update),
            '%restored%' => $plan->count(ParticipantImportRowAction::Restore),
            '%unchanged%' => $plan->count(ParticipantImportRowAction::Unchanged),
            '%removed%' => $plan->count(ParticipantImportRowAction::Remove)
                + ($plan->mode === ParticipantImportMode::Sync ? $preview->removedPeople() : 0),
        ]));

        return $toParticipants;
    }

    /**
     * The previewed state from the posted hidden fields - for the way back when nothing else could be read.
     *
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    private static function stateOf(array $input): array
    {
        return array_intersect_key($input, array_flip(['sheet', 'encoding', 'separator', 'headers', 'map', 'mode']));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function backToPreview(string $competitionId, string $token, array $query): RedirectResponse
    {
        return $this->redirectToRoute('participant_import_preview', [
            'competitionId' => $competitionId,
            'token' => $token,
            ...$query,
        ], Response::HTTP_SEE_OTHER);
    }

    private function flash(string $type, string $key): void
    {
        $this->addFlash($type, $this->translator->trans($key));
    }
}
