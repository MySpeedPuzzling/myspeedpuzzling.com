<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * More people or puzzles in one send than AskToBorrowPuzzles allows - the sheet never offers that, so only a hand-made
 * request gets here.
 */
final class BorrowRequestTooLarge extends UnprocessableEntityHttpException
{
}
