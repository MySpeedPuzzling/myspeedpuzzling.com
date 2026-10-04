<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A puzzle's catalogue record as a moderator saves it - every field, whether it changes or not.
 * Applied by PuzzleRecordUpdater, for a change request approval, an approval of a new puzzle and a direct edit alike.
 */
readonly final class PuzzleRecordValues
{
    public function __construct(
        public string $name,
        // The main title's language when the box has no English title - null = English, or not known
        public null|string $nameLanguage,
        // Every other name, in order - the whole list as it is saved
        public PuzzleNames $alternativeNames,
        public null|string $manufacturerId,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public PuzzleImageChoice $image = PuzzleImageChoice::Keep,
        // Required for PuzzleImageChoice::Upload
        public null|UploadedFile $uploadedImage = null,
        // The record the form was loaded with (PuzzleRecordVersion) - a save over a newer record is refused; null
        // checks nothing (the internal API, a form rendered before the version existed)
        public null|string $recordVersion = null,
    ) {
    }
}
