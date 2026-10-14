<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\OnlineRound;

/**
 * The round's online timing settings a participant's phone uses (docs/features/online-competitions/README.md §5.3) -
 * the participant state's `round.settings` and their hash, `round.settingsHash`, which the heartbeat answers carry too:
 * a phone holding another hash fetches its state again (as for the round clock's RoundClock::stateHash()). The one
 * definition of both - the state (OnlineRoundState), the heartbeat's per-round cache (OnlineAccessLight) and the push
 * after a save (OnlineRoundSettingsLiveUpdates) all read them here.
 *
 * In: finish detection, live video, participants seeing each other, the studio, what time's up asks (pieces left or
 * placed). Not in: the recording and the missing-piece rule (nothing on the phone uses them), the studio's YouTube link
 * - changing those sends no phone to fetch its state.
 */
final readonly class OnlineParticipantSettings
{
    private function __construct(
        public OnlineDetectionMode $detection,
        public bool $video,
        public bool $seeEachOther,
        public bool $studio,
        public OnlineUnfinishedMeasure $unfinishedMeasure,
    ) {
    }

    public static function of(OnlineRound $onlineRound): self
    {
        return new self(
            detection: $onlineRound->detectionMode,
            video: $onlineRound->videoEnabled,
            seeEachOther: $onlineRound->participantsSeeEachOther,
            studio: $onlineRound->studioEnabled,
            unfinishedMeasure: $onlineRound->unfinishedMeasure,
        );
    }

    /**
     * The state's `round.settings` - in this order
     *
     * @return array{detection: string, video: bool, seeEachOther: bool, studio: bool, unfinishedMeasure: string}
     */
    public function toArray(): array
    {
        return [
            'detection' => $this->detection->value,
            'video' => $this->video,
            'seeEachOther' => $this->seeEachOther,
            'studio' => $this->studio,
            'unfinishedMeasure' => $this->unfinishedMeasure->value,
        ];
    }

    /**
     * Short and stable for the same settings, like RoundClock::stateHash():
     *     substr(sha1(json_encode(toArray())), 0, 12)
     */
    public function hash(): string
    {
        return substr(sha1(json_encode($this->toArray(), JSON_THROW_ON_ERROR)), 0, 12);
    }
}
