<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ImportCompetitionData
{
    public function __construct(
        public null|string $competitionName,
        public null|string $competitionDateFrom,
        public null|string $competitionDateTo,
        public null|string $competitionLocation,
        public null|string $roundStart,
        public null|string $roundTimeLimit,
        public null|string $puzzlePieces,
        public null|string $puzzleBrand,
        public null|string $roundName,
        public null|string $puzzleName,
        public null|string $playerName,
        public null|string $playerLocation,
        public null|string $resultTime,
        public null|string $resultMissingPieces,
        public null|string $resultQualified,
    ) {
    }
}
