<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use SpeedPuzzling\Web\Value\SuspiciousTimeReplyAnswer;
use SpeedPuzzling\Web\Value\SuspiciousTimeResponse;

/**
 * One person told about one mark of a case, with what they did and what a moderator answered.
 */
readonly final class SuspiciousTimeCaseNoticeRow
{
    public function __construct(
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public DateTimeImmutable $markedAt,
        public DateTimeImmutable $notifiedAt,
        public SuspiciousTimeNoticeVia $via,
        public null|SuspiciousTimeResponse $response,
        public null|string $responseText,
        public null|DateTimeImmutable $respondedAt,
        public null|SuspiciousTimeReplyAnswer $answer,
        public null|string $answerNote,
        public null|DateTimeImmutable $answeredAt,
    ) {
    }

    public function isOpenReply(): bool
    {
        return $this->response === SuspiciousTimeResponse::SaysCorrect && $this->answer === null;
    }
}
