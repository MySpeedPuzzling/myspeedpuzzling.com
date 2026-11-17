<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use DateTimeImmutable;

/**
 * A pending link request of the participants sheet (docs/features/competitions-management/participant-linking.md §7.1):
 * the claimant as they showed themselves at the claim - their name and #code snapshot, even for a private profile (the
 * claim screen says the organisers will see it: a consented disclosure to this event's organisers only, §3.3). The
 * player id is what an approval links (`player` change `to`).
 */
readonly final class ParticipantsSheetLinkRequest implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $participantId,
        // claim | dispute (LinkRequestKind)
        public string $kind,
        public string $playerId,
        public string $playerName,
        public string $playerCode,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @return array{id: string, participantId: string, kind: string, playerId: string, playerName: string, playerCode: string, createdAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'participantId' => $this->participantId,
            'kind' => $this->kind,
            'playerId' => $this->playerId,
            'playerName' => $this->playerName,
            'playerCode' => $this->playerCode,
            'createdAt' => $this->createdAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
