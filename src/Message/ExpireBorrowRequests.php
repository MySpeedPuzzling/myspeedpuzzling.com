<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Daily (myspeedpuzzling:expire-borrow-requests): borrow requests older than BorrowRequest::EXPIRES_AFTER_DAYS close
 * their open puzzles, and the asker is told. Answers how many requests expired.
 */
readonly final class ExpireBorrowRequests
{
}
