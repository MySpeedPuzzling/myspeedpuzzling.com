<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Exceptions\InternalApiParticipantsNotAdded;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Message\ApplyParticipantSheetChanges;
use SpeedPuzzling\Web\Query\GetCompetitionParticipantsForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\AppliedParticipantSheetChanges;
use SpeedPuzzling\Web\Results\ManageableCompetitionParticipant;
use SpeedPuzzling\Web\Results\SheetChangeOutcome;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantNameKey;
use SpeedPuzzling\Web\Services\ParticipantsSheetLiveUpdates;
use SpeedPuzzling\Web\Value\SheetChange;
use SpeedPuzzling\Web\Value\SheetChangeGroup;
use SpeedPuzzling\Web\Value\SheetChangeStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds people to a competition and enters them into rounds - `{"participants": [{"name", "country"}], "roundIds": [...]}`
 * - through the participants sheet's one write message (ApplyParticipantSheetChanges, under CompetitionParticipantsLock,
 * credited to INTERNAL_API_REVIEWER_PLAYER_ID in its receipt): a `newParticipant` change per new person and a `place`
 * change (`out` → `in`) per round somebody is not in yet, all in ONE group - the sheet's rules decide, all or nothing.
 *
 * Idempotent for a script: a name is matched to the event's participants who are not removed by the import's name key
 * (ParticipantNameKey - case, spaces, diacritics, apostrophes and dashes do not matter); a match is not added again,
 * only entered into the rounds it is missing (its country is left as it is). A name of several participants is refused
 * (409 `ambiguous_name`), the same name twice in the body is one person. A removed participant is never brought back -
 * a new one is created. Two calls racing each other may still both create a person (the match is read before the lock).
 *
 * Answers 201 `[{participantId, name, roundIds, created}]` in the body's order (`roundIds` = every round of the event
 * the participant is in now). Works for drafts and for any event - the participants sheet does not ask for online ones.
 */
final class AddCompetitionParticipantsController extends AbstractController
{
    public const int MAX_PARTICIPANTS = 1000;

    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly GetCompetitionParticipantsForManagement $getParticipants,
        private readonly MessageBusInterface $messageBus,
        private readonly ParticipantsSheetLiveUpdates $sheetLiveUpdates,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly TranslatorInterface $translator,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/competitions/{competitionId}/participants',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId, Request $request): JsonResponse
    {
        $competition = $this->competitionRepository->get($competitionId);
        $competitionId = $competition->id->toString();

        $body = InternalApiJsonBody::parse($request);
        $input = new InternalApiInput($body, ['participants', 'roundIds']);
        $roundIds = $input->requiredIdList('roundIds', 'the rounds to enter everybody into ([] = none).');
        $people = $this->people($body['participants'] ?? null, $input);
        $input->throwIfInvalid();
        assert($roundIds !== null);

        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to add the participants as.');
        }

        $eventRoundIds = array_map(
            static fn (CompetitionRound $round): string => $round->id->toString(),
            $this->competitionRoundRepository->ofCompetition($competition),
        );
        $unknownRoundIds = array_values(array_diff($roundIds, $eventRoundIds));

        if ($unknownRoundIds !== []) {
            throw new NotFoundHttpException(sprintf('Unknown round ids of this competition: %s. Nothing was added.', implode(', ', $unknownRoundIds)));
        }

        // The event's participants who are not removed, by name key
        /** @var array<string, list<ManageableCompetitionParticipant>> $existingByKey */
        $existingByKey = [];

        foreach ($this->getParticipants->all($competitionId) as $participant) {
            $existingByKey[ParticipantNameKey::of($participant->participantName)][] = $participant;
        }

        $ambiguous = [];
        $changes = [];
        /** @var array<string, array{participantId: string, created: bool}> $resolved name key => participant */
        $resolved = [];

        foreach ($people as $key => $person) {
            $matches = $existingByKey[$key] ?? [];

            if (count($matches) > 1) {
                $ambiguous[] = $person['name'];

                continue;
            }

            if ($matches !== []) {
                $participantId = $matches[0]->participantId;
                $alreadyIn = $matches[0]->roundIds;
                $resolved[$key] = ['participantId' => $participantId, 'created' => false];
            } else {
                $participantId = Uuid::uuid7()->toString();
                $alreadyIn = [];
                $resolved[$key] = ['participantId' => $participantId, 'created' => true];
                $changes[] = SheetChange::newParticipant($participantId, $person['name'], $person['country'], null);
            }

            foreach ($roundIds as $roundId) {
                if (in_array($roundId, $alreadyIn, true) === false) {
                    $changes[] = SheetChange::place($participantId, $roundId, SheetChange::OUT, SheetChange::IN);
                }
            }
        }

        if ($ambiguous !== []) {
            throw InternalApiParticipantsNotAdded::ambiguousNames($ambiguous);
        }

        $created = count(array_filter($resolved, static fn (array $one): bool => $one['created']));

        if ($changes !== []) {
            $this->apply($competitionId, $changes);
        }

        $request->attributes->set(InternalApiAuditSubscriber::SUMMARY_ATTRIBUTE, [
            'participants' => count($people),
            'created' => $created,
            'changes' => count($changes),
        ]);

        // Read again: the rounds everybody is in now
        $roundsByParticipant = [];
        $namesByParticipant = [];

        foreach ($this->getParticipants->all($competitionId) as $participant) {
            $roundsByParticipant[$participant->participantId] = $participant->roundIds;
            $namesByParticipant[$participant->participantId] = $participant->participantName;
        }

        $answer = [];

        foreach ($resolved as $one) {
            $participantId = $one['participantId'];
            $participantRoundIds = array_values(array_intersect($eventRoundIds, $roundsByParticipant[$participantId] ?? []));

            $answer[] = [
                'participantId' => $participantId,
                'name' => $namesByParticipant[$participantId] ?? '',
                'roundIds' => $participantRoundIds,
                'created' => $one['created'],
            ];
        }

        return new JsonResponse($answer, Response::HTTP_CREATED);
    }

    /**
     * @param non-empty-list<SheetChange> $changes
     */
    private function apply(string $competitionId, array $changes): void
    {
        try {
            $envelope = $this->messageBus->dispatch(new ApplyParticipantSheetChanges(
                competitionId: $competitionId,
                actingPlayerId: $this->reviewerPlayerId,
                changesetId: Uuid::uuid7()->toString(),
                groups: [new SheetChangeGroup(Uuid::uuid7()->toString(), $changes)],
            ));
        } catch (ParticipantImportPreviewStale) {
            throw InternalApiParticipantsNotAdded::changedMeanwhile();
        } catch (HandlerFailedException $exception) {
            // A player deleted between the plan and the write - rolled back, like the sheet's own controller says it
            if ($exception->getPrevious() instanceof ForeignKeyConstraintViolationException) {
                throw InternalApiParticipantsNotAdded::changedMeanwhile();
            }

            throw $exception;
        }

        $applied = $envelope->last(HandledStamp::class)?->getResult();
        assert($applied instanceof AppliedParticipantSheetChanges);
        $group = $applied->groups[0] ?? null;
        assert($group !== null);

        if ($group->status === SheetChangeStatus::Conflict) {
            throw InternalApiParticipantsNotAdded::changedMeanwhile();
        }

        if ($group->status === SheetChangeStatus::Refused) {
            $reasons = [];

            foreach ($group->changes as $outcome) {
                if ($outcome->status === SheetChangeStatus::Refused) {
                    $reasons[] = $this->describe($changes[$outcome->index] ?? null, $outcome);
                }
            }

            throw InternalApiParticipantsNotAdded::refused($reasons !== [] ? $reasons : ['refused']);
        }

        // Committed by now - like the sheet's own controller: the open sheets and the event's displays read again
        if ($applied->changedTheSheet()) {
            $this->sheetLiveUpdates->changed($competitionId, $applied->versionAfter);
            $this->displayLiveUpdates->resultsChangedInCompetition($competitionId);
        }
    }

    private function describe(null|SheetChange $change, SheetChangeOutcome $outcome): string
    {
        $parameters = [];

        foreach ($outcome->parameters as $name => $value) {
            $parameters['%' . $name . '%'] = $value ?? '-';
        }

        $key = $outcome->messageKey();
        $text = $key !== null ? $this->translator->trans($key, $parameters, null, 'en') : '';
        $who = $change->name ?? $change->participantId ?? '?';
        $where = $change?->roundId !== null ? ' (round ' . $change->roundId . ')' : '';

        return sprintf('%s%s: %s%s', $who, $where, (string) $outcome->reason, $text !== '' ? ' - ' . $text : '');
    }

    /**
     * The `participants` list, read field by field (errors collected in `$input`) - keyed by name key, the same name
     * twice is one person (its first spelling and country).
     *
     * @return array<string, array{name: string, country: null|string}>
     */
    private function people(mixed $participants, InternalApiInput $input): array
    {
        if (is_array($participants) === false || array_is_list($participants) === false || $participants === []) {
            $input->addError('participants', 'is required - a non-empty list of {"name": "...", "country": "cz" | null}.');

            return [];
        }

        if (count($participants) > self::MAX_PARTICIPANTS) {
            $input->addError('participants', sprintf('can hold at most %d people per request.', self::MAX_PARTICIPANTS));

            return [];
        }

        $people = [];

        foreach ($participants as $index => $participant) {
            $field = sprintf('participants[%d]', $index);

            if (is_array($participant) === false || array_is_list($participant) && $participant !== []) {
                $input->addError($field, 'must be an object {"name": "...", "country": "cz" | null}.');

                continue;
            }

            /** @var array<string, mixed> $participant */
            $person = new InternalApiInput($participant, ['name', 'country']);
            $name = $person->string('name', required: true);
            $country = $person->string('country');

            try {
                $person->throwIfInvalid();
            } catch (InternalApiInvalidInput $invalid) {
                foreach ($invalid->errors as $personField => $error) {
                    $input->addError($field . '.' . $personField, $error);
                }

                continue;
            }

            assert($name !== null);
            $people[ParticipantNameKey::of($name)] ??= ['name' => $name, 'country' => $country !== null ? strtolower($country) : null];
        }

        return $people;
    }
}
