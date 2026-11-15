<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\MyData;

use SpeedPuzzling\Web\Value\FirstTryAssessment;
use SpeedPuzzling\Web\Value\PaceFormCheck;
use SpeedPuzzling\Web\Value\ResultEntryCheck;

/**
 * What happened to an edit of one result in "My data" (MyDataResultEditor): saved, left as it is, or refused with the
 * edit form's own reasons - and everything the edit form's notice (`first_try/_notice.html.twig`) needs to ask its
 * questions.
 */
readonly final class MyDataResultEditOutcome
{
    /**
     * @param array<string, string> $errors worded already, keyed by the field (MyDataResultEdit::TIME …) or '' for
     *                                      the result as a whole
     */
    public function __construct(
        public bool $saved,
        public array $errors,
        public ResultEntryCheck $check,
        public null|PaceFormCheck $paceCheck,
        public bool $paceConfirmed,
        // What the handler saw when somebody saved a first try in the meantime - else the check's own
        public null|FirstTryAssessment $firstTry,
        // The refusal is a question only the row sheet can ask (the same time saved already, the first try is
        // elsewhere, a time far off the player's own): an edit in place sends the player there
        public bool $asksAQuestion = false,
    ) {
    }

    public static function nothingToSave(): self
    {
        return new self(false, [], ResultEntryCheck::nothing(), null, false, null);
    }

    public function isRefused(): bool
    {
        return $this->errors !== [];
    }

    public function firstError(): null|string
    {
        return array_values($this->errors)[0] ?? null;
    }
}
