<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ParticipantsSheet;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Exceptions\SheetChangesetIdTaken;
use SpeedPuzzling\Web\Exceptions\UnreadableSheetChanges;
use SpeedPuzzling\Web\Message\ApplyParticipantSheetChanges;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\AppliedParticipantSheetChanges;
use SpeedPuzzling\Web\Results\SheetChangeOutcome;
use SpeedPuzzling\Web\Results\SheetGroupOutcome;
use SpeedPuzzling\Web\Results\SheetWarning;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\ParticipantsSheet\SheetChangesParser;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Value\SheetChangeStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The participants sheet's change sets (ApplyParticipantSheetChanges): `{"changesetId", "dryRun", "groups": [...]}` →
 * an answer per group and change, the sheet versions around the write and - after the commit - a private Mercure update
 * for the other open sheets of the event. The official results API's rules (OfficialResultsApi: JSON only, stateless
 * CSRF, 401 instead of the login page, organisers only). Every refusal, conflict and warning carries the organiser's
 * text in `message`; codes stay in `reason` / `code` for the page.
 * docs/features/competitions-management/participants-spreadsheet.md §6, delivery contract §3.
 */
final class ApplyParticipantSheetChangesController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsApi $api,
        private readonly ParticipantsSheetLiveUpdates $liveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/participants-sheet-api/{competitionId}/changes',
        name: 'participants_sheet_changes',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): JsonResponse
    {
        try {
            $competitionId = $this->competitionRepository->get($competitionId)->id->toString();
        } catch (CompetitionNotFound) {
            // An event deleted while a page still holds changes for it: a refusal the page shows, not a request to retry
            return OfficialResultsApi::error('competition_not_found', JsonResponse::HTTP_NOT_FOUND, [
                'message' => $this->translator->trans('official_results.reason.competition_not_found'),
            ]);
        }

        $playerId = $this->api->authorise($request, $competitionId, write: true);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        try {
            $body = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return OfficialResultsApi::error('invalid_json', JsonResponse::HTTP_BAD_REQUEST, [
                'message' => $this->translator->trans('participants_sheet_server.invalid.not_a_list'),
            ]);
        }

        try {
            // Any JSON - a list or a number is an unreadable change set like any other (not_a_list), with its text
            $changeSet = SheetChangesParser::parse($body);
        } catch (UnreadableSheetChanges $exception) {
            // The organiser reads `message` - always the translated reason, never the parser's developer text
            return OfficialResultsApi::error('invalid_changes', JsonResponse::HTTP_BAD_REQUEST, [
                'reason' => $exception->reason,
                'message' => $this->translator->trans('participants_sheet_server.invalid.' . $exception->reason),
            ]);
        }

        try {
            $envelope = $this->messageBus->dispatch(new ApplyParticipantSheetChanges(
                competitionId: $competitionId,
                actingPlayerId: $playerId,
                changesetId: $changeSet['changesetId'],
                groups: $changeSet['groups'],
                dryRun: $changeSet['dryRun'],
            ));
        } catch (SheetChangesetIdTaken) {
            return OfficialResultsApi::error('changeset_id_taken', JsonResponse::HTTP_CONFLICT, [
                'message' => $this->translator->trans('participants_sheet_server.changeset_id_taken'),
            ]);
        } catch (ParticipantImportPreviewStale) {
            // Something the change set names vanished outside the event's lock (a player deleting their account, ...):
            // nothing was written - sent again, it is planned against what is there now
            return $this->changedMeanwhile();
        } catch (HandlerFailedException $exception) {
            // The same between the read and the write: a player deleted after the plan checked them (the transaction
            // rolled back, nothing was written)
            if (!$exception->getPrevious() instanceof ForeignKeyConstraintViolationException) {
                throw $exception;
            }

            return $this->changedMeanwhile();
        }

        $applied = $envelope->last(HandledStamp::class)?->getResult();
        assert($applied instanceof AppliedParticipantSheetChanges);

        // Committed by now - the other open sheets of the event fetch the state again
        if ($applied->changedTheSheet()) {
            $this->liveUpdates->changed($competitionId, $applied->versionAfter);
        }

        return OfficialResultsApi::json([
            'dryRun' => $applied->dryRun,
            'replayed' => $applied->replayed,
            'versionBefore' => $applied->versionBefore,
            'versionAfter' => $applied->versionAfter,
            'groups' => array_map($this->group(...), $applied->groups),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function group(SheetGroupOutcome $group): array
    {
        return [
            'id' => $group->id,
            'status' => $group->status->value,
            'changes' => array_map(fn (SheetChangeOutcome $change): array => [
                'index' => $change->index,
                'status' => $change->status->value,
                'reason' => $change->reason,
                'message' => $this->changeMessage($change),
                'current' => $change->current,
            ], $group->changes),
            'warnings' => array_map(fn (SheetWarning $warning): array => [
                'code' => $warning->code,
                'message' => $this->message('participants_sheet_server.warning.' . $warning->code, $warning->parameters),
                'participantId' => $warning->participantId,
                'teamId' => $warning->teamId,
                'roundId' => $warning->roundId,
            ], $group->warnings),
            'deletedTeams' => $group->deletedTeams,
        ];
    }

    /**
     * The organiser's text: the reason's (one code, several causes - SheetChangeOutcome::messageKey()), "not saved" for a
     * change skipped with its group, none for one that went through.
     */
    private function changeMessage(SheetChangeOutcome $change): null|string
    {
        $key = $change->messageKey();

        if ($key !== null) {
            return $this->message($key, $change->parameters);
        }

        return $change->status === SheetChangeStatus::Skipped ? $this->message('participants_sheet_server.skipped', []) : null;
    }

    private function changedMeanwhile(): JsonResponse
    {
        return OfficialResultsApi::error('changed_meanwhile', JsonResponse::HTTP_CONFLICT, [
            'message' => $this->translator->trans('participants_sheet_server.changed_meanwhile'),
        ]);
    }

    /**
     * @param array<string, null|string|int> $parameters a null value is a pair/team without a name and members
     */
    private function message(string $key, array $parameters): string
    {
        $translated = [];

        foreach ($parameters as $name => $value) {
            $translated['%' . $name . '%'] = $value ?? $this->translator->trans('participants_sheet_server.no_name');
        }

        return $this->translator->trans($key, $translated);
    }
}
