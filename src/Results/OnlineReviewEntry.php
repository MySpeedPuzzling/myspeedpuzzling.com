<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One participant of an online round's review list (docs/features/online-competitions/README.md §5.9): who, the status
 * (OnlineReviewAssessment: needs a look / confirmed / unfinished / no finish), the suggested official result, the
 * official result the entry holds now, the confirmed decision (moment, source, when) and the reviewer's adjustment
 * against the nearest tap, the flags, how many taps there are - and (Phase 2) how many finish candidates, and the
 * detection error of a confirmed finish (confirmed − the candidate it was detected by, OnlineReviewAssessment) - and
 * (Phase 3) the completeness check of the photo the review opens on (the suggested candidate's or tap's photo -
 * OnlineReview): `false` while the check is off (no `check` key at all - the JSON as before), null when there is no
 * such photo.
 */
readonly final class OnlineReviewEntry implements \JsonSerializable
{
    /**
     * @param OnlineReviewAssessment::STATUS_* $status
     * @param list<OnlineReviewFlag> $flags
     */
    public function __construct(
        public string $participantId,
        public string $entryId,
        public null|string $onlineEntryId,
        public string $name,
        public null|string $country,
        public string $status,
        public OnlineReviewSuggestion $suggestion,
        public RoundEntryResult $official,
        public null|int $confirmedMomentMs,
        public null|string $confirmedSource,
        public null|int $reviewedAtMs,
        public null|int $adjustmentMs,
        public array $flags,
        public int $taps,
        public null|int $readyAtMs,
        public int $candidates = 0,
        public null|int $detectionErrorMs = null,
        public null|false|OnlineFinishPhotoCheck $check = false,
    ) {
    }

    /**
     * The same row with the completeness check of the photo the review opens on
     */
    public function withCheck(null|OnlineFinishPhotoCheck $check): self
    {
        return new self(
            participantId: $this->participantId,
            entryId: $this->entryId,
            onlineEntryId: $this->onlineEntryId,
            name: $this->name,
            country: $this->country,
            status: $this->status,
            suggestion: $this->suggestion,
            official: $this->official,
            confirmedMomentMs: $this->confirmedMomentMs,
            confirmedSource: $this->confirmedSource,
            reviewedAtMs: $this->reviewedAtMs,
            adjustmentMs: $this->adjustmentMs,
            flags: $this->flags,
            taps: $this->taps,
            readyAtMs: $this->readyAtMs,
            candidates: $this->candidates,
            detectionErrorMs: $this->detectionErrorMs,
            check: $check,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'participantId' => $this->participantId,
            'entryId' => $this->entryId,
            'onlineEntryId' => $this->onlineEntryId,
            'name' => $this->name,
            'country' => $this->country,
            'status' => $this->status,
            'suggestion' => $this->suggestion,
            'official' => $this->official->toWire(),
            'confirmed' => $this->status !== OnlineReviewAssessment::STATUS_CONFIRMED ? null : [
                'momentMs' => $this->confirmedMomentMs,
                'source' => $this->confirmedSource,
                'reviewedAtMs' => $this->reviewedAtMs,
                'adjustmentMs' => $this->adjustmentMs,
                'detectionErrorMs' => $this->detectionErrorMs,
            ],
            'flags' => $this->flags,
            'taps' => $this->taps,
            'readyAtMs' => $this->readyAtMs,
            'candidates' => $this->candidates,
        ];

        // Only while the completeness check is on - otherwise the row is byte for byte what it was before it
        if ($this->check !== false) {
            $data['check'] = $this->check;
        }

        return $data;
    }
}
