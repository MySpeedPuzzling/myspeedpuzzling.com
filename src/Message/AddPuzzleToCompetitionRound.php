<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class AddPuzzleToCompetitionRound
{
    public function __construct(
        public UuidInterface $roundPuzzleId,
        public string $roundId,
        public string $userId,
        public string $brand,
        public string $puzzle,
        public null|int $piecesCount,
        public null|UploadedFile $puzzlePhoto,
        public EanList $eans,
        public BrandCodeList $brandCodes,
        public bool $hideUntilRoundStarts,
        public PuzzleHideMode $hideMode = PuzzleHideMode::Entirely,
        // The round's automatic reveal (start + reveal delay) the organiser's page named for a secret puzzle - required
        // whenever $hideUntilRoundStarts: checked under the handler's lock, another moment now (the round's start or
        // delay changed after the page was loaded) or none at all is refused (AutomaticRevealChangedMeanwhile), so a
        // secret puzzle is never added for an earlier reveal than the one shown. No caller is exempt - one that adds a
        // secret puzzle says which moment it showed (CompetitionRound::automaticRevealAt() as it read the round).
        // Ignored for a puzzle that is not secret.
        public null|DateTimeImmutable $shownAutomaticRevealAt = null,
    ) {
    }
}
