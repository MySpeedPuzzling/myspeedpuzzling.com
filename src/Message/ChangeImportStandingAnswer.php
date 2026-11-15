<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Change" beside an answer that stands for the whole file (docs/features/data-import/adding.md "A question is
 * asked once"): the question ($code - an unusual time, "the file says it is on MySpeedPuzzling") is asked again of
 * the rows that reach it from now on. The rows answered so far stay answered. Answers whether an answer stood.
 */
readonly final class ChangeImportStandingAnswer
{
    public function __construct(
        public string $importId,
        public string $playerId,
        public string $code,
    ) {
    }
}
