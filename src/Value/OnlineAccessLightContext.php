<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * What a heartbeat needs to know about a personal link (OnlineAccessLight) - the ids only, kept in the shared cache
 * for a minute so a heartbeat costs no statement: the participant, their competition, the round their page is about
 * now (OnlineAccess' choice; null while the competition has no round with online timing), their entry in it (null when
 * they are not entered) and the device holding that entry (OnlineEntry::$activeDeviceId, null before any
 * `device.ready`). Ids in lower case.
 */
final readonly class OnlineAccessLightContext
{
    public function __construct(
        public string $participantId,
        public string $competitionId,
        public null|string $roundId,
        public null|string $entryId,
        public null|string $activeDeviceId,
    ) {
    }

    /**
     * @return array{participantId: string, competitionId: string, roundId: null|string, entryId: null|string, activeDeviceId: null|string}
     */
    public function toArray(): array
    {
        return [
            'participantId' => $this->participantId,
            'competitionId' => $this->competitionId,
            'roundId' => $this->roundId,
            'entryId' => $this->entryId,
            'activeDeviceId' => $this->activeDeviceId,
        ];
    }

    /**
     * Back from the cache - null for anything this version did not write
     */
    public static function fromArray(mixed $cached): null|self
    {
        if (!is_array($cached)) {
            return null;
        }

        $participantId = $cached['participantId'] ?? null;
        $competitionId = $cached['competitionId'] ?? null;
        $roundId = $cached['roundId'] ?? null;
        $entryId = $cached['entryId'] ?? null;
        $activeDeviceId = $cached['activeDeviceId'] ?? null;

        if (
            !is_string($participantId) || !Uuid::isValid($participantId)
            || !is_string($competitionId) || !Uuid::isValid($competitionId)
            || ($roundId !== null && (!is_string($roundId) || !Uuid::isValid($roundId)))
            || ($entryId !== null && (!is_string($entryId) || !Uuid::isValid($entryId)))
            || ($activeDeviceId !== null && !is_string($activeDeviceId))
        ) {
            return null;
        }

        return new self($participantId, $competitionId, $roundId, $entryId, $activeDeviceId);
    }
}
