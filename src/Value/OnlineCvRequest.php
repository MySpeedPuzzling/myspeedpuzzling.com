<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the CV worker checks (tools/online-cv/ `POST /check`, docs/features/online-competitions/README.md §5.8): the
 * finish photo, the entry's empty-mat photo (the background-holes check), the round puzzle's catalogue image (the
 * reference-grid check) - presigned GET URLs a few minutes long, made for the stores' server-side endpoints
 * (OnlineCvInputs) - and the puzzle's piece count (null with several puzzles in the round: the checks need it).
 */
final readonly class OnlineCvRequest
{
    public function __construct(
        public string $finishUrl,
        public null|string $emptyMatUrl,
        public null|string $referenceUrl,
        public null|int $pieceCount,
    ) {
    }

    /**
     * @return list<string>
     */
    public function urls(): array
    {
        return array_values(array_filter([$this->finishUrl, $this->emptyMatUrl, $this->referenceUrl], static fn (null|string $url): bool => $url !== null));
    }

    /**
     * @return array{finishUrl: string, emptyMatUrl: null|string, referenceUrl: null|string, pieceCount: null|int}
     */
    public function toArray(): array
    {
        return [
            'finishUrl' => $this->finishUrl,
            'emptyMatUrl' => $this->emptyMatUrl,
            'referenceUrl' => $this->referenceUrl,
            'pieceCount' => $this->pieceCount,
        ];
    }
}
