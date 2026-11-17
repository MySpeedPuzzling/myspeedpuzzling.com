<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineDetectionModeLocked;
use SpeedPuzzling\Web\Exceptions\OnlineVideoNotConfigured;
use SpeedPuzzling\Web\Message\EnableOnlineRound;
use SpeedPuzzling\Web\Message\UpdateOnlineRoundSettings;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\AdminOnlineTiming;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundSettingsLiveUpdates;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A round's online timing - what its "Online timing" page does (OnlineRoundSettingsController), through the same
 * messages: turns it on when it is off (EnableOnlineRound, credited to INTERNAL_API_REVIEWER_PLAYER_ID - it also gives
 * every participant going without a personal link one), then saves the settings (UpdateOnlineRoundSettings).
 *
 * Body (all optional - a field left out keeps the round's value; a round just turned on has the page's defaults
 * `off` / `left` / `complete_required`, video off): `detectionMode` (off | shadow | on), `unfinishedMeasure`
 * (left | placed), `missingPieceRule` (complete_required | penalty_5s), `videoEnabled`, `participantsSeeEachOther`.
 * The studio and recording are not on the page - kept as they are.
 *
 * Refusals, all before anything is written: a round that cannot use online timing (an in-person event, not solo, a
 * pick round - OnlineTimingNotAvailable, 409), another finish detection from the round's start until a Reset
 * (OnlineDetectionModeLocked, 409 - the same mode is fine), a change of the video switches while no media server is
 * configured (OnlineVideoNotConfigured, 422 - the page hides them then and keeps their values; sending the stored values
 * is fine). Drafts work like on the page.
 */
final class PutRoundOnlineTimingController extends AbstractController
{
    public const array FIELDS = ['detectionMode', 'unfinishedMeasure', 'missingPieceRule', 'videoEnabled', 'participantsSeeEachOther'];

    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly OnlineRoundSettingsLiveUpdates $settingsLiveUpdates,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/rounds/{roundId}/online-timing',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['PUT'],
    )]
    public function __invoke(string $roundId, Request $request): JsonResponse
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $roundId = $round->id->toString();
        $input = InternalApiInput::fromRequest($request, self::FIELDS);

        $detectionMode = self::enum($input, 'detectionMode', OnlineDetectionMode::class);
        $unfinishedMeasure = self::enum($input, 'unfinishedMeasure', OnlineUnfinishedMeasure::class);
        $missingPieceRule = self::enum($input, 'missingPieceRule', OnlineMissingPieceRule::class);
        $videoEnabled = $input->bool('videoEnabled');
        $participantsSeeEachOther = $input->bool('participantsSeeEachOther');
        $input->throwIfInvalid();

        $onlineRound = $this->onlineRoundRepository->ofRound($roundId);

        // What the round has now - or the page's defaults for a round about to be turned on (OnlineRound's own)
        $current = [
            'detectionMode' => $onlineRound->detectionMode ?? OnlineDetectionMode::Off,
            'unfinishedMeasure' => $onlineRound->unfinishedMeasure ?? OnlineUnfinishedMeasure::PiecesLeft,
            'missingPieceRule' => $onlineRound->missingPieceRule ?? OnlineMissingPieceRule::CompleteRequired,
            'videoEnabled' => $onlineRound->videoEnabled ?? false,
            'participantsSeeEachOther' => $onlineRound->participantsSeeEachOther ?? false,
        ];

        $detectionMode ??= $current['detectionMode'];
        $unfinishedMeasure ??= $current['unfinishedMeasure'];
        $missingPieceRule ??= $current['missingPieceRule'];
        $videoEnabled ??= $current['videoEnabled'];
        $participantsSeeEachOther ??= $current['participantsSeeEachOther'];

        // The page hides the video switches without a media server and keeps their values - a change asked for is refused
        if (
            $this->liveKitSettings->isAvailable() === false
            && ($videoEnabled !== $current['videoEnabled'] || $participantsSeeEachOther !== $current['participantsSeeEachOther'])
        ) {
            throw new OnlineVideoNotConfigured();
        }

        // OnlineRound::detectionModeLockedAt()'s rule, asked before turning anything on (the handler refuses it too)
        $detectionLocked = RoundClock::fromRound($round, $this->clock->now())->isWaiting() === false;

        if ($detectionLocked && $detectionMode !== $current['detectionMode']) {
            throw new OnlineDetectionModeLocked();
        }

        $turnedOn = false;
        $linksGiven = 0;

        if ($onlineRound === null) {
            if ($this->reviewerPlayerId === '') {
                throw new BadRequestHttpException('INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to turn online timing on as.');
            }

            // Refuses a round that cannot use online timing (OnlineTimingNotAvailable, 409)
            $envelope = $this->messageBus->dispatch(new EnableOnlineRound(
                competitionId: $round->competition->id->toString(),
                roundId: $roundId,
                actingPlayerId: $this->reviewerPlayerId,
            ));
            $given = $envelope->last(HandledStamp::class)?->getResult();
            $linksGiven = is_int($given) ? $given : 0;
            $turnedOn = true;

            $onlineRound = $this->onlineRoundRepository->ofRound($roundId);
            assert($onlineRound !== null);
        }

        $this->messageBus->dispatch(new UpdateOnlineRoundSettings(
            roundId: $roundId,
            detectionMode: $detectionMode,
            videoEnabled: $videoEnabled,
            participantsSeeEachOther: $participantsSeeEachOther,
            // Not on the page - kept as they are
            studioEnabled: $onlineRound->studioEnabled,
            recordingEnabled: $onlineRound->recordingEnabled,
            unfinishedMeasure: $unfinishedMeasure,
            missingPieceRule: $missingPieceRule,
        ));

        // After the commit, like the page: the open participant pages fetch the settings (a Mercure failure is only logged)
        $this->settingsLiveUpdates->settingsChanged($roundId);

        return new JsonResponse([
            'roundId' => $roundId,
            'competitionId' => $round->competition->id->toString(),
            // Turned on by this request; how many participants got a personal link with it
            'turnedOn' => $turnedOn,
            'linksGiven' => $linksGiven,
            'detectionModeLocked' => $detectionLocked,
            'videoAvailable' => $this->liveKitSettings->isAvailable(),
            'onlineTiming' => AdminOnlineTiming::fromOnlineRound($onlineRound)->toArray(),
        ]);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return null|T
     */
    private static function enum(InternalApiInput $input, string $field, string $enum): null|\BackedEnum
    {
        if ($input->has($field) === false) {
            return null;
        }

        $value = $input->string($field, required: true);

        if ($value === null) {
            return null;
        }

        $case = $enum::tryFrom($value);

        if ($case === null) {
            $input->addError($field, sprintf('must be one of: %s.', implode(', ', array_map(
                static fn (\BackedEnum $case): string => (string) $case->value,
                $enum::cases(),
            ))));
        }

        return $case;
    }
}
